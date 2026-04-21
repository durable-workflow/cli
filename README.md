# Durable Workflow CLI

Command-line interface for running and interacting with the [Durable Workflow Server](https://github.com/durable-workflow/server).

## Installation

Three options depending on what you have installed:

**1. Standalone binary (no PHP required).** The easiest path — a one-liner
installer that detects your OS and arch:

```bash
# Linux and macOS
curl -fsSL https://durable-workflow.com/install.sh | sh
```

```powershell
# Windows
irm https://durable-workflow.com/install.ps1 | iex
```

The installers download the release `SHA256SUMS` manifest and verify the
binary checksum before writing `dw` into the install directory.

Or download a native binary directly from the [releases
page](https://github.com/durable-workflow/cli/releases). Available assets:
`dw-linux-x86_64`, `dw-linux-aarch64`,
`dw-macos-aarch64`, `dw-windows-x86_64.exe`.

macOS x86_64 standalone binaries are not currently produced because the
`macos-13` runner label is not available to this org; Intel Mac users can
run the PHAR with a system PHP.

**2. PHAR (requires PHP >= 8.2).** Download `dw.phar` from the
[releases page](https://github.com/durable-workflow/cli/releases) and run it
with `php dw.phar` (or `chmod +x` and call directly — the PHAR
has a `#!/usr/bin/env php` shebang).

**3. Composer.**

```bash
composer global require durable-workflow/cli
```

### Building from source

```bash
make phar      # Build the PHAR (requires PHP >= 8.2 and Composer)
make binary    # Build the PHAR plus a standalone native binary for the
               # current platform (downloads Box and static-php-cli on demand)
make clean     # Remove build artifacts
```

Build artifacts land in `./build/`. See [scripts/build.sh](scripts/build.sh)
for the underlying steps; tools are cached under `build/.tools/`.

### Live Server Smoke Test

Unit tests use mocked HTTP clients. To verify the packaged `dw` entrypoint
against a real server, start a local Durable Workflow server first, then run:

```bash
make smoke-server
```

By default the smoke test targets `http://localhost:8080` with no token. Override
the target and credentials when needed:

```bash
DURABLE_WORKFLOW_CLI_SMOKE_SERVER_URL=http://localhost:18082 \
DURABLE_WORKFLOW_CLI_SMOKE_ADMIN_TOKEN=admin-token \
DURABLE_WORKFLOW_CLI_SMOKE_OPERATOR_TOKEN=operator-token \
DURABLE_WORKFLOW_CLI_SMOKE_WORKER_TOKEN=worker-token \
make smoke-server
```

The smoke path creates a disposable namespace, starts and inspects a workflow,
reads its history, registers a diagnostic worker, polls and completes the
workflow task through the worker protocol, creates and deletes a paused
schedule, and terminates a second cleanup workflow.

## Configuration

For day-to-day work, create named environment profiles. Profiles keep the
server URL, namespace, token source, TLS verification mode, and default output
format together so commands do not drift between shell aliases:

```bash
dw env:set dev --server=http://localhost:8080 --namespace=default --make-default
dw env:set prod --server=https://api.example.com --namespace=orders --token-env=PROD_DW_TOKEN --profile-output=json
dw env:list
dw env:show prod
```

Profiles are stored in `~/.config/dw/config.json` by default, or
`$XDG_CONFIG_HOME/dw/config.json` when `XDG_CONFIG_HOME` is set. Set
`DW_CONFIG_HOME` to point `dw` at a separate config directory.

Profile selection is explicit and typo-safe:

```bash
dw env:use dev
DW_ENV=prod dw workflow:list
dw --env=prod workflow:list
```

Unknown names passed through `--env`, `DW_ENV`, or `dw env:use` fail instead
of falling back to another target. Literal token values are redacted by
`env:list` and `env:show` unless `--show-token` is passed; prefer
`--token-env=NAME` so secrets stay out of the config file.

For one-off automation, set the server URL and auth token via environment
variables:

```bash
export DURABLE_WORKFLOW_SERVER_URL=http://localhost:8080
export DURABLE_WORKFLOW_AUTH_TOKEN=your-token
export DURABLE_WORKFLOW_NAMESPACE=default
```

Or pass them as options to any command:

```bash
dw --server=http://localhost:8080 --token=your-token --namespace=production workflow:list
```

The CLI targets control-plane contract version `2` automatically via
`X-Durable-Workflow-Control-Plane-Version: 2` and expects canonical v2
response fields such as `*_name` and `wait_for`. Non-canonical legacy aliases
such as `signal` and `wait_policy` are rejected.

The server also emits a nested `control_plane.contract` document with schema
`durable-workflow.v2.control-plane-response.contract`, version `1`, and
`legacy_field_policy: reject_non_canonical`. The CLI validates that nested
boundary before trusting the server-emitted `legacy_fields`,
`required_fields`, and `success_fields` metadata.

For request fields such as `workflow:start --duplicate-policy` and
`workflow:update --wait`, the CLI now reads the server-published
`control_plane.request_contract` manifest from `GET /api/cluster/info` before
sending the command. Supported servers publish schema
`durable-workflow.v2.control-plane-request.contract`, version `1`, with an
`operations` map. The CLI treats missing or unknown request-contract
schema/version metadata as a compatibility error instead of silently guessing.
Use `dw server:info` to inspect the current canonical values,
rejected aliases, and removed fields advertised by the target server.
Use `dw doctor` when you need the full resolved local/remote diagnostic state:
CLI build identity, selected server/namespace/profile, redacted token source,
TLS verification mode, `/api/cluster/info`, and any version-skew warning.

## Shell Completion

Generate shell completion scripts with the built-in `completion` command:

```bash
dw completion bash
dw completion zsh
dw completion fish
```

For ad-hoc use, evaluate the generated script in your current shell:

```bash
eval "$(dw completion bash)"
```

For persistent installation, write the script to a shell-specific completion
location, or source it from your shell startup file. The completion endpoint
suggests command names, option names, and stable values for enum-like fields
such as workflow status, duplicate policy, update wait policy, schedule overlap
policy, worker status, search attribute type, and local dev database driver.

## Compatibility

CLI version 0.1.x is compatible with servers that advertise
`control_plane.version: "2"` and
`control_plane.request_contract.schema: durable-workflow.v2.control-plane-request.contract`
version `1` from `GET /api/cluster/info`.

The top-level server `version` is build identity only. The CLI validates the
protocol manifests on first invocation and raises a clear error if incompatible:

```bash
$ dw workflow:list
Server compatibility error: unsupported control_plane.version [3]; dw CLI 0.1.x requires control_plane.version 2.
Upgrade the server or use a compatible CLI version.
```

See the [Version Compatibility](https://durable-workflow.github.io/docs/2.0/compatibility) documentation for the full compatibility matrix across all components.

## Commands

### Server

```bash
# Check server health
dw server:health

# Show server version and capabilities
dw server:info

# Diagnose the resolved connection and compatibility state
dw doctor
dw doctor --env=prod --output=json

# Start a local development server
dw server:start-dev
dw server:start-dev --port=9090 --db=sqlite
```

### Workflows

```bash
# Start a workflow
dw workflow:start --type=order.process --input='{"order_id":123}'
dw workflow:start --type=order.process --input-file=payload.json
dw workflow:start --type=order.process --input='b3BhcXVlLWlk' --input-encoding=base64
dw workflow:start --type=order.process --workflow-id=order-123
dw workflow:start --type=order.process --execution-timeout=3600 --run-timeout=600

# List workflows
dw workflow:list
dw workflow:list --status=running
dw workflow:list --type=order.process

# Describe a workflow
dw workflow:describe order-123
dw workflow:describe order-123 --run-id=01HXYZ --json

# Watch a long-running workflow and print state changes
dw watch workflow order-123
dw watch workflow order-123 --run-id=01HXYZ --interval=5 --max-polls=60

# Send a signal
dw workflow:signal order-123 payment-received --input='{"amount":99.99}'

# Query workflow state
dw workflow:query order-123 current-status

# Send an update
dw workflow:update order-123 approve --input='{"approver":"admin"}'

# Cancel a workflow (workflow code can observe and clean up)
dw workflow:cancel order-123 --reason="Customer request"
dw workflow:cancel --all-matching='customer-42' --yes --reason="Customer request"

# Terminate a workflow (immediate, no cleanup)
dw workflow:terminate order-123 --reason="Stuck workflow"

# View event history
dw workflow:history order-123 01HXYZ
dw workflow:history order-123 01HXYZ --follow
```

### Namespaces

```bash
# List namespaces
dw namespace:list

# Create a namespace
dw namespace:create staging --description="Staging environment" --retention=7

# Describe a namespace
dw namespace:describe staging

# Update a namespace
dw namespace:update staging --retention=14
```

### Schedules

```bash
# Create a schedule
dw schedule:create --workflow-type=reports.daily --cron="0 9 * * *"
dw schedule:create --workflow-type=reports.daily --cron="0 9 * * *" --input-file=payload.json
dw schedule:create --schedule-id=daily-report --workflow-type=reports.daily --cron="0 9 * * *" --timezone=America/New_York

# List schedules
dw schedule:list

# Describe a schedule
dw schedule:describe daily-report

# Pause/resume
dw schedule:pause daily-report --note="Holiday freeze"
dw schedule:resume daily-report

# Trigger immediately
dw schedule:trigger daily-report

# Backfill missed runs
dw schedule:backfill daily-report --start-time=2024-01-01T00:00:00Z --end-time=2024-01-07T00:00:00Z

# Delete a schedule
dw schedule:delete daily-report
```

### Task Queues

```bash
# List task queues
dw task-queue:list

# Describe a task queue (pollers, backlog)
dw task-queue:describe default
```

### Worker Protocol Diagnostics

```bash
# Register a diagnostic worker identity
dw worker:register cli-worker --task-queue=orders --workflow-type=orders.Checkout

# Poll and lease one workflow task
dw workflow-task:poll cli-worker --task-queue=orders --json

# Complete the leased workflow task with a JSON workflow result
dw workflow-task:complete TASK_ID ATTEMPT --lease-owner=cli-worker --complete-result='{"ok":true}'
```

### Activities

```bash
# Complete an activity externally
dw activity:complete TASK_ID ATTEMPT_ID --input='{"status":"done"}'
dw activity:complete TASK_ID ATTEMPT_ID --input-file=result.json

# Fail an activity externally
dw activity:fail TASK_ID ATTEMPT_ID --message="External service unavailable" --non-retryable
```

Input-accepting commands use the same payload flags everywhere:
`--input` for inline values, `--input-file` for a file path or `-` for stdin,
and `--input-encoding=json|raw|base64` with `json` as the default.

### System Operations

```bash
# Show task repair diagnostics
dw system:repair-status

# Run a task repair sweep
dw system:repair-pass

# Show expired activity timeout diagnostics
dw system:activity-timeout-status

# Run activity timeout enforcement sweep
dw system:activity-timeout-pass

# Target specific execution IDs
dw system:activity-timeout-pass --execution-id=EXEC_ID_1 --execution-id=EXEC_ID_2
```

## Global Options

| Option | Description |
|--------|-------------|
| `--server`, `-s` | Server URL (default: `$DURABLE_WORKFLOW_SERVER_URL` or `http://localhost:8080`) |
| `--env` | Named profile to use (overrides `$DW_ENV` and `dw env:use`; hard-fails if missing) |
| `--namespace` | Target namespace (default: `$DURABLE_WORKFLOW_NAMESPACE` or `default`) |
| `--token` | Auth token (default: `$DURABLE_WORKFLOW_AUTH_TOKEN`) |

## Exit Codes

The CLI uses a stable exit-code policy so scripts and CI pipelines can react
to specific failure modes without parsing stderr. Values follow Symfony
Console's canonical `0`/`1`/`2` for success / failure / usage, and extend
from there:

| Code | Name | Meaning |
|------|------|---------|
| 0 | `SUCCESS` | Operation completed successfully. |
| 1 | `FAILURE` | Generic failure — command ran but did not succeed. |
| 2 | `INVALID` | Invalid usage — bad arguments, unknown options, or local validation. Also returned for HTTP 4xx responses that are not covered below (e.g. 400, 422). |
| 3 | `NETWORK` | Could not reach the server (connection refused, DNS failure, TLS handshake failure, transport error). |
| 4 | `AUTH` | Authentication or authorization failure. Returned for HTTP `401` and `403`. |
| 5 | `NOT_FOUND` | Resource not found. Returned for HTTP `404`. |
| 6 | `SERVER` | Server error. Returned for HTTP `5xx`. |
| 7 | `TIMEOUT` | Request timed out before the server responded. Also returned for HTTP `408`. |

Example:

```bash
dw workflow:describe chk-does-not-exist
echo $?  # 5 (NOT_FOUND)

dw server:health --server=http://unreachable:9999
echo $?  # 3 (NETWORK)
```

Exit codes are defined in [`DurableWorkflow\Cli\Support\ExitCode`](src/Support/ExitCode.php)
and are covered by [`tests/Commands/ExitCodePolicyTest.php`](tests/Commands/ExitCodePolicyTest.php).

## JSON Output

Every list, describe, read, and mutating command supports `--json` for
machine-readable output. Mutating commands (POST/PUT/DELETE) return the
server's raw response body when `--json` is set, making them safe to pipe
into `jq` or feed into downstream tooling.

```bash
# Read surface — stable even when no --json flag is passed for list views.
dw workflow:list --json | jq '.workflows[].workflow_id'

# Mutating surface — capture server response for idempotent automation.
wf_id=$(dw workflow:start --type=orders.Checkout --json | jq -r '.workflow_id')
dw workflow:signal "$wf_id" approve --json | jq '.command_status'
```

The CLI publishes patch-stable JSON Schema files for every `--json` response
and for `workflow:history-export` replay bundles. PHAR and standalone binary
builds bundle the schema catalog under `schemas/output/`; operators can inspect
the embedded catalog without unpacking the artifact:

```bash
dw schema:list
dw schema:manifest | jq '.commands["workflow:list"]'
dw schema:show workflow:list > workflow-list.schema.json
```

Schemas are additive across patch releases: new optional fields may appear, but
required top-level fields and their basic types stay stable.

## License

MIT
