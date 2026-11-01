'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const policyPath = path.join(__dirname, 'standalone-dependencies.json');
const hash = (value) => crypto.createHash('sha256').update(value).digest('hex');

function loadDependencyPolicy() {
  const policy = JSON.parse(fs.readFileSync(policyPath, 'utf8'));
  const openssl = policy.openssl;
  if (policy.schema !== 'durable-workflow.cli.standalone-dependencies/v1' || !openssl) {
    throw new Error('standalone dependency policy is invalid');
  }
  const version = /^3\.5\.(0|[1-9]\d*)$/.exec(openssl.version);
  if (!version || Number(version[1]) < 9) {
    throw new Error('standalone OpenSSL must use 3.5 LTS at 3.5.9 or later');
  }
  const filename = `openssl-${openssl.version}.tar.gz`;
  if (openssl.filename !== filename ||
      openssl.url !== `https://github.com/openssl/openssl/releases/download/openssl-${openssl.version}/${filename}` ||
      !/^[a-f0-9]{64}$/.test(openssl.sha256)) {
    throw new Error('OpenSSL source identity must name an exact official archive and SHA-256');
  }
  if (openssl.support_ends !== '2030-04-08') {
    throw new Error('OpenSSL 3.5 must retain its documented LTS support boundary');
  }
  if (Date.now() >= Date.parse(`${openssl.support_ends}T00:00:00Z`)) {
    throw new Error('the selected OpenSSL line has reached its support end');
  }
  if (!/^\d+\.\d+\.\d+$/.test(policy.curl?.minimum_version)) {
    throw new Error('standalone libcurl must have a stable minimum version');
  }
  return policy;
}

function dependencyPolicyIdentity() {
  const policy = loadDependencyPolicy();
  return {
    openssl_version: policy.openssl.version,
    openssl_sha256: policy.openssl.sha256,
    policy_sha256: hash(fs.readFileSync(policyPath)),
    verifier_sha256: hash(fs.readFileSync(__filename)),
  };
}

function verifyOpenSslDownload(directory, policy = loadDependencyPolicy()) {
  const lock = JSON.parse(fs.readFileSync(path.join(directory, '.lock.json'), 'utf8'));
  const entry = lock.openssl;
  if (entry?.source_type !== 'archive' || entry.filename !== policy.openssl.filename) {
    throw new Error('SPC must use the pinned OpenSSL source archive, not a prebuilt library');
  }
  const archive = path.join(directory, entry.filename);
  const stat = fs.lstatSync(archive);
  if (!stat.isFile() || stat.isSymbolicLink() || stat.size === 0) {
    throw new Error('OpenSSL source must be a nonempty regular archive');
  }
  const digest = hash(fs.readFileSync(archive));
  if (digest !== policy.openssl.sha256) {
    throw new Error('OpenSSL source SHA-256 does not match the pinned official archive');
  }
  return {
    schema: 'durable-workflow.cli.openssl-source/v1',
    version: policy.openssl.version,
    filename: entry.filename,
    url: policy.openssl.url,
    sha256: digest,
    verified: true,
  };
}

module.exports = {loadDependencyPolicy, dependencyPolicyIdentity, verifyOpenSslDownload};

if (require.main === module) {
  try {
    const args = process.argv.slice(2);
    let result;
    if (args.length === 1 && args[0] === 'identity') {
      result = dependencyPolicyIdentity();
    } else if (args.length === 1 && args[0] === 'url') {
      process.stdout.write(`${loadDependencyPolicy().openssl.url}\n`);
      return;
    } else if (args.length === 2 && args[0] === 'verify-download') {
      result = verifyOpenSslDownload(args[1]);
    } else {
      throw new Error('usage: verify-standalone-dependencies.js identity | url | verify-download <downloads directory>');
    }
    process.stdout.write(`${JSON.stringify(result)}\n`);
  } catch (error) {
    process.stderr.write(`Standalone dependency verification failed: ${error.message}\n`);
    process.exitCode = 1;
  }
}
