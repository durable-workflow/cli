'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const test = require('node:test');

const installer = process.env.DW_TEST_UNIX_INSTALLER || path.join(__dirname, '../install.sh');
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
const payload = label => `#!/bin/sh\nprintf '%s\\n' '${label}' >> "$CALL_RECORD"\nprintf '%s\\n' 'dw 2.3.7 (fixture)'\n`;

function runInstaller(selected, pin, channel) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dw-installer-source-'));
  const tools = path.join(directory, 'tools');
  const installed = path.join(directory, 'bin');
  const home = path.join(directory, 'home');
  const temporary = path.join(directory, 'tmp');
  for (const name of [tools, installed, home, temporary]) fs.mkdirSync(name, {mode: 0o700});
  const record = path.join(directory, 'calls');
  const asset = 'dw-linux-x86_64';
  fs.writeFileSync(path.join(directory, 'payload'), selected);
  fs.writeFileSync(path.join(directory, 'SHA256SUMS'), `${hash(selected)}  ${asset}\n`);
  fs.writeFileSync(path.join(installed, 'dw-fixture'), 'original installed bytes');
  fs.writeFileSync(path.join(tools, 'uname'), '#!/bin/sh\ncase "$1" in -s) echo Linux;; -m) echo x86_64;; *) exit 88;; esac\n', {mode: 0o700});
  fs.writeFileSync(path.join(tools, 'curl'), `#!/bin/sh
set -eu
while [ "$#" -gt 0 ]; do
  case "$1" in
    -o) destination=$2; shift 2;;
    --retry) shift 2;;
    -fsSL) shift;;
    https://fixture.invalid/releases/*) url=$1; shift;;
    *) exit 88;;
  esac
done
printf '%s\\n' "$url" >> "$FIXTURE_ROOT/downloads"
case "$url" in
  */SHA256SUMS) cp "$FIXTURE_ROOT/SHA256SUMS" "$destination";;
  */dw-linux-x86_64) cp "$FIXTURE_ROOT/payload" "$destination";;
  *) exit 88;;
esac
`, {mode: 0o700});
  const environment = {PATH: `${tools}:${installed}:/usr/bin:/bin:/usr/sbin:/sbin`, HOME: home, TMPDIR: temporary,
    FIXTURE_ROOT: directory, CALL_RECORD: record, VERSION: channel ? 'stable' : '2.3.7',
    DURABLE_WORKFLOW_BIN_NAME: 'dw-fixture', DURABLE_WORKFLOW_INSTALL_DIR: installed,
    DURABLE_WORKFLOW_INSTALL_OUTPUT: 'json', DURABLE_WORKFLOW_RELEASE_BASE_URL: 'https://fixture.invalid/releases'};
  if (pin !== undefined) environment.DURABLE_WORKFLOW_INSTALL_EXPECTED_SHA256 = pin;
  try {
    const result = spawnSync('/bin/sh', [installer], {env: environment, encoding: 'utf8', timeout: 10000});
    assert.ifError(result.error);
    return {status: result.status, stderr: result.stderr, stdout: result.stdout,
      invocations: fs.existsSync(record) ? fs.readFileSync(record, 'utf8').trim().split('\n') : [],
      installed: fs.readFileSync(path.join(installed, 'dw-fixture'), 'utf8'),
      downloads: fs.existsSync(path.join(directory, 'downloads')) ? fs.readFileSync(path.join(directory, 'downloads'), 'utf8') : ''};
  } finally { fs.rmSync(directory, {recursive: true, force: true}); }
}

for (const channel of [false, true]) {
  test(`actual Unix installer refuses raced payload plus matching manifest before execution/replacement: default=${channel}`, () => {
    const result = runInstaller(payload('wrong'), hash(payload('qualified')), channel);
    assert.deepEqual(result.invocations, []);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /pinned artifact digest/);
    assert.equal(result.installed, 'original installed bytes');
  });
  test(`actual Unix installer accepts the qualified digest with a private HOME: default=${channel}`, () => {
    const selected = payload('qualified');
    const result = runInstaller(selected, hash(selected), channel);
    assert.equal(result.status, 0, result.stderr);
    assert.ok(result.invocations.length > 0);
    assert.equal(result.installed, selected);
    assert.equal(JSON.parse(result.stdout).status, 'ready');
    assert.match(result.downloads, channel ? /\/latest\/download\// : /\/download\/2\.3\.7\//);
  });
}

test('actual Unix installer preserves unset-pin behavior and refuses malformed pins before downloads', () => {
  const selected = payload('ordinary');
  const ordinary = runInstaller(selected, undefined, false);
  assert.equal(ordinary.status, 0, ordinary.stderr);
  assert.ok(ordinary.invocations.length > 0);
  for (const pin of ['not-a-digest', 'a'.repeat(63), 'A'.repeat(64), 'a'.repeat(64) + '\n']) {
    const result = runInstaller(selected, pin, false);
    assert.notEqual(result.status, 0);
    assert.deepEqual(result.invocations, []);
    assert.equal(result.downloads, '');
    assert.equal(result.installed, 'original installed bytes');
  }
});
