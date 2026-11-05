'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');

const REPOSITORY = 'durable-workflow/cli';
const WORKFLOW = '.github/workflows/release.yml';
const SCHEMA = 'durable-workflow.cli.verifier-tool/v1';
const CACHE_SCHEMA = 'durable-workflow.php-runtime-cache/v2';
const MAX_BINARY = 64 * 1024 * 1024;
const MAX_ARCHIVE = MAX_BINARY + 48 * 1024;
const PLATFORMS = Object.freeze({
  'linux-x86_64': {name: 'php', job: 'build-binary'},
  'linux-aarch64': {name: 'php', job: 'build-binary'},
  'macos-aarch64': {name: 'php', job: 'build-binary'},
  'windows-x86_64': {name: 'php.exe', job: 'build-binary-windows'},
});
const compare = (a, b) => a.localeCompare(b);
const sha256 = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const digest = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const commit = value => typeof value === 'string' && /^[a-f0-9]{40}$/.test(value);
const identifier = value => typeof value === 'string' && /^[1-9]\d*$/.test(value);

function regularBytes(filename, maximum = MAX_BINARY) {
  const stat = fs.lstatSync(filename);
  if (!stat.isFile() || stat.isSymbolicLink() || stat.size < 1 || stat.size > maximum) {
    throw new Error('private verifier input must be a bounded nonempty regular file');
  }
  return fs.readFileSync(filename);
}

function run(command, args, options = {}) {
  const result = spawnSync(command, args, {encoding: 'utf8', timeout: 30000,
    maxBuffer: 1024 * 1024, ...options});
  if (result.error || result.status !== 0) {
    throw new Error(`private verifier ${path.basename(command)} command failed`);
  }
  return result.stdout;
}

function noCredentials(environment) {
  for (const name of ['GH_TOKEN', 'GITHUB_TOKEN', 'GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN']) {
    if (environment[name]) throw new Error('private verifier inspection requires credential-free execution');
  }
}

function archiveName(platform) {
  if (!Object.hasOwn(PLATFORMS, platform)) throw new Error('unknown private verifier platform');
  return `verifier-tool-${platform}.tar`;
}

function expectedProducer(environment) {
  const producer = {repository: REPOSITORY, workflow: WORKFLOW,
    run_id: environment.GITHUB_RUN_ID, run_attempt: environment.GITHUB_RUN_ATTEMPT,
    head: environment.RELEASE_CONTROL_COMMIT, source: environment.VERIFIED_SOURCE_SHA};
  if (environment.GITHUB_REPOSITORY !== REPOSITORY || !identifier(producer.run_id) ||
      !identifier(producer.run_attempt) || !commit(producer.head) || !commit(producer.source)) {
    throw new Error('private verifier requires exact original producer authority');
  }
  return producer;
}

function expectedRecipe(root, environment) {
  const recipe = {cache_schema: CACHE_SCHEMA, php_version: environment.VERIFIER_PHP_VERSION,
    spc_version: environment.VERIFIER_SPC_VERSION, spc_revision: environment.VERIFIER_SPC_REVISION,
    workflow_sha256: sha256(regularBytes(path.join(root, WORKFLOW))),
    dependencies_sha256: sha256(regularBytes(path.join(root, 'scripts/ci/standalone-dependencies.json')))};
  if (!/^\d+\.\d+\.\d+$/.test(recipe.php_version) ||
      !/^\d+\.\d+\.\d+$/.test(recipe.spc_version) || !commit(recipe.spc_revision)) {
    throw new Error('private verifier recipe must use the exact approved toolchain pins');
  }
  return recipe;
}

function validateManifest(manifest, expected) {
  if (manifest?.schema !== SCHEMA || !Object.hasOwn(PLATFORMS, expected.platform)) {
    throw new Error('private verifier manifest identity is invalid');
  }
  assert.deepEqual(Object.keys(manifest).sort(compare), ['executable', 'platform', 'producer', 'recipe', 'schema']);
  assert.deepEqual(manifest.producer, {...expected.producer, job: PLATFORMS[expected.platform].job});
  assert.equal(manifest.platform, expected.platform);
  const {toolchain_id: toolchain, ...recipe} = manifest.recipe || {};
  assert.deepEqual(recipe, expected.recipe);
  assert.ok(digest(toolchain));
  const executable = manifest.executable;
  assert.deepEqual(Object.keys(executable || {}).sort(compare), ['name', 'sha256', 'size', 'version']);
  assert.equal(executable.name, PLATFORMS[expected.platform].name);
  assert.equal(executable.version, expected.recipe.php_version);
  assert.ok(digest(executable.sha256));
  assert.ok(Number.isSafeInteger(executable.size) && executable.size > 0 && executable.size <= MAX_BINARY);
  return manifest;
}

function pythonAuthority(execute = run) {
  const command = process.platform === 'win32' ? 'python' : 'python3';
  const probe = JSON.parse(execute(command, ['-I', '-c',
    'import json,sys,os; print(json.dumps({"version":sys.version.split()[0],"binary":os.path.realpath(sys.executable)}))']));
  if (!/^3\.\d+\.\d+$/.test(probe.version) || typeof probe.binary !== 'string' ||
      !path.isAbsolute(probe.binary) || /[\r\n\0]/.test(probe.binary)) {
    throw new Error('native archive-reader Python authority is unavailable');
  }
  const binary = fs.realpathSync(probe.binary);
  return {...probe, binary, sha256: sha256(regularBytes(binary))};
}

function archiveCommand(phase, arguments_, execute = run) {
  const python = pythonAuthority(execute);
  execute(python.binary, ['-I', path.join(__dirname, 'verifier-tool-archive.py'), phase, ...arguments_]);
  return python;
}

function createManifest(executable, expected, toolchain, authority) {
  const bytes = regularBytes(executable);
  if (authority.binary !== fs.realpathSync(executable) || authority.sha256 !== sha256(bytes) ||
      authority.version !== expected.recipe.php_version || !digest(toolchain)) {
    throw new Error('produced PHP CLI differs from its exact executable authority');
  }
  return validateManifest({schema: SCHEMA, producer: {...expected.producer,
    job: PLATFORMS[expected.platform].job}, platform: expected.platform,
    recipe: {...expected.recipe, toolchain_id: toolchain},
    executable: {name: PLATFORMS[expected.platform].name, sha256: sha256(bytes),
      size: bytes.length, version: authority.version}}, expected);
}

function pack(executable, manifest, directory, execute = run) {
  fs.mkdirSync(directory, {mode: 0o700});
  const metadata = path.join(directory, 'verifier-tool.json');
  fs.writeFileSync(metadata, JSON.stringify(manifest), {flag: 'wx', mode: 0o600});
  const archive = path.join(directory, archiveName(manifest.platform));
  archiveCommand('pack', [executable, metadata, archive, manifest.executable.name], execute);
  regularBytes(archive, MAX_ARCHIVE);
  return {filename: archive, sha256: sha256(regularBytes(archive, MAX_ARCHIVE))};
}

function artifactIdentity(artifact, expected, repositoryId) {
  const producer = expected.producer;
  if (!artifact || !Number.isSafeInteger(artifact.id) || artifact.id < 1 || artifact.expired !== false ||
      artifact.name !== archiveName(expected.platform) || !identifier(repositoryId) ||
      String(artifact.workflow_run?.id) !== producer.run_id ||
      String(artifact.workflow_run?.repository_id) !== repositoryId ||
      artifact.workflow_run?.head_sha !== producer.head ||
      typeof artifact.digest !== 'string' || !/^sha256:[a-f0-9]{64}$/.test(artifact.digest)) {
    throw new Error('private verifier artifact is not the exact original producer input');
  }
  return {id: String(artifact.id), digest: artifact.digest.slice('sha256:'.length)};
}

function unpack(archive, destination, expected, artifact, execute = run) {
  if (!identifier(artifact.id) || !digest(artifact.digest) ||
      sha256(regularBytes(archive, MAX_ARCHIVE)) !== artifact.digest) {
    throw new Error('private verifier artifact digest differs before archive inspection');
  }
  fs.mkdirSync(destination, {mode: 0o700});
  const python = archiveCommand('unpack', [archive, destination, PLATFORMS[expected.platform].name], execute);
  const contents = fs.readdirSync(destination).sort(compare);
  assert.deepEqual(contents, [PLATFORMS[expected.platform].name, 'verifier-tool.json'].sort(compare));
  const manifest = validateManifest(JSON.parse(regularBytes(path.join(destination, 'verifier-tool.json'), 16384)), expected);
  const binary = path.join(destination, manifest.executable.name);
  const bytes = regularBytes(binary);
  if (bytes.length !== manifest.executable.size || sha256(bytes) !== manifest.executable.sha256) {
    throw new Error('private verifier executable differs from its bound artifact');
  }
  return {binary: fs.realpathSync(binary), manifest, artifact, archive_reader: python};
}

function cacheManifest(directory, platform, toolchain) {
  if (!Object.hasOwn(PLATFORMS, platform) || !digest(toolchain)) {
    throw new Error('private verifier cache identity is invalid');
  }
  return {schema: CACHE_SCHEMA, toolchain_id: toolchain,
    micro_sha256: sha256(regularBytes(path.join(directory, 'micro.sfx'))),
    cli_name: PLATFORMS[platform].name,
    cli_sha256: sha256(regularBytes(path.join(directory, PLATFORMS[platform].name)))};
}

function validateCache(directory, manifest, platform, toolchain) {
  assert.deepEqual(manifest, cacheManifest(directory, platform, toolchain));
  return true;
}

function context(environment = process.env, execute = run, root = path.join(__dirname, '../..')) {
  const producer = expectedProducer(environment);
  if (execute('git', ['-C', root, 'rev-parse', 'HEAD']).trim() !== producer.head) {
    throw new Error('private verifier policy checkout differs from the original producer head');
  }
  return {producer, recipe: expectedRecipe(root, environment)};
}

function bindArtifacts(directory, artifacts, base, repositoryId, execute = run) {
  if (!fs.lstatSync(directory).isDirectory() || fs.lstatSync(directory).isSymbolicLink()) {
    throw new Error('private verifier handoff must be a regular owned directory');
  }
  const names = Object.keys(PLATFORMS).map(archiveName).sort(compare);
  assert.deepEqual(fs.readdirSync(directory).sort(compare), names);
  const tools = {};
  const seen = new Set();
  for (const platform of Object.keys(PLATFORMS)) {
    const candidates = artifacts.filter(artifact => artifact.name === archiveName(platform));
    if (candidates.length !== 1) throw new Error('private verifier artifact inventory is missing or ambiguous');
    const expected = {...base, platform};
    const artifact = artifactIdentity(candidates[0], expected, repositoryId);
    if (seen.has(artifact.id)) throw new Error('private verifier artifacts must be distinct');
    seen.add(artifact.id);
    const extracted = fs.mkdtempSync(path.join(osTemporary(), 'cli-verifier-bind-'));
    try {
      const unpacked = unpack(path.join(directory, archiveName(platform)), path.join(extracted, 'tool'),
        expected, artifact, execute);
      tools[platform] = {artifact, manifest: unpacked.manifest};
    } finally {
      fs.rmSync(extracted, {recursive: true, force: true});
    }
  }
  return {schema: SCHEMA, producer: base.producer, tools};
}

function osTemporary() {
  return fs.realpathSync(process.env.RUNNER_TEMP || require('node:os').tmpdir());
}

function indexedTool(index, platform, base) {
  assert.deepEqual(Object.keys(index || {}).sort(compare), ['producer', 'schema', 'tools']);
  assert.equal(index.schema, SCHEMA);
  assert.deepEqual(index.producer, base.producer);
  assert.deepEqual(Object.keys(index.tools || {}).sort(compare), Object.keys(PLATFORMS).sort(compare));
  const ids = new Set();
  for (const name of Object.keys(PLATFORMS)) {
    const entry = index.tools[name];
    assert.deepEqual(Object.keys(entry || {}).sort(compare), ['artifact', 'manifest']);
    assert.deepEqual(Object.keys(entry.artifact || {}).sort(compare), ['digest', 'id']);
    assert.ok(identifier(entry.artifact.id) && digest(entry.artifact.digest));
    assert.ok(!ids.has(entry.artifact.id));
    ids.add(entry.artifact.id);
    validateManifest(entry.manifest, {...base, platform: name});
  }
  if (!Object.hasOwn(PLATFORMS, platform)) throw new Error('unknown indexed verifier platform');
  return index.tools[platform];
}

function inspectStaticPhp(executable, expected, environment = process.env, execute = run) {
  noCredentials(environment);
  const verifier = require('./verify-installed-release');
  verifier.nativePlatform(expected.platform, {os: process.platform, arch: process.arch}, execute);
  verifier.verifyNativeBinary(expected.platform, executable, execute);
  const directory = fs.mkdtempSync(path.join(osTemporary(), 'cli-verifier-probe-'));
  try {
    for (const name of ['home', 'tmp']) fs.mkdirSync(path.join(directory, name), {mode: 0o700});
    const child = verifier.childEnvironment(environment, directory);
    const source = `echo json_encode(['version'=>PHP_VERSION,'binary'=>realpath(PHP_BINARY),
      'sapi'=>PHP_SAPI,'int_size'=>PHP_INT_SIZE,'phar'=>extension_loaded('phar')], JSON_THROW_ON_ERROR);`;
    const value = JSON.parse(execute(executable, ['-n', '-r', source], {env: child, timeout: 10000}));
    const binary = fs.realpathSync(executable);
    if (value.version !== expected.recipe.php_version || typeof value.binary !== 'string' ||
        !path.isAbsolute(value.binary) || fs.realpathSync(value.binary) !== binary ||
        value.sapi !== 'cli' || value.int_size !== 8 || value.phar !== true) {
      throw new Error('private verifier must be the exact native static PHP CLI with built-in PHAR');
    }
    const evidence = JSON.parse(execute(binary, ['-n', path.join(__dirname, 'probe-standalone-runtime.php')],
      {env: child, timeout: 10000}));
    const families = {'linux-x86_64': 'Linux', 'linux-aarch64': 'Linux',
      'macos-aarch64': 'Darwin', 'windows-x86_64': 'Windows'};
    if (evidence.php_version !== value.version || evidence.sapi !== 'cli' || evidence.os_family !== families[expected.platform]) {
      throw new Error('private verifier runtime identity differs');
    }
    const runtime = require('./verify-standalone-runtime').verifyRuntimeDependencies(evidence);
    return {authority: {version: value.version, binary, sha256: sha256(regularBytes(binary)), arguments: ['-n']},
      runtime};
  } finally {
    fs.rmSync(directory, {recursive: true, force: true});
  }
}

function cleanup(directory) {
  if (!directory || !fs.existsSync(directory)) return;
  const absolute = path.resolve(directory);
  if (path.dirname(absolute) !== osTemporary() || !/^cli-verifier-tool-[A-Za-z\d]+$/.test(path.basename(absolute)) ||
      fs.lstatSync(absolute).isSymbolicLink() || fs.realpathSync(absolute) !== absolute) {
    throw new Error('private verifier cleanup refused an unowned path');
  }
  fs.rmSync(absolute, {recursive: true, force: true});
}

function consume(directory, index, platform, base, environment = process.env, execute = run) {
  noCredentials(environment);
  const entry = indexedTool(index, platform, base);
  const name = archiveName(platform);
  if (!fs.lstatSync(directory).isDirectory() || fs.lstatSync(directory).isSymbolicLink()) {
    throw new Error('private verifier artifact directory is invalid');
  }
  assert.deepEqual(fs.readdirSync(directory), [name]);
  const owned = fs.mkdtempSync(path.join(osTemporary(), 'cli-verifier-tool-'));
  try {
    const input = unpack(path.join(directory, name), path.join(owned, 'tool'), {...base, platform}, entry.artifact, execute);
    assert.deepEqual(input.manifest, entry.manifest);
    const inspected = inspectStaticPhp(input.binary, {...base, platform}, environment, execute);
    assert.equal(inspected.authority.sha256, entry.manifest.executable.sha256);
    return {directory: owned, ...inspected,
      binding: {artifact: entry.artifact, manifest: input.manifest, archive_reader: input.archive_reader,
        runtime: inspected.runtime}};
  } catch (error) {
    cleanup(owned);
    throw error;
  }
}

function validateBinding(binding, index, platform, base, authority) {
  const entry = indexedTool(index, platform, base);
  assert.deepEqual(Object.keys(binding || {}).sort(compare), ['archive_reader', 'artifact', 'manifest', 'runtime']);
  assert.deepEqual(binding.artifact, entry.artifact);
  assert.deepEqual(binding.manifest, entry.manifest);
  assert.deepEqual(authority.arguments, ['-n']);
  assert.equal(authority.version, entry.manifest.executable.version);
  assert.equal(authority.sha256, entry.manifest.executable.sha256);
  assert.equal(binding.runtime.openssl.source.version,
    JSON.parse(regularBytes(path.join(__dirname, 'standalone-dependencies.json'))).openssl.version);
  return binding;
}

function output(environment, key, value) {
  if (!environment.GITHUB_OUTPUT || !/^[a-z][a-z-]*$/.test(key) || /[\r\n]/.test(value)) {
    throw new Error('private verifier requires an owned single-line workflow output');
  }
  fs.appendFileSync(environment.GITHUB_OUTPUT, `${key}=${value}\n`);
}

function cachePhase(phase, arguments_) {
  if (arguments_.length !== 4) throw new Error('cache phase requires platform, recipe, binary directory and manifest');
  const [platform, toolchain, directory, filename] = arguments_;
  if (phase === 'cache-write') {
    fs.writeFileSync(filename, JSON.stringify(cacheManifest(directory, platform, toolchain)), {mode: 0o600});
  } else {
    validateCache(directory, JSON.parse(regularBytes(filename, 4096)), platform, toolchain);
  }
}

function prepare(environment, execute) {
  noCredentials(environment);
  const base = context(environment, execute);
  const platform = environment.VERIFIER_PLATFORM;
  if (!Object.hasOwn(PLATFORMS, platform) || environment.GITHUB_JOB !== PLATFORMS[platform].job) {
    throw new Error('private verifier can only be sealed by its native producer job');
  }
  const executable = path.resolve(environment.VERIFIER_BUILD_PHP || '');
  const expected = {...base, platform};
  const inspected = inspectStaticPhp(executable, expected, environment, execute);
  const manifest = createManifest(executable, expected, environment.TOOLCHAIN_ID, inspected.authority);
  const archive = pack(executable, manifest, environment.VERIFIER_ARTIFACT_DIRECTORY, execute);
  output(environment, 'archive', archive.filename);
}

function bind(environment, execute) {
  const base = context(environment, execute);
  const pages = JSON.parse(execute('gh', ['api', '--hostname', 'github.com', '--paginate', '--slurp',
    `repos/${REPOSITORY}/actions/runs/${base.producer.run_id}/artifacts?per_page=100`]));
  if (!Array.isArray(pages) || !pages.length || pages.some(page => !Array.isArray(page.artifacts))) {
    throw new Error('private verifier artifact metadata is incomplete');
  }
  const artifacts = pages.flatMap(page => page.artifacts);
  if (pages.some(page => page.total_count !== artifacts.length)) {
    throw new Error('private verifier artifact metadata is truncated');
  }
  const index = bindArtifacts(environment.VERIFIER_ARTIFACT_DIRECTORY, artifacts, base,
    environment.GITHUB_REPOSITORY_ID, execute);
  output(environment, 'tools', JSON.stringify(index));
}

function select(environment, execute) {
  noCredentials(environment);
  const base = context(environment, execute);
  const entry = indexedTool(JSON.parse(environment.VERIFIER_TOOL_INDEX), environment.VERIFIER_PLATFORM, base);
  output(environment, 'artifact-id', entry.artifact.id);
  output(environment, 'artifact-digest', entry.artifact.digest);
}

function main(environment = process.env, execute = run) {
  const [phase, ...arguments_] = process.argv.slice(2);
  if (['cache-write', 'cache-check'].includes(phase)) return cachePhase(phase, arguments_);
  if (arguments_.length) throw new Error('private verifier phase does not accept extra arguments');
  const handlers = {cleanup: () => cleanup(environment.VERIFIER_TOOL_DIRECTORY),
    proof: () => qualificationProof(environment, execute), prepare: () => prepare(environment, execute),
    bind: () => bind(environment, execute), select: () => select(environment, execute)};
  if (!Object.hasOwn(handlers, phase)) throw new Error('unknown private verifier phase');
  return handlers[phase]();
}

function qualificationProof(environment, execute = run, directory = process.cwd()) {
  noCredentials(environment);
  const platform = environment.VERIFIER_PLATFORM;
  if (!Object.hasOwn(PLATFORMS, platform)) throw new Error('unknown private verifier proof platform');
  const result = {schema: 'durable-workflow.cli.verifier-tool-proof/v1', platform,
    expected_producer: expectedProducer(environment), distribution_executed: false, live_https_qualified: false,
    phases: {authority: environment.PRIVATE_TOOL_AUTHORITY_OUTCOME,
      synthetic_phar_metadata: environment.PHAR_INSPECTION_OUTCOME}, outcome: 'fail'};
  if (result.phases.authority === 'success' && result.phases.synthetic_phar_metadata === 'success') {
    try {
      const authority = require('./verify-installed-release').assertPhpAuthority(JSON.parse(environment.VERIFIER_PHP_AUTHORITY));
      const binding = validateBinding(JSON.parse(environment.VERIFIER_TOOL_BINDING),
        JSON.parse(environment.VERIFIER_TOOL_INDEX), platform, context(environment, execute), authority);
      Object.assign(result, {producer: binding.manifest.producer, artifact: binding.artifact,
        recipe: binding.manifest.recipe, executable: authority, runtime: binding.runtime,
        archive_reader: binding.archive_reader, archive_members: [PLATFORMS[platform].name, 'verifier-tool.json'],
        synthetic_phar_stub_executed: false, outcome: 'pass'});
    } catch {
      result.failure = 'bound private tool no longer matches its qualification inputs';
    }
  }
  fs.writeFileSync(path.join(directory, `verifier-tool-proof-${platform}.json`), JSON.stringify(result, null, 2), {flag: 'wx', mode: 0o600});
  if (result.failure) throw new Error(result.failure);
  return result;
}

module.exports = {SCHEMA, CACHE_SCHEMA, MAX_BINARY, MAX_ARCHIVE, PLATFORMS, regularBytes, noCredentials,
  archiveName, expectedProducer, expectedRecipe, validateManifest, pythonAuthority, archiveCommand,
  createManifest, pack, artifactIdentity, unpack, cacheManifest, validateCache,
  context, bindArtifacts, indexedTool, inspectStaticPhp, cleanup, consume, validateBinding, output, qualificationProof, prepare, bind, select, main};

if (require.main === module) {
  try { main(); } catch (error) {
    process.stderr.write(`Private verifier tool refused: ${error.message}\n`);
    process.exitCode = 1;
  }
}
