# Platform Conformance — `dw` CLI Claim

The `dw` CLI participates in the public platform conformance suite
specified by [`durable-workflow.github.io/static/platform-conformance-contract.json`](https://durable-workflow.github.io/platform-conformance-contract.json),
schema `durable-workflow.v2.platform-conformance.suite`, version `12`,
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
`signal_query_runtime_contract`, `search_attribute_runtime_contract`,
`namespace_runtime_contract`, `child_workflow_runtime_contract`,
`saga_runtime_contract`, `worker_versioning_runtime_contract`, and
`cli_json_envelopes`. The CLI-owned fixtures below are the source for
the control-plane and JSON-envelope categories. The stable runtime
categories are sourced from the public scenario manifests named in the
suite.

## Fixture sources served by this repo

| Category | Source path | Status |
| --- | --- | --- |
| `control_plane_request_response` | `tests/fixtures/control-plane/` | stable, parity-shared with `sdk-python` |
| `cli_json_envelopes` | `tests/fixtures/control-plane/`, `schemas/` | stable |
| `worker_task_lifecycle` (CLI input side) | `tests/fixtures/external-task/`, `tests/fixtures/external-task-input/` | stable |

## Runtime scenario sources consumed by this claim

| Category | Source path | Status |
| --- | --- | --- |
| `signal_query_runtime_contract` | `durable-workflow.github.io/static/platform-conformance/signal-query-runtime-scenarios.json` (served at `/platform-conformance/signal-query-runtime-scenarios.json`) | stable, suite version `12` |
| `search_attribute_runtime_contract` | `durable-workflow.github.io/static/platform-conformance/search-attribute-runtime-scenarios.json` (served at `/platform-conformance/search-attribute-runtime-scenarios.json`) | stable, suite version `12` |
| `namespace_runtime_contract` | `durable-workflow.github.io/static/platform-conformance/namespace-runtime-scenarios.json` (served at `/platform-conformance/namespace-runtime-scenarios.json`) | stable, suite version `12` |
| `child_workflow_runtime_contract` | `durable-workflow.github.io/static/platform-conformance/child-workflow-runtime-scenarios.json` (served at `/platform-conformance/child-workflow-runtime-scenarios.json`) | stable, suite version `12` |
| `saga_runtime_contract` | `durable-workflow.github.io/static/platform-conformance/saga-runtime-scenarios.json` (served at `/platform-conformance/saga-runtime-scenarios.json`) | stable, suite version `12` |
| `worker_versioning_runtime_contract` | `durable-workflow.github.io/static/platform-conformance/worker-versioning-runtime-scenarios.json` (served at `/platform-conformance/worker-versioning-runtime-scenarios.json`) | stable, suite version `12` |

The fixtures in this repo are exercised today by:

- `scripts/check-sdk-python-parity.sh`
- the `sdk-python-parity` job in `.github/workflows/build.yml`

Local command tests also exercise CLI signal/query JSON behavior, but
implementation tests are not stable fixture sources for
`signal_query_runtime_contract`. The public conformance harness reads
runtime categories from the scenario manifests above and records results
against published artifacts.

Namespace commands, `--namespace` scoping, JSON namespace context, and
default-scope behavior are covered by `namespace_runtime_contract`. The
public suite keeps that runtime category required in version `12`, and
the namespace scenario manifest is the stable source for evaluating
namespace parity against published artifacts.

The CLI's namespace-scoped workflow, schedule, search-attribute, task-queue,
and worker visibility outputs include the effective namespace in human and JSON
modes.
Namespace resource commands expose both the server resource `name` and the
operator-facing `namespace` field in JSON output, so CRUD responses carry the
same namespace context as scoped workflow, schedule, and search-attribute
commands.
List and history commands also attach the same namespace to each JSON/JSONL
item so line-oriented automation keeps scope after the top-level envelope is
dropped.
Omitting `--namespace` resolves to the configured/default namespace and still
sends a single `X-Namespace` header; it does not request a cross-namespace
aggregate.

Child workflow commands and JSON output are covered through
`child_workflow_runtime_contract` when the CLI starts, observes,
cancels, or queries parent workflows that orchestrate child workflow
runs. The public suite defines that runtime category in version `12`, and
the child-workflow scenario manifest is the stable source for evaluating
same-language, cross-language, cancellation, replay, fan-out, and
namespace behavior against published artifacts.

Saga compensation commands and JSON output are covered through
`saga_runtime_contract` when the CLI starts, observes, queries, and
exports runs that are compensating or have failed during compensation.
The saga scenario manifest is the stable source for evaluating reverse
compensation order, retry idempotence, compensation failure visibility,
typed compensation errors, and operator-visible in-progress compensation
state against published artifacts.

Worker build-ID and rollout commands are covered through
`worker_versioning_runtime_contract` when the CLI lists workers, inspects
task-queue build IDs, drains or resumes worker cohorts, and describes
workflow runs pinned to compatible workers.

## Release gate

A release of the `dw` CLI must produce a passing harness result
document before tag, with the conformance level at `full` or
`provisional` (provisional categories enumerated in release notes).

| Field | Value |
| --- | --- |
| Required claimed targets | `cli_json_client` |
| Required suite version | public docs-site manifest `durable-workflow.v2.platform-conformance.suite` version `12` |
| CI job | `platform-conformance` (lands when the harness reference implementation publishes; until then `sdk-python-parity` covers CLI-owned fixture parity) |
| Block on `nonconforming` | yes |
| Artifact attached to release | harness result document, schema `durable-workflow.v2.platform-conformance.result` |

A `nonconforming` result blocks the release. A failure in a provisional
category emits a warning and does not block.

## Cross-references

- Authority spec: <https://durable-workflow.github.io/docs/2.0/platform-conformance>
- Authority manifest: <https://durable-workflow.github.io/platform-conformance-contract.json>
- Signal/query runtime scenarios: <https://durable-workflow.github.io/platform-conformance/signal-query-runtime-scenarios.json>
- Search-attribute runtime scenarios: <https://durable-workflow.github.io/platform-conformance/search-attribute-runtime-scenarios.json>
- Namespace runtime scenarios: <https://durable-workflow.github.io/platform-conformance/namespace-runtime-scenarios.json>
- Child-workflow runtime scenarios: <https://durable-workflow.github.io/platform-conformance/child-workflow-runtime-scenarios.json>
- Saga runtime scenarios: <https://durable-workflow.github.io/platform-conformance/saga-runtime-scenarios.json>
- Worker-versioning runtime scenarios: <https://durable-workflow.github.io/platform-conformance/worker-versioning-runtime-scenarios.json>
- Public docs page: <https://durable-workflow.github.io/docs/2.0/compatibility>
- Polyglot parity doc:
  <https://durable-workflow.github.io/docs/polyglot/cli-python-parity>
- Existing per-repo gate: `scripts/check-sdk-python-parity.sh`.
