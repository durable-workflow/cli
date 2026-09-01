# Durable Workflow CLI

<p align="center">
  <a href="https://github.com/durable-workflow/cli/actions/workflows/build.yml?query=branch%3Amain"><img src="https://github.com/durable-workflow/cli/actions/workflows/build.yml/badge.svg?branch=main" alt="Build status"></a>
  <a href="https://github.com/durable-workflow/cli/releases/latest"><img src="https://img.shields.io/github/v/release/durable-workflow/cli?sort=semver" alt="Latest release"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/durable-workflow/cli" alt="MIT license"></a>
</p>

`dw` is the command-line interface for Durable Workflow Cloud and self-hosted
Durable Workflow Server. Use it to start and inspect workflows, send signals
and updates, manage schedules and namespaces, diagnose workers and task queues,
and automate operations with stable JSON output.

## Install

The standalone binary does not require PHP.

```bash
# Linux and macOS
curl -fsSL https://durable-workflow.com/install.sh | sh
```

```powershell
# Windows
irm https://durable-workflow.com/install.ps1 | iex
```

The installers verify the downloaded binary against the release
`SHA256SUMS` manifest. Releases also include GitHub artifact attestations,
native binaries, `dw.phar`, installer scripts, and a Homebrew formula.

```bash
dw --version
dw list
```

See the [distribution guide](docs/distribution.md) for exact-version installs,
direct downloads, checksum and attestation verification, Homebrew, PHAR, and
reproducible builds.

## Connect

Named profiles keep a runtime URL, namespace, token source, TLS policy, and
output preference together.

```bash
# Self-hosted Server
dw env:set local \
  --server=http://localhost:8080 \
  --namespace=default \
  --make-default

# Durable Workflow Cloud namespace
dw env:set cloud \
  --server="$DURABLE_WORKFLOW_RUNTIME_URL" \
  --namespace="$DURABLE_WORKFLOW_NAMESPACE" \
  --token-env=DURABLE_WORKFLOW_CLIENT_TOKEN

dw doctor --env=local
dw server:info --env=cloud
```

An unknown profile fails instead of silently falling back to another runtime.
Literal tokens are redacted from normal profile output; prefer `--token-env`
so credentials remain outside the config file.

## Run a Workflow

Start a worker for the same namespace and task queue, then run:

```bash
dw workflow:start \
  --type=orders.checkout \
  --task-queue=orders \
  --workflow-id="order-$(date +%s)" \
  --input='{"order_id":"123"}' \
  --wait \
  --json
```

Common operations:

```bash
dw workflow:list --status=running
dw workflow:describe <workflow-id> --json
dw workflow:signal <workflow-id> payment-received --input='{"amount":99.99}'
dw workflow:query <workflow-id> current-status --json
dw workflow:update <workflow-id> approve --input='{"approver":"operator"}'
dw workflow:cancel <workflow-id> --reason="Customer request"
dw workflow:history <workflow-id> <run-id>
```

## Automation Contract

All read and mutating commands support machine-readable output. Use
`--output=json` for one response or `--output=jsonl` for line-oriented records.

```bash
dw workflow:list --output=json | jq '.workflows[].workflow_id'
dw schedule:list --output=jsonl
dw schema:list
dw schema:show workflow:list > workflow-list.schema.json
```

Exit codes distinguish usage, network, authentication, not-found, server,
timeout, and compatibility failures. Published JSON Schema manifests define
the patch-stable response envelopes.

## Reference

- [CLI guide](https://durable-workflow.com/docs/2.0/polyglot/cli/)
- [Command reference](https://durable-workflow.com/docs/2.0/polyglot/cli-reference/)
- [Complete repository reference](docs/cli-reference.md)
- [Distribution and verification](docs/distribution.md)
- [Component conformance](docs/conformance.md)
- [Durable Workflow Server](https://github.com/durable-workflow/server)

## Development

```bash
composer install
composer test
make phar
```

Run `make help` for the complete local development surface.

## License

MIT
