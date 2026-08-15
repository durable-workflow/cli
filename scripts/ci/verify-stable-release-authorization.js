'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const {parseReleaseVersion} = require('./release-version');

const AUTHORIZATION_SCHEMA = 'durable-workflow.stable-authorization/v1';
const CONTROL_REPOSITORY = 'durable-workflow/.github';
const PRODUCT_OWNER_REVIEWER_ID = 1130888;
const STABLE_VERSION = '2.0.0';
const TAG_PREFIX = `stable-authorization/${STABLE_VERSION}/`;
const WORKFLOW_REF =
  'durable-workflow/.github/.github/workflows/stable-authorization.yml@refs/heads/main';

function canonicalize(value) {
  if (Array.isArray(value)) {
    return value.map(canonicalize);
  }
  if (value !== null && typeof value === 'object') {
    return Object.fromEntries(
      Object.keys(value).sort().map(key => [key, canonicalize(value[key])]),
    );
  }

  return value;
}

function digest(value) {
  const source = `${JSON.stringify(canonicalize(value), null, 2)}\n`;
  return crypto.createHash('sha256').update(source).digest('hex');
}

function requireExactKeys(value, expected, context) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error(`${context} must be an object`);
  }
  const actual = Object.keys(value).sort();
  const required = [...expected].sort();
  if (JSON.stringify(actual) !== JSON.stringify(required)) {
    throw new Error(`${context} keys must be exactly ${required.join(', ')}`);
  }

  return value;
}

function readJson(file, context) {
  let source;
  try {
    source = fs.readFileSync(file, 'utf8');
  } catch (error) {
    throw new Error(`cannot read ${context}: ${error.message}`);
  }

  try {
    return JSON.parse(source);
  } catch (error) {
    throw new Error(`${context} is not valid JSON: ${error.message}`);
  }
}

function validateStableAuthorization(authorization, options) {
  requireExactKeys(authorization, [
    'artifact_tuple',
    'channel',
    'contract',
    'decision',
    'evidence_gate',
    'readout_sha256',
    'request_sha256',
    'schema',
    'stable_version',
  ], 'stable authorization');

  if (
    authorization.schema !== AUTHORIZATION_SCHEMA
    || authorization.channel !== 'stable'
    || authorization.stable_version !== options.releaseVersion
    || authorization.evidence_gate !== 'pass'
  ) {
    throw new Error('stable authorization does not authorize this stable release');
  }
  if (options.releaseVersion !== STABLE_VERSION) {
    throw new Error(`stable authorization is only defined for ${STABLE_VERSION}`);
  }

  const authorizationTag = options.authorizationTag;
  if (!authorizationTag.startsWith(TAG_PREFIX)) {
    throw new Error(`stable authorization tag must start with ${TAG_PREFIX}`);
  }
  const candidate = authorizationTag.slice(TAG_PREFIX.length);
  if (!/^[a-z0-9][a-z0-9._-]{0,55}$/.test(candidate)) {
    throw new Error('stable authorization tag has an invalid candidate identity');
  }

  const tuple = authorization.artifact_tuple;
  if (
    !tuple
    || tuple.tag !== `release-candidate/rc/${candidate}`
    || !/^[0-9a-f]{40}$/.test(String(tuple.commit || ''))
  ) {
    throw new Error('stable authorization is not bound to the selected release candidate');
  }
  if (
    options.request?.stable_version !== STABLE_VERSION
    || JSON.stringify(options.request?.artifact_tuple) !== JSON.stringify(tuple)
  ) {
    throw new Error('stable authorization request does not bind the authorized artifact tuple');
  }
  const cli = tuple.components?.cli;
  const parsedCliVersion = parseReleaseVersion(cli?.version);
  if (
    !cli
    || cli.commit !== options.releaseCommit
    || parsedCliVersion === null
    || parsedCliVersion.core.join('.') !== STABLE_VERSION
    || parsedCliVersion.prerelease?.[0] !== 'rc'
  ) {
    throw new Error('stable authorization CLI component does not match the release commit');
  }

  const decision = requireExactKeys(authorization.decision, [
    'actor',
    'environment',
    'environment_approval',
    'environment_protection',
    'repository',
    'run_attempt',
    'run_id',
    'run_url',
    'status',
    'type',
    'workflow_commit',
    'workflow_ref',
  ], 'stable authorization decision');
  if (
    decision.status !== 'authorized'
    || decision.type !== 'protected-human-review'
    || decision.repository !== CONTROL_REPOSITORY
    || decision.workflow_ref !== WORKFLOW_REF
    || decision.environment !== 'stable-authorization'
    || !/^[A-Za-z0-9-]{1,39}$/.test(String(decision.actor || ''))
    || !/^[0-9a-f]{40}$/.test(String(decision.workflow_commit || ''))
    || !Number.isInteger(decision.run_id)
    || decision.run_id < 1
    || !Number.isInteger(decision.run_attempt)
    || decision.run_attempt < 1
    || decision.run_url
      !== `https://github.com/${CONTROL_REPOSITORY}/actions/runs/${decision.run_id}`
  ) {
    throw new Error('stable authorization lacks the protected human decision');
  }

  const protection = decision.environment_protection;
  const approval = decision.environment_approval;
  if (
    protection?.prevent_self_review !== true
    || JSON.stringify(protection?.required_reviewer_user_ids) !== `[${PRODUCT_OWNER_REVIEWER_ID}]`
    || approval?.state !== 'approved'
    || approval?.run_id !== decision.run_id
    || approval?.run_attempt !== decision.run_attempt
    || approval?.user?.id !== PRODUCT_OWNER_REVIEWER_ID
  ) {
    throw new Error('stable authorization lacks approved product-owner review evidence');
  }

  if (authorization.request_sha256 !== digest(options.request)) {
    throw new Error('stable authorization request digest does not match the immutable record');
  }
  if (authorization.readout_sha256 !== digest(options.readout)) {
    throw new Error('stable authorization readout digest does not match the immutable record');
  }
  if (
    authorization.contract?.url
      !== 'https://raw.githubusercontent.com/durable-workflow/.github/main/stable-authorization/contract.json'
    || authorization.contract?.sha256 !== digest(options.contract)
  ) {
    throw new Error('stable authorization contract digest does not match the immutable record');
  }

  return authorization;
}

function verifyStableAuthorizationDirectory(directory, options) {
  const authorization = readJson(
    path.join(directory, 'stable-authorization.json'),
    'stable authorization',
  );
  const request = readJson(
    path.join(directory, 'stable-authorization-request.json'),
    'stable authorization request',
  );
  const readout = readJson(
    path.join(directory, 'release-critical-readout.json'),
    'stable authorization readout',
  );
  const contract = readJson(
    path.join(directory, 'release-critical-contract.json'),
    'stable authorization contract',
  );

  return validateStableAuthorization(authorization, {
    ...options,
    contract,
    readout,
    request,
  });
}

module.exports = {
  AUTHORIZATION_SCHEMA,
  PRODUCT_OWNER_REVIEWER_ID,
  STABLE_VERSION,
  WORKFLOW_REF,
  digest,
  validateStableAuthorization,
  verifyStableAuthorizationDirectory,
};

if (require.main === module) {
  const [directory, releaseVersion, releaseCommit, authorizationTag] = process.argv.slice(2);
  if (!directory || !releaseVersion || !releaseCommit || !authorizationTag) {
    process.stderr.write(
      'Usage: verify-stable-release-authorization.js <directory> <release-version> <release-commit> <authorization-tag>\n',
    );
    process.exit(2);
  }

  try {
    verifyStableAuthorizationDirectory(directory, {
      authorizationTag,
      releaseCommit,
      releaseVersion,
    });
    process.stdout.write(
      `Verified ${authorizationTag} for stable CLI ${releaseVersion} at ${releaseCommit}.\n`,
    );
  } catch (error) {
    process.stderr.write(`Stable release authorization verification failed: ${error.message}\n`);
    process.exit(1);
  }
}
