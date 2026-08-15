'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const test = require('node:test');

const script = path.join(__dirname, 'reconcile-release-metadata.sh');

function reconcile(tag) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dw-release-metadata-'));
  const gh = path.join(directory, 'gh');
  const calls = path.join(directory, 'calls');
  fs.writeFileSync(gh, `#!/usr/bin/env bash
set -eu
printf '%s\\n' "$*" >> "${calls}"
case "$*" in
  *releases/tags*) printf '91\\n' ;;
esac
`);
  fs.chmodSync(gh, 0o755);
  const result = spawnSync('bash', [script, tag], {
    cwd: path.resolve(__dirname, '../..'),
    encoding: 'utf8',
    env: {
      ...process.env,
      GH_CLI: gh,
      GITHUB_REPOSITORY: 'durable-workflow/cli',
    },
  });
  const observed = fs.readFileSync(calls, 'utf8');
  fs.rmSync(directory, {force: true, recursive: true});

  assert.equal(result.status, 0, result.stderr);
  return observed;
}

test('alpha, beta, and rc releases are reconciled as prereleases', () => {
  for (const tag of ['2.0.0-alpha.1', '2.0.0-beta.2', '2.0.0-rc.14']) {
    const calls = reconcile(tag);
    assert.match(calls, /-F prerelease=true/);
    assert.match(calls, /-f make_latest=false/);
  }
});

test('an existing rc.13 release is repaired with a metadata-only patch', () => {
  const calls = reconcile('2.0.0-rc.13');
  assert.match(calls, /api repos\/durable-workflow\/cli\/releases\/tags\/2\.0\.0-rc\.13/);
  assert.match(calls, /api --method PATCH repos\/durable-workflow\/cli\/releases\/91/);
  assert.match(calls, /-F prerelease=true/);
  assert.match(calls, /-f make_latest=false/);
  assert.doesNotMatch(calls, /upload|delete|assets/);
});

test('stable releases are reconciled into the stable latest channel', () => {
  const calls = reconcile('2.0.0');
  assert.match(calls, /-F prerelease=false/);
  assert.match(calls, /-f make_latest=true/);
});
