# Platform Conformance — `dw` CLI Claim

The `dw` CLI participates in the platform conformance suite specified
in [`workflow/docs/architecture/platform-conformance-suite.md`](https://github.com/durable-workflow/workflow/blob/v2/docs/architecture/platform-conformance-suite.md)
and mirrored by `Workflow\V2\Support\PlatformConformanceSuite`. This
document is the per-repo claim: it lists the conformance targets the
CLI claims, the fixtures it serves, and the release gate that blocks
publication when conformance is broken.

## Claimed targets

The CLI claims one target from the suite's matrix:

- `cli_json_client` — implements the `--output=json` and
  `--output=jsonl` envelopes that automation, agents, and operator
  scripts depend on. Covers the `cli_json` surface family.

## Fixture sources served by this repo

| Category | Source path | Status |
| --- | --- | --- |
| `control_plane_request_response` | `tests/fixtures/control-plane/` | stable, parity-shared with `sdk-python` |
| `cli_json_envelopes` | `tests/fixtures/control-plane/`, `schemas/` | stable |
| `worker_task_lifecycle` (CLI input side) | `tests/fixtures/external-task/`, `tests/fixtures/external-task-input/` | stable |

The fixtures in this repo are exercised today by:

- `scripts/check-sdk-python-parity.sh`
- the `sdk-python-parity` job in `.github/workflows/build.yml`

These are the per-repo gates that already enforce the contract; the
public conformance harness, when it lands, will read the same fixtures
from this repo's declared paths.

## Release gate

A release of the `dw` CLI must produce a passing harness result
document before tag, with the conformance level at `full` or
`provisional` (provisional categories enumerated in release notes).

| Field | Value |
| --- | --- |
| Required claimed targets | `cli_json_client` |
| Required suite version | `PlatformConformanceSuite::VERSION` (currently `1`) |
| CI job | `platform-conformance` (lands when the harness reference implementation publishes; until then `sdk-python-parity` covers the same ground) |
| Block on `nonconforming` | yes |
| Artifact attached to release | harness result document, schema `durable-workflow.v2.platform-conformance.result` |

A `nonconforming` result blocks the release. A failure in a provisional
category emits a warning and does not block.

## Cross-references

- Authority spec: `workflow/docs/architecture/platform-conformance-suite.md`
- Authority manifest class: `Workflow\V2\Support\PlatformConformanceSuite`
- Public docs page: <https://durable-workflow.github.io/docs/2.0/compatibility>
- Polyglot parity doc:
  <https://durable-workflow.github.io/docs/polyglot/cli-python-parity>
- Existing per-repo gate: `scripts/check-sdk-python-parity.sh`.
