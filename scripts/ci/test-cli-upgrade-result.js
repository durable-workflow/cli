'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const {verifyUpgradeResult} = require('./verify-cli-upgrade-result');

test('release qualification accepts a normal forward upgrade in both modes', () => {
  const base = {
    current_version: '2.0.0-rc.9',
    direction: 'upgrade',
    target_version: '2.0.0-rc.10',
  };

  assert.equal(verifyUpgradeResult({
    channelVersion: '2.0.0-rc.10',
    evidence: {...base, status: 'dry-run'},
    mode: 'dry-run',
    releaseVersion: '2.0.0-rc.9',
  }).relation, 'forward-upgrade');
  assert.equal(verifyUpgradeResult({
    channelVersion: '2.0.0-rc.10',
    evidence: {...base, status: 'upgraded'},
    mode: 'apply',
    releaseVersion: '2.0.0-rc.9',
  }).relation, 'forward-upgrade');
});

test('release qualification accepts a channel no-op in both modes', () => {
  for (const mode of ['dry-run', 'apply']) {
    assert.equal(verifyUpgradeResult({
      channelVersion: '2.0.0',
      evidence: {
        current_version: '2.0.0',
        status: 'noop',
        target_version: '2.0.0',
      },
      mode,
      releaseVersion: '2.0.0',
    }).relation, 'noop');
  }
});

test('release qualification accepts an already-newer binary only when no downgrade is prepared', () => {
  for (const mode of ['dry-run', 'apply']) {
    assert.equal(verifyUpgradeResult({
      channelVersion: '2.0.0-rc.12',
      evidence: {
        current_version: '2.0.0-rc.33',
        reason: 'no change was made',
        status: 'newer',
        target_version: '2.0.0-rc.12',
      },
      mode,
      releaseVersion: '2.0.0-rc.33',
    }).relation, 'already-newer');
  }
});

test('release qualification rejects advertised or performed default downgrades', () => {
  assert.throws(
    () => verifyUpgradeResult({
      channelVersion: '2.0.0-rc.12',
      evidence: {
        current_version: '2.0.0-rc.33',
        status: 'dry-run',
        target_version: '2.0.0-rc.12',
      },
      mode: 'dry-run',
      releaseVersion: '2.0.0-rc.33',
    }),
    /expected newer/,
  );
  assert.throws(
    () => verifyUpgradeResult({
      channelVersion: '2.0.0-rc.12',
      evidence: {
        current_version: '2.0.0-rc.33',
        direction: 'downgrade',
        status: 'downgraded',
        target_version: '2.0.0-rc.12',
      },
      mode: 'apply',
      releaseVersion: '2.0.0-rc.33',
    }),
    /expected newer/,
  );
});

test('release qualification uses numeric prerelease ordering', () => {
  assert.equal(verifyUpgradeResult({
    channelVersion: '2.0.0-beta.9',
    evidence: {
      current_version: '2.0.0-beta.10',
      status: 'newer',
      target_version: '2.0.0-beta.9',
    },
    mode: 'dry-run',
    releaseVersion: '2.0.0-beta.10',
  }).relation, 'already-newer');
});
