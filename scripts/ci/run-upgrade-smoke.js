'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const {execFile, execFileSync} = require('node:child_process');
const {promisify, isDeepStrictEqual} = require('node:util');
const {validateChannel, requiredAssets} = require('./verify-cli-release-channel');
const {releaseImplementation} = require('./release-version');
const {verifyUpgradeResult} = require('./verify-cli-upgrade-result');
const {ASSETS, releaseIdentity, releaseAuthority, childEnvironment} = require('./verify-installed-release');

const AUTHORITY = 'https://api.github.com/repos/durable-workflow/cli/releases/latest';
const SCHEMA = 'durable-workflow.cli.upgrade-smoke-snapshot/v1';
const UPGRADE_ARGS = Object.freeze(['upgrade', '--dry-run', '--output=json']);
const CREDENTIALS = ['GH_TOKEN', 'GITHUB_TOKEN', 'GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN'];
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');

function binding(environment) {
  const run = {id: environment.GITHUB_RUN_ID, attempt: environment.GITHUB_RUN_ATTEMPT};
  if (!/^[1-9][0-9]*$/.test(run.id) || !/^[1-9][0-9]*$/.test(run.attempt)) {
    throw new Error('build smoke requires an exact controller run and attempt');
  }
  const source = {...releaseIdentity(environment.RELEASE_TAG, environment.VERIFIED_SOURCE_SHA),
    ...releaseAuthority(environment, environment.RELEASE_TAG), run};
  if (source.initiator === 'push' && source.control.commit !== source.commit) {
    throw new Error('tag-triggered smoke source and control must be identical');
  }
  return source;
}

function baseline(release) {
  validateChannel(release);
  if (!Number.isSafeInteger(release.id) || release.id <= 0 || release.immutable !== true) {
    throw new Error('smoke metadata requires an immutable public release identity');
  }
  const assets = release.assets;
  const expected = releaseImplementation(release.tag_name) === 'php' ? ASSETS : requiredAssets(release.tag_name);
  if (assets.length !== expected.length || assets.some(item => !expected.includes(item.name)) ||
      new Set(assets.map(item => item.name)).size !== assets.length ||
      new Set(assets.map(item => item.id)).size !== assets.length) {
    throw new Error('smoke metadata has duplicate or excessive assets');
  }
  for (const item of assets) {
    if (!Number.isSafeInteger(item.id) || item.id <= 0 || !Number.isSafeInteger(item.size) || item.size <= 0 ||
        !/^[A-Za-z0-9._-]+$/.test(item.name) || item.state !== 'uploaded' ||
        !/^sha256:[a-f0-9]{64}$/.test(item.digest)) {
      throw new Error('smoke metadata has an unknown asset identity');
    }
  }
  return {id: release.id, tag: release.tag_name, immutable: true,
    assets: assets.map(({id, name, size, digest}) => ({id, name, size, digest}))
      .sort((left, right) => Buffer.compare(Buffer.from(left.name), Buffer.from(right.name)))};
}

function regularBytes(filename, maximum = 1024 * 1024) {
  const stat = fs.lstatSync(filename);
  if (!stat.isFile() || stat.isSymbolicLink() || stat.size <= 0 || stat.size > maximum) {
    throw new Error('smoke input must be a bounded regular file');
  }
  return fs.readFileSync(filename);
}

function privateDirectory() {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'cli-upgrade-smoke-'));
  for (const name of ['home', 'tmp', 'config']) fs.mkdirSync(path.join(directory, name), {mode: 0o700});
  return directory;
}

function capture(filename, environment = process.env, execute = execFileSync) {
  const source = binding(environment);
  if (environment.GITHUB_REPOSITORY !== 'durable-workflow/cli' || !environment.GH_TOKEN) {
    throw new Error('metadata capture requires the owning repository read token');
  }
  const directory = privateDirectory();
  const deadline = Date.now() + 30000;
  try {
    const credential = {...childEnvironment(environment, directory), GH_TOKEN: environment.GH_TOKEN,
      GH_CONFIG_DIR: path.join(directory, 'gh-config'), GH_PROMPT_DISABLED: '1'};
    const read = (command, args) => {
      if (command !== 'gh' || args[0] !== 'api' || !args[1].startsWith('repos/durable-workflow/cli/')) {
        throw new Error('metadata capture requires the fixed public repository');
      }
      const timeout = deadline - Date.now();
      if (timeout <= 0) throw new Error('metadata capture exceeded its deadline');
      try {
        return execute(command, ['api', '--hostname', 'github.com', args[1]], {
          env: credential, encoding: 'utf8', maxBuffer: 256 * 1024, timeout, killSignal: 'SIGKILL',
        });
      } catch (error) {
        const status = /HTTP\s+(\d{3})/.exec(String(error.stderr || ''))?.[1] || 'unknown';
        throw new Error(`authenticated metadata read failed (HTTP ${status})`);
      }
    };
    const body = read('gh', ['api', 'repos/durable-workflow/cli/releases/latest']);
    const release = JSON.parse(body);
    const identity = baseline(release);
    if (identity.tag !== environment.OBSERVED_LATEST) throw new Error('Latest changed after release resolution');
    let object = JSON.parse(read('gh', ['api',
      `repos/durable-workflow/cli/git/ref/tags/${identity.tag}`])).object;
    for (let depth = 0; object?.type === 'tag' && depth < 16; depth += 1) {
      if (!/^[a-f0-9]{40}$/.test(object.sha)) throw new Error('stable tag object is invalid');
      object = JSON.parse(read('gh', ['api', `repos/durable-workflow/cli/git/tags/${object.sha}`])).object;
    }
    if (object?.type !== 'commit' || !/^[a-f0-9]{40}$/.test(object.sha)) {
      throw new Error('stable tag does not resolve to an exact source commit');
    }
    const commit = object.sha;
    const snapshot = {schema: SCHEMA, authority: AUTHORITY, source,
      captured_at: new Date().toISOString(), baseline: {...identity, commit},
      body, body_sha256: hash(body)};
    fs.writeFileSync(filename, `${JSON.stringify(snapshot, null, 2)}\n`, {flag: 'wx', mode: 0o600});
    return snapshot;
  } finally {
    fs.rmSync(directory, {recursive: true, force: true});
  }
}

function readSnapshot(filename, environment) {
  const bytes = regularBytes(filename);
  if (!/^[a-f0-9]{64}$/.test(environment.EXPECTED_METADATA_SHA256) ||
      hash(bytes) !== environment.EXPECTED_METADATA_SHA256) {
    throw new Error('smoke metadata differs from the exact producer artifact');
  }
  const snapshot = JSON.parse(bytes);
  if (snapshot.schema !== SCHEMA || snapshot.authority !== AUTHORITY ||
      !isDeepStrictEqual(snapshot.source, binding(environment)) || typeof snapshot.body !== 'string' ||
      hash(snapshot.body) !== snapshot.body_sha256) {
    throw new Error('smoke metadata source or body binding differs');
  }
  const age = Date.now() - Date.parse(snapshot.captured_at);
  if (!Number.isFinite(age) || age < -5000 || age > 2 * 60 * 60 * 1000) {
    throw new Error('smoke metadata is outside its capture window');
  }
  const {commit, ...identity} = snapshot.baseline || {};
  if (!/^[a-f0-9]{40}$/.test(commit) || identity.tag !== environment.OBSERVED_LATEST ||
      !isDeepStrictEqual(identity, baseline(JSON.parse(snapshot.body)))) {
    throw new Error('smoke metadata release or asset binding differs');
  }
  return snapshot;
}

async function exercise(filename, binary, evidencePath, environment = process.env, dependencies = {}) {
  for (const key of CREDENTIALS) {
    if (environment[key]) throw new Error('build-smoke execution credentials must be absent');
  }
  if (environment.DURABLE_WORKFLOW_RELEASE_API_URL) throw new Error('build smoke requires its owned metadata authority');
  const snapshot = readSnapshot(filename, environment);
  regularBytes(binary, 64 * 1024 * 1024);
  const executable = fs.realpathSync(binary);
  const binaryDigest = hash(regularBytes(executable, 64 * 1024 * 1024));
  const directory = privateDirectory();
  const route = `/latest/${crypto.randomBytes(16).toString('hex')}`;
  let requests = 0;
  const server = http.createServer((request, response) => {
    if (request.method !== 'GET' || request.url !== route || ++requests !== 1) {
      response.writeHead(404).end();
      return;
    }
    response.writeHead(200, {'Content-Type': 'application/json',
      'Content-Length': Buffer.byteLength(snapshot.body), Connection: 'close'});
    response.end(snapshot.body);
  });
  server.maxConnections = 2;
  server.requestTimeout = 5000;
  server.headersTimeout = 5000;
  server.setTimeout(5000, socket => socket.destroy());
  let evidence = {schema: 'durable-workflow.cli.upgrade-build-smoke/v1', source: snapshot.source,
    metadata_sha256: environment.EXPECTED_METADATA_SHA256, baseline: snapshot.baseline,
    authority_delivery: 'captured-public-metadata-loopback', live_https_qualified: false,
    outcome: 'fail'};
  try {
    await new Promise((resolve, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolve);
    });
    const child = childEnvironment(environment, directory);
    child.DURABLE_WORKFLOW_RELEASE_API_URL = `http://127.0.0.1:${server.address().port}${route}`;
    const invoke = dependencies.invoke || promisify(execFile);
    const result = await invoke(executable, UPGRADE_ARGS, {
      env: child, encoding: 'utf8', maxBuffer: 1024 * 1024, timeout: dependencies.timeout || 30000,
      windowsHide: true, killSignal: 'SIGKILL',
    });
    evidence.result = verifyUpgradeResult({evidence: JSON.parse(result.stdout),
      releaseVersion: snapshot.source.tag, channelVersion: snapshot.baseline.tag, mode: 'dry-run'});
    if (requests !== 1) throw new Error('upgrade did not consume exactly one captured metadata response');
    if (hash(regularBytes(executable, 64 * 1024 * 1024)) !== binaryDigest) {
      throw new Error('read-only upgrade smoke modified its binary');
    }
    evidence.outcome = 'pass';
  } catch (error) {
    evidence.error = error.message;
    throw error;
  } finally {
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
    fs.rmSync(directory, {recursive: true, force: true});
    evidence.cleanup = {responder_closed: true, private_state_removed: !fs.existsSync(directory)};
    fs.writeFileSync(evidencePath, `${JSON.stringify(evidence, null, 2)}\n`, {flag: 'wx', mode: 0o600});
  }
  return evidence;
}

module.exports = {AUTHORITY, UPGRADE_ARGS, baseline, capture, readSnapshot, exercise};

if (require.main === module) {
  (async () => {
    const [phase, ...args] = process.argv.slice(2);
    if (phase === 'capture' && args.length === 0) return capture('upgrade-smoke-metadata.json');
    if (phase === 'exercise' && args.length === 3) return exercise(...args);
    throw new Error('usage: run-upgrade-smoke.js capture|exercise METADATA BINARY EVIDENCE');
  })().catch(error => {
    process.stderr.write(`Required upgrade build-smoke control failed: ${error.message}\n`);
    process.exitCode = 1;
  });
}
