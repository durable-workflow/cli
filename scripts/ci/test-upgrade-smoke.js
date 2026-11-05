'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const {execFile, execFileSync, spawnSync} = require('node:child_process');
const {promisify} = require('node:util');
const {ASSETS} = require('./verify-installed-release');
const {RUST_REQUIRED_ASSETS} = require('./verify-cli-release-channel');
const {AUTHORITY, UPGRADE_ARGS, baseline, capture, readSnapshot, exercise} = require('./run-upgrade-smoke');

const COMMIT = 'a'.repeat(40);
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
const source = {RELEASE_TAG: '2.3.8', VERIFIED_SOURCE_SHA: COMMIT, RELEASE_INITIATOR: 'push',
  RELEASE_CONTROL_REF: 'refs/tags/2.3.8', RELEASE_CONTROL_COMMIT: COMMIT,
  GITHUB_RUN_ID: '100', GITHUB_RUN_ATTEMPT: '1',
  OBSERVED_LATEST: '2.3.6', PATH: process.env.PATH, SystemRoot: process.env.SystemRoot};

function release() {
  return {id: 123, tag_name: '2.3.6', draft: false, prerelease: false, immutable: true,
    assets: ASSETS.map((name, index) => ({id: index + 1, name, size: 100,
      state: 'uploaded', digest: `sha256:${'b'.repeat(64)}`}))};
}

test('captured baseline binds the complete published implementation asset set', () => {
  const value = release();
  assert.equal(baseline(value).assets.length, 14);
  value.tag_name = '2.5.0';
  value.assets = RUST_REQUIRED_ASSETS.map((name, index) => ({id: index + 1, name,
    size: 100, state: 'uploaded', digest: `sha256:${'b'.repeat(64)}`}));
  assert.equal(baseline(value).assets.length, 15);
});

function fixture(t, value = release()) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'cli-smoke-test-'));
  t.after(() => fs.rmSync(directory, {recursive: true, force: true}));
  const metadata = path.join(directory, 'metadata.json');
  const binary = path.join(directory, 'synthetic-binary');
  const evidence = path.join(directory, 'evidence.json');
  fs.writeFileSync(binary, 'synthetic fixture, never executed');
  const reads = [];
  capture(metadata, {...source, GH_TOKEN: 'synthetic-read-token', GITHUB_REPOSITORY: 'durable-workflow/cli'},
    (command, args, options) => {
      reads.push(args.at(-1));
      assert.equal(command, 'gh');
      assert.deepEqual(args.slice(0, 3), ['api', '--hostname', 'github.com']);
      assert.equal(options.env.GH_TOKEN, 'synthetic-read-token');
      assert.ok(fs.existsSync(options.env.HOME));
      assert.equal(options.env.GITHUB_TOKEN, undefined);
      return JSON.stringify(args.at(-1).endsWith('/releases/latest') ? value
        : {object: {type: 'commit', sha: 'c'.repeat(40)}});
    });
  assert.equal(reads.filter(value => value.endsWith('/releases/latest')).length, 1);
  const environment = {...source, EXPECTED_METADATA_SHA256: hash(fs.readFileSync(metadata))};
  return {directory, metadata, binary, evidence, environment};
}

async function syntheticCommand(_binary, args, options, fallback, expectedBody) {
  assert.deepEqual(args, ['upgrade', '--dry-run', '--output=json']);
  for (const name of ['GH_TOKEN', 'GITHUB_TOKEN', 'ACTIONS_RUNTIME_TOKEN', 'DURABLE_WORKFLOW_TOKEN']) {
    assert.equal(options.env[name], undefined);
  }
  const response = await fetch(options.env.DURABLE_WORKFLOW_RELEASE_API_URL || fallback);
  if (!response.ok) throw new Error(`HTTP ${response.status} rate limit exceeded`);
  const body = await response.text();
  if (expectedBody !== undefined) assert.equal(body, expectedBody);
  const value = JSON.parse(body);
  return {stdout: JSON.stringify({current_version: '2.3.8', target_version: value.tag_name, status: 'newer'})};
}

test('the original unauthenticated 403 fails, while exact captured metadata exercises the real command contract', async t => {
  const input = fixture(t);
  const old = http.createServer((_request, response) => response.writeHead(403).end('rate limit exceeded'));
  await new Promise(resolve => old.listen(0, '127.0.0.1', resolve));
  t.after(() => new Promise(resolve => old.close(resolve)));
  const fallback = `http://127.0.0.1:${old.address().port}/latest`;
  await assert.rejects(syntheticCommand(input.binary, UPGRADE_ARGS, {env: {}}, fallback), /HTTP 403/);
  let endpoint;
  let privateHome;
  const result = await exercise(input.metadata, input.binary, input.evidence, input.environment, {
    invoke: async (...args) => {
      endpoint = args[2].env.DURABLE_WORKFLOW_RELEASE_API_URL;
      privateHome = args[2].env.HOME;
      return syntheticCommand(...args, fallback, JSON.parse(fs.readFileSync(input.metadata)).body);
    },
  });
  assert.equal(result.outcome, 'pass');
  assert.equal(result.live_https_qualified, false);
  assert.equal(result.authority_delivery, 'captured-public-metadata-loopback');
  assert.equal(result.baseline.id, 123);
  assert.equal(result.baseline.commit, 'c'.repeat(40));
  assert.equal(result.baseline.assets.length, 14);
  assert.equal(result.result.relation, 'already-newer');
  assert.deepEqual(result.cleanup, {responder_closed: true, private_state_removed: true});
  assert.equal(fs.existsSync(privateHome), false);
  await assert.rejects(fetch(endpoint, {signal: AbortSignal.timeout(500)}));
});

function writeSyntheticExecutable(filename) {
  fs.writeFileSync(filename, `#!/usr/bin/env node
const assert = require('node:assert/strict');
assert.deepEqual(process.argv.slice(2), ['upgrade', '--dry-run', '--output=json']);
assert.equal(process.env.GH_TOKEN, undefined);
assert.equal(process.env.GITHUB_TOKEN, undefined);
fetch(process.env.DURABLE_WORKFLOW_RELEASE_API_URL).then(response => response.json()).then(value => {
  console.log(JSON.stringify({current_version: '2.3.8', target_version: value.tag_name, status: 'newer'}));
}).catch(() => { process.exitCode = 1; });
`);
  fs.chmodSync(filename, 0o700);
}

test('the default executor runs a credential-free synthetic executable with the exact upgrade arguments',
  {skip: process.platform === 'win32'}, async t => {
    const input = fixture(t);
    writeSyntheticExecutable(input.binary);
    const result = await exercise(input.metadata, input.binary, input.evidence, input.environment);
    assert.equal(result.outcome, 'pass');
    assert.equal(result.live_https_qualified, false);
  });

function materializeToolchainCheckout(job, directory) {
  const root = path.resolve(__dirname, '../..');
  const workflow = fs.readFileSync(path.join(root, '.github/workflows/release.yml'), 'utf8');
  const definition = workflow.split(`\n  ${job}:`)[1].split(/\n {2}\S/)[0];
  const patterns = /^ {10}sparse-checkout: \|\n((?: {12}.+\n)+)/m.exec(definition);
  assert.ok(patterns, `missing sparse checkout for ${job}`);
  const checkout = path.join(directory, 'release-toolchain-control');
  const options = {encoding: 'utf8', timeout: 10000,
    env: {...source, GIT_CONFIG_NOSYSTEM: '1', GIT_CONFIG_GLOBAL: os.devNull}};
  const git = process.platform === 'win32' ? String.raw`C:\Program Files\Git\cmd\git.exe` : '/usr/bin/git';
  execFileSync(git, ['clone', '--quiet', '--shared', '--no-checkout', root, checkout], options);
  execFileSync(git, ['-C', checkout, 'sparse-checkout', 'set', '--no-cone', '--stdin'],
    {...options, input: patterns[1].replace(/^ {12}/gm, '')});
  execFileSync(git, ['-C', checkout, 'checkout', '--quiet', '--detach', 'HEAD'], options);
  return path.join(checkout, 'scripts/ci/run-upgrade-smoke.mjs');
}

for (const job of ['build-binary', 'build-binary-windows']) {
  test(`${job} sparse checkout loads the actual smoke parent and its complete imports`, t => {
    const input = fixture(t);
    const entrypoint = materializeToolchainCheckout(job, input.directory);
    const args = [entrypoint, 'exercise', input.metadata, input.binary, input.evidence];
    const options = {cwd: input.directory, encoding: 'utf8', timeout: 10000};
    const refused = spawnSync(process.execPath, args, {...options,
      env: {...input.environment, GITHUB_TOKEN: 'synthetic-inherited-job-token'}});
    assert.equal(refused.status, 1);
    assert.match(refused.stderr, /execution credentials must be absent/);
    assert.doesNotMatch(refused.stderr, /MODULE_NOT_FOUND|synthetic-inherited-job-token/);
    assert.equal(fs.existsSync(input.evidence), false);
    if (process.platform === 'win32') return;
    writeSyntheticExecutable(input.binary);
    const executed = spawnSync(process.execPath, args, {...options, env: input.environment});
    assert.equal(executed.status, 0, executed.stderr);
    const result = JSON.parse(fs.readFileSync(input.evidence));
    assert.equal(result.outcome, 'pass');
    assert.equal(result.live_https_qualified, false);
    assert.deepEqual(result.cleanup, {responder_closed: true, private_state_removed: true});
  });
}

test('a failed authenticated metadata read never falls back to a synthetic successful snapshot', t => {
  const input = fixture(t);
  const destination = path.join(input.directory, 'absent.json');
  let privateHome;
  assert.throws(() => capture(destination, {...source, GH_TOKEN: 'synthetic',
    GITHUB_REPOSITORY: 'durable-workflow/cli'}, (_command, _args, options) => {
    privateHome = options.env.HOME;
    throw Object.assign(new Error('fixture failure'), {stderr: 'gh: rate limit exceeded (HTTP 403)'});
  }), /HTTP 403/);
  assert.equal(fs.existsSync(destination), false);
  assert.equal(fs.existsSync(privateHome), false);
});

test('capture refuses drifting Latest and unknown release/asset identities', t => {
  const input = fixture(t);
  for (const mutate of [value => { value.tag_name = '2.3.9'; }, value => { value.immutable = false; },
    value => { delete value.id; }, value => { value.assets[0].digest = null; },
    value => { value.assets.push(value.assets[0]); }, value => { value.assets[0].name = 'unknown'; }]) {
    const value = release(); mutate(value);
    assert.throws(() => capture(path.join(input.directory, 'refused.json'), {...source,
      GH_TOKEN: 'synthetic', GITHUB_REPOSITORY: 'durable-workflow/cli'}, () => JSON.stringify(value)));
    assert.equal(fs.existsSync(path.join(input.directory, 'refused.json')), false);
  }
});

test('workflow delivers one exact private snapshot only to build smoke, with no credential in execution', () => {
  const workflow = fs.readFileSync(path.join(__dirname, '../../.github/workflows/release.yml'), 'utf8');
  const controller = workflow.split('\n  resolve-release:')[1].split('\n  release-preflight:')[0];
  assert.equal(controller.split('run-upgrade-smoke.mjs capture').length - 1, 1);
  assert.match(controller, /GH_TOKEN: \$\{\{ github.token \}\}/);
  assert.match(controller, /archive: false\n\s+name: upgrade-smoke-metadata/);
  assert.match(controller, /smoke_metadata_digest: \$\{\{ steps.smoke_metadata.outputs.artifact-digest \}\}/);
  const unix = workflow.split('\n  build-binary:')[1].split('\n  build-binary-windows:')[0];
  const windows = workflow.split('\n  build-binary-windows:')[1].split('\n  bundle-release:')[0];
  for (const job of [unix, windows]) {
    assert.match(job, /artifact-ids: \$\{\{ needs.resolve-release.outputs.smoke_metadata_id \}\}/);
    assert.match(job, /digest-mismatch: error/);
    const smoke = job.split('      - name: Smoke test binary')[1]
      .split('      - name: Retain deterministic upgrade build-smoke evidence')[0];
    assert.match(smoke, /EXPECTED_METADATA_SHA256: \$\{\{ needs.resolve-release.outputs.smoke_metadata_digest \}\}/);
    for (const name of ['GH_TOKEN', 'GITHUB_TOKEN', 'GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN']) {
      assert.match(smoke, new RegExp(`^          ${name}: ''$`, 'm'));
    }
    assert.doesNotMatch(smoke, /github.token|secrets\./);
    assert.match(smoke, /run-upgrade-smoke.mjs exercise/);
  }
  const launcher = unix.split('      - name: Qualify direct signals in the packaged POSIX launcher')[1]
    .split('      - name: Retain the verified standalone dependency inventory')[0];
  for (const name of ['GH_TOKEN', 'GITHUB_TOKEN', 'GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN']) {
    assert.match(launcher, new RegExp(`^          ${name}: ''$`, 'm'));
  }
  const installed = workflow.split('\n  verify-release:')[1];
  assert.doesNotMatch(installed, /run-upgrade-smoke|smoke_metadata|DURABLE_WORKFLOW_RELEASE_API_URL/);
  assert.match(installed, /run-public-release-verifier.mjs exercise/);
  const handoff = workflow.split('\n  bundle-release:')[1].split('\n  release:')[0];
  assert.match(handoff, /pattern: dw-\*/);
});

test('the actual entrypoint refuses a credential in its own parent environment before reading inputs', () => {
  const result = spawnSync(process.execPath, [path.join(__dirname, 'run-upgrade-smoke.mjs'),
    'exercise', 'missing-metadata', 'missing-binary', 'missing-evidence'], {
    env: {...source, GITHUB_TOKEN: 'synthetic-inherited-job-token'}, encoding: 'utf8', timeout: 5000,
  });
  assert.equal(result.status, 1);
  assert.match(result.stderr, /execution credentials must be absent/);
  assert.doesNotMatch(result.stderr, /synthetic-inherited-job-token|ENOENT/);
});

test('a cyclic annotated stable tag is bounded and cannot create a snapshot', t => {
  const input = fixture(t);
  let calls = 0;
  const filename = path.join(input.directory, 'cycle.json');
  assert.throws(() => capture(filename, {...source, GH_TOKEN: 'synthetic',
    GITHUB_REPOSITORY: 'durable-workflow/cli'}, (_command, args) => {
    calls += 1;
    return JSON.stringify(args.at(-1).endsWith('/releases/latest') ? release()
      : {object: {type: 'tag', sha: 'b'.repeat(40)}});
  }), /exact source commit/);
  assert.equal(calls, 18);
  assert.equal(fs.existsSync(filename), false);
});

test('snapshot must match its immutable producer digest and exact source/control/baseline binding', t => {
  const input = fixture(t);
  assert.equal(readSnapshot(input.metadata, input.environment).authority, AUTHORITY);
  assert.throws(() => readSnapshot(input.metadata, {...input.environment, GITHUB_RUN_ATTEMPT: '2'}), /source or body/);
  fs.appendFileSync(input.metadata, ' ');
  assert.throws(() => readSnapshot(input.metadata, input.environment), /exact producer/);
  const original = JSON.parse(fs.readFileSync(input.metadata));
  for (const mutate of [value => { value.source.commit = 'd'.repeat(40); },
    value => { value.baseline.assets[0].digest = `sha256:${'e'.repeat(64)}`; },
    value => { value.body += ' '; }, value => { value.authority = 'https://example.invalid'; },
    value => { value.captured_at = '2000-01-01T00:00:00Z'; }]) {
    const snapshot = structuredClone(original); mutate(snapshot);
    fs.writeFileSync(input.metadata, JSON.stringify(snapshot));
    assert.throws(() => readSnapshot(input.metadata, {...input.environment,
      EXPECTED_METADATA_SHA256: hash(fs.readFileSync(input.metadata))}));
  }
});

test('execution refuses credentials or an inherited authority override before starting a command', async t => {
  const input = fixture(t);
  await Promise.all(['GH_TOKEN', 'GITHUB_TOKEN', 'GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN',
    'DURABLE_WORKFLOW_RELEASE_API_URL'].map(async name => {
    let calls = 0;
    await assert.rejects(exercise(input.metadata, input.binary, input.evidence,
      {...input.environment, [name]: 'synthetic'}, {invoke: () => { calls += 1; }}), /credentials|authority/);
    assert.equal(calls, 0);
  }));
});

for (const failure of ['http403', 'wrong-target', 'downgrade', 'invalid-json', 'no-request', 'changed-binary']) {
  test(`build-smoke ${failure} is a failure with the exact responder and private state cleaned`, async t => {
    const input = fixture(t);
    let endpoint;
    let privateHome;
    await assert.rejects(exercise(input.metadata, input.binary, input.evidence, input.environment, {
      invoke: async (binary, args, options) => {
        endpoint = options.env.DURABLE_WORKFLOW_RELEASE_API_URL;
        privateHome = options.env.HOME;
        const result = failure === 'no-request'
          ? {stdout: JSON.stringify({current_version: '2.3.8', target_version: '2.3.6', status: 'newer'})}
          : await syntheticCommand(binary, args, options);
        if (failure === 'http403') return {stdout: JSON.stringify({current_version: '2.3.8', target_version: null, status: 'error'})};
        if (failure === 'wrong-target') return {stdout: JSON.stringify({current_version: '2.3.8', target_version: '9.0.0', status: 'newer'})};
        if (failure === 'downgrade') return {stdout: JSON.stringify({current_version: '2.3.8', target_version: '2.3.6', status: 'dry-run', direction: 'downgrade'})};
        if (failure === 'invalid-json') return {stdout: 'not JSON'};
        if (failure === 'changed-binary') fs.appendFileSync(binary, 'modified');
        return result;
      },
    }));
    const result = JSON.parse(fs.readFileSync(input.evidence));
    assert.equal(result.outcome, 'fail');
    assert.deepEqual(result.cleanup, {responder_closed: true, private_state_removed: true});
    assert.equal(fs.existsSync(privateHome), false);
    await assert.rejects(fetch(endpoint, {signal: AbortSignal.timeout(500)}));
  });
}

test('a timed-out command is killed and its responder/private state are cleaned', async t => {
  const input = fixture(t);
  let child;
  let privateHome;
  await assert.rejects(exercise(input.metadata, input.binary, input.evidence, input.environment, {
    timeout: 50,
    invoke: (_binary, _args, options) => {
      privateHome = options.env.HOME;
      const result = promisify(execFile)(process.execPath, ['-e', 'setInterval(() => {}, 1000)'], options);
      child = result.child;
      return result;
    },
  }));
  assert.ok(child.exitCode !== null || child.signalCode !== null);
  assert.equal(fs.existsSync(privateHome), false);
  assert.equal(JSON.parse(fs.readFileSync(input.evidence)).outcome, 'fail');
});
