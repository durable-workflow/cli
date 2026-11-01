'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const {loadDependencyPolicy, dependencyPolicyIdentity, verifyOpenSslDownload} = require('./verify-standalone-dependencies');

function fixture(t) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'dw-openssl-source-'));
  t.after(() => fs.rmSync(root, {recursive: true, force: true}));
  const policy = structuredClone(loadDependencyPolicy());
  const bytes = Buffer.from('disposable OpenSSL archive fixture\n');
  policy.openssl.sha256 = crypto.createHash('sha256').update(bytes).digest('hex');
  const archive = path.join(root, policy.openssl.filename);
  fs.writeFileSync(archive, bytes);
  const lock = {openssl: {source_type: 'archive', filename: policy.openssl.filename, hash: 'legacy-sha1-is-not-authority'}};
  const save = () => fs.writeFileSync(path.join(root, '.lock.json'), JSON.stringify(lock));
  save();
  return {root, policy, archive, lock, save};
}

test('accepts only the selected source bytes and records their pinned identity', (t) => {
  const f = fixture(t);
  const result = verifyOpenSslDownload(f.root, f.policy);
  assert.equal(result.verified, true);
  assert.equal(result.sha256, f.policy.openssl.sha256);
  assert.equal(result.version, '3.5.9');
});

test('rejects tampered source even when the SPC lock keeps its accepted archive name', (t) => {
  const f = fixture(t);
  fs.appendFileSync(f.archive, 'changed');
  assert.throws(() => verifyOpenSslDownload(f.root, f.policy), /SHA-256/);
});

test('refuses stale, prebuilt, missing and redirected archive lock entries', (t) => {
  const f = fixture(t);
  for (const entry of [null, {...f.lock.openssl, filename: 'openssl-3.6.2.tar.gz'}, {...f.lock.openssl, source_type: 'pre-built'}, {...f.lock.openssl, filename: '../openssl-3.5.9.tar.gz'}]) {
    f.lock.openssl = entry;
    f.save();
    assert.throws(() => verifyOpenSslDownload(f.root, f.policy), /pinned OpenSSL source archive/);
  }
});

test('refuses a symlink in place of the verified source archive', (t) => {
  const f = fixture(t);
  const target = path.join(f.root, 'original');
  fs.renameSync(f.archive, target);
  fs.symlinkSync(target, f.archive);
  assert.throws(() => verifyOpenSslDownload(f.root, f.policy), /regular archive/);
});

test('source policy and verifier digests bind the cache identity to the selected library', (t) => {
  const identity = dependencyPolicyIdentity();
  assert.equal(identity.openssl_version, '3.5.9');
  for (const key of ['openssl_sha256', 'policy_sha256', 'verifier_sha256']) {
    assert.match(identity[key], /^[a-f0-9]{64}$/);
  }
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'dw-openssl-policy-'));
  t.after(() => fs.rmSync(root, {recursive: true, force: true}));
  const helper = path.join(root, 'verify-standalone-dependencies.js');
  fs.copyFileSync(path.join(__dirname, 'verify-standalone-dependencies.js'), helper);
  const policy = structuredClone(loadDependencyPolicy());
  const policyFile = path.join(root, 'standalone-dependencies.json');
  fs.copyFileSync(path.join(__dirname, 'standalone-dependencies.json'), policyFile);
  const resolve = () => JSON.parse(execFileSync(process.execPath, [helper, 'identity'], {encoding: 'utf8'}));
  assert.deepEqual(resolve(), identity);
  policy.openssl.sha256 = 'f'.repeat(64);
  fs.writeFileSync(policyFile, JSON.stringify(policy));
  const changed = resolve();
  assert.notEqual(changed.policy_sha256, identity.policy_sha256);
  assert.notEqual(changed.openssl_sha256, identity.openssl_sha256);
  assert.equal(changed.verifier_sha256, identity.verifier_sha256);
  fs.appendFileSync(helper, '\n// Changed verifier policy.\n');
  assert.notEqual(resolve().verifier_sha256, changed.verifier_sha256);
});

test('both native build paths verify the exact OpenSSL source before compilation and bind its cache policy', () => {
  const workflow = fs.readFileSync(path.join(__dirname, '../../.github/workflows/release.yml'), 'utf8');
  const unix = workflow.split('\n  build-binary:')[1].split('\n  build-binary-windows:')[0];
  const windows = workflow.split('\n  build-binary-windows:')[1].split('\n  bundle-release:')[0];
  for (const job of [unix, windows]) {
    assert.match(job, /scripts\/ci\/standalone-dependencies\.json/);
    assert.match(job, /--custom-url="openssl:/);
    assert.match(job, /verify-download downloads > openssl-source-verification\.json/);
    assert.ok(job.indexOf('verify-download downloads') < job.indexOf('Build and bind phpmicro on cold build'));
    assert.match(job, /dependency_policy=\$/);
    assert.match(job, /standalone-runtime-verification\.json/);
    assert.match(job, /\.dependencies\.json/);
  }
});

test('all four public dependency inventories use the ordinary required-asset and attestation path', () => {
  const workflow = fs.readFileSync(path.join(__dirname, '../../.github/workflows/release.yml'), 'utf8');
  const publication = workflow.split('\n  release:')[1];
  for (const target of ['linux-x86_64', 'linux-aarch64', 'macos-aarch64', 'windows-x86_64']) {
    assert.ok(publication.includes(`dw-${target}.dependencies.json`), target);
  }
  assert.match(publication, /subject-path: dist\/\*/);
});

test('each native platform exercises read-only outbound HTTPS before its release artifact is handed off', () => {
  const workflow = fs.readFileSync(path.join(__dirname, '../../.github/workflows/release.yml'), 'utf8');
  const unix = workflow.split('\n  build-binary:')[1].split('\n  build-binary-windows:')[0];
  const windows = workflow.split('\n  build-binary-windows:')[1].split('\n  bundle-release:')[0];
  for (const job of [unix, windows]) {
    assert.match(job, /upgrade --dry-run --output=json/);
    assert.ok(job.indexOf('upgrade --dry-run --output=json') < job.indexOf('Retain the verified standalone dependency inventory'));
  }
});
