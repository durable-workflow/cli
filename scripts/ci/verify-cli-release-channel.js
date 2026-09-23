'use strict';

const {parseReleaseVersion} = require('./release-version');

const AUTHORITY_SCHEMA = 'durable-workflow.docs.stable-releases';
const DEFAULT_CHANNEL_URL =
  'https://durable-workflow.com/stable-releases.json';
const DEFAULT_API_BASE = 'https://api.github.com/repos/durable-workflow/cli';
const REQUIRED_ASSETS = Object.freeze([
  'SHA256SUMS',
  'dw-linux-aarch64',
  'dw-linux-x86_64',
  'dw-macos-aarch64',
  'dw-windows-x86_64.exe',
  'dw.phar',
  'dw.rb',
  'install.ps1',
  'install.sh',
  'verify-release.sh',
]);

function expectedPrerelease(tag) {
  const parsed = parseReleaseVersion(tag);
  if (parsed === null) {
    throw new Error(`release tag is not valid SemVer: ${tag}`);
  }

  return parsed.prerelease !== null;
}

function validateChannel(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error('CLI release authority must be a JSON object');
  }
  if (value.schema !== AUTHORITY_SCHEMA || value.schema_version !== 1) {
    throw new Error('CLI release authority must use the stable-releases schema v1');
  }

  const version = value.artifacts?.cli;
  const parsed = parseReleaseVersion(version);
  if (parsed === null || parsed.prerelease !== null || parsed.build !== null) {
    throw new Error(`stable CLI version must be exact MAJOR.MINOR.PATCH: ${String(version)}`);
  }

  return Object.freeze({
    channel: 'stable',
    schema: value.schema,
    version,
  });
}

function validateReleaseMetadata(value, tag) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error(`GitHub release metadata for ${tag} must be a JSON object`);
  }
  if (value.tag_name !== tag) {
    throw new Error(`GitHub release metadata returned ${String(value.tag_name)} for ${tag}`);
  }
  if (value.draft !== false) {
    throw new Error(`GitHub release ${tag} is still a draft`);
  }

  const prerelease = expectedPrerelease(tag);
  if (value.prerelease !== prerelease) {
    throw new Error(
      `GitHub release ${tag} has prerelease=${String(value.prerelease)}; expected ${prerelease}`,
    );
  }

  const assets = Array.isArray(value.assets)
    ? new Set(value.assets.map(asset => asset?.name).filter(name => typeof name === 'string'))
    : new Set();
  const missing = REQUIRED_ASSETS.filter(asset => !assets.has(asset));
  if (missing.length > 0) {
    throw new Error(`GitHub release ${tag} is missing required assets: ${missing.join(', ')}`);
  }

  return value;
}

async function fetchJson(url, fetchImpl = globalThis.fetch) {
  if (typeof fetchImpl !== 'function') {
    throw new Error('a Fetch API implementation is required');
  }

  const response = await fetchImpl(url, {
    headers: {
      Accept: 'application/vnd.github+json, application/json',
      'User-Agent': 'durable-workflow-cli-release-verifier',
      'X-GitHub-Api-Version': '2022-11-28',
    },
    redirect: 'follow',
  });
  if (!response.ok) {
    throw new Error(`HTTP ${response.status} fetching ${url}`);
  }

  return response.json();
}

async function verifyPublicReleaseChannel(options = {}) {
  const channelUrl = options.channelUrl || DEFAULT_CHANNEL_URL;
  const apiBase = (options.apiBase || DEFAULT_API_BASE).replace(/\/$/, '');
  const fetchImpl = options.fetchImpl || globalThis.fetch;
  const releaseTag = options.releaseTag;

  if (typeof releaseTag !== 'string' || releaseTag === '') {
    throw new Error('releaseTag is required');
  }

  const channel = validateChannel(await fetchJson(channelUrl, fetchImpl));
  const releaseUrl = `${apiBase}/releases/tags/${encodeURIComponent(releaseTag)}`;
  const release = validateReleaseMetadata(
    await fetchJson(releaseUrl, fetchImpl),
    releaseTag,
  );
  const channelReleaseUrl = `${apiBase}/releases/tags/${encodeURIComponent(channel.version)}`;
  const channelRelease = channel.version === releaseTag
    ? release
    : validateReleaseMetadata(
      await fetchJson(channelReleaseUrl, fetchImpl),
      channel.version,
    );

  if ((channel.channel === 'prerelease') !== channelRelease.prerelease) {
    throw new Error(
      `CLI ${channel.channel} channel and GitHub metadata disagree for ${channel.version}`,
    );
  }

  return Object.freeze({
    channel: channel.channel,
    channel_version: channel.version,
    release_prerelease: release.prerelease,
    release_tag: releaseTag,
  });
}

function parseArguments(arguments_) {
  const options = {};
  for (let index = 0; index < arguments_.length; index += 1) {
    const argument = arguments_[index];
    if (!['--api-base', '--channel-url', '--release-tag'].includes(argument)) {
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
    apiBase: options.api_base,
    channelUrl: options.channel_url,
    releaseTag: options.release_tag,
  };
}

module.exports = {
  AUTHORITY_SCHEMA,
  DEFAULT_CHANNEL_URL,
  REQUIRED_ASSETS,
  expectedPrerelease,
  validateChannel,
  validateReleaseMetadata,
  verifyPublicReleaseChannel,
};

if (require.main === module) {
  verifyPublicReleaseChannel(parseArguments(process.argv.slice(2)))
    .then(result => process.stdout.write(`${JSON.stringify(result)}\n`))
    .catch(error => {
      process.stderr.write(`CLI release channel verification failed: ${error.message}\n`);
      process.exitCode = 1;
    });
}
