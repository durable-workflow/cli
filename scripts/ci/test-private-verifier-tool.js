'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const test = require('node:test');
const tool = require('./private-verifier-tool');
const {nativePlatform, PLATFORMS} = require('./verify-installed-release');
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');

function fixture(t) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'cli-private-verifier-'));
  t.after(() => fs.rmSync(root, {recursive: true, force: true}));
  const environment = {GITHUB_REPOSITORY: 'durable-workflow/cli', GITHUB_RUN_ID: '123',
    GITHUB_RUN_ATTEMPT: '1', RELEASE_CONTROL_COMMIT: 'a'.repeat(40), VERIFIED_SOURCE_SHA: 'b'.repeat(40),
    VERIFIER_PHP_VERSION: '8.4.27', VERIFIER_SPC_VERSION: '2.8.5',
    VERIFIER_SPC_REVISION: '4318ef8fa32a02460ec1554746674a7bc42b49fa'};
  const expected = {platform: 'linux-x86_64', producer: tool.expectedProducer(environment),
    recipe: tool.expectedRecipe(path.resolve(__dirname, '../..'), environment)};
  const executable = path.join(root, 'synthetic-executable');
  fs.writeFileSync(executable, 'inert native-tool fixture bytes, never executed');
  const authority = {binary: fs.realpathSync(executable), version: '8.4.27',
    sha256: hash(fs.readFileSync(executable))};
  const manifest = tool.createManifest(executable, expected, 'c'.repeat(64), authority);
  return {root, environment, expected, executable, authority, manifest};
}

test('private manifest binds exact original producer, platform, recipe and executable identity', t => {
  const f = fixture(t);
  assert.deepEqual(tool.validateManifest(f.manifest, f.expected), f.manifest);
  for (const [field, value] of [['run_id', '124'], ['run_attempt', '2'],
    ['head', 'd'.repeat(40)], ['source', 'e'.repeat(40)], ['repository', 'foreign/repository'],
    ['workflow', '.github/workflows/foreign.yml'], ['job', 'foreign-producer']]) {
    assert.throws(() => tool.validateManifest({...f.manifest,
      producer: {...f.manifest.producer, [field]: value}}, f.expected));
  }
  assert.throws(() => tool.validateManifest({...f.manifest, platform: 'linux-aarch64'}, f.expected));
  assert.throws(() => tool.validateManifest({...f.manifest,
    recipe: {...f.manifest.recipe, cache_schema: 'micro-only/v1'}}, f.expected));
  assert.throws(() => tool.validateManifest({...f.manifest,
    recipe: {...f.manifest.recipe, workflow_sha256: '0'.repeat(64)}}, f.expected));
  assert.throws(() => tool.validateManifest({...f.manifest,
    executable: {...f.manifest.executable, name: 'ambient-php'}}, f.expected));
  assert.throws(() => tool.validateManifest({...f.manifest, extra: true}, f.expected));
  assert.throws(() => tool.createManifest(f.executable, f.expected, 'c'.repeat(64),
    {...f.authority, binary: path.join(f.root, 'foreign-php')}));
});

test('private artifact metadata refuses wrong run/head/repository/name, expiry and unbound digest', t => {
  const f = fixture(t);
  const artifact = {id: 456, expired: false, name: tool.archiveName(f.expected.platform),
    digest: 'sha256:' + 'f'.repeat(64), workflow_run: {id: 123, repository_id: 789, head_sha: 'a'.repeat(40)}};
  assert.deepEqual(tool.artifactIdentity(artifact, f.expected, '789'), {id: '456', digest: 'f'.repeat(64)});
  for (const change of [{id: 0}, {expired: true}, {digest: null}, {name: 'latest-tool'},
    {workflow_run: {...artifact.workflow_run, id: 124}},
    {workflow_run: {...artifact.workflow_run, repository_id: 790}},
    {workflow_run: {...artifact.workflow_run, head_sha: 'b'.repeat(40)}}]) {
    assert.throws(() => tool.artifactIdentity({...artifact, ...change}, f.expected, '789'));
  }
});

test('actual standard-library archive reader accepts the closed synthetic payload without executing it', t => {
  const f = fixture(t);
  const archive = tool.pack(f.executable, f.manifest, path.join(f.root, 'packed'));
  const identity = {id: '456', digest: archive.sha256};
  const result = tool.unpack(archive.filename, path.join(f.root, 'unpacked'), f.expected, identity);
  assert.equal(hash(fs.readFileSync(result.binary)), f.manifest.executable.sha256);
  assert.deepEqual(result.manifest, f.manifest);
  assert.deepEqual(fs.readdirSync(path.dirname(result.binary)).sort(), ['php', 'verifier-tool.json']);
  assert.match(result.archive_reader.version, /^3\./);
  assert.throws(() => tool.unpack(archive.filename, path.join(f.root, 'bad-digest'), f.expected,
    {...identity, digest: '0'.repeat(64)}), /digest differs before archive inspection/);
  assert.equal(fs.existsSync(path.join(f.root, 'bad-digest')), false);
  assert.throws(() => tool.unpack(archive.filename, path.join(f.root, 'wrong-producer'),
    {...f.expected, producer: {...f.expected.producer, run_attempt: '2'}}, identity));
  fs.appendFileSync(archive.filename, 'tampered archive bytes');
  assert.throws(() => tool.unpack(archive.filename, path.join(f.root, 'tampered'), f.expected, identity));
});

test('a fresh archive digest cannot admit executable bytes changed beside the original manifest', t => {
  const f = fixture(t);
  fs.appendFileSync(f.executable, 'changed');
  const archive = tool.pack(f.executable, f.manifest, path.join(f.root, 'packed'));
  assert.throws(() => tool.unpack(archive.filename, path.join(f.root, 'unpacked'), f.expected,
    {id: '456', digest: archive.sha256}), /executable differs/);
});

test('actual archive reader refuses traversal, links, duplicate/missing members and appended records', t => {
  const f = fixture(t);
  const python = tool.pythonAuthority();
  const script = `import io,sys,tarfile,json
name,kind=sys.argv[1:]
with tarfile.open(name,'w',format=tarfile.USTAR_FORMAT) as archive:
 for member in ['php','verifier-tool.json']:
  if kind=='missing' and member=='php': continue
  info=tarfile.TarInfo('../outside' if kind=='traversal' and member=='php' else member)
  data=b'inert'; info.size=len(data)
  if kind in ['symlink','hardlink'] and member=='php':
   info.type=tarfile.SYMTYPE if kind=='symlink' else tarfile.LNKTYPE
   info.linkname='../outside'; info.size=0
  archive.addfile(info,io.BytesIO(data))
  if kind=='duplicate' and member=='php': archive.addfile(info,io.BytesIO(data))
if kind=='trailing':
 with open(name,'ab') as output: output.write(b'foreign trailing record')
`;
  for (const kind of ['traversal', 'symlink', 'hardlink', 'duplicate', 'missing', 'trailing']) {
    const archive = path.join(f.root, `${kind}.tar`);
    execFileSync(python.binary, ['-I', '-c', script, archive, kind], {timeout: 10000});
    assert.throws(() => tool.unpack(archive, path.join(f.root, kind), f.expected,
      {id: '456', digest: hash(fs.readFileSync(archive))}));
    assert.equal(fs.existsSync(path.join(f.root, kind, 'php')), false);
    assert.equal(fs.existsSync(path.join(f.root, 'outside')), false);
  }
});

test('CLI+micro cache refuses old schema, missing CLI, changed bytes, wrong recipe and extra fields', t => {
  const f = fixture(t);
  const cache = path.join(f.root, 'cache'); fs.mkdirSync(cache);
  fs.writeFileSync(path.join(cache, 'micro.sfx'), 'inert micro');
  fs.writeFileSync(path.join(cache, 'php'), 'inert CLI');
  const manifest = tool.cacheManifest(cache, 'linux-x86_64', 'c'.repeat(64));
  assert.equal(tool.validateCache(cache, manifest, 'linux-x86_64', 'c'.repeat(64)), true);
  for (const change of [{schema: 'durable-workflow.phpmicro-cache/v1'}, {extra: true},
    {toolchain_id: 'd'.repeat(64)}, {cli_sha256: 'e'.repeat(64)}]) {
    assert.throws(() => tool.validateCache(cache, {...manifest, ...change}, 'linux-x86_64', 'c'.repeat(64)));
  }
  fs.appendFileSync(path.join(cache, 'php'), 'changed');
  assert.throws(() => tool.validateCache(cache, manifest, 'linux-x86_64', 'c'.repeat(64)));
  fs.unlinkSync(path.join(cache, 'php'));
  assert.throws(() => tool.validateCache(cache, manifest, 'linux-x86_64', 'c'.repeat(64)));
});

test('the four-tool index binds every original artifact and refuses substitution or missing platforms', t => {
  const f = fixture(t);
  const directory = path.join(f.root, 'handoff'); fs.mkdirSync(directory);
  const artifacts = [];
  let id = 456;
  for (const platform of Object.keys(tool.PLATFORMS)) {
    const expected = {...f.expected, platform};
    const manifest = tool.createManifest(f.executable, expected, 'c'.repeat(64), f.authority);
    const archive = tool.pack(f.executable, manifest, path.join(f.root, platform));
    fs.copyFileSync(archive.filename, path.join(directory, tool.archiveName(platform)));
    artifacts.push({id: id++, expired: false, name: tool.archiveName(platform),
      digest: 'sha256:' + archive.sha256,
      workflow_run: {id: 123, repository_id: 789, head_sha: 'a'.repeat(40)}});
  }
  const index = tool.bindArtifacts(directory, artifacts, f.expected, '789');
  assert.deepEqual(tool.indexedTool(index, 'linux-x86_64', f.expected).manifest, f.manifest);
  assert.throws(() => tool.indexedTool({...index, producer: {...index.producer, run_attempt: '2'}},
    'linux-x86_64', f.expected));
  const missing = structuredClone(index); delete missing.tools['macos-aarch64'];
  assert.throws(() => tool.indexedTool(missing, 'linux-x86_64', f.expected));
  const duplicate = structuredClone(index);
  duplicate.tools['macos-aarch64'].artifact.id = duplicate.tools['linux-x86_64'].artifact.id;
  assert.throws(() => tool.indexedTool(duplicate, 'linux-x86_64', f.expected));
  assert.throws(() => tool.bindArtifacts(directory, [...artifacts, artifacts[0]], f.expected, '789'));
  fs.writeFileSync(path.join(directory, 'extra.tar'), 'inert');
  assert.throws(() => tool.bindArtifacts(directory, artifacts, f.expected, '789'));
});

test('static PHP inspection uses only the bound executable with no ini or inherited service credentials', t => {
  const f = fixture(t);
  const [platform, native] = Object.entries(PLATFORMS).find(([, value]) =>
    value.os === process.platform && value.arch === process.arch);
  if (platform === 'windows-x86_64') {
    const pe = Buffer.alloc(256); pe.writeUInt16LE(0x5a4d); pe.writeUInt32LE(128, 60);
    pe.writeUInt32LE(0x4550, 128); pe.writeUInt16LE(0x8664, 132); fs.writeFileSync(f.executable, pe);
  }
  const expected = {...f.expected, platform};
  const basic = {version: '8.4.27', binary: fs.realpathSync(f.executable), sapi: 'cli', int_size: 8, phar: true};
  const runtime = {php_version: '8.4.27', sapi: 'cli', os_family: {linux:'Linux',darwin:'Darwin',win32:'Windows'}[process.platform],
    openssl_version: 'OpenSSL 3.5.9', openssl_header_version: 'OpenSSL 3.5.9',
    curl: platform === 'windows-x86_64' ? null : {version:'8.23.0',ssl_version:'OpenSSL/3.5.9'}};
  const descriptions = {'linux-x86_64':'ELF 64-bit LSB executable, x86-64,',
    'linux-aarch64':'ELF 64-bit LSB executable, ARM aarch64,', 'macos-aarch64':'Mach-O 64-bit executable arm64'};
  let home;
  const execute = (command, args, options) => {
    if (command === 'uname' || command === 'pwsh') return native.machine;
    if (command === 'sysctl') return '1';
    if (command === 'file') return descriptions[platform];
    assert.equal(fs.realpathSync(command), fs.realpathSync(f.executable));
    assert.equal(args[0], '-n');
    assert.equal(options.env.ACTIONS_RUNTIME_TOKEN, undefined);
    home = options.env.HOME;
    assert.equal(fs.existsSync(home), true);
    return JSON.stringify(args.includes('-r') ? basic : runtime);
  };
  const result = tool.inspectStaticPhp(f.executable, expected, {ACTIONS_RUNTIME_TOKEN:'synthetic-service-token'}, execute);
  assert.deepEqual(result.authority.arguments, ['-n']);
  assert.equal(result.runtime.openssl.version, '3.5.9');
  assert.equal(fs.existsSync(home), false);
  for (const field of ['version', 'binary', 'sapi', 'phar']) {
    const bad = {...basic, [field]: null};
    assert.throws(() => tool.inspectStaticPhp(f.executable, expected, {}, (command, args, options) =>
      command === f.executable && args.includes('-r') ? JSON.stringify(bad) : execute(command, args, options)));
  }
});

test('private tool inspection refuses all four inherited GitHub credentials', () => {
  for (const name of ['GH_TOKEN', 'GITHUB_TOKEN', 'GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN']) {
    assert.throws(() => tool.noCredentials({[name]: 'synthetic-read-token'}));
  }
  tool.noCredentials({});
});

test('native source cell records archive-reader authority without fetching or building PHP',
  {skip: !process.env.DW_SOURCE_TOOL_CUSTODY}, t => {
    assert.equal(process.env.DW_SOURCE_TOOL_CUSTODY, '1');
    const native = nativePlatform(process.env.VERIFIER_PLATFORM, {os: process.platform, arch: process.arch});
    const python = tool.pythonAuthority();
    t.diagnostic(JSON.stringify({native, archive_reader: python, private_php_build_qualified: false}));
  });

function nativeToolFixture(t) {
  const f = fixture(t);
  const [platform, native] = Object.entries(PLATFORMS).find(([, value]) =>
    value.os === process.platform && value.arch === process.arch);
  if (platform === 'windows-x86_64') {
    const pe = Buffer.alloc(256); pe.writeUInt16LE(0x5a4d); pe.writeUInt32LE(128, 60);
    pe.writeUInt32LE(0x4550, 128); pe.writeUInt16LE(0x8664, 132); fs.writeFileSync(f.executable, pe);
    f.authority.sha256 = hash(pe);
  }
  const directory = path.join(f.root, 'four-tools'); fs.mkdirSync(directory);
  const artifacts = [];
  let id = 456;
  for (const name of Object.keys(tool.PLATFORMS)) {
    const manifest = tool.createManifest(f.executable, {...f.expected, platform: name}, 'c'.repeat(64), f.authority);
    const archive = tool.pack(f.executable, manifest, path.join(f.root, name));
    fs.copyFileSync(archive.filename, path.join(directory, tool.archiveName(name)));
    artifacts.push({id: id++, expired: false, name: tool.archiveName(name), digest: 'sha256:' + archive.sha256,
      workflow_run: {id: 123, repository_id: 789, head_sha: 'a'.repeat(40)}});
  }
  const index = tool.bindArtifacts(directory, artifacts, f.expected, '789');
  const selected = path.join(f.root, 'selected'); fs.mkdirSync(selected);
  fs.copyFileSync(path.join(directory, tool.archiveName(platform)), path.join(selected, tool.archiveName(platform)));
  let phpCalls = 0;
  const execute = (command, args, options = {}) => {
    if (command === 'git') return f.expected.producer.head;
    if (command === 'uname' || command === 'pwsh') return native.machine;
    if (command === 'sysctl') return '1';
    if (command === 'file') return {'linux-x86_64':'ELF 64-bit LSB executable, x86-64,',
      'linux-aarch64':'ELF 64-bit LSB executable, ARM aarch64,',
      'macos-aarch64':'Mach-O 64-bit executable arm64'}[platform];
    if (path.basename(command) === tool.PLATFORMS[platform].name) {
      phpCalls++;
      assert.equal(args[0], '-n');
      return JSON.stringify(args.includes('-r')
        ? {version:'8.4.27', binary:fs.realpathSync(command), sapi:'cli', int_size:8, phar:true}
        : {php_version:'8.4.27', sapi:'cli',
          os_family:{linux:'Linux',darwin:'Darwin',win32:'Windows'}[process.platform],
          openssl_version:'OpenSSL 3.5.9', openssl_header_version:'OpenSSL 3.5.9',
          curl: platform === 'windows-x86_64' ? null : {version:'8.23.0', ssl_version:'OpenSSL/3.5.9'}});
    }
    return execFileSync(command, args, {encoding:'utf8', timeout:10000, ...options});
  };
  return {...f, platform, directory, selected, artifacts, index, execute, phpCalls: () => phpCalls};
}

test('consumer binds original bytes before mocked native inspection, then refuses a tampered archive before PHP', t => {
  const f = nativeToolFixture(t);
  const result = tool.consume(f.selected, f.index, f.platform, f.expected, {}, f.execute);
  t.after(() => tool.cleanup(result.directory));
  assert.equal(f.phpCalls(), 2);
  assert.equal(tool.validateBinding(result.binding, f.index, f.platform, f.expected, result.authority), result.binding);
  assert.throws(() => tool.validateBinding(result.binding, f.index, f.platform, f.expected,
    {...result.authority, arguments: []}));
  const before = fs.readdirSync(path.dirname(result.directory)).filter(name => name.startsWith('cli-verifier-tool-'));
  fs.appendFileSync(path.join(f.selected, tool.archiveName(f.platform)), 'tamper');
  assert.throws(() => tool.consume(f.selected, f.index, f.platform, f.expected, {}, f.execute), /digest differs/);
  assert.equal(f.phpCalls(), 2);
  assert.deepEqual(fs.readdirSync(path.dirname(result.directory)).filter(name => name.startsWith('cli-verifier-tool-')), before);
  assert.throws(() => tool.cleanup(f.root), /unowned path/);
  assert.equal(fs.existsSync(f.root), true);
});

test('producer context and CLI phases refuse foreign checkout, missing native output and inherited credentials', t => {
  const f = fixture(t);
  assert.throws(() => tool.context(f.environment, () => 'f'.repeat(40)), /policy checkout/);
  assert.throws(() => tool.prepare({...f.environment, GITHUB_JOB:'foreign', VERIFIER_PLATFORM:'linux-x86_64'},
    () => f.expected.producer.head), /native producer job/);
  let calls = 0;
  for (const phase of [tool.prepare, tool.select]) {
    assert.throws(() => phase({...f.environment, GH_TOKEN:'synthetic-token'}, () => { calls++; }), /credential-free/);
  }
  assert.equal(calls, 0);
  assert.throws(() => tool.prepare({...f.environment, GITHUB_JOB:'build-binary', VERIFIER_PLATFORM:'linux-x86_64',
    VERIFIER_BUILD_PHP:path.join(f.root, 'missing')}, () => f.expected.producer.head));
});

test('qualification receipts report only actual bound private-tool proof and retain changed-executable failure', t => {
  const f = nativeToolFixture(t);
  const result = tool.consume(f.selected, f.index, f.platform, f.expected, {}, f.execute);
  t.after(() => tool.cleanup(result.directory));
  const environment = {...f.environment, VERIFIER_PLATFORM:f.platform,
    PRIVATE_TOOL_AUTHORITY_OUTCOME:'success', PHAR_INSPECTION_OUTCOME:'success',
    VERIFIER_PHP_AUTHORITY:JSON.stringify(result.authority), VERIFIER_TOOL_BINDING:JSON.stringify(result.binding),
    VERIFIER_TOOL_INDEX:JSON.stringify(f.index)};
  const receipt = path.join(f.root, `verifier-tool-proof-${f.platform}.json`);
  const passed = tool.qualificationProof(environment, f.execute, f.root);
  assert.equal(passed.outcome, 'pass');
  assert.equal(passed.distribution_executed, false);
  assert.equal(passed.live_https_qualified, false);
  assert.deepEqual(passed.producer, result.binding.manifest.producer);
  assert.deepEqual(passed.artifact, result.binding.artifact);
  assert.deepEqual(passed.archive_members, [tool.PLATFORMS[f.platform].name, 'verifier-tool.json']);
  fs.unlinkSync(receipt);
  fs.appendFileSync(result.authority.binary, 'changed');
  assert.throws(() => tool.qualificationProof(environment, f.execute, f.root), /no longer matches/);
  const failed = JSON.parse(fs.readFileSync(receipt));
  assert.equal(failed.outcome, 'fail');
  assert.equal(failed.executable, undefined);
  assert.equal(failed.live_https_qualified, false);
  fs.unlinkSync(receipt);
  const skipped = tool.qualificationProof({...environment, PRIVATE_TOOL_AUTHORITY_OUTCOME:'failure',
    PHAR_INSPECTION_OUTCOME:'skipped'}, f.execute, f.root);
  assert.equal(skipped.outcome, 'fail');
  assert.equal(skipped.producer, undefined);
});
