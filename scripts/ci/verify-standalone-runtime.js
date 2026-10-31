'use strict';

const fs = require('node:fs');
const {loadDependencyPolicy, dependencyPolicyIdentity} = require('./verify-standalone-dependencies');

const MINIMUM_PHP_VERSION = '8.4.27';
const MINIMUM_PHP_VERSION_ID = 80427;

function verifyRuntimeDependencies(evidence) {
  const policy = loadDependencyPolicy();
  const opensslVersion = /^OpenSSL (\d+\.\d+\.\d+)(?:\s|$)/.exec(evidence.openssl_version);
  if (!opensslVersion || opensslVersion[1] !== policy.openssl.version) {
    throw new Error('produced OpenSSL runtime does not match the supported exact source pin');
  }
  const headers = /^OpenSSL (\d+\.\d+\.\d+)(?:\s|$)/.exec(evidence.openssl_header_version);
  if (!headers || headers[1] !== policy.openssl.version) {
    throw new Error('produced OpenSSL headers do not match the selected runtime');
  }
  if (!['Linux', 'Darwin', 'Windows'].includes(evidence.os_family)) {
    throw new Error('standalone runtime must identify a supported operating system');
  }
  if (evidence.os_family === 'Windows') {
    if (evidence.curl !== null) {
      throw new Error('Windows standalone must retain its streams transport policy');
    }
  } else {
    const curl = evidence.curl;
    const version = typeof curl?.version === 'string' && /^\d+\.\d+\.\d+$/.test(curl.version);
    const compareVersions = (a, b) => {
      const left = a.split('.').map(Number);
      const right = b.split('.').map(Number);
      for (let i = 0; i < left.length; i++) {
        if (left[i] !== right[i]) return left[i] - right[i];
      }
      return 0;
    };
    if (!version || compareVersions(curl.version, policy.curl.minimum_version) < 0) {
      throw new Error('produced libcurl does not meet the supported minimum version');
    }
    if (curl.ssl_version !== `OpenSSL/${policy.openssl.version}`) {
      throw new Error('libcurl must use the same pinned OpenSSL runtime');
    }
  }

  return {
    os_family: evidence.os_family,
    openssl: {
      version: opensslVersion[1],
      header_version: headers[1],
      support_ends: policy.openssl.support_ends,
      source: policy.openssl,
    },
    curl: evidence.curl,
    pcre_version: evidence.pcre_version ?? null,
    oniguruma_version: evidence.oniguruma_version ?? null,
    iconv_version: evidence.iconv_version ?? null,
    dependency_policy: dependencyPolicyIdentity(),
  };
}

function verifyStandaloneRuntime(evidence, expectedVersion) {
  const match = typeof expectedVersion === 'string' && /^8\.4\.(0|[1-9]\d*)$/.exec(expectedVersion);
  if (!match) {
    throw new Error('standalone PHP pin must be a stable 8.4 patch version');
  }
  const expectedVersionId = 80400 + Number(match[1]);
  if (!Number.isSafeInteger(expectedVersionId) || expectedVersionId < MINIMUM_PHP_VERSION_ID) {
    throw new Error(`standalone PHP pin must be at least ${MINIMUM_PHP_VERSION}`);
  }
  if (!evidence || typeof evidence !== 'object' || Array.isArray(evidence)) {
    throw new Error('standalone runtime probe must return a JSON object');
  }
  if (evidence.sapi !== 'micro') {
    throw new Error('standalone runtime probe must execute in the produced micro SAPI');
  }
  if (evidence.php_version !== expectedVersion) {
    throw new Error('produced PHP runtime does not match the configured stable pin');
  }
  if (!Number.isInteger(evidence.php_version_id) || evidence.php_version_id !== expectedVersionId) {
    throw new Error('produced PHP runtime version ID does not match the configured pin');
  }

  return Object.freeze({
    schema: 'durable-workflow.cli.standalone-runtime/v1',
    minimum_php_version: MINIMUM_PHP_VERSION,
    expected_php_version: expectedVersion,
    php_version: evidence.php_version,
    php_version_id: evidence.php_version_id,
    sapi: evidence.sapi,
    ...verifyRuntimeDependencies(evidence),
    verified: true,
  });
}

module.exports = {verifyStandaloneRuntime};

if (require.main === module) {
  try {
    const arguments_ = process.argv.slice(2);
    if (arguments_.length !== 2 || arguments_[0] !== '--expected-version') {
      throw new Error('usage: verify-standalone-runtime.js --expected-version <PHP version>');
    }
    const input = fs.readFileSync(0, 'utf8');
    if (Buffer.byteLength(input) > 4096) {
      throw new Error('standalone runtime probe output exceeds the size limit');
    }
    let evidence;
    try {
      evidence = JSON.parse(input);
    } catch {
      throw new Error('standalone runtime probe must return valid JSON');
    }
    const result = verifyStandaloneRuntime(evidence, arguments_[1]);
    process.stdout.write(`${JSON.stringify(result)}\n`);
  } catch (error) {
    process.stderr.write(`Standalone runtime verification failed: ${error.message}\n`);
    process.exitCode = 1;
  }
}
