# Durable Workflow CLI

<p align="center">
  <a href="https://github.com/durable-workflow/cli/actions/workflows/build.yml?query=branch%3Amain"><img src="https://github.com/durable-workflow/cli/actions/workflows/build.yml/badge.svg?branch=main" alt="Build status"></a>
  <a href="https://sonarcloud.io/summary/new_code?id=durable-workflow_cli"><img src="https://sonarcloud.io/api/project_badges/measure?project=durable-workflow_cli&amp;metric=alert_status" alt="Quality Gate"></a>
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
native binaries, installer scripts, and a Homebrew formula. From 2.5.0 `dw`
is a native Rust binary and releases no longer include `dw.phar`; see the
[2.5.0 notes](docs/release-notes/2.5.0.md) to move off a PHAR install. The
2.3.x PHAR line receives security fixes only until **2027-02-28** and is
unsupported after that date.

```bash
dw --version
dw list
```

See the [distribution guide](docs/distribution.md) for exact-version installs,
direct downloads, checksum and attestation verification, Homebrew, and
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
dw workflow:update-outcome <workflow-id> <update-id> --wait=completed
dw workflow:cancel <workflow-id> --reason="Customer request"
dw workflow:history <workflow-id> <run-id>
dw service-call:describe <endpoint> <service> <operation> <call-id> --json
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

The released CLI is the Rust crate in [`rust/`](rust/README.md): build and
test it with `cargo`, and compare it with 2.x through its parity harness.
The PHP implementation below remains for the 2.3.x maintenance line, which
receives security fixes only until 2027-02-28.

```bash
composer install
composer test
make phar
```

Run `make help` for the complete local development surface.

The PHP maintenance standalone builder acquires SPC through the fixed upstream
release identities in [spc-artifact.py](scripts/ci/spc-artifact.py). It verifies
the compressed archive and extracted binary for all four recorded platforms,
and publishes caches atomically without replacing an existing file. Linux
execution requires Python 3, sealed memory descriptors and bubblewrap at
`/usr/bin/bwrap` with working unprivileged user namespaces. One sealed copy
supplies every download, build and combine call through a private read-only
mount, including the embedded PHP loader's self-reads. These controls run in
the existing Python parity test lane. The namespace helper must be owned by
root without setuid, setgid or file capabilities. The SPC process receives
only `PATH`, `HOME`, `TMPDIR`, `SOURCE_DATE_EPOCH` and fixed `LC_ALL=C`.

The legacy macOS standalone builder refuses execution. Use the supported native
Rust CLI or a qualified Linux legacy build. Native Rust CLI macOS releases are
unaffected. This binds the SPC component only. PHP, downloaded libraries, build
caches and each host platform still require their applicable toolchain
qualification.

The PHAR smoke caller has a separate [runtime profile](docker/php-ci/phar.ini)
and [launcher](scripts/ci/run-php-phar-container.sh). Its host controls and Box
artifact stay read-only. Only dependencies, build output, generated build
metadata and private runtime data are writable. The setup reuses the accepted
PHP image build source, then independently probes the PHAR settings and runs
the TLS and replacement controls. Source preparation alone does not qualify
the hosted caller or the separate reproducibility and legacy release paths.

Standalone release builds use the reviewed [dependency source policy](scripts/ci/standalone-dependencies.json).
The workflow verifies the OpenSSL archive before compilation and checks the
produced runtime after both cold builds and cache restores. Each release includes
per-platform `.dependencies.json` inventories in the normal checksum and
attestation set, recording PHP, OpenSSL, libcurl and available library versions.

## License

MIT
