'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');

const {
  AUTHORIZATION_SCHEMA,
  PRODUCT_OWNER_REVIEWER_ID,
  STABLE_VERSION,
  WORKFLOW_REF,
  digest,
  validateStableAuthorization,
} = require('./verify-stable-release-authorization');

const RELEASE_COMMIT = 'a'.repeat(40);
const CANDIDATE = 'current-2-0';
const AUTHORIZATION_TAG = `stable-authorization/2.0.0/${CANDIDATE}`;

function fixture() {
  const artifactTuple = {
    tag: `release-candidate/rc/${CANDIDATE}`,
    commit: 'b'.repeat(40),
    components: {
      cli: {version: '2.0.0-rc.14', commit: RELEASE_COMMIT},
    },
  };
  const request = {
    schema: 'request',
    stable_version: STABLE_VERSION,
    artifact_tuple: artifactTuple,
  };
  const readout = {schema: 'readout', evidence_gate: 'pass'};
  const contract = {schema: 'contract', stable_version: STABLE_VERSION};
  const runId = 12345;
  const runAttempt = 2;
  const authorization = {
    schema: AUTHORIZATION_SCHEMA,
    channel: 'stable',
    stable_version: STABLE_VERSION,
    artifact_tuple: artifactTuple,
    contract: {
      url: 'https://raw.githubusercontent.com/durable-workflow/.github/main/stable-authorization/contract.json',
      sha256: digest(contract),
    },
    request_sha256: digest(request),
    readout_sha256: digest(readout),
    evidence_gate: 'pass',
    decision: {
      status: 'authorized',
      type: 'protected-human-review',
      actor: 'release-operator',
      repository: 'durable-workflow/.github',
      workflow_ref: WORKFLOW_REF,
      workflow_commit: 'c'.repeat(40),
      run_id: runId,
      run_attempt: runAttempt,
      run_url: `https://github.com/durable-workflow/.github/actions/runs/${runId}`,
      environment: 'stable-authorization',
      environment_protection: {
        prevent_self_review: true,
        required_reviewer_user_ids: [PRODUCT_OWNER_REVIEWER_ID],
      },
      environment_approval: {
        state: 'approved',
        run_id: runId,
        run_attempt: runAttempt,
        user: {id: PRODUCT_OWNER_REVIEWER_ID},
      },
    },
  };

  return {authorization, contract, readout, request};
}

function validate(value = fixture()) {
  return validateStableAuthorization(value.authorization, {
    authorizationTag: AUTHORIZATION_TAG,
    contract: value.contract,
    readout: value.readout,
    releaseCommit: RELEASE_COMMIT,
    releaseVersion: STABLE_VERSION,
    request: value.request,
  });
}

test('stable 2.0 publication accepts exact protected authorization', () => {
  assert.equal(validate().stable_version, STABLE_VERSION);
});

test('stable publication rejects missing product-owner approval', () => {
  const value = fixture();
  value.authorization.decision.environment_approval.state = 'rejected';
  assert.throws(() => validate(value), /product-owner review evidence/);
});

test('stable publication rejects authorization for a different CLI commit', () => {
  const value = fixture();
  value.authorization.artifact_tuple.components.cli.commit = 'd'.repeat(40);
  assert.throws(() => validate(value), /does not match the release commit/);
});

test('stable publication rejects modified immutable evidence', () => {
  const value = fixture();
  value.request.evidence = {unexpected: true};
  assert.throws(() => validate(value), /request digest does not match/);
});
