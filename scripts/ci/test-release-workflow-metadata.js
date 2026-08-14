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
