'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const {
  ASSETS, PLATFORMS, CELL_MILLISECONDS, releaseIdentity, childEnvironment, nativePlatform,
  checksums, verifyEmbeddedPhar, verifyNativeBinary, remaining, tagCommit, verifyBundle, publicResponse,
  assertPackageIdentity, assertInstalledInspection, assertVerifiedInputs, exercise,
} = require('./verify-installed-release');

const COMMIT = 'a'.repeat(40);
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
const state = () => {
  const started = Date.now();
  return {started, deadline: started + CELL_MILLISECONDS};
};

function fixture() {
  const phar = Buffer.from('synthetic archive bytes, never executed');
  const bytes = new Map(ASSETS.filter(name => name !== 'SHA256SUMS').map(name => [name,
    name === 'dw.phar' ? phar : Buffer.concat([Buffer.from(`fixture ${name}\n`), phar])]));
  bytes.set('SHA256SUMS', Buffer.from([...bytes].map(([name, value]) => `${hash(value)}  ${name}`).join('\n') + '\n'));
  const calls = [];
  const metadata = {tag_name: '2.3.7', draft: false, prerelease: false,
    assets: ASSETS.map(name => ({name}))};
  return {
    bytes, calls, metadata,
    run(command, args) {
      calls.push({command, args});
      assert.equal(command, 'gh', 'verification must not execute an installer or distribution');
      if (args[0] === 'api' && args[1].includes('/releases/tags/')) return JSON.stringify(metadata);
      if (args[0] === 'api' && args[1].includes('/git/ref/tags/')) return JSON.stringify({object: {type: 'commit', sha: COMMIT}});
      assert.deepEqual(args.slice(0, 2), ['attestation', 'verify']);
      assert.ok(args.includes('--source-digest'));
      assert.ok(args.includes(COMMIT));
      assert.ok(args.includes('refs/tags/2.3.7'));
      assert.ok(args.includes('durable-workflow/cli/.github/workflows/release.yml'));
      return '[{}]';
    },
    async fetch(url, options) {
      assert.equal(options.redirect, 'manual');
      assert.equal(options.headers, undefined, 'public downloads must not forward authentication');
      assert.ok(url.startsWith('https://github.com/durable-workflow/cli/releases/download/2.3.7/'));
      const name = new URL(url).pathname.split('/').at(-1);
      return new Response(bytes.get(name), {status: 200});
    },
  };
}

test('release identity refuses malformed tags and nonexact source hashes', () => {
  assert.deepEqual(releaseIdentity('2.3.7', COMMIT), {tag: '2.3.7', commit: COMMIT});
  for (const tag of ['latest', 'v2.3.7', '../2.3.7', '2.03.7', '2.3.7\n']) {
    assert.throws(() => releaseIdentity(tag, COMMIT));
  }
  assert.throws(() => releaseIdentity('2.3.7', 'main'));
});

test('installed children receive private config and no verification/runtime credentials', () => {
  const value = childEnvironment({PATH: '/tools', GH_TOKEN: 'fixture', GITHUB_TOKEN: 'fixture',
    ACTIONS_ID_TOKEN_REQUEST_TOKEN: 'fixture', DURABLE_WORKFLOW_TOKEN: 'fixture',
    DW_CLI_VERSION: 'fake', NODE_OPTIONS: '--require=fixture', HOME: '/original-home',
    SSL_CERT_FILE: '/public-ca.pem'}, '/temporary-cell', ['/temporary-cell/bin']);
  assert.equal(value.PATH, ['/temporary-cell/bin', '/tools'].join(path.delimiter));
  assert.equal(value.DW_CONFIG_HOME, path.join('/temporary-cell', 'config'));
  assert.equal(value.SSL_CERT_FILE, '/public-ca.pem');
  for (const key of ['GH_TOKEN', 'GITHUB_TOKEN', 'ACTIONS_ID_TOKEN_REQUEST_TOKEN',
    'DURABLE_WORKFLOW_TOKEN', 'DW_CLI_VERSION', 'NODE_OPTIONS', 'HOME']) assert.equal(value[key], undefined);
});

for (const [name, platform] of Object.entries(PLATFORMS)) {
  test(`native machine observation is required for ${name}`, () => {
    const execute = command => command === 'sysctl' ? '1\n' : platform.machine + '\n';
    assert.equal(nativePlatform(name, platform, execute).machine, platform.machine);
    assert.throws(() => nativePlatform(name, {...platform, arch: 'other'}, execute));
    assert.throws(() => nativePlatform(name, platform, () => 'wrong\n'));
  });
}

test('checksum manifest requires every asset exactly once and no paths', () => {
  const good = fixture().bytes.get('SHA256SUMS').toString();
  assert.equal(checksums(good).size, 13);
  for (const bad of [good + good, good.replace('dw.phar', '../dw.phar'),
    good.replace(/^.*\n/, ''), good.replace(/^[a-f0-9]/, 'g')]) {
    assert.throws(() => checksums(bad));
  }
});

test('standalone PHAR identity compares complete bytes', () => {
  const phar = Buffer.from('entire synthetic archive');
  verifyEmbeddedPhar(Buffer.concat([Buffer.from('launcher'), phar]), phar);
  assert.throws(() => verifyEmbeddedPhar(Buffer.from('archive name only'), phar));
});

test('native format rejects an executable for a different architecture', () => {
  verifyNativeBinary('macos-aarch64', 'fixture', () => 'Mach-O 64-bit executable arm64\n');
  assert.throws(() => verifyNativeBinary('macos-aarch64', 'fixture', () => 'Mach-O 64-bit executable x86_64\n'));
  assert.throws(() => verifyNativeBinary('linux-aarch64', 'fixture', () => 'ELF 64-bit LSB executable, x86-64,\n'));
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dw-native-fixture-'));
  try {
    const binary = Buffer.alloc(128);
    binary.writeUInt16LE(0x5a4d, 0); binary.writeUInt32LE(64, 60);
    binary.writeUInt32LE(0x4550, 64); binary.writeUInt16LE(0x8664, 68);
    const filename = path.join(directory, 'fixture.exe'); fs.writeFileSync(filename, binary);
    verifyNativeBinary('windows-x86_64', filename);
    binary.writeUInt16LE(0xaa64, 68); fs.writeFileSync(filename, binary);
    assert.throws(() => verifyNativeBinary('windows-x86_64', filename));
  } finally { fs.rmSync(directory, {recursive: true, force: true}); }
});

test('the original cell deadline cannot be extended or restarted', () => {
  assert.ok(remaining(state()) > 0);
  assert.throws(() => remaining({started: 1, deadline: 1 + CELL_MILLISECONDS}));
  const extended = state(); extended.deadline += 1;
  assert.throws(() => remaining(extended));
});

test('public redirects never send authentication or leave approved HTTPS hosts', async () => {
  let calls = 0;
  const fetchImpl = async (url, options) => {
    calls += 1; assert.equal(options.headers, undefined);
    return new Response(null, {status: 302, headers: {location: 'http://github.com/unsafe'}});
  };
  await assert.rejects(publicResponse('https://github.com/release', state(), fetchImpl), /HTTPS/);
  assert.equal(calls, 1);
  await assert.rejects(publicResponse('https://unrelated.example/release', state(), fetchImpl), /HTTPS/);
  assert.equal(calls, 1);
});

test('release CDN redirects are bounded and retain anonymous downloads', async () => {
  let calls = 0;
  const value = await publicResponse('https://github.com/release', state(), async (url, options) => {
    calls += 1; assert.equal(options.headers, undefined);
    return calls === 1
      ? new Response(null, {status: 302, headers: {location: 'https://release-assets.githubusercontent.com/fixture'}})
      : new Response('fixture', {status: 200});
  });
  assert.equal(await value.text(), 'fixture');
  assert.equal(calls, 2);
  calls = 0;
  await assert.rejects(publicResponse('https://github.com/release', state(), async () => {
    calls += 1;
    return new Response(null, {status: 302, headers: {location: 'https://github.com/again'}});
  }), /redirect bound/);
  assert.equal(calls, 6);
});

test('annotated tag peels are bounded and do not accept a branch or unknown object', () => {
  assert.equal(tagCommit('2.3.7', {}, state(), () => JSON.stringify({object: {type: 'commit', sha: COMMIT}})), COMMIT);
  assert.throws(() => tagCommit('2.3.7', {}, state(), () => JSON.stringify({object: {type: 'tag', sha: COMMIT}})));
  assert.throws(() => tagCommit('2.3.7', {}, state(), () => JSON.stringify({object: {type: 'tree', sha: COMMIT}})));
});

test('verification wiring checks every input and executes no distribution code', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dw-release-fixture-'));
  try {
    const input = fixture();
    const result = await verifyBundle('2.3.7', COMMIT, path.join(directory, 'release'), {}, state(), input);
    assert.equal(Object.keys(result.digests).length, 14);
    assert.equal(input.calls.filter(call => call.args[0] === 'attestation').length, 14);
  } finally { fs.rmSync(directory, {recursive: true, force: true}); }
});

function packageFixture() {
  const lock = JSON.parse(fs.readFileSync(path.join(__dirname, '../../composer.lock'), 'utf8'));
  const packages = Object.fromEntries(lock.packages.map(item => [item.name,
    {pretty_version: item.version, reference: item.source?.reference || item.dist?.reference}]));
  return {lock, metadata: {version: '2.3.7', commit: COMMIT, packages}};
}

test('package identity uses every production pin and refuses dev/source/version substitutions', () => {
  const {lock, metadata} = packageFixture();
  const expected = {tag: '2.3.7', commit: COMMIT};
  assertPackageIdentity(metadata, lock, expected);
  for (const mutation of ['version', 'commit', 'package', 'dev']) {
    const changed = structuredClone(metadata);
    if (mutation === 'version') changed.version = '2.3.6';
    if (mutation === 'commit') changed.commit = 'b'.repeat(40);
    if (mutation === 'package') changed.packages['symfony/http-client'].reference = 'b'.repeat(40);
    if (mutation === 'dev') changed.packages[lock['packages-dev'][0].name] = {};
    assert.throws(() => assertPackageIdentity(changed, lock, expected));
  }
});

test('installed inspection refuses an absent command or a changed schema', () => {
  const commands = {commands: ['workflow:list', 'schema:show', 'upgrade', 'doctor'].map(name => ({name}))};
  const schema = {type: 'object', properties: {workflows: {type: 'array'}}};
  assertInstalledInspection(commands, schema, schema);
  assert.throws(() => assertInstalledInspection({commands: []}, schema, schema));
  assert.throws(() => assertInstalledInspection(commands, {type: 'array'}, schema));
});

test('execution step refuses credentials before reading state or running a command', () => {
  let calls = 0;
  for (const key of ['GH_TOKEN', 'GITHUB_TOKEN', 'GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN']) {
    assert.throws(() => exercise('unused', 'unused', {[key]: 'fixture'}, () => { calls += 1; }), /credentials/);
  }
  assert.equal(calls, 0);
});

test('unverified phase state is refused and removed before any installed child starts', () => {
  const directory = fs.mkdtempSync(path.join(fs.realpathSync(os.tmpdir()), 'dw-public-release-'));
  const cell = {...state(), tag: '2.3.7', commit: COMMIT, platform: 'linux-x86_64', owner: 'c'.repeat(32)};
  fs.writeFileSync(path.join(directory, 'state.json'), JSON.stringify(cell));
  let calls = 0;
  assert.throws(() => exercise(directory, 'unused', {}, () => { calls += 1; }), /did not complete/);
  assert.equal(calls, 0);
  assert.equal(fs.existsSync(directory), false);
});

test('changed inputs cannot cross from verification to execution', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dw-input-fixture-'));
  try {
    const input = fixture();
    fs.mkdirSync(path.join(directory, 'release')); fs.mkdirSync(path.join(directory, 'channel'));
    for (const [name, bytes] of input.bytes) fs.writeFileSync(path.join(directory, 'release', name), bytes);
    fs.writeFileSync(path.join(directory, 'channel', 'dw-linux-x86_64'), input.bytes.get('dw-linux-x86_64'));
    const cell = {tag: '2.3.7', commit: COMMIT, platform: 'linux-x86_64', verified: true,
      release: {tag: '2.3.7', commit: COMMIT, digests: Object.fromEntries([...input.bytes].map(([name, bytes]) => [name, hash(bytes)]))},
      channel: {digest: hash(input.bytes.get('dw-linux-x86_64'))}};
    assertVerifiedInputs(directory, cell);
    fs.writeFileSync(path.join(directory, 'release', 'install.sh'), 'changed after verification');
    assert.throws(() => assertVerifiedInputs(directory, cell), /changed between/);
  } finally { fs.rmSync(directory, {recursive: true, force: true}); }
});

for (const [platform, observation] of Object.entries(PLATFORMS)) {
  test(`temporary install execution wiring for ${platform} uses no credentials or registry: synthetic fixtures only`, () => {
    const base = fs.realpathSync(os.tmpdir());
    const directory = fs.mkdtempSync(path.join(base, 'dw-public-release-'));
    const evidence = path.join(base, `dw-evidence-fixture-${crypto.randomBytes(8).toString('hex')}.json`);
    const input = fixture();
    const release = path.join(directory, 'release'); fs.mkdirSync(release);
    for (const [name, bytes] of input.bytes) fs.writeFileSync(path.join(release, name), bytes);
    const name = `dw-${platform}${platform.startsWith('windows') ? '.exe' : ''}`;
    const channelBytes = Buffer.from('different synthetic default artifact');
    fs.mkdirSync(path.join(directory, 'channel'));
    fs.writeFileSync(path.join(directory, 'channel', name), channelBytes);
    const cell = {...state(), tag: '2.3.7', commit: COMMIT, platform, owner: 'c'.repeat(32),
      verified: true, native: {platform}, release: {tag: '2.3.7', commit: COMMIT,
        digests: Object.fromEntries([...input.bytes].map(([key, value]) => [key, hash(value)]))},
      channel: {tag: '2.3.6', commit: 'b'.repeat(40), digest: hash(channelBytes)}};
    fs.writeFileSync(path.join(directory, 'state.json'), JSON.stringify(cell));
    const schema = JSON.parse(fs.readFileSync(path.join(__dirname, '../../schemas/output/workflow-list.schema.json'), 'utf8'));
    const calls = [];
    function execute(command, args, options = {}) {
      calls.push({command, args});
      for (const key of ['GH_TOKEN', 'GITHUB_TOKEN', 'DURABLE_WORKFLOW_TOKEN']) assert.equal(options.env?.[key], undefined);
      if (command === 'uname' || command === 'pwsh' && args[2]?.includes('OSArchitecture')) return observation.machine;
      if (command === 'sysctl') return '1';
      if (command === 'php') return JSON.stringify(packageFixture().metadata);
      if (args.includes('-File') || args[0]?.endsWith('install.sh')) {
        const destination = options.env.DURABLE_WORKFLOW_INSTALL_DIR;
        if (platform.startsWith('windows')) assert.equal(options.env.DURABLE_WORKFLOW_INSTALL_NO_PATH_UPDATE, '1');
        const bytes = options.env.VERSION ? input.bytes.get(name) : channelBytes;
        fs.writeFileSync(path.join(destination, platform.startsWith('windows') ? 'dw.exe' : 'dw'), bytes);
        return '';
      }
      if (args.includes('command -v dw') || args[2]?.includes('Get-Command')) {
        return path.join(options.env.DURABLE_WORKFLOW_INSTALL_DIR, platform.startsWith('windows') ? 'dw.exe' : 'dw');
      }
      if (args[0] === '--version') {
        const version = options.env.VERSION || '2.3.6';
        return `Durable Workflow CLI ${version} (commit fixture)`;
      }
      if (args[0] === 'list') return JSON.stringify({commands: ['workflow:list', 'schema:show', 'upgrade', 'doctor'].map(value => ({name: value}))});
      if (args[0] === 'schema:show') return JSON.stringify(schema);
      if (args[0] === 'upgrade') return JSON.stringify({current_version: '2.3.7', target_version: '2.3.6', status: 'newer'});
      throw new Error(`unmocked command ${command}`);
    }
    try {
      exercise(directory, evidence, {PATH: '/fixture-tools'}, execute, observation);
      assert.equal(fs.existsSync(directory), false);
      const result = JSON.parse(fs.readFileSync(evidence));
      assert.equal(result.outcome, 'pass');
      assert.equal(result.channel, '2.3.6', 'default selection must not imply promotion');
      assert.equal(calls.filter(call => call.args[0] === 'upgrade').length, 2);
      assert.equal(calls.some(call => call.command === 'gh'), false);
    } finally {
      fs.rmSync(directory, {recursive: true, force: true}); fs.rmSync(evidence, {force: true});
    }
  });
}

for (const mutation of ['missing', 'source', 'hash', 'attestation', 'embedded']) {
  test(`public input refusal before execution: ${mutation}`, async () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dw-refusal-fixture-'));
    try {
      const input = fixture();
      if (mutation === 'missing') input.metadata.assets.pop();
      if (mutation === 'source') {
        const original = input.run; input.run = (command, args) => args[1].includes('/git/ref/')
          ? JSON.stringify({object: {type: 'commit', sha: 'b'.repeat(40)}}) : original(command, args);
      }
      if (mutation === 'hash') input.bytes.set('install.sh', Buffer.from('changed'));
      if (mutation === 'attestation') {
        const original = input.run; input.run = (command, args) => args[0] === 'attestation' ? '[]' : original(command, args);
      }
      if (mutation === 'embedded') {
        input.bytes.set('dw-linux-x86_64', Buffer.from('no archive'));
        input.bytes.set('SHA256SUMS', Buffer.from([...input.bytes].filter(([name]) => name !== 'SHA256SUMS')
          .map(([name, value]) => `${hash(value)}  ${name}`).join('\n') + '\n'));
      }
      await assert.rejects(verifyBundle('2.3.7', COMMIT, path.join(directory, 'release'), {}, state(), input));
    } finally { fs.rmSync(directory, {recursive: true, force: true}); }
  });
}
