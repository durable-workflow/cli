# Platform Conformance — `dw` CLI Claim

The `dw` CLI participates in the public platform conformance suite
specified by [`durable-workflow.github.io/static/platform-conformance-contract.json`](https://durable-workflow.github.io/platform-conformance-contract.json),
schema `durable-workflow.v2.platform-conformance.suite`, version `4`,
and documented at the public
[Platform Conformance Suite](https://durable-workflow.github.io/docs/2.0/platform-conformance)
authority page. This document is the per-repo claim: it lists the
conformance targets the CLI claims, the fixtures it serves, and the
release gate that blocks publication when conformance is broken.

## Claimed targets

The CLI claims one target from the suite's matrix:

- `cli_json_client` — implements the `--output=json` and
  `--output=jsonl` envelopes that automation, agents, and operator
  scripts depend on. Covers the `cli_json` surface family.

The authority row for `cli_json_client` requires
`control_plane_request_response` (request side),
`signal_query_runtime_contract`, and `cli_json_envelopes`. The CLI-owned
fixtures below are the source for the control-plane and JSON-envelope
categories. The stable signal/query runtime category is sourced from
the public scenario manifest named in the suite.

## Fixture sources served by this repo

| Category | Source path | Status |
| --- | --- | --- |
| `control_plane_request_response` | `tests/fixtures/control-plane/` | stable, parity-shared with `sdk-python` |
| `cli_json_envelopes` | `tests/fixtures/control-plane/`, `schemas/` | stable |
| `worker_task_lifecycle` (CLI input side) | `tests/fixtures/external-task/`, `tests/fixtures/external-task-input/` | stable |

## Runtime scenario sources consumed by this claim

| Category | Source path | Status |
| --- | --- | --- |
| `signal_query_runtime_contract` | `durable-workflow.github.io/static/platform-conformance/signal-query-runtime-scenarios.json` (served at `/platform-conformance/signal-query-runtime-scenarios.json`) | stable, suite version `4` |

The fixtures in this repo are exercised today by:

- `scripts/check-sdk-python-parity.sh`
- the `sdk-python-parity` job in `.github/workflows/build.yml`

Local command tests also exercise CLI signal/query JSON behavior, but
implementation tests are not stable fixture sources for
`signal_query_runtime_contract`. The public conformance harness reads
that category from the scenario manifest above and records results
against published artifacts.

## Release gate

A release of the `dw` CLI must produce a passing harness result
document before tag, with the conformance level at `full` or
`provisional` (provisional categories enumerated in release notes).

| Field | Value |
| --- | --- |
| Required claimed targets | `cli_json_client` |
| Required suite version | public docs-site manifest `durable-workflow.v2.platform-conformance.suite` version `4` |
| CI job | `platform-conformance` (lands when the harness reference implementation publishes; until then `sdk-python-parity` covers CLI-owned fixture parity) |
| Block on `nonconforming` | yes |
| Artifact attached to release | harness result document, schema `durable-workflow.v2.platform-conformance.result` |

A `nonconforming` result blocks the release. A failure in a provisional
category emits a warning and does not block.

## Cross-references

- Authority spec: <https://durable-workflow.github.io/docs/2.0/platform-conformance>
- Authority manifest: <https://durable-workflow.github.io/platform-conformance-contract.json>
- Signal/query runtime scenarios: <https://durable-workflow.github.io/platform-conformance/signal-query-runtime-scenarios.json>
- Public docs page: <https://durable-workflow.github.io/docs/2.0/compatibility>
- Polyglot parity doc:
  <https://durable-workflow.github.io/docs/polyglot/cli-python-parity>
- Existing per-repo gate: `scripts/check-sdk-python-parity.sh`.
