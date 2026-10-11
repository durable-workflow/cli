'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');

const {
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

test('channel classification validates GitHub latest stable metadata and assets', () => {
  assert.deepEqual(
    validateChannel(release('2.0.0', false)),
    {
      channel: 'stable',
      version: '2.0.0',
    },
  );

  assert.throws(
    () => validateChannel(release('2.0.0-rc.1', true)),
    /must be exact MAJOR.MINOR.PATCH/,
  );
  assert.throws(() => validateChannel({...release('2.0.0', false), draft: true}), /still a draft/);
  assert.throws(() => validateChannel(release('2.0.0', true)), /prerelease=true; expected false/);
});

test('the current stable channel remains publicly discoverable with complete assets', async () => {
  const releaseTag = '2.0.0-rc.32';
  const supportedTag = '2.0.0';
  const channel = release(supportedTag, false);
  const fetchImpl = async url => {
    if (url.endsWith('/releases/latest')) {
      return jsonResponse(channel);
    }
    if (url.endsWith(`/releases/tags/${releaseTag}`)) {
      return jsonResponse(release(releaseTag, true));
    }
    if (url.endsWith(`/releases/tags/${supportedTag}`)) {
      return jsonResponse(release(supportedTag, false));
    }
    return jsonResponse({}, 404);
  };

  assert.deepEqual(
    await verifyPublicReleaseChannel({
      apiBase: 'https://api.example.test/repos/durable-workflow/cli',
      fetchImpl,
      releaseTag,
    }),
    {
      channel: 'stable',
      channel_version: supportedTag,
      release_prerelease: true,
      release_tag: releaseTag,
    },
  );

  const incompleteFetch = async url => {
    if (url.endsWith(`/releases/tags/${releaseTag}`)) {
      return jsonResponse(release(releaseTag, true));
    }
    const incompleteSupportedRelease = release(supportedTag, false);
    incompleteSupportedRelease.assets = incompleteSupportedRelease.assets.filter(
      asset => asset.name !== 'SHA256SUMS',
    );
    return jsonResponse(incompleteSupportedRelease);
  };
  await assert.rejects(
    verifyPublicReleaseChannel({
      apiBase: 'https://api.example.test/repos/durable-workflow/cli',
      fetchImpl: incompleteFetch,
      releaseTag,
    }),
    /missing required assets: SHA256SUMS/,
  );

  const unavailableFetch = async url => {
    if (url.endsWith(`/releases/tags/${releaseTag}`)) {
      return jsonResponse(release(releaseTag, true));
    }
    return jsonResponse({}, 404);
  };
  await assert.rejects(
    verifyPublicReleaseChannel({
      apiBase: 'https://api.example.test/repos/durable-workflow/cli',
      fetchImpl: unavailableFetch,
      releaseTag,
    }),
    /HTTP 404 fetching .*releases\/latest/,
  );
});

test('stable transition resolution requires stable public metadata', async () => {
  const fetchImpl = async url => {
    return jsonResponse(release('2.0.0', false));
  };

  const evidence = await verifyPublicReleaseChannel({
    apiBase: 'https://api.example.test/repos/durable-workflow/cli',
    fetchImpl,
    releaseTag: '2.0.0',
  });
  assert.equal(evidence.channel, 'stable');
  assert.equal(evidence.release_prerelease, false);
});

test('a new stable patch uses GitHub latest while the documentation pointer is older', async () => {
  const apiBase = 'https://api.example.test/repos/durable-workflow/cli';
  const calls = [];
  const fetchImpl = async url => {
    calls.push(url);
    if (url === `${apiBase}/releases/latest` || url === `${apiBase}/releases/tags/2.2.2`) {
      return jsonResponse(release('2.2.2', false));
    }
    if (url === 'https://durable-workflow.com/stable-releases.json') {
      return jsonResponse({schema: 'durable-workflow.docs.stable-releases', schema_version: 1, artifacts: {cli: '2.2.0'}});
    }
    return jsonResponse({}, 404);
  };
  const evidence = await verifyPublicReleaseChannel({apiBase, fetchImpl, releaseTag: '2.2.2'});
  assert.equal(evidence.channel_version, '2.2.2');
  assert.deepEqual(calls, [`${apiBase}/releases/latest`, `${apiBase}/releases/tags/2.2.2`]);
});

test('an explicitly older stable release validates against the newer actual stable channel', async () => {
  const fetchImpl = async url => jsonResponse(release(url.endsWith('/latest') ? '2.2.2' : '2.2.0', false));
  const evidence = await verifyPublicReleaseChannel({fetchImpl, releaseTag: '2.2.0'});
  assert.equal(evidence.channel_version, '2.2.2');
  assert.equal(evidence.release_tag, '2.2.0');
});
