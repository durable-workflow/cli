# Durable Workflow CLI

Command-line interface for running and interacting with the [Durable Workflow Server](https://github.com/durable-workflow/server).

## Installation

Three options depending on what you have installed:

**1. Standalone binary (no PHP required).** Download a native binary for your
platform from the [releases page](https://github.com/durable-workflow/cli/releases):

```bash
curl -fsSL -o durable-workflow \
  https://github.com/durable-workflow/cli/releases/latest/download/durable-workflow-linux-x86_64
chmod +x durable-workflow
./durable-workflow --version
```

Available assets: `durable-workflow-linux-x86_64`, `durable-workflow-linux-aarch64`,
`durable-workflow-macos-aarch64`.

Windows and macOS x86_64 standalone binaries are not currently produced.
Windows is blocked on an upstream PHP 8.4 + OpenSSL 3 compile bug in
static-php-cli; macOS x86_64 is dropped because the `macos-13` runner
label is not available to this org. On Windows, install a system PHP
(>= 8.4) and use the PHAR.

**2. PHAR (requires PHP >= 8.2).** Download `durable-workflow.phar` from the
[releases page](https://github.com/durable-workflow/cli/releases) and run it
with `php durable-workflow.phar` (or `chmod +x` and call directly — the PHAR
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

## Configuration

Set the server URL and auth token via environment variables:

```bash
export DURABLE_WORKFLOW_SERVER_URL=http://localhost:8080
export DURABLE_WORKFLOW_AUTH_TOKEN=your-token
export DURABLE_WORKFLOW_NAMESPACE=default
```

Or pass them as options to any command:

```bash
durable-workflow --server=http://localhost:8080 --token=your-token --namespace=production workflow:list
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
Use `durable-workflow server:info` to inspect the current canonical values,
rejected aliases, and removed fields advertised by the target server.

## Commands

### Server

```bash
# Check server health
durable-workflow server:health

# Show server version and capabilities
durable-workflow server:info

# Start a local development server
durable-workflow server:start-dev
durable-workflow server:start-dev --port=9090 --db=sqlite
```

### Workflows

```bash
# Start a workflow
durable-workflow workflow:start --type=order.process --input='{"order_id":123}'
durable-workflow workflow:start --type=order.process --workflow-id=order-123
durable-workflow workflow:start --type=order.process --execution-timeout=3600 --run-timeout=600

# List workflows
durable-workflow workflow:list
durable-workflow workflow:list --status=running
durable-workflow workflow:list --type=order.process

# Describe a workflow
durable-workflow workflow:describe order-123
durable-workflow workflow:describe order-123 --run-id=01HXYZ --json

# Send a signal
durable-workflow workflow:signal order-123 payment-received --input='{"amount":99.99}'

# Query workflow state
durable-workflow workflow:query order-123 current-status

# Send an update
durable-workflow workflow:update order-123 approve --input='{"approver":"admin"}'

# Cancel a workflow (workflow code can observe and clean up)
durable-workflow workflow:cancel order-123 --reason="Customer request"

# Terminate a workflow (immediate, no cleanup)
durable-workflow workflow:terminate order-123 --reason="Stuck workflow"

# View event history
durable-workflow workflow:history order-123 01HXYZ
durable-workflow workflow:history order-123 01HXYZ --follow
```

### Namespaces

```bash
# List namespaces
durable-workflow namespace:list

# Create a namespace
durable-workflow namespace:create staging --description="Staging environment" --retention=7

# Describe a namespace
durable-workflow namespace:describe staging

# Update a namespace
durable-workflow namespace:update staging --retention=14
```

### Schedules

```bash
# Create a schedule
durable-workflow schedule:create --workflow-type=reports.daily --cron="0 9 * * *"
durable-workflow schedule:create --schedule-id=daily-report --workflow-type=reports.daily --cron="0 9 * * *" --timezone=America/New_York

# List schedules
durable-workflow schedule:list

# Describe a schedule
durable-workflow schedule:describe daily-report

# Pause/resume
durable-workflow schedule:pause daily-report --note="Holiday freeze"
durable-workflow schedule:resume daily-report

# Trigger immediately
durable-workflow schedule:trigger daily-report

# Backfill missed runs
durable-workflow schedule:backfill daily-report --start-time=2024-01-01T00:00:00Z --end-time=2024-01-07T00:00:00Z

# Delete a schedule
durable-workflow schedule:delete daily-report
```

### Task Queues

```bash
# List task queues
durable-workflow task-queue:list

# Describe a task queue (pollers, backlog)
durable-workflow task-queue:describe default
```

### Activities

```bash
# Complete an activity externally
durable-workflow activity:complete TASK_ID --result='{"status":"done"}'

# Fail an activity externally
durable-workflow activity:fail TASK_ID --message="External service unavailable" --non-retryable
```

### System Operations

```bash
# Show task repair diagnostics
durable-workflow system:repair-status

# Run a task repair sweep
durable-workflow system:repair-pass

# Show expired activity timeout diagnostics
durable-workflow system:activity-timeout-status

# Run activity timeout enforcement sweep
durable-workflow system:activity-timeout-pass

# Target specific execution IDs
durable-workflow system:activity-timeout-pass --execution-id=EXEC_ID_1 --execution-id=EXEC_ID_2
```

## Global Options

| Option | Description |
|--------|-------------|
| `--server`, `-s` | Server URL (default: `$DURABLE_WORKFLOW_SERVER_URL` or `http://localhost:8080`) |
| `--namespace` | Target namespace (default: `$DURABLE_WORKFLOW_NAMESPACE` or `default`) |
| `--token` | Auth token (default: `$DURABLE_WORKFLOW_AUTH_TOKEN`) |

## License

MIT
