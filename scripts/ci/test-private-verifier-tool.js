'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const test = require('node:test');
const tool = require('./private-verifier-tool');
const {nativePlatform} = require('./verify-installed-release');
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
