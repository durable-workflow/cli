'use strict';

const fs = require('node:fs');
const {compareReleaseVersions, parseReleaseVersion} = require('./release-version');

const MODES = new Set(['dry-run', 'apply']);

function verifyUpgradeResult({evidence, releaseVersion, channelVersion, mode}) {
  if (!MODES.has(mode)) {
    throw new Error(`upgrade verification mode must be one of: ${[...MODES].join(', ')}`);
  }
  if (parseReleaseVersion(releaseVersion) === null) {
    throw new Error(`published CLI version is not valid SemVer: ${String(releaseVersion)}`);
  }
  if (parseReleaseVersion(channelVersion) === null) {
    throw new Error(`qualified channel version is not valid SemVer: ${String(channelVersion)}`);
  }
  if (!evidence || typeof evidence !== 'object' || Array.isArray(evidence)) {
    throw new Error('dw upgrade evidence must be a JSON object');
  }
  if (evidence.current_version !== releaseVersion) {
    throw new Error(
      `dw upgrade ran from ${String(evidence.current_version)}, expected published CLI ${releaseVersion}`,
    );
  }
  if (evidence.target_version !== channelVersion) {
    throw new Error(
      `dw upgrade resolved ${String(evidence.target_version)}, expected qualified channel ${channelVersion}`,
    );
  }

  const comparison = compareReleaseVersions(releaseVersion, channelVersion);
  let expectedStatus;
  let expectedDirection = null;
  let relation;
  if (comparison < 0) {
    expectedStatus = mode === 'dry-run' ? 'dry-run' : 'upgraded';
    expectedDirection = 'upgrade';
    relation = 'forward-upgrade';
  } else if (comparison === 0) {
    expectedStatus = 'noop';
    relation = 'noop';
  } else {
    expectedStatus = 'newer';
    relation = 'already-newer';
  }

  if (evidence.status !== expectedStatus) {
    throw new Error(
      `dw upgrade reported status=${String(evidence.status)} for ${relation}; expected ${expectedStatus}`,
    );
  }
  if (expectedDirection !== null && evidence.direction !== expectedDirection) {
    throw new Error(
      `dw upgrade reported direction=${String(evidence.direction)} for ${relation}; expected ${expectedDirection}`,
    );
  }
  if (relation === 'already-newer') {
    if (evidence.direction === 'downgrade') {
      throw new Error('default dw upgrade advertised a downgrade to the qualified channel');
    }
    if (Object.hasOwn(evidence, 'asset_url') || Object.hasOwn(evidence, 'checksum_url')) {
      throw new Error('default dw upgrade prepared downgrade assets for an already-newer binary');
    }
  }

  return Object.freeze({
    channel_version: channelVersion,
    current_version: releaseVersion,
    mode,
    relation,
    status: evidence.status,
  });
}

function parseArguments(arguments_) {
  const options = {};
  for (let index = 0; index < arguments_.length; index += 1) {
    const argument = arguments_[index];
    if (!['--channel-version', '--mode', '--release-version'].includes(argument)) {
      throw new Error(`unknown argument: ${argument}`);
    }
    const value = arguments_[index + 1];
    if (value === undefined || value.startsWith('--')) {
      throw new Error(`${argument} requires a value`);
    }
    options[argument.slice(2).replaceAll('-', '_')] = value;
    index += 1;
  }

  return {
    channelVersion: options.channel_version,
    mode: options.mode,
    releaseVersion: options.release_version,
  };
}

module.exports = {verifyUpgradeResult};

if (require.main === module) {
  try {
    const options = parseArguments(process.argv.slice(2));
    const evidence = JSON.parse(fs.readFileSync(0, 'utf8'));
    const result = verifyUpgradeResult({...options, evidence});
    process.stdout.write(`${JSON.stringify(result)}\n`);
  } catch (error) {
    process.stderr.write(`CLI upgrade verification failed: ${error.message}\n`);
    process.exitCode = 1;
  }
}
