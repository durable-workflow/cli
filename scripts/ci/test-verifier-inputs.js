'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const {execFileSync} = require('node:child_process');
const {ASSETS, PLATFORMS, CELL_MILLISECONDS, capturePhpAuthority, inspectPhpAuthority,
  assertPhpAuthority, assertInstallerSource, verifyBundle, verify} = require('./verify-installed-release');

const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const COMMIT = 'a'.repeat(40);
const git = process.platform === 'win32' ? String.raw`C:\Program Files\Git\cmd\git.exe` : '/usr/bin/git';

function scratch(t) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'cli-verifier-input-'));
  t.after(() => fs.rmSync(directory, {recursive: true, force: true}));
  return directory;
}

function syntheticPhp(directory) {
  const binary = path.join(directory, 'synthetic-php-tool');
  fs.writeFileSync(binary, 'synthetic tool; never executed');
  const authority = {version: '8.4.27', binary: fs.realpathSync(binary), sha256: hash(fs.readFileSync(binary))};
  return {authority, execute(command) {
    assert.ok(command === 'php' || command === authority.binary);
    return JSON.stringify({version: authority.version, binary: authority.binary});
  }};
}

test('PHP authority binds the exact bootstrap result and executable bytes without a patch-pin claim', t => {
  const fixture = syntheticPhp(scratch(t));
  const environment = {VERIFIER_PHP_SERIES: '8.4', BOOTSTRAPPED_PHP_VERSION: '8.4.27'};
  assert.deepEqual(capturePhpAuthority(environment, fixture.execute), fixture.authority);
  assert.throws(() => capturePhpAuthority({...environment, BOOTSTRAPPED_PHP_VERSION: '8.4.28'}, fixture.execute));
  assert.throws(() => capturePhpAuthority({...environment, VERIFIER_PHP_SERIES: '8.3'}, fixture.execute));
  assert.throws(() => capturePhpAuthority({...environment, BOOTSTRAPPED_PHP_VERSION: ''}, fixture.execute));
  assert.deepEqual(assertPhpAuthority(fixture.authority), fixture.authority);
  fs.appendFileSync(fixture.authority.binary, 'changed');
  assert.throws(() => assertPhpAuthority(fixture.authority), /captured authority/);
});

test('PHP authority capture refuses inherited credentials before executing any tool', t => {
  const fixture = syntheticPhp(scratch(t));
  for (const name of ['GH_TOKEN', 'GITHUB_TOKEN', 'GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN']) {
    let calls = 0;
    assert.throws(() => capturePhpAuthority({VERIFIER_PHP_SERIES: '8.4',
      BOOTSTRAPPED_PHP_VERSION: '8.4.27', [name]: 'synthetic-read-token'}, () => { calls += 1; }));
    assert.equal(calls, 0);
  }
  assert.ok(fixture.authority.sha256);
});

test('real autocrlf checkout cannot change immutable installer authority or admit transformed public bytes', t => {
  const directory = scratch(t);
  const root = path.resolve(__dirname, '../..');
  const checkout = path.join(directory, 'checkout');
  const downloaded = path.join(directory, 'downloaded');
  fs.mkdirSync(downloaded);
  const options = {encoding: 'utf8', timeout: 15000,
    env: {...process.env, GIT_CONFIG_NOSYSTEM: '1', GIT_CONFIG_GLOBAL: os.devNull, GIT_ATTR_NOSYSTEM: '1'}};
  const execute = (_command, args) => execFileSync(git, args, options);
  execFileSync(git, ['clone', '--quiet', '--shared', '--no-checkout', root, checkout], options);
  execFileSync(git, ['-C', checkout, 'config', '--local', 'core.autocrlf', 'true'], options);
  execFileSync(git, ['-C', checkout, 'checkout', '--quiet', '--detach', 'HEAD'], options);
  const commit = execute('git', ['-C', checkout, 'rev-parse', 'HEAD']).trim();
  for (const name of ['install.sh', 'install.ps1']) {
    const blob = Buffer.from(execute('git', ['-C', checkout, 'show', `${commit}:scripts/${name}`]));
    const working = fs.readFileSync(path.join(checkout, 'scripts', name));
    assert.notDeepEqual(working, blob, 'the real Git checkout must reproduce the original byte mismatch');
    fs.writeFileSync(path.join(downloaded, name), blob);
  }
  assertInstallerSource(downloaded, commit, execute, checkout);
  fs.copyFileSync(path.join(checkout, 'scripts/install.ps1'), path.join(downloaded, 'install.ps1'));
  assert.throws(() => assertInstallerSource(downloaded, commit, execute, checkout), error => {
    assert.equal(error.installer.name, 'install.ps1');
    assert.match(error.installer.expected_sha256, /^[a-f0-9]{64}$/);
    assert.match(error.installer.actual_sha256, /^[a-f0-9]{64}$/);
    assert.notEqual(error.installer.actual_sha256, error.installer.expected_sha256);
    return true;
  });
  assert.throws(() => assertInstallerSource(downloaded, 'b'.repeat(40), execute, checkout), /Git identity/);
});

function publicInputs() {
  return {run(command, args) {
    assert.equal(command, 'gh');
    if (args[1].includes('/releases/tags/')) return JSON.stringify({tag_name: '2.3.7', draft: false,
      prerelease: false, assets: ASSETS.map(name => ({name}))});
    if (args[1].includes('/git/ref/tags/')) return JSON.stringify({object: {type: 'commit', sha: COMMIT}});
    throw new Error('unexpected public-input fixture command');
  }};
}

function failedDownload() {
  let calls = 0;
  return async (_url, options) => {
    assert.equal(options.headers, undefined);
    assert.equal(options.redirect, 'manual');
    calls += 1;
    if (calls === 1) return new Response(null, {status: 302,
      headers: {location: 'https://release-assets.githubusercontent.com/asset?signature=do-not-leak'}});
    throw new TypeError('untrusted signed URL and credentials must not be reported',
      {cause: {code: 'UND_ERR_CONNECT_TIMEOUT', errno: -110, syscall: 'connect',
        message: 'signature=do-not-leak'}});
  };
}

test('download failure preserves asset/host/hop/status and safe system cause without signed URLs', async t => {
  const base = scratch(t);
  const state = {started: Date.now(), deadline: Date.now() + CELL_MILLISECONDS};
  const input = publicInputs();
  await assert.rejects(verifyBundle('2.3.7', COMMIT, path.join(base, 'release'), {}, state,
    {...input, fetch: failedDownload()}), error => {
    assert.deepEqual(error.publicDownload, {asset: 'dw.phar', host: 'release-assets.githubusercontent.com',
      hop: 1, status: null, codes: ['UND_ERR_CONNECT_TIMEOUT'], errno: [-110], syscall: ['connect']});
    assert.doesNotMatch(JSON.stringify(error), /signature|do-not-leak|credentials/);
    return true;
  });
});

function nativeRun(php, input) {
  const platform = Object.entries(PLATFORMS).find(([, value]) => value.os === process.platform && value.arch === process.arch);
  assert.ok(platform);
  return {platform: platform[0], execute(command, args) {
    if (command === php.authority.binary) return php.execute(command, args);
    if (command === 'uname' || command === 'pwsh') return platform[1].machine;
    if (command === 'sysctl') return '1';
    if (command === 'gh' && args[0] === '--version') return 'gh synthetic-version';
    if (command === 'gh' && args.includes('--help')) return 'synthetic help';
    return input.run(command, args);
  }};
}

test('failed public preparation retains sanitized evidence and exact private-state cleanup', async t => {
  const directory = scratch(t);
  const php = syntheticPhp(directory);
  const fixture = nativeRun(php, publicInputs());
  const output = path.join(directory, 'outputs');
  const evidence = path.join(directory, 'evidence.json');
  const environment = {VERIFIER_PHP_AUTHORITY: JSON.stringify(php.authority),
    RUNNER_TEMP: process.env.RUNNER_TEMP || os.tmpdir(),
    GITHUB_OUTPUT: output, RELEASE_INITIATOR: 'push', RELEASE_CONTROL_REF: 'refs/tags/2.3.7',
    RELEASE_CONTROL_COMMIT: COMMIT, GH_TOKEN: 'synthetic-read-token'};
  await assert.rejects(verify({tag: '2.3.7', commit: COMMIT, platform: fixture.platform, evidencePath: evidence},
    environment, {run: fixture.execute, fetch: failedDownload()}));
  const result = JSON.parse(fs.readFileSync(evidence));
  assert.equal(result.outcome, 'fail');
  assert.equal(result.phase, 'release-bundle');
  assert.equal(result.public_inputs_verified, false);
  assert.equal(result.distribution_executed, false);
  assert.deepEqual(result.cleanup, {private_state_allocated: true, private_state_removed: true});
  assert.equal(result.failure.download.asset, 'dw.phar');
  assert.doesNotMatch(fs.readFileSync(evidence, 'utf8'), /signature|do-not-leak|synthetic-read-token/);
  const owned = fs.readFileSync(output, 'utf8').trim().slice('directory='.length);
  assert.equal(fs.existsSync(owned), false);
});

test('missing PHP authority retains failure before allocating private state', async t => {
  const directory = scratch(t);
  const evidence = path.join(directory, 'evidence.json');
  const fixture = nativeRun(syntheticPhp(directory), publicInputs());
  await assert.rejects(verify({tag: '2.3.7', commit: COMMIT, platform: fixture.platform, evidencePath: evidence},
    {RELEASE_INITIATOR: 'push', RELEASE_CONTROL_REF: 'refs/tags/2.3.7', RELEASE_CONTROL_COMMIT: COMMIT},
    {run: fixture.execute}));
  const result = JSON.parse(fs.readFileSync(evidence));
  assert.equal(result.phase, 'prerequisites');
  assert.deepEqual(result.cleanup, {private_state_allocated: false, private_state_removed: true});
});

test('native CI records and rechecks the actual bootstrapped PHP authority',
  {skip: !process.env.DW_REQUIRE_NATIVE_VERIFIER_AUTHORITY}, () => {
    assert.equal(process.env.DW_REQUIRE_NATIVE_VERIFIER_AUTHORITY, '1');
    const authority = assertPhpAuthority(JSON.parse(process.env.VERIFIER_PHP_AUTHORITY));
    assert.deepEqual(inspectPhpAuthority(authority.binary), authority);
  });
