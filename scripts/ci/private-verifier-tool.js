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
const sha256 = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const digest = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const commit = value => typeof value === 'string' && /^[a-f0-9]{40}$/.test(value);
const identifier = value => typeof value === 'string' && /^[1-9][0-9]*$/.test(value);

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
  if (!manifest || manifest.schema !== SCHEMA || !Object.hasOwn(PLATFORMS, expected.platform)) {
    throw new Error('private verifier manifest identity is invalid');
  }
  assert.deepEqual(Object.keys(manifest).sort(), ['executable', 'platform', 'producer', 'recipe', 'schema']);
  assert.deepEqual(manifest.producer, {...expected.producer, job: PLATFORMS[expected.platform].job});
  assert.equal(manifest.platform, expected.platform);
  const {toolchain_id: toolchain, ...recipe} = manifest.recipe || {};
  assert.deepEqual(recipe, expected.recipe);
  assert.ok(digest(toolchain));
  const executable = manifest.executable;
  assert.deepEqual(Object.keys(executable || {}).sort(), ['name', 'sha256', 'size', 'version']);
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
  const contents = fs.readdirSync(destination).sort();
  assert.deepEqual(contents, [PLATFORMS[expected.platform].name, 'verifier-tool.json'].sort());
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

module.exports = {SCHEMA, CACHE_SCHEMA, MAX_BINARY, MAX_ARCHIVE, PLATFORMS, regularBytes, noCredentials,
  archiveName, expectedProducer, expectedRecipe, validateManifest, pythonAuthority, archiveCommand,
  createManifest, pack, artifactIdentity, unpack, cacheManifest, validateCache};
