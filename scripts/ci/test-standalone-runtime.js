'use strict';

const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const path = require('node:path');
const test = require('node:test');
const {verifyStandaloneRuntime} = require('./verify-standalone-runtime');

const verifier = path.join(__dirname, 'verify-standalone-runtime.js');
const fixed = {
  php_version: '8.4.27', php_version_id: 80427, sapi: 'micro',
  os_family: 'Linux', openssl_version: 'OpenSSL 3.5.9 29 Sep 2026',
  openssl_header_version: 'OpenSSL 3.5.9 29 Sep 2026',
  curl: {version: '8.23.0', ssl_version: 'OpenSSL/3.5.9'},
};

test('accepts the configured fixed micro runtime and later supported patch pins', () => {
  for (const patch of [27, 28]) {
    const version = `8.4.${patch}`;
    const result = verifyStandaloneRuntime({...fixed, php_version: version, php_version_id: 80400 + patch}, version);
    assert.equal(result.verified, true);
    assert.equal(result.php_version, version);
    assert.equal(result.minimum_php_version, '8.4.27');
  }
});

test('refuses an old runtime even when a caller supplies its matching old pin', () => {
  assert.throws(() => verifyStandaloneRuntime({php_version: '8.4.23', php_version_id: 80423, sapi: 'micro'}, '8.4.23'), /at least 8\.4\.27/);
  assert.throws(() => verifyStandaloneRuntime({php_version: '8.4.23', php_version_id: 80423, sapi: 'micro'}, '8.4.27'), /does not match/);
});

test('refuses external PHP, prereleases and runtimes from a different cache identity', () => {
  assert.throws(() => verifyStandaloneRuntime({...fixed, sapi: 'cli'}, '8.4.27'), /produced micro SAPI/);
  assert.throws(() => verifyStandaloneRuntime({...fixed, php_version: '8.4.27RC1'}, '8.4.27'), /does not match/);
  assert.throws(() => verifyStandaloneRuntime({...fixed, php_version: '8.4.28', php_version_id: 80428}, '8.4.27'), /does not match/);
});

test('refuses incomplete or contradictory runtime metadata', () => {
  for (const evidence of [null, [], {}, {...fixed, php_version_id: '80427'}, {...fixed, php_version_id: 80423}, {...fixed, php_version_id: 80427.5}]) {
    assert.throws(() => verifyStandaloneRuntime(evidence, '8.4.27'));
  }
  for (const version of ['8.5.0', '8.4.27-dev', 'v8.4.27', '8.4.027', null]) {
    assert.throws(() => verifyStandaloneRuntime(fixed, version), /stable 8\.4/);
  }
});

test('the command fails closed on malformed, empty, noisy or oversized probe output', () => {
  for (const input of ['', '{', 'warning\n' + JSON.stringify(fixed), ' '.repeat(4097)]) {
    const result = spawnSync(process.execPath, [verifier, '--expected-version', '8.4.27'], {input, encoding: 'utf8'});
    assert.equal(result.status, 1);
    assert.equal(result.stdout, '');
  }
});

test('the command emits a bounded verification receipt only for valid runtime evidence', () => {
  const result = spawnSync(process.execPath, [verifier, '--expected-version', '8.4.27'], {input: JSON.stringify(fixed), encoding: 'utf8'});
  assert.equal(result.status, 0);
  assert.equal(result.stderr, '');
  const receipt = JSON.parse(result.stdout);
  assert.equal(receipt.verified, true);
  assert.equal(receipt.php_version_id, 80427);
  assert.equal(receipt.openssl.version, '3.5.9');
  assert.equal(receipt.curl.version, '8.23.0');
});

test('refuses absent, unsupported or mismatched OpenSSL runtimes from stale caches', () => {
  for (const value of [null, undefined, 'OpenSSL 3.6.2 2026', 'OpenSSL 3.6.5 2026', 'OpenSSL 3.5.8 2026', 'OpenSSL 3.5.90 2026', 'LibreSSL 3.5.9']) {
    assert.throws(() => verifyStandaloneRuntime({...fixed, openssl_version: value}, '8.4.27'), /produced OpenSSL/);
  }
});

test('requires libcurl on Unix with its version floor and the same OpenSSL backend', () => {
  for (const curl of [null, {}, {version: '8.22.0', ssl_version: 'OpenSSL/3.5.9'}, {version: '8.23.0-dev', ssl_version: 'OpenSSL/3.5.9'}]) {
    assert.throws(() => verifyStandaloneRuntime({...fixed, curl}, '8.4.27'), /libcurl/);
  }
  assert.throws(() => verifyStandaloneRuntime({...fixed, curl: {...fixed.curl, ssl_version: 'OpenSSL/3.6.2'}}, '8.4.27'), /same pinned/);
  for (const os_family of ['Linux', 'Darwin']) {
    const result = verifyStandaloneRuntime({...fixed, os_family, curl: {...fixed.curl, version: '8.24.0'}}, '8.4.27');
    assert.equal(result.verified, true);
  }
});

test('retains Windows streams policy while checking its OpenSSL and dependency inventory', () => {
  const result = verifyStandaloneRuntime({...fixed, os_family: 'Windows', curl: null}, '8.4.27');
  assert.equal(result.verified, true);
  assert.equal(result.curl, null);
  assert.match(result.openssl.source.sha256, /^[a-f0-9]{64}$/);
  assert.throws(() => verifyStandaloneRuntime({...fixed, os_family: 'Windows'}, '8.4.27'), /streams transport/);
  assert.throws(() => verifyStandaloneRuntime({...fixed, os_family: 'unknown'}, '8.4.27'), /supported operating system/);
});

test('checks the loaded OpenSSL library separately from its compilation headers on every platform', () => {
  for (const os_family of ['Linux', 'Darwin', 'Windows']) {
    const evidence = {...fixed, os_family, curl: os_family === 'Windows' ? null : fixed.curl};
    assert.throws(() => verifyStandaloneRuntime({...evidence, openssl_version: 'OpenSSL 3.6.5'}, '8.4.27'), /produced OpenSSL runtime/);
    assert.throws(() => verifyStandaloneRuntime({...evidence, openssl_header_version: 'OpenSSL 3.6.5'}, '8.4.27'), /OpenSSL headers/);
  }
});
