'use strict';

const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repository = path.resolve(__dirname, '../..');
const resolver = path.join(__dirname, 'release-version.js');
const workflowPath = path.join(repository, '.github/workflows/release.yml');
const {releaseImplementation, releaseMetadata} = require('./release-version');

function workflowShell(marker) {
  const source = fs.readFileSync(workflowPath, 'utf8').split(marker)[1];
  const lines = source.split('\n        run: |\n')[1].split('\n');
  const shell = [];
  for (const line of lines) {
    if (line.trim() && !line.startsWith('          ')) break;
    shell.push(line.slice(10));
  }
  return shell.join('\n');
}

const latestCases = [
  ['unchanged valid', [0, '2.3.6'], [0, '2.3.6'], true, false, 2],
  ['newer initial', [0, '2.5.0'], [0, '2.5.0'], false, false, 1],
  ['changed final', [0, '2.3.6'], [0, '2.5.0'], true, false, 2],
  ['first failure', [1, ''], [0, '2.3.6'], false, false, 2],
  ['second failure', [0, '2.3.6'], [1, ''], true, false, 2],
  ['both failures', [1, ''], [1, ''], false, false, 2],
  ['failed first with output', [1, '2.3.6'], [0, '2.3.6'], false, false, 2],
  ['failed second with output', [0, '2.3.6'], [1, '2.3.6'], true, false, 2],
  ['malformed first', [0, 'null'], [0, '2.3.6'], false, false, 2],
  ['malformed second', [0, '2.3.6'], [0, 'null'], true, false, 2],
  ['empty success', [0, ''], [0, '2.3.6'], false, false, 2],
  ['prerelease as latest', [0, '2.3.6-rc.1'], [0, '2.3.6'], false, false, 2],
  ['multiline metadata', [0, '2.3.6\nmake_latest=true'], [0, '2.3.6'], false, false, 2],
];

for (const [label, first, second, initial, final, count] of latestCases) {
  test(`latest observations fail closed: ${label}`, () => {
    const os = require('node:os');
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dw-latest-observations-'));
    try {
      const output = path.join(directory, 'outputs');
      const calls = path.join(directory, 'calls');
      const counter = path.join(directory, 'counter');
      fs.writeFileSync(counter, '0\n');
      for (const prefix of ['scripts/ci', 'release-control/scripts/ci']) {
        fs.mkdirSync(path.join(directory, prefix), {recursive: true});
        fs.copyFileSync(resolver, path.join(directory, prefix, 'release-version.js'));
      }
      fs.mkdirSync(path.join(directory, 'dist'));
      fs.writeFileSync(path.join(directory, 'dist/synthetic'), 'fixture only\n');
      fs.symlinkSync(process.execPath, path.join(directory, 'node'));
      fs.writeFileSync(path.join(directory, 'gh'), `#!/bin/sh
set -eu
if [ "$1" = api ] && [ "$2" = repos/durable-workflow/cli/releases/latest ]; then
  read -r count < "$FAKE_COUNTER"
  count=$((count + 1))
  printf '%s\\n' "$count" > "$FAKE_COUNTER"
  if [ "$count" -eq 1 ]; then printf '%s\\n' "$FIRST_TAG"; exit "$FIRST_STATUS";
  elif [ "$count" -eq 2 ]; then printf '%s\\n' "$SECOND_TAG"; exit "$SECOND_STATUS";
  else exit 99; fi
elif [ "$1" = release ] && [ "$2" = create ]; then printf '%s\\n' "$@" > "$FAKE_CALLS";
else exit 99; fi
`, {mode: 0o700});
      const env = {
        PATH: `${directory}:/usr/bin:/bin`,
        FAKE_COUNTER: counter, FAKE_CALLS: calls,
        FIRST_STATUS: String(first[0]), FIRST_TAG: first[1],
        SECOND_STATUS: String(second[0]), SECOND_TAG: second[1],
        GITHUB_REPOSITORY: 'durable-workflow/cli', GITHUB_OUTPUT: output,
        GITHUB_EVENT_NAME: 'workflow_dispatch', DISPATCH_OPERATION: 'release',
        DISPATCH_TAG: '2.3.7', DISPATCH_COMMIT: 'a'.repeat(40),
        CONTROL_REF: 'refs/heads/main', CONTROL_COMMIT: 'b'.repeat(40),
      };
      execFileSync('/bin/bash', ['-c', workflowShell('- id: resolve\n')], {
        cwd: directory, env, timeout: 10000, stdio: 'pipe',
      });
      const entries = fs.readFileSync(output, 'utf8').trim().split('\n').map(line => {
        const index = line.indexOf('=');
        return [line.slice(0, index), line.slice(index + 1)];
      });
      assert.equal(new Set(entries.map(([key]) => key)).size, entries.length);
      const resolved = Object.fromEntries(entries);
      assert.equal(resolved.make_latest, String(initial));
      assert.equal(resolved.tag, '2.3.7');
      assert.equal(resolved.commit, 'a'.repeat(40));
      if (!first[0] && first[1] === '2.3.6') assert.equal(resolved.observed_latest, first[1]);
      else if (first[0] || first[1] !== '2.5.0') assert.equal(resolved.observed_latest, '');

      // The privileged step must use the workflow's policy authority, never
      // the package checkout's helper (the two authorities can differ).
      fs.writeFileSync(path.join(directory, 'scripts/ci/release-version.js'), 'process.exit(99);\n');
      execFileSync('/bin/bash', ['-c', workflowShell('- name: Create GitHub Release\n')], {
        cwd: directory,
        env: {...env, RELEASE_TAG: resolved.tag, PRERELEASE: resolved.prerelease,
          MAKE_LATEST: resolved.make_latest, RECHECK_LATEST: resolved.recheck_latest || resolved.make_latest,
          OBSERVED_LATEST: resolved.observed_latest},
        timeout: 10000, stdio: 'pipe',
      });
      const args = fs.readFileSync(calls, 'utf8').trim().split('\n');
      assert.ok(args.includes(`--latest=${final}`));
      assert.ok(args.includes('--verify-tag'));
      assert.equal(Number(fs.readFileSync(counter, 'utf8')), count);
    } finally {
      fs.rmSync(directory, {force: true, recursive: true});
    }
  });
}

test('the maintenance release stays on PHP while the Rust version boundary is preserved', () => {
  for (const [version, implementation] of [
    ['2.3.7', 'php'], ['2.4.99', 'php'], ['2.5.0-rc.1', 'rust'],
    ['2.5.0', 'rust'], ['2.10.0', 'rust'], ['3.0.0', 'rust'],
  ]) {
    assert.equal(releaseImplementation(version), implementation, version);
  }
  assert.equal(releaseImplementation('invalid'), null);
});

test('maintenance metadata preserves a newer latest release and prerelease state', () => {
  assert.deepEqual(releaseMetadata('2.3.7', '2.5.0'), {prerelease: false, makeLatest: false});
  assert.deepEqual(releaseMetadata('2.3.7', '2.3.6'), {prerelease: false, makeLatest: true});
  assert.deepEqual(releaseMetadata('2.3.7-rc.1', '2.3.6'), {prerelease: true, makeLatest: false});
  assert.deepEqual(releaseMetadata('2.10.0', '2.9.0'), {prerelease: false, makeLatest: true});
});

test('maintenance publication remains nonlatest for unchanged, changed and unknown channels', () => {
  const os = require('node:os');
  const workflow = fs.readFileSync(workflowPath, 'utf8');
  const step = workflow.slice(workflow.indexOf('- name: Create GitHub Release'));
  const lines = step.split('\n        run: |\n')[1].split('\n');
  const shell = [];
  for (const line of lines) {
    if (line.trim() && !line.startsWith('          ')) break;
    shell.push(line.slice(10));
  }
  assert.ok(shell.some(line => line.includes('gh release create')));
  for (const [latest, observed, status, expected] of [
    ['2.3.6', '2.3.6', '0', 'false'], ['2.5.0', '2.3.6', '0', 'false'],
    ['2.3.6', '2.3.6', '1', 'false'], ['', '', '1', 'false'],
    ['null', 'null', '0', 'false'], ['', '', '0', 'false'],
  ]) {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'dw-maintenance-control-'));
    try {
      const calls = path.join(directory, 'calls');
      fs.mkdirSync(path.join(directory, 'dist'));
      fs.writeFileSync(path.join(directory, 'dist', 'synthetic'), 'fixture only\n');
      fs.mkdirSync(path.join(directory, 'release-control/scripts/ci'), {recursive: true});
      fs.copyFileSync(resolver, path.join(directory, 'release-control/scripts/ci/release-version.js'));
      fs.symlinkSync(process.execPath, path.join(directory, 'node'));
      fs.writeFileSync(path.join(directory, 'gh'), `#!/bin/sh
set -eu
if [ "$1" = api ]; then printf '%s\\n' "$FAKE_LATEST"; exit "$FAKE_STATUS";
elif [ "$1" = release ] && [ "$2" = create ]; then printf '%s\\n' "$@" > "$FAKE_CALLS";
else exit 99; fi
`, {mode: 0o700});
      execFileSync('/bin/bash', ['-c', shell.join('\n')], {
        cwd: directory,
        env: {
          PATH: `${directory}:/usr/bin:/bin`,
          FAKE_LATEST: latest, FAKE_STATUS: status, FAKE_CALLS: calls,
          GITHUB_REPOSITORY: 'durable-workflow/cli',
          RELEASE_TAG: '2.3.7', PRERELEASE: 'false', MAKE_LATEST: 'true', RECHECK_LATEST: 'true', OBSERVED_LATEST: observed,
        },
        timeout: 10000,
        stdio: 'pipe',
      });
      assert.ok(fs.readFileSync(calls, 'utf8').split('\n').includes(`--latest=${expected}`));
    } finally {
      fs.rmSync(directory, {force: true, recursive: true});
    }
  }
});

test('native launcher qualification reuses the guarded PHAR dependency artifact', () => {
  const workflow = fs.readFileSync(workflowPath, 'utf8');
  const phar = workflow.slice(workflow.indexOf('\n  build-phar:'), workflow.indexOf('\n  build-binary:'));
  const native = workflow.split('\n  build-binary:')[1].split(/\n {2}(?=\S)/)[0];

  assert.match(phar, /launcher-vendor-id: \$\{\{ steps\.launcher_vendor\.outputs\.artifact-id \}\}/);
  assert.match(phar, /id: launcher_vendor[\s\S]*?name: launcher-vendor\n\s+path: vendor\//);
  assert.match(workflow, /artifact-ids: \$\{\{ needs\.build-phar\.outputs\.launcher-vendor-id \}\}\n\s+path: vendor\n\s+digest-mismatch: error/);
  assert.match(native, /needs: \[resolve-release, release-preflight, build-phar\]/);
  assert.doesNotMatch(native, /composer install/);
});

function resolveMetadata(tag) {
  const normalized = execFileSync(process.execPath, [resolver, 'normalize', tag], {
    encoding: 'utf8',
  }).trim();
  const [prerelease, makeLatest] = execFileSync(
    process.execPath,
    [resolver, 'metadata', normalized],
    {encoding: 'utf8'},
  ).trim().split(' ');

  return {normalized, prerelease, makeLatest};
}

test('alpha, beta, and rc tags resolve to GitHub prerelease metadata', () => {
  for (const tag of ['v2.0.0-alpha.1', '2.0.0-beta.2', '2.0.0-rc.31']) {
    assert.deepEqual(resolveMetadata(tag), {
      normalized: tag.startsWith('v') ? tag.slice(1) : tag,
      prerelease: 'true',
      makeLatest: 'false',
    });
  }
});

test('a stable tag remains eligible for the normal stable release state', () => {
  assert.deepEqual(resolveMetadata('v2.0.0'), {
    normalized: '2.0.0',
    prerelease: 'false',
    makeLatest: 'true',
  });
});

test('the release action consumes metadata from the resolved tag', () => {
  const workflow = fs.readFileSync(workflowPath, 'utf8');

  assert.match(workflow, /prerelease: \$\{\{ steps\.resolve\.outputs\.prerelease \}\}/);
  assert.match(workflow, /make_latest: \$\{\{ steps\.resolve\.outputs\.make_latest \}\}/);
  assert.match(
    workflow,
    /release_metadata="\$\(node scripts\/ci\/release-version\.js metadata "\$tag" "\$current_latest"\)"/,
  );
  assert.match(workflow, /read -r prerelease make_latest <<< "\$release_metadata"/);
  assert.match(workflow, /echo "prerelease=\$prerelease" >> "\$GITHUB_OUTPUT"/);
  assert.match(workflow, /echo "make_latest=\$make_latest" >> "\$GITHUB_OUTPUT"/);

  const releaseAction = workflow.slice(
    workflow.indexOf('- name: Create GitHub Release'),
    workflow.indexOf('- name: Verify public release downloads'),
  );
  assert.match(releaseAction, /PRERELEASE: \$\{\{ needs\.resolve-release\.outputs\.prerelease \}\}/);
  assert.match(releaseAction, /MAKE_LATEST: \$\{\{ needs\.resolve-release\.outputs\.make_latest \}\}/);
  assert.match(releaseAction, /--notes-file release-notes\.md --generate-notes "--latest=\$MAKE_LATEST"/);
  assert.match(releaseAction, /flags\+=\(--prerelease\)/);
  assert.match(releaseAction, /gh release create "\$RELEASE_TAG" --repo "\$GITHUB_REPOSITORY" "\$\{flags\[@\]\}" dist\/\*/);
  assert.doesNotMatch(workflow, /softprops\/action-gh-release/);
});

test('metadata reconciliation can only write from the main branch', () => {
  const workflow = fs.readFileSync(workflowPath, 'utf8');
  const jobStart = workflow.indexOf('  reconcile-existing-release-metadata:');
  const jobEnd = workflow.indexOf('\n  build-phar:', jobStart);

  assert.notEqual(jobStart, -1);
  assert.notEqual(jobEnd, -1);

  const reconciliationJob = workflow.slice(jobStart, jobEnd);
  assert.match(
    reconciliationJob,
    /if: >-\n\s+github\.ref == 'refs\/heads\/main' &&/,
  );
  assert.match(reconciliationJob, /permissions:\n\s+contents: write/);
});

test('installed code executes only after the credentialed verification step exits', () => {
  const workflow = fs.readFileSync(workflowPath, 'utf8');
  const verify = workflowJob(workflow, 'verify-release');
  const verificationStart = verify.indexOf('- name: Verify public release downloads');
  const executionStart = verify.indexOf('- name: Exercise verified temporary installations');
  const cleanupStart = verify.indexOf('- name: Remove temporary verification state');
  assert.ok(verificationStart >= 0 && executionStart > verificationStart && cleanupStart > executionStart);
  const inputs = verify.slice(verificationStart, executionStart);
  const execution = verify.slice(executionStart, cleanupStart);
  assert.match(inputs, /GH_TOKEN: \$\{\{ github\.token \}\}/);
  assert.match(inputs, /run: node scripts\/ci\/run-public-release-verifier\.mjs verify/);
  assert.match(execution, /run: node scripts\/ci\/run-public-release-verifier\.mjs exercise/);
  assert.match(execution, /VERIFIER_DIRECTORY: \$\{\{ steps\.public-inputs\.outputs\.directory \}\}/);
  assert.doesNotMatch(execution, /GH_TOKEN|GITHUB_TOKEN|github\.token|secrets\./);
  assert.match(verify.slice(cleanupStart), /if: always\(\)/);
});

function workflowJob(workflow, name) {
  const start = workflow.indexOf(`\n  ${name}:\n`);
  assert.notEqual(start, -1, `missing job ${name}`);
  const rest = workflow.slice(start + 1);
  const next = rest.slice(1).search(/\n {2}[a-z][a-z0-9-]*:\n/);
  return next === -1 ? rest : rest.slice(0, next + 1);
}

test('the publishing job never runs installers or the published dw', () => {
  const workflow = fs.readFileSync(workflowPath, 'utf8');
  const publish = workflowJob(workflow, 'release');

  // GitHub keeps every secret a job can use in memory and any step in an
  // id-token job can mint tokens, so code from the release runs elsewhere.
  assert.match(publish, /contents: write/);
  assert.match(publish, /id-token: write/);
  assert.doesNotMatch(publish, /Verify public release downloads/);
  // Syntax checks (sh -n) and copies of the installer are fine; running it is not.
  assert.doesNotMatch(publish, /sh "\$installer"|\bsh scripts\/install\.sh|DURABLE_WORKFLOW_BIN_NAME=/);
  assert.doesNotMatch(publish, /dw-release-check|dw-channel-check|upgrade --/);
});

test('public release verification runs read-only after publication', () => {
  const workflow = fs.readFileSync(workflowPath, 'utf8');
  const verify = workflowJob(workflow, 'verify-release');

  assert.match(verify, /needs: \[resolve-release, release\]/);
  assert.match(verify, /needs\.release\.result == 'success'/);
  assert.match(verify, /permissions:\n\s+contents: read\n/);
  assert.match(verify, /attestations: read/);
  assert.doesNotMatch(verify, /contents: write|id-token|attestations: write|environment:|secrets\./);
  // Verification code comes from the workflow's own commit, not a job output.
  assert.match(verify, /ref: \$\{\{ github\.sha \}\}/);
  assert.doesNotMatch(verify, /ref: \$\{\{ needs\./);
  assert.match(verify, /VERIFIED_SOURCE_SHA: \$\{\{ needs\.resolve-release\.outputs\.commit \}\}/);
  assert.match(verify, /RELEASE_CONTROL_REF: \$\{\{ needs\.resolve-release\.outputs\.control_ref \}\}/);
  assert.match(verify, /RELEASE_CONTROL_COMMIT: \$\{\{ needs\.resolve-release\.outputs\.control_commit \}\}/);
  assert.match(verify, /RELEASE_INITIATOR: \$\{\{ needs\.resolve-release\.outputs\.initiator \}\}/);

  assert.match(verify, /persist-credentials: false/);
  assert.match(verify, /- name: Verify public release downloads/);
  assert.match(verify, /run: node scripts\/ci\/run-public-release-verifier\.mjs exercise/);
  assert.match(verify, /timeout-minutes: 15/);
  assert.match(verify, /name: release-evidence/);
});

test('verifier PHP is established and bound before the public-input read token is available', () => {
  const verify = workflowJob(fs.readFileSync(workflowPath, 'utf8'), 'verify-release');
  const bootstrap = verify.indexOf('- name: Establish trusted verifier PHP');
  const capture = verify.indexOf('- name: Capture exact verifier PHP authority');
  const inputs = verify.indexOf('- name: Verify public release downloads');
  assert.ok(bootstrap >= 0 && capture > bootstrap && inputs > capture);
  const trustedTools = verify.slice(bootstrap, inputs);
  assert.match(trustedTools, /shivammathur\/setup-php@f3e473d116dcccaddc5834248c87452386958240/);
  assert.match(trustedTools, /BOOTSTRAPPED_PHP_VERSION: \$\{\{ steps\.verifier-php\.outputs\.php-version \}\}/);
  assert.match(trustedTools, /run: node scripts\/ci\/run-public-release-verifier\.mjs authority/);
  assert.doesNotMatch(trustedTools, /github\.token|secrets\./);
  assert.match(verify.slice(inputs), /VERIFIER_PHP_AUTHORITY: \$\{\{ steps\.verifier-php-authority\.outputs\.authority \}\}/);
});

test('public installation cells retain exactly the four existing native producer platforms', () => {
  const workflow = fs.readFileSync(workflowPath, 'utf8');
  const verify = workflowJob(workflow, 'verify-release');
  const cells = [...verify.matchAll(/- name: ([a-z0-9_-]+)\n\s+runner: ([a-z0-9.-]+)/g)]
    .map(match => [match[1], match[2]]);
  assert.deepEqual(cells, [
    ['linux-x86_64', 'ubuntu-24.04'], ['linux-aarch64', 'ubuntu-24.04-arm'],
    ['macos-aarch64', 'macos-15'], ['windows-x86_64', 'windows-2022'],
  ]);
  assert.match(verify, /fail-fast: false/);
  const jobEnvironment = verify.split('\n    env:\n')[1].split('\n    steps:\n')[0];
  assert.doesNotMatch(jobEnvironment, /TOKEN|secrets\./);
  assert.match(verify, /run: node scripts\/ci\/run-public-release-verifier\.mjs cleanup/);
});
