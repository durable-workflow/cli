'use strict';

const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repository = path.resolve(__dirname, '../..');
const resolver = path.join(__dirname, 'release-version.js');
const workflowPath = path.join(repository, '.github/workflows/release.yml');

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
    /release_metadata="\$\(node scripts\/ci\/release-version\.js metadata "\$tag"\)"/,
  );
  assert.match(workflow, /read -r prerelease make_latest <<< "\$release_metadata"/);
  assert.match(workflow, /echo "prerelease=\$prerelease" >> "\$GITHUB_OUTPUT"/);
  assert.match(workflow, /echo "make_latest=\$make_latest" >> "\$GITHUB_OUTPUT"/);

  const releaseAction = workflow.slice(
    workflow.indexOf('- name: Create GitHub Release'),
    workflow.indexOf('- name: Verify public release downloads'),
  );
  assert.match(
    releaseAction,
    /prerelease: \$\{\{ needs\.resolve-release\.outputs\.prerelease \}\}/,
  );
  assert.match(
    releaseAction,
    /make_latest: \$\{\{ needs\.resolve-release\.outputs\.make_latest \}\}/,
  );
  assert.match(releaseAction, /body_path: release-notes\.md/);
  assert.match(releaseAction, /files: \|\n\s+dist\/\*/);
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

test('upgrade channel verification uses the executable installed from the public release', () => {
  const workflow = fs.readFileSync(workflowPath, 'utf8');
  const verificationStart = workflow.indexOf('- name: Verify public release downloads');
  const verificationEnd = workflow.indexOf(
    '- name: Verify live docs release audit after public downloads',
    verificationStart,
  );

  assert.notEqual(verificationStart, -1);
  assert.notEqual(verificationEnd, -1);

  const verification = workflow.slice(verificationStart, verificationEnd);
  assert.match(
    verification,
    /upgrade_evidence="\$\("\$install_dir\/dw-release-check" upgrade --dry-run --output=json\)"/,
  );
  assert.doesNotMatch(verification, /dist\/dw-linux-x86_64 upgrade/);
});
