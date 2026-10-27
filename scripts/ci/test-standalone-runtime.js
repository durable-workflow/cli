'use strict';

const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const path = require('node:path');
const test = require('node:test');
const {verifyStandaloneRuntime} = require('./verify-standalone-runtime');

const verifier = path.join(__dirname, 'verify-standalone-runtime.js');
const fixed = {php_version: '8.4.27', php_version_id: 80427, sapi: 'micro'};

test('accepts the configured fixed micro runtime and later supported patch pins', () => {
  for (const patch of [27, 28]) {
    const version = `8.4.${patch}`;
    const result = verifyStandaloneRuntime({php_version: version, php_version_id: 80400 + patch, sapi: 'micro'}, version);
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
});
