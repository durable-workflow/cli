# Platform Conformance — `dw` CLI Claim

The `dw` CLI participates in the public platform conformance suite
specified by [`durable-workflow.github.io/static/platform-conformance-contract.json`](https://durable-workflow.github.io/platform-conformance-contract.json),
schema `durable-workflow.v2.platform-conformance.suite`, version `27`,
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
`signal_query_runtime_contract`, `workflow_update_runtime_contract`,
`search_attribute_runtime_contract`, `schedules_runtime_contract`,
`namespace_runtime_contract`,
`child_workflow_runtime_contract`, `saga_runtime_contract`,
`worker_versioning_runtime_contract`, `migration_runtime_contract`,
`skew_refusal_matrix_contract`, `principal_attribution_contract`, and
`cli_json_envelopes`.
The CLI-owned fixtures below are the source for
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
| `signal_query_runtime_contract` | `durable-workflow.github.io/static/platform-conformance/signal-query-runtime-scenarios.json` (served at `/platform-conformance/signal-query-runtime-scenarios.json`) | stable, suite version `27`, manifest version `3` |
| `workflow_update_runtime_contract` | `durable-workflow.github.io/static/platform-conformance/workflow-update-runtime-scenarios.json` (served at `/platform-conformance/workflow-update-runtime-scenarios.json`) | stable, suite version `27`, manifest version `1` |
| `search_attribute_runtime_contract` | `durable-workflow.github.io/static/platform-conformance/search-attribute-runtime-scenarios.json` (served at `/platform-conformance/search-attribute-runtime-scenarios.json`) | stable, suite version `27`, manifest version `1` |
| `schedules_runtime_contract` | `durable-workflow.github.io/static/platform-conformance/schedules-runtime-scenarios.json` (served at `/platform-conformance/schedules-runtime-scenarios.json`) | stable, suite version `27`, manifest version `3` |
| `namespace_runtime_contract` | `durable-workflow.github.io/static/platform-conformance/namespace-runtime-scenarios.json` (served at `/platform-conformance/namespace-runtime-scenarios.json`) | stable, suite version `27`, manifest version `1` |
| `child_workflow_runtime_contract` | `durable-workflow.github.io/static/platform-conformance/child-workflow-runtime-scenarios.json` (served at `/platform-conformance/child-workflow-runtime-scenarios.json`) | stable, suite version `27`, manifest version `1` |
| `saga_runtime_contract` | `durable-workflow.github.io/static/platform-conformance/saga-runtime-scenarios.json` (served at `/platform-conformance/saga-runtime-scenarios.json`) | stable, suite version `27`, manifest version `1` |
| `worker_versioning_runtime_contract` | `durable-workflow.github.io/static/platform-conformance/worker-versioning-runtime-scenarios.json` (served at `/platform-conformance/worker-versioning-runtime-scenarios.json`) | stable, suite version `27`, manifest version `1` |
| `migration_runtime_contract` | `durable-workflow.github.io/static/platform-conformance/migration-runtime-scenarios.json` (served at `/platform-conformance/migration-runtime-scenarios.json`) | stable, suite version `27`, manifest version `1` |
| `skew_refusal_matrix_contract` | `durable-workflow.github.io/static/platform-conformance/skew-refusal-matrix-scenarios.json` (served at `/platform-conformance/skew-refusal-matrix-scenarios.json`) | stable, suite version `27`, manifest version `1` |
| `principal_attribution_contract` | `durable-workflow.github.io/static/platform-conformance/principal-attribution-scenarios.json` (served at `/platform-conformance/principal-attribution-scenarios.json`) | stable, suite version `27`, manifest version `1` |

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
public suite keeps that runtime category required in version `27`, and
the namespace scenario manifest is the stable source for evaluating
namespace parity against published artifacts.

Schedule commands and JSON output are covered through
`schedules_runtime_contract` when the CLI creates or observes
schedules, lists and describes them, and exercises pause, resume,
trigger, and delete through `dw schedules` lifecycle commands.

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
runs. The public suite defines that runtime category in version `27`, and
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

Migration runtime and version-skew refusal scenarios are covered through
`migration_runtime_contract` and `skew_refusal_matrix_contract` when the
CLI observes migrated resources, emits compatible JSON during migration,
and refuses unsupported artifact skew with machine-readable diagnostics.

Principal attribution scenarios are covered through
`principal_attribution_contract` when the CLI surfaces server-derived
principal identity in workflow history and operator diagnostics without
accepting spoofed caller-provided identity fields.

Workflow update runtime diagnostics are covered through
`workflow_update_runtime_contract` when `workflow:update --json` surfaces
the update state, request identifier, outcome or reason, request payload,
result or error details, and history references for accepted, completed,
failed, and refused update paths. `workflow:describe --json` also preserves
the server-published `commands[]` and `updates[]` rows so operator tools can
inspect update state after the original request has returned.

## Release gate

A release of the `dw` CLI must produce a passing harness result
document before tag, with the conformance level at `full` or
`provisional` (provisional categories enumerated in release notes).

| Field | Value |
| --- | --- |
| Required claimed targets | `cli_json_client` |
| Required suite version | public docs-site manifest `durable-workflow.v2.platform-conformance.suite` version `27` |
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
- Schedules runtime scenarios: <https://durable-workflow.github.io/platform-conformance/schedules-runtime-scenarios.json>
- Namespace runtime scenarios: <https://durable-workflow.github.io/platform-conformance/namespace-runtime-scenarios.json>
- Child-workflow runtime scenarios: <https://durable-workflow.github.io/platform-conformance/child-workflow-runtime-scenarios.json>
- Saga runtime scenarios: <https://durable-workflow.github.io/platform-conformance/saga-runtime-scenarios.json>
- Worker-versioning runtime scenarios: <https://durable-workflow.github.io/platform-conformance/worker-versioning-runtime-scenarios.json>
- Migration runtime scenarios: <https://durable-workflow.github.io/platform-conformance/migration-runtime-scenarios.json>
- Skew-refusal matrix scenarios: <https://durable-workflow.github.io/platform-conformance/skew-refusal-matrix-scenarios.json>
- Principal attribution scenarios: <https://durable-workflow.github.io/platform-conformance/principal-attribution-scenarios.json>
- Workflow update runtime scenarios: <https://durable-workflow.github.io/platform-conformance/workflow-update-runtime-scenarios.json>
- Public docs page: <https://durable-workflow.github.io/docs/2.0/compatibility>
- Polyglot parity doc:
  <https://durable-workflow.github.io/docs/polyglot/cli-python-parity>
- Existing per-repo gate: `scripts/check-sdk-python-parity.sh`.
