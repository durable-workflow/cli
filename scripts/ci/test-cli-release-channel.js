'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');

const {
  AUTHORITY_SCHEMA,
  REQUIRED_ASSETS,
  expectedPrerelease,
  validateChannel,
  validateReleaseMetadata,
  verifyPublicReleaseChannel,
} = require('./verify-cli-release-channel');

function release(tag, prerelease) {
  return {
    assets: REQUIRED_ASSETS.map(name => ({name})),
    draft: false,
    prerelease,
    tag_name: tag,
  };
}

function jsonResponse(value, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    async json() {
      return value;
    },
  };
}

test('alpha, beta, and rc tags always require prerelease metadata', () => {
  for (const tag of ['2.0.0-alpha.4', '2.0.0-beta.21', '2.0.0-rc.14']) {
    assert.equal(expectedPrerelease(tag), true, tag);
    assert.equal(validateReleaseMetadata(release(tag, true), tag).prerelease, true);
  }
  assert.equal(expectedPrerelease('2.0.0'), false);
  assert.equal(validateReleaseMetadata(release('2.0.0', false), '2.0.0').prerelease, false);
});

test('a prerelease tag exposed as a stable GitHub Release fails closed', () => {
  assert.throws(
    () => validateReleaseMetadata(release('2.0.0-rc.14', false), '2.0.0-rc.14'),
    /prerelease=false; expected true/,
  );
});

test('channel classification supports prerelease and stable transition records', () => {
  assert.deepEqual(
    validateChannel({
      schema: AUTHORITY_SCHEMA,
      schema_version: 2,
      outcome: 'pass',
      qualified_artifact_versions: {cli: '2.0.0-rc.31'},
    }),
    {
      schema: AUTHORITY_SCHEMA,
      channel: 'prerelease',
      version: '2.0.0-rc.31',
    },
  );
  assert.equal(validateChannel({
    schema: AUTHORITY_SCHEMA,
    schema_version: 2,
    outcome: 'pass',
    qualified_artifact_versions: {cli: '2.0.0'},
  }).version, '2.0.0');

  assert.throws(
    () => validateChannel({
      schema: AUTHORITY_SCHEMA,
      schema_version: 2,
      outcome: 'pass',
      qualified_artifact_versions: {cli: '2.0.0-preview.1'},
    }),
    /must be an alpha, beta, or rc/,
  );
});

test('the supported prerelease must remain publicly discoverable with complete assets', async () => {
  const releaseTag = '2.0.0-rc.32';
  const supportedTag = '2.0.0-rc.12';
  const channel = {
    schema: AUTHORITY_SCHEMA,
    schema_version: 2,
    outcome: 'pass',
    qualified_artifact_versions: {cli: supportedTag},
  };
  const fetchImpl = async url => {
    if (url === 'https://example.test/channel.json') {
      return jsonResponse(channel);
    }
    if (url.endsWith(`/releases/tags/${releaseTag}`)) {
      return jsonResponse(release(releaseTag, true));
    }
    if (url.endsWith(`/releases/tags/${supportedTag}`)) {
      return jsonResponse(release(supportedTag, true));
    }
    return jsonResponse({}, 404);
  };

  assert.deepEqual(
    await verifyPublicReleaseChannel({
      apiBase: 'https://api.example.test/repos/durable-workflow/cli',
      channelUrl: 'https://example.test/channel.json',
      fetchImpl,
      releaseTag,
    }),
    {
      channel: 'prerelease',
      channel_version: supportedTag,
      release_prerelease: true,
      release_tag: releaseTag,
    },
  );

  const incompleteFetch = async url => {
    if (url === 'https://example.test/channel.json') {
      return jsonResponse(channel);
    }
    if (url.endsWith(`/releases/tags/${releaseTag}`)) {
      return jsonResponse(release(releaseTag, true));
    }
    const incompleteSupportedRelease = release(supportedTag, true);
    incompleteSupportedRelease.assets = incompleteSupportedRelease.assets.filter(
      asset => asset.name !== 'SHA256SUMS',
    );
    return jsonResponse(incompleteSupportedRelease);
  };
  await assert.rejects(
    verifyPublicReleaseChannel({
      apiBase: 'https://api.example.test/repos/durable-workflow/cli',
      channelUrl: 'https://example.test/channel.json',
      fetchImpl: incompleteFetch,
      releaseTag,
    }),
    /missing required assets: SHA256SUMS/,
  );

  const unavailableFetch = async url => {
    if (url === 'https://example.test/channel.json') {
      return jsonResponse(channel);
    }
    if (url.endsWith(`/releases/tags/${releaseTag}`)) {
      return jsonResponse(release(releaseTag, true));
    }
    return jsonResponse({}, 404);
  };
  await assert.rejects(
    verifyPublicReleaseChannel({
      apiBase: 'https://api.example.test/repos/durable-workflow/cli',
      channelUrl: 'https://example.test/channel.json',
      fetchImpl: unavailableFetch,
      releaseTag,
    }),
    /HTTP 404 fetching .*2\.0\.0-rc\.12/,
  );
});

test('stable transition resolution requires stable public metadata', async () => {
  const fetchImpl = async url => {
    if (url === 'https://example.test/channel.json') {
      return jsonResponse({
        schema: AUTHORITY_SCHEMA,
        schema_version: 2,
        outcome: 'pass',
        qualified_artifact_versions: {cli: '2.0.0'},
      });
    }
    return jsonResponse(release('2.0.0', false));
  };

  const evidence = await verifyPublicReleaseChannel({
    apiBase: 'https://api.example.test/repos/durable-workflow/cli',
    channelUrl: 'https://example.test/channel.json',
    fetchImpl,
    releaseTag: '2.0.0',
  });
  assert.equal(evidence.channel, 'stable');
  assert.equal(evidence.release_prerelease, false);
});
