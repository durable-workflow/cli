'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {isDeepStrictEqual} = require('node:util');
const {spawnSync} = require('node:child_process');
const {parseReleaseVersion, releaseImplementation} = require('./release-version');
const {validateReleaseMetadata, validateChannel} = require('./verify-cli-release-channel');
const {verifyUpgradeResult} = require('./verify-cli-upgrade-result');

const REPOSITORY = 'durable-workflow/cli';
const PLATFORMS = Object.freeze({
  'linux-x86_64': {os: 'linux', arch: 'x64', machine: 'x86_64'},
  'linux-aarch64': {os: 'linux', arch: 'arm64', machine: 'aarch64'},
  'macos-aarch64': {os: 'darwin', arch: 'arm64', machine: 'arm64'},
  'windows-x86_64': {os: 'win32', arch: 'x64', machine: 'X64'},
});
const ASSETS = Object.freeze([
  'dw.phar', 'dw-linux-x86_64', 'dw-linux-aarch64', 'dw-macos-aarch64',
  'dw-windows-x86_64.exe', 'dw-linux-x86_64.dependencies.json',
  'dw-linux-aarch64.dependencies.json', 'dw-macos-aarch64.dependencies.json',
  'dw-windows-x86_64.dependencies.json', 'dw.rb', 'install.sh', 'install.ps1',
  'verify-release.sh', 'SHA256SUMS',
]);
const MAX_ASSET_BYTES = 64 * 1024 * 1024;
const CELL_MILLISECONDS = 14 * 60 * 1000;
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');

function releaseIdentity(tag, commit) {
  const version = parseReleaseVersion(tag);
  if (version === null || !/^[a-f0-9]{40}$/.test(commit)) {
    throw new Error('verification requires an exact release tag and full source commit');
  }
  return {tag, commit};
}

function releaseAuthority(environment, tag) {
  const initiator = environment.RELEASE_INITIATOR;
  const control = {ref: environment.RELEASE_CONTROL_REF, commit: environment.RELEASE_CONTROL_COMMIT};
  const expectedRef = initiator === 'push' ? `refs/tags/${tag}` : 'refs/heads/main';
  if (!['push', 'workflow_dispatch'].includes(initiator) || control.ref !== expectedRef ||
      !/^[a-f0-9]{40}$/.test(control.commit)) {
    throw new Error('verification requires the resolved release control authority');
  }
  return {initiator, control};
}

function childEnvironment(environment, directory, binDirectories = []) {
  const child = {};
  for (const key of ['PATH', 'SystemRoot', 'WINDIR', 'COMSPEC', 'PATHEXT', 'TEMP', 'TMP', 'TMPDIR', 'LANG',
    'SSL_CERT_FILE', 'SSL_CERT_DIR', 'CURL_CA_BUNDLE', 'NODE_EXTRA_CA_CERTS']) {
    if (environment[key]) child[key] = environment[key];
  }
  child.PATH = [...binDirectories, child.PATH || ''].join(path.delimiter);
  child.HOME = path.join(directory, 'home');
  child.USERPROFILE = child.HOME;
  child.TMPDIR = path.join(directory, 'tmp');
  child.TEMP = child.TMPDIR;
  child.TMP = child.TMPDIR;
  child.DW_CONFIG_HOME = path.join(directory, 'config');
  child.NO_COLOR = '1';
  return child;
}

function run(command, args, options = {}) {
  const result = spawnSync(command, args, {
    encoding: 'utf8', maxBuffer: 4 * 1024 * 1024, timeout: 60000, ...options,
  });
  if (result.error || result.status !== 0) {
    throw new Error(`required command ${path.basename(command)} failed (${result.status ?? 'unavailable'})`,
      {cause: result.error});
  }
  return result.stdout;
}

const PHP_AUTHORITY_PROBE = `
if (PHP_VERSION_ID < 80200 || !extension_loaded('phar')) exit(1);
echo json_encode(['version' => PHP_VERSION, 'binary' => realpath(PHP_BINARY)], JSON_THROW_ON_ERROR);
`;

function inspectPhpAuthority(executable, execute = run) {
  const value = JSON.parse(execute(executable, ['-r', PHP_AUTHORITY_PROBE], {timeout: 10000}));
  if (!/^\d+\.\d+\.\d+$/.test(value.version) || typeof value.binary !== 'string' ||
      !path.isAbsolute(value.binary) || /[\r\n\0]/.test(value.binary)) {
    throw new Error('verifier PHP authority has an invalid version or executable path');
  }
  const binary = fs.realpathSync(value.binary);
  return {version: value.version, binary, sha256: hash(regularBytes(binary))};
}

function capturePhpAuthority(environment = process.env, execute = run) {
  for (const name of ['GH_TOKEN', 'GITHUB_TOKEN', 'GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN']) {
    if (environment[name]) throw new Error('PHP authority capture requires credential-free execution');
  }
  const version = environment.BOOTSTRAPPED_PHP_VERSION;
  const series = environment.VERIFIER_PHP_SERIES;
  if (!/^\d+\.\d+$/.test(series) || !/^\d+\.\d+\.\d+$/.test(version) || !version.startsWith(`${series}.`)) {
    throw new Error('verifier PHP bootstrap did not provide an exact resolved version');
  }
  const authority = inspectPhpAuthority('php', execute);
  if (authority.version !== version) throw new Error('verifier PHP differs from the trusted bootstrap version');
  return authority;
}

function assertPhpAuthority(authority) {
  if (!authority || !/^\d+\.\d+\.\d+$/.test(authority.version) ||
      typeof authority.binary !== 'string' || !path.isAbsolute(authority.binary) ||
      /[\r\n\0]/.test(authority.binary) || !/^[a-f0-9]{64}$/.test(authority.sha256) ||
      fs.realpathSync(authority.binary) !== authority.binary || hash(regularBytes(authority.binary)) !== authority.sha256) {
    throw new Error('verifier PHP executable differs from the captured authority');
  }
  return authority;
}

function nativePlatform(name, observed, execute = run) {
  const expected = Object.hasOwn(PLATFORMS, name) ? PLATFORMS[name] : null;
  if (!expected || observed.os !== expected.os || observed.arch !== expected.arch) {
    throw new Error('verification runner is not the requested native platform');
  }
  const machine = expected.os === 'win32'
    ? execute('pwsh', ['-NoProfile', '-Command', '[System.Runtime.InteropServices.RuntimeInformation]::OSArchitecture.ToString()']).trim()
    : execute('uname', ['-m']).trim();
  if (machine !== expected.machine) throw new Error('native machine architecture does not match the release asset');
  if (expected.os === 'darwin' && execute('sysctl', ['-n', 'hw.optional.arm64']).trim() !== '1') {
    throw new Error('macOS verification requires native Apple Silicon');
  }
  return {platform: name, os: observed.os, arch: observed.arch, machine};
}

function checksums(text, assets = ASSETS) {
  const result = new Map();
  for (const line of text.trim().split('\n')) {
    const match = /^([a-f0-9]{64}) {2}([A-Za-z0-9._-]+)\r?$/.exec(line);
    if (!match || !assets.includes(match[2]) || match[2] === 'SHA256SUMS' || result.has(match[2])) {
      throw new Error('release checksum manifest contains an invalid or duplicate entry');
    }
    result.set(match[2], match[1]);
  }
  if (result.size !== assets.length - 1) throw new Error('release checksum manifest is incomplete');
  return result;
}

function regularBytes(filename) {
  const stat = fs.lstatSync(filename);
  if (!stat.isFile() || stat.isSymbolicLink() || stat.size < 1 || stat.size > MAX_ASSET_BYTES) {
    throw new Error('release input must be a bounded nonempty regular file');
  }
  return fs.readFileSync(filename);
}

function verifyEmbeddedPhar(binary, phar) {
  if (!binary.includes(phar)) throw new Error('standalone does not contain the exact verified PHAR');
}

function verifyNativeBinary(name, filename, execute = run) {
  if (name === 'windows-x86_64') {
    const bytes = regularBytes(filename);
    const offset = bytes.length >= 64 ? bytes.readUInt32LE(60) : -1;
    if (bytes.length < 64 || bytes.readUInt16LE(0) !== 0x5a4d || offset < 64 || offset > bytes.length - 6 ||
        bytes.readUInt32LE(offset) !== 0x4550 || bytes.readUInt16LE(offset + 4) !== 0x8664) {
      throw new Error('Windows asset is not a native x86_64 PE executable');
    }
    return;
  }
  const description = execute('file', ['-b', filename]).trim();
  const expected = {
    'linux-x86_64': /^ELF 64-bit LSB (?:pie )?executable, x86-64,/,
    'linux-aarch64': /^ELF 64-bit LSB (?:pie )?executable, ARM aarch64,/,
    'macos-aarch64': /^Mach-O 64-bit executable arm64\b/,
  }[name];
  if (!expected?.test(description)) throw new Error('release executable format does not match the native cell');
}

function remaining(state, maximum = 60000) {
  const milliseconds = state.deadline - Date.now();
  if (!Number.isSafeInteger(state.started) || state.deadline - state.started !== CELL_MILLISECONDS ||
      milliseconds <= 0 || milliseconds > CELL_MILLISECONDS) {
    throw new Error('verification cell deadline is invalid or exhausted');
  }
  return Math.min(maximum, milliseconds);
}

function api(route, environment, state, execute = run) {
  return JSON.parse(execute('gh', ['api', `repos/${REPOSITORY}/${route}`], {
    env: environment, timeout: remaining(state),
  }));
}

function tagCommit(tag, environment, state, execute = run) {
  let object = api(`git/ref/tags/${encodeURIComponent(tag)}`, environment, state, execute).object;
  for (let depth = 0; object?.type === 'tag' && depth < 16; depth += 1) {
    if (!/^[a-f0-9]{40}$/.test(object.sha)) throw new Error('public tag object is invalid');
    object = api(`git/tags/${object.sha}`, environment, state, execute).object;
  }
  if (object?.type !== 'commit' || !/^[a-f0-9]{40}$/.test(object.sha)) {
    throw new Error('public tag does not resolve to a commit');
  }
  return object.sha;
}

function safeSystemFailure(error) {
  const nested = Array.isArray(error?.cause?.errors) ? error.cause.errors.slice(0, 4) : [];
  const causes = [error, error?.cause, ...nested].filter(Boolean);
  const codes = [...new Set(causes.map(value => value.code)
    .filter(value => typeof value === 'string' && /^[A-Z][A-Z0-9_]{0,63}$/.test(value)))];
  const errno = [...new Set(causes.map(value => value.errno)
    .filter(value => Number.isInteger(value) && Math.abs(value) <= 2147483647))];
  const syscall = [...new Set(causes.map(value => value.syscall)
    .filter(value => ['connect', 'read', 'write', 'open', 'getaddrinfo'].includes(value)))];
  return {codes, errno, syscall};
}

async function publicResponse(url, state, fetchImpl, hop = 0) {
  if (!Number.isInteger(hop) || hop < 0 || hop >= 6) throw new Error('public download exceeded its redirect bound');
  const hosts = new Set(['github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com']);
  const target = new URL(url);
  if (target.protocol !== 'https:' || !hosts.has(target.hostname) ||
      target.username || target.password || (target.port && target.port !== '443')) {
    throw new Error('public download redirected outside the approved HTTPS release hosts');
  }
  state.download = {...state.download, host: target.hostname, hop, status: null};
  const response = await fetchImpl(target.href, {
    signal: AbortSignal.timeout(remaining(state)), redirect: 'manual',
  });
  state.download.status = Number.isInteger(response.status) ? response.status : null;
  if (![301, 302, 303, 307, 308].includes(response.status)) return response;
  const location = response.headers.get('location');
  await response.body?.cancel();
  if (!location) throw new Error('public download redirect has no destination');
  return publicResponse(new URL(location, target).href, state, fetchImpl, hop + 1);
}

async function download(tag, name, destination, state, fetchImpl = fetch) {
  const url = `https://github.com/${REPOSITORY}/releases/download/${tag}/${name}`;
  state.download = {asset: ASSETS.includes(name) ? name : null, host: null, hop: 0, status: null};
  try {
    const response = await publicResponse(url, state, fetchImpl);
    if (!response.ok) throw new Error(`public download failed for ${name}`);
    const file = fs.openSync(destination, 'wx', 0o600);
    let length = 0;
    try {
      for await (const chunk of response.body) {
        length += chunk.length;
        if (length > MAX_ASSET_BYTES) throw new Error(`public asset ${name} exceeds the size bound`);
        fs.writeSync(file, chunk);
      }
    } finally {
      fs.closeSync(file);
    }
    if (length === 0) throw new Error(`public asset ${name} is empty`);
  } catch (error) {
    const system = safeSystemFailure(error);
    throw Object.assign(new Error(`public download failed for ${name} (${system.codes.join(',') || 'input failure'})`),
      {publicDownload: {...state.download, ...system}});
  }
}

function attest(filename, tag, commit, environment, state, execute = run) {
  const workflow = releaseImplementation(tag) === 'rust' ? 'release-rust.yml' : 'release.yml';
  const results = JSON.parse(execute('gh', ['attestation', 'verify', filename,
    '--repo', REPOSITORY, '--source-ref', `refs/tags/${tag}`, '--source-digest', commit,
    '--signer-workflow', `${REPOSITORY}/.github/workflows/${workflow}`, '--signer-digest', commit,
    '--deny-self-hosted-runners', '--format', 'json'], {
    env: environment, timeout: remaining(state, 90000),
  }));
  if (!Array.isArray(results) || results.length === 0) throw new Error('artifact attestation has no verified result');
}

function validateAssets(release) {
  const names = new Set(release.assets?.map(asset => asset.name));
  if (names.size !== ASSETS.length || names.size !== release.assets?.length ||
      ASSETS.some(name => !names.has(name))) {
    throw new Error('published release does not match the exact required asset inventory');
  }
}

async function downloadAssets(names, tag, directory, state, fetchImpl, index = 0) {
  if (index >= names.length) return;
  await download(tag, names[index], path.join(directory, names[index]), state, fetchImpl);
  return downloadAssets(names, tag, directory, state, fetchImpl, index + 1);
}

async function verifyBundle(tag, commit, directory, environment, state, dependencies = {}) {
  releaseIdentity(tag, commit);
  const execute = dependencies.run || run;
  const release = validateReleaseMetadata(api(`releases/tags/${tag}`, environment, state, execute), tag);
  validateAssets(release);
  if (tagCommit(tag, environment, state, execute) !== commit) throw new Error('public tag source differs from the planned commit');
  fs.mkdirSync(directory, {mode: 0o700});
  await downloadAssets(ASSETS, tag, directory, state, dependencies.fetch || fetch);
  attest(path.join(directory, 'SHA256SUMS'), tag, commit, environment, state, execute);
  const manifest = checksums(regularBytes(path.join(directory, 'SHA256SUMS')).toString('utf8'));
  const digests = {};
  for (const name of ASSETS) {
    const filename = path.join(directory, name);
    digests[name] = hash(regularBytes(filename));
    if (name !== 'SHA256SUMS' && digests[name] !== manifest.get(name)) throw new Error(`checksum mismatch for ${name}`);
    if (name !== 'SHA256SUMS') attest(filename, tag, commit, environment, state, execute);
  }
  const phar = regularBytes(path.join(directory, 'dw.phar'));
  for (const name of ASSETS.filter(name => /^dw-(linux|macos|windows)/.test(name) && !name.endsWith('.json'))) {
    verifyEmbeddedPhar(regularBytes(path.join(directory, name)), phar);
  }
  return {tag, commit, digests};
}

function assetName(platform) {
  if (!Object.hasOwn(PLATFORMS, platform)) throw new Error('unsupported verification platform');
  return `dw-${platform}${platform.startsWith('windows') ? '.exe' : ''}`;
}

async function verifyDefault(platform, directory, environment, state, dependencies = {}) {
  const execute = dependencies.run || run;
  const release = api('releases/latest', environment, state, execute);
  const {version: tag} = validateChannel(release);
  const commit = tagCommit(tag, environment, state, execute);
  const names = release.assets.map(asset => asset.name);
  if (new Set(names).size !== names.length || names.some(name => !/^[A-Za-z0-9._-]+$/.test(name))) {
    throw new Error('default channel has invalid or duplicate asset names');
  }
  fs.mkdirSync(directory, {mode: 0o700});
  const name = assetName(platform);
  await download(tag, 'SHA256SUMS', path.join(directory, 'SHA256SUMS'), state, dependencies.fetch || fetch);
  attest(path.join(directory, 'SHA256SUMS'), tag, commit, environment, state, execute);
  await download(tag, name, path.join(directory, name), state, dependencies.fetch || fetch);
  attest(path.join(directory, name), tag, commit, environment, state, execute);
  const manifest = checksums(regularBytes(path.join(directory, 'SHA256SUMS')).toString('utf8'), names);
  const digest = hash(regularBytes(path.join(directory, name)));
  if (digest !== manifest.get(name)) throw new Error('default channel asset checksum mismatch');
  return {tag, commit, asset: name, digest};
}

function statePath(directory) {
  const base = fs.realpathSync(process.env.RUNNER_TEMP || os.tmpdir());
  const absolute = path.resolve(directory);
  if (path.dirname(absolute) !== base || !path.basename(absolute).startsWith('dw-public-release-') ||
      fs.lstatSync(absolute).isSymbolicLink() || fs.realpathSync(absolute) !== absolute) {
    throw new Error('verification state is outside its owned temporary directory');
  }
  return path.join(absolute, 'state.json');
}

function readState(directory) {
  const state = JSON.parse(regularBytes(statePath(directory)).toString('utf8'));
  releaseIdentity(state.tag, state.commit);
  releaseAuthority({RELEASE_INITIATOR: state.initiator,
    RELEASE_CONTROL_REF: state.control?.ref, RELEASE_CONTROL_COMMIT: state.control?.commit}, state.tag);
  if (!Object.hasOwn(PLATFORMS, state.platform) || !/^[a-f0-9]{32}$/.test(state.owner)) {
    throw new Error('verification state has an invalid identity');
  }
  remaining(state);
  return state;
}

function cleanup(directory) {
  if (directory && fs.existsSync(directory)) {
    statePath(directory);
    fs.rmSync(directory, {recursive: true, force: true});
  }
}

function assertInstallerSource(directory, controlCommit, execute = run, root = path.join(__dirname, '../..')) {
  if (!/^[a-f0-9]{40}$/.test(controlCommit) ||
      execute('git', ['-C', root, 'rev-parse', 'HEAD']).trim() !== controlCommit) {
    throw new Error('qualified installer control Git identity differs');
  }
  for (const name of ['install.sh', 'install.ps1']) {
    const expected = Buffer.from(execute('git', ['-C', root, 'show', `${controlCommit}:scripts/${name}`]), 'utf8');
    const actual = regularBytes(path.join(directory, name));
    if (!actual.equals(expected)) {
      throw Object.assign(new Error(`published installer ${name} differs from the qualified control Git blob`),
        {installer: {name, control_commit: controlCommit, expected_sha256: hash(expected), actual_sha256: hash(actual)}});
    }
  }
}

function preparePrivateDirectories(directory) {
  for (const name of ['home', 'tmp']) {
    fs.mkdirSync(path.join(directory, name), {recursive: true, mode: 0o700});
  }
}

function inputFailureEvidence(filename, state, error, allocated, removed) {
  if (!filename) return;
  fs.writeFileSync(filename, JSON.stringify({schema: 'durable-workflow.cli.public-install/v1',
    tag: state.tag, commit: state.commit, platform: state.platform, initiator: state.initiator,
    control: state.control, native: state.native, tools: state.tools,
    verified_at: new Date().toISOString(), outcome: 'fail', phase: state.phase,
    public_inputs_verified: false, distribution_executed: false,
    failure: {system: safeSystemFailure(error), download: error.publicDownload, installer: error.installer},
    cleanup: {private_state_allocated: allocated, private_state_removed: removed}}, null, 2),
  {flag: 'wx', mode: 0o600});
}

async function verify(options, environment = process.env, dependencies = {}) {
  releaseIdentity(options.tag, options.commit);
  const authority = releaseAuthority(environment, options.tag);
  const started = Date.now();
  const state = {tag: options.tag, commit: options.commit, platform: options.platform,
    ...authority,
    started, deadline: started + CELL_MILLISECONDS, owner: crypto.randomBytes(16).toString('hex')};
  const execute = dependencies.run || run;
  let directory;
  try {
    state.phase = 'prerequisites';
    state.native = nativePlatform(options.platform, {os: process.platform, arch: process.arch}, execute);
    const php = assertPhpAuthority(JSON.parse(environment.VERIFIER_PHP_AUTHORITY || 'null'));
    if (!isDeepStrictEqual(inspectPhpAuthority(php.binary, execute), php)) {
      throw new Error('verifier PHP authority changed after bootstrap');
    }
    const ghVersion = execute('gh', ['--version']).split('\n')[0];
    execute('gh', ['attestation', 'verify', '--help']);
    state.tools = {node: process.version, php: php.version, php_authority: php, gh: ghVersion};
    state.phase = 'private-state';
    const base = fs.realpathSync(environment.RUNNER_TEMP || os.tmpdir());
    directory = fs.mkdtempSync(path.join(base, 'dw-public-release-'));
    preparePrivateDirectories(directory);
    fs.writeFileSync(path.join(directory, 'state.json'), JSON.stringify(state), {mode: 0o600});
    if (environment.GITHUB_OUTPUT) fs.appendFileSync(environment.GITHUB_OUTPUT, `directory=${directory}\n`);
    const verificationEnvironment = childEnvironment(environment, directory);
    verificationEnvironment.GH_TOKEN = environment.GH_TOKEN;
    verificationEnvironment.GH_PROMPT_DISABLED = '1';
    verificationEnvironment.GH_CONFIG_DIR = path.join(directory, 'gh-config');
    verificationEnvironment.XDG_CACHE_HOME = path.join(directory, 'cache');
    verificationEnvironment.LOCALAPPDATA = path.join(directory, 'cache');
    state.phase = 'release-bundle';
    state.release = await verifyBundle(options.tag, options.commit, path.join(directory, 'release'), verificationEnvironment, state, dependencies);
    state.phase = 'installer-source';
    assertInstallerSource(path.join(directory, 'release'), state.control.commit, execute);
    state.phase = 'native-artifact';
    verifyNativeBinary(options.platform, path.join(directory, 'release', assetName(options.platform)), execute);
    state.phase = 'default-channel';
    state.channel = await verifyDefault(options.platform, path.join(directory, 'channel'), verificationEnvironment, state, dependencies);
    verifyNativeBinary(options.platform, path.join(directory, 'channel', assetName(options.platform)), execute);
    state.verified = true;
    fs.writeFileSync(statePath(directory), JSON.stringify(state), {mode: 0o600});
    return directory;
  } catch (error) {
    let removed = false;
    try {
      cleanup(directory);
      removed = true;
    } finally {
      inputFailureEvidence(options.evidencePath, state, error, Boolean(directory), removed);
    }
    throw error;
  }
}

function assertVerifiedInputs(directory, state) {
  if (state.verified !== true || state.release?.tag !== state.tag || state.release?.commit !== state.commit) {
    throw new Error('public input verification did not complete');
  }
  for (const name of ASSETS) {
    if (hash(regularBytes(path.join(directory, 'release', name))) !== state.release.digests[name]) {
      throw new Error('verified public input changed between credential and execution phases');
    }
  }
  if (hash(regularBytes(path.join(directory, 'channel', assetName(state.platform)))) !== state.channel.digest) {
    throw new Error('verified default channel input changed');
  }
}

const compareNames = (left, right) => left.localeCompare(right);

function addVirtualLinks(virtual, item, kind, key) {
  for (const [name, value] of Object.entries(item[kind] || {})) {
    // Composer excludes platform links (php, ext-*, lib-*) from its
    // installed package table. Library links always have vendor/name.
    if (!name.includes('/')) continue;
    const entry = virtual.get(name) || {dev_requirement: false};
    const values = entry[key] || [];
    const constraint = value === 'self.version' ? item.version : value;
    if (!values.includes(constraint)) values.push(constraint);
    entry[key] = values.sort(compareNames);
    virtual.set(name, entry);
  }
}

function expectedVirtualPackages(lock) {
  const virtual = new Map();
  for (const item of lock.packages) {
    for (const [kind, key] of [['provide', 'provided'], ['replace', 'replaced']]) {
      addVirtualLinks(virtual, item, kind, key);
    }
  }
  return virtual;
}

function applicationPackageName(metadata) {
  // Composer records the application itself separately from its locked
  // dependencies. Bind that one project record; never treat it as an extra library.
  if (metadata.application) {
    const application = metadata.application;
    const installed = metadata.packages?.[application.name];
    if (application.name !== 'durable-workflow/cli' || application.type !== 'project' ||
        installed?.type !== application.type || installed.reference !== application.reference ||
        installed.pretty_version !== application.pretty_version) {
      throw new Error('published PHAR has invalid application package metadata');
    }
    return [application.name];
  }
  return [];
}

function assertVirtualPackages(packages, virtual) {
  for (const [name, expected] of virtual) {
    const actual = structuredClone(packages[name]);
    for (const key of ['provided', 'replaced']) {
      if (Array.isArray(actual[key])) actual[key].sort(compareNames);
    }
    if (!isDeepStrictEqual(actual, expected)) throw new Error(`published Composer virtual-package metadata differs for ${name}`);
  }
}

function assertPackageIdentity(metadata, lock, state) {
  if (metadata.version !== state.tag || metadata.commit !== state.commit) {
    throw new Error('published PHAR build identity does not match the exact version/source');
  }
  const virtual = expectedVirtualPackages(lock);
  const expectedNames = [...lock.packages.map(item => item.name), ...virtual.keys(), ...applicationPackageName(metadata)]
    .sort(compareNames);
  const actualNames = Object.keys(metadata.packages || {}).sort(compareNames);
  if (!isDeepStrictEqual(actualNames, expectedNames)) {
    throw new Error(`published PHAR does not match the exact production package inventory: ${JSON.stringify({
      missing: expectedNames.filter(name => !actualNames.includes(name)),
      extra: actualNames.filter(name => !expectedNames.includes(name)),
    })}`);
  }
  assertVirtualPackages(metadata.packages, virtual);
  for (const item of lock.packages) {
    const installed = metadata.packages?.[item.name];
    const version = item.version.replace(/^v/, '');
    if (installed?.pretty_version?.replace(/^v/, '') !== version ||
        installed.reference !== (item.source?.reference || item.dist?.reference)) {
      throw new Error(`published package identity differs for ${item.name}`);
    }
  }
  for (const item of lock['packages-dev']) {
    if (metadata.packages?.[item.name]) throw new Error('published PHAR contains a development-only package');
  }
}

function packageMetadata(filename, environment, state, execute = run) {
  // This is distribution code execution. It runs only in the later step,
  // after verification has exited and its credential is no longer present.
  const code = String.raw`
$installed = require 'phar://'.$argv[1].'/vendor/composer/installed.php';
require 'phar://'.$argv[1].'/src/GeneratedBuildInfo.php';
echo json_encode(['packages' => $installed['versions'],
    'application' => $installed['root'] ?? null,
    'version' => \DurableWorkflow\Cli\GeneratedBuildInfo::VERSION,
    'commit' => \DurableWorkflow\Cli\GeneratedBuildInfo::COMMIT], JSON_THROW_ON_ERROR);
`;
  const php = assertPhpAuthority(state.tools?.php_authority);
  return JSON.parse(execute(php.binary, ['-r', code, filename], {env: environment, timeout: remaining(state)}));
}

function install(directory, destination, state, environment, exact, execute = run) {
  fs.mkdirSync(destination, {mode: 0o700});
  const child = childEnvironment(environment, directory, [destination]);
  child.DURABLE_WORKFLOW_INSTALL_DIR = destination;
  child.DURABLE_WORKFLOW_INSTALL_OUTPUT = 'json';
  const expected = exact ? state.release.digests[assetName(state.platform)] : state.channel.digest;
  child.DURABLE_WORKFLOW_INSTALL_EXPECTED_SHA256 = expected;
  child.DURABLE_WORKFLOW_RELEASE_BASE_URL = `https://github.com/${REPOSITORY}/releases`;
  if (exact) child.VERSION = state.tag;
  const installer = path.join(directory, 'release', state.platform.startsWith('windows') ? 'install.ps1' : 'install.sh');
  const windows = state.platform.startsWith('windows');
  const installerName = windows ? 'install.ps1' : 'install.sh';
  if (hash(regularBytes(installer)) !== state.release.digests[installerName]) {
    throw new Error('verified installer changed before execution');
  }
  if (windows) child.DURABLE_WORKFLOW_INSTALL_NO_PATH_UPDATE = '1';
  const command = windows ? 'pwsh' : 'sh';
  const args = windows ? ['-NoProfile', '-File', installer] : [installer];
  execute(command, args, {env: child, timeout: remaining(state, 120000)});
  const binary = path.join(destination, windows ? 'dw.exe' : 'dw');
  const digest = hash(regularBytes(binary));
  if (digest !== expected) throw new Error('installed executable differs from its preverified public artifact');
  const lookupArgs = windows
    ? ['-NoProfile', '-Command', '(Get-Command dw -CommandType Application).Source']
    : ['-c', 'command -v dw'];
  const active = execute(command, lookupArgs, {env: child, timeout: remaining(state)}).trim();
  if (fs.realpathSync(active) !== fs.realpathSync(binary)) throw new Error('ordinary dw invocation resolves outside the temporary installation');
  const version = execute(windows ? 'dw.exe' : 'dw', ['--version'], {env: child, timeout: remaining(state)});
  const expectedVersion = exact ? state.tag : state.channel.tag;
  if (!version.includes(` ${expectedVersion} `)) throw new Error('installed executable reports the wrong version');
  return {binary, child};
}

function assertInstalledInspection(commands, schema, expectedSchema) {
  const names = new Set(commands.commands?.map(command => command.name));
  if (['workflow:list', 'schema:show', 'upgrade', 'doctor'].some(name => !names.has(name)) ||
      !isDeepStrictEqual(schema, expectedSchema)) {
    throw new Error('installed command/schema inspection differs from the qualified source');
  }
}

function exercise(directory, evidencePath, environment = process.env, execute = run, observed = null) {
  for (const key of ['GH_TOKEN', 'GITHUB_TOKEN', 'GH_ENTERPRISE_TOKEN', 'GITHUB_ENTERPRISE_TOKEN']) {
    if (environment[key]) throw new Error('verification credentials must be absent from the execution step');
  }
  if (environment.DURABLE_WORKFLOW_RELEASE_API_URL) {
    throw new Error('installed qualification requires the native live default HTTPS authority');
  }
  const state = readState(directory);
  try {
    if (environment.VERIFIER_PLATFORM && environment.VERIFIER_PLATFORM !== state.platform) {
      throw new Error('verification and execution platform identities differ');
    }
    assertVerifiedInputs(directory, state);
    nativePlatform(state.platform, observed ?? {os: process.platform, arch: process.arch}, execute);
    preparePrivateDirectories(directory);
    const child = childEnvironment(environment, directory);
    const lock = JSON.parse(fs.readFileSync(path.join(__dirname, '../../composer.lock'), 'utf8'));
    const expectedSchema = JSON.parse(fs.readFileSync(path.join(__dirname, '../../schemas/output/workflow-list.schema.json'), 'utf8'));
    const metadata = packageMetadata(path.join(directory, 'release', 'dw.phar'), child, state, execute);
    assertPackageIdentity(metadata, lock, state);
    const exact = install(directory, path.join(directory, 'exact'), state, environment, true, execute);
    const commands = JSON.parse(execute(exact.binary, ['list', '--format=json'], {env: exact.child, timeout: remaining(state)}));
    const schema = JSON.parse(execute(exact.binary, ['schema:show', 'workflow:list'], {env: exact.child, timeout: remaining(state)}));
    assertInstalledInspection(commands, schema, expectedSchema);
    const selected = install(directory, path.join(directory, 'default'), state, environment, false, execute);
    const upgrades = [];
    for (const mode of ['dry-run', 'apply']) {
      const args = ['upgrade', '--output=json', ...(mode === 'dry-run' ? ['--dry-run'] : [])];
      const result = JSON.parse(execute(exact.binary, args, {env: exact.child, timeout: remaining(state, 120000)}));
      upgrades.push(verifyUpgradeResult({evidence: result, releaseVersion: state.tag, channelVersion: state.channel.tag, mode}));
    }
    const upgradedDigest = upgrades.at(-1).relation === 'forward-upgrade'
      ? state.channel.digest : state.release.digests[assetName(state.platform)];
    if (hash(regularBytes(exact.binary)) !== upgradedDigest) throw new Error('upgrade result does not match the preverified channel artifact');
    fs.writeFileSync(evidencePath, JSON.stringify({schema: 'durable-workflow.cli.public-install/v1',
      tag: state.tag, initiator: state.initiator, control: state.control,
      artifact_versions: {cli: state.tag},
      installable_artifacts: {verified_public_downloads: true, version: state.tag},
      verified_at: new Date().toISOString(),
      assets: ASSETS.map(name => ({name,
        url: `https://github.com/${REPOSITORY}/releases/download/${state.tag}/${name}`})),
      version: state.tag, commit: state.commit, native: state.native, tools: state.tools, channel: state.channel.tag,
      digests: state.release.digests, package_count: lock.packages.length,
      exact_install: true, default_install: Boolean(selected.binary), upgrades,
      live_default_lookup: {authority: 'https://api.github.com/repos/durable-workflow/cli/releases/latest',
        credential_free: true, dry_run: true, apply: true}, outcome: 'pass'}, null, 2),
    {flag: 'wx', mode: 0o600});
  } finally {
    cleanup(directory);
  }
}

module.exports = {
  ASSETS, PLATFORMS, CELL_MILLISECONDS, releaseIdentity, releaseAuthority, childEnvironment, nativePlatform,
  checksums, verifyEmbeddedPhar, verifyNativeBinary, remaining, tagCommit, verifyBundle, publicResponse,
  assertPackageIdentity, assertVerifiedInputs, assertInstalledInspection,
  readState, cleanup, verify, exercise, install, packageMetadata, main,
  inspectPhpAuthority, capturePhpAuthority, assertPhpAuthority, assertInstallerSource,
  safeSystemFailure,
};

async function main() {
  const [phase, ...extra] = process.argv.slice(2);
  if (extra.length || !['authority', 'verify', 'exercise', 'cleanup'].includes(phase)) {
    throw new Error('usage: run-public-release-verifier.mjs authority|verify|exercise|cleanup');
  }
  if (phase === 'authority') {
    if (!process.env.GITHUB_OUTPUT) throw new Error('PHP authority capture requires the owned workflow output');
    const authority = capturePhpAuthority();
    fs.appendFileSync(process.env.GITHUB_OUTPUT, `authority=${JSON.stringify(authority)}\n`);
    return authority;
  }
  if (phase === 'cleanup') return cleanup(process.env.VERIFIER_DIRECTORY);
  if (phase === 'verify') {
    if (!Object.hasOwn(PLATFORMS, process.env.VERIFIER_PLATFORM)) throw new Error('missing exact verification platform');
    if (process.env.GITHUB_REPOSITORY !== REPOSITORY || !process.env.GH_TOKEN) {
      throw new Error('verification requires the owning repository read token');
    }
    return verify({tag: process.env.RELEASE_TAG, commit: process.env.VERIFIED_SOURCE_SHA,
      platform: process.env.VERIFIER_PLATFORM,
      evidencePath: path.resolve(`release-public-download-evidence-${process.env.VERIFIER_PLATFORM}.json`)});
  }
  const platform = process.env.VERIFIER_PLATFORM;
  if (!Object.hasOwn(PLATFORMS, platform)) throw new Error('missing exact verification platform');
  return exercise(process.env.VERIFIER_DIRECTORY,
    path.resolve(`release-public-download-evidence-${platform}.json`));
}

if (require.main === module) {
  process.stderr.write('Use run-public-release-verifier.mjs to invoke the verifier.\n');
  process.exitCode = 1;
}
