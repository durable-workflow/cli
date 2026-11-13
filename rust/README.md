# dw in Rust

This crate is the Rust implementation of `dw` (#72). It will ship as a 2.x
minor release, because it keeps the 2.x command names, options, profile
file, JSON envelopes and exit codes, so existing scripts and profiles keep
working. A 2.x version also stays inside the CLI range that PHP Server 2.x
advertises.

It ships as `dw` from 2.5.0, replacing the PHP implementation; every 2.x
command is ported.

## Status

| Area | State |
|---|---|
| Option parsing (2.x rules), grouped aliases (`dw schedule list`) | Ported |
| Command lookup: aliases, abbreviations, "Did you mean", namespace listings, `list` | Ported, from the 2.x catalog |
| `--help`, `-h` and `dw help <command>` for all 2.x commands | Ported, from the 2.x catalog |
| Profiles: file, location, permissions, resolution order | Ported |
| Output modes, error envelope, next steps, exit codes | Ported |
| `env:list`, `env:set`, `env:use`, `env:show`, `env:delete` | Ported |
| `server:health`, `server:info` (and `cluster:info`) | Ported |
| Compatibility preflight, session and `--version` warnings, exit 8 refusals | Ported |
| `server:start-dev`: local Laravel launch, Compose prerequisites and Unix signal cleanup | Ported |
| `runtime:check`: native build identity and compilation target | Ported, intentional output/help difference |
| `doctor`: redacted connection sources, server metadata, warnings and next steps | Ported |
| `workflow:list`: one namespace/page, filters, Server envelopes, JSON and JSONL | Ported |
| `workflow:list-runs`: one workflow response page, envelope/cursor, JSON and JSONL | Ported |
| `namespace:list`, `namespace:describe`, `namespace:create`, `namespace:update`, `namespace:delete`: namespace configuration and lifecycle, JSON and JSONL | Ported |
| `workflow:describe`, `workflow:show-run`: one execution or run, `--follow`, JSON | Ported |
| `workflow:history`: every page, `--follow` long-poll, JSON and streamed JSONL | Ported |
| `workflow:history-export`: the Server bundle to stdout or `--output-file` | Ported |
| `schedule:list`, `schedule:describe`, `schedule:history`, `schedule:create`, `schedule:update`, `schedule:delete`, `schedule:pause`, `schedule:resume`, `schedule:trigger`, `schedule:backfill`: JSON and JSONL, `--input`/`--input-file`/`--input-encoding` | Ported |
| `workflow:cancel` (one run, or a bounded `--all-matching` batch pinned to each selected run), `workflow:terminate`, `workflow:archive`, `workflow:redrive`, `workflow:repair`, `workflow:migrate-v1` | Ported |
| `workflow:query`, `workflow:start` (`--wait` with JSON wait diagnostics), `workflow:signal`, `workflow:update`: one request each, command keys, update diagnostics | Ported |
| `search-attribute:list`, `search-attribute:create`, `search-attribute:delete`, `storage:test`, `namespace:set-storage-driver` | Ported |
| `task-queue:list`, `task-queue:describe`, `task-queue:build-ids`, `task-queue:drain`, `task-queue:promote`, `task-queue:resume`: admission, leases, pollers, build-id rollout | Ported |
| `worker:list`, `worker:describe`, `worker:register`, `worker:deregister`: `worker:register` checks the worker-protocol response version as 2.x does | Ported |
| `activity:list`, `activity:describe`, `activity:complete`, `activity:fail`: standalone activities; complete and fail speak the worker protocol | Ported |
| `workflow-task:poll`, `workflow-task:complete`, `workflow-task:fail`, `workflow-task:history`, `query-task:poll`, `query-task:complete`, `query-task:fail`: worker-protocol diagnostics, one request each | Ported |
| `system:repair-status`, `system:repair-pass`, `system:retention-status`, `system:retention-pass`, `system:activity-timeout-status`, `system:activity-timeout-pass`, `system:operator-metrics`: each `*-pass` is one request; failed entries exit 1 | Ported |
| `service-call:describe`, `bridge:webhook`: one GET; one POST with the 2.x target/correlation/input checks before any request | Ported |
| `debug workflow` (with the cancellation cascade view) and `watch workflow`: every poll is one read; `watch` exits 0 on completed, 1 on another terminal state and 7 at `--max-polls` | Ported |
| `--tls-verify=false` against HTTPS: no certificate or hostname checks, as in 2.x, plus a stderr warning on every such command (also with `--json` and `-q`) | Ported, intentional addition |
| `batch:cancel`, `batch:signal`, `batch:update`, `batch:show`, `batch:resume`: resumable bulk operations with a private manifest and an optional `--deadline` ([runbook](../docs/batch-operations.md), cli#60). `parity/live_batch.py` runs them against the published Servers in CI | Rust-only, new |
| `task-queue:pause-activities`, `task-queue:resume-activities`, `task-queue:activity-dispatch`: pause, resume and read activity dispatch on one task queue (Server #577), one request each after checking cluster info for `capabilities.task_queue_activity_dispatch_pause`; `task-queue:describe` and `task-queue:list` show the state when the Server reports it | Rust-only, new |
| `workflow:update-outcome`: read an update's outcome later by its update ID (Server #1131, durable-workflow/.github#212), one GET after checking cluster info for `capabilities.update_outcome_reads`; pending, completed (including a null result), failed (exit 2), rejected and `update_not_found` (exit 5) are reported distinctly, and the update is never sent again. `workflow:update` points an unfinished update at it when the Server advertises the capability | Rust-only, new |

Server calls go through the published `durable-workflow` crate, pinned to
the latest qualified release.

`workflow:list-runs` sends its workflow ID as one percent-encoded SDK path
segment. IDs containing slashes, query delimiters or fragments stay within
that segment; they cannot select another route. Empty IDs and `.` or `..`
are refused with exit 2 before the runs request. This deliberately replaces
the PHP command's interpolation of the ID into the request path.

The command returns one Server response and leaves any `next_page_token`
unconsumed. JSON preserves that envelope and cursor; JSONL emits only the
returned records. Successful responses must identify the requested workflow
and `list_runs` operation. Explicit row workflow IDs must match; omitted IDs
in the published PHP Server shape inherit the verified envelope without
adding fields. Contradictory identities or operations are refused.

`workflow:describe`, `workflow:show-run`, `workflow:history` and
`workflow:history-export` send workflow and run IDs the same way, each as one
encoded path segment, and refuse empty, `.` and `..` IDs before any request.
Human output prints Server values as text, never as console markup.

`workflow:cancel`, `terminate`, `archive`, `redrive`, `repair` and
`migrate-v1` make one request per target and refuse redirects. A lost
response is reported as a transport failure and is never submitted again.
`workflow:cancel --all-matching` selects at most `--limit` workflows from the
visibility list, pins each to the run the list reported, and cancels that run
only, never the workflow's current run, which may be a successor. A workflow
listed without a run ID is reported, not cancelled. A repeated page token, or
a page that adds nothing new, stops the selection before any cancellation.
Without `--yes` the confirmation is asked on stderr and one answer line is
read from stdin, as in 2.x; `-n` or end of input declines.

`workflow:query`, `workflow:start`, `workflow:signal` and `workflow:update`
send exactly one request and never resend it. Signals and updates carry a
caller command key (`request_id`): `--request-id`, or one generated per
invocation as `cli-signal-<32 hex>` or `cli-update-<32 hex>`. When the response
is lost or the Server fails (exit 3, 7 or 6), the outcome is unknown: the error
names the key, and the next steps say to inspect workflow history and command
receipts before retrying with the same key. These commands follow PHP main's
command-key contract (#61); published 2.3.5 had no `--request-id` on
`workflow:signal`, so the catalog adds it as a reviewed update.

Namespace visibility requests make one target attempt and refuse redirects. Use
the actual Server URL; a 3xx response is reported as a refusal.

Namespace lifecycle requests make one target attempt and refuse HTTP redirects.
Use the actual Server URL; a redirect is not reported as a completed creation,
update or deletion. A lost response remains uncertain and is not automatically
submitted again.

Schedule commands follow the same rules: one request per history page or
mutation, no redirect followed, and no automatic resubmission after a lost
response. The schedule ID is sent as one percent-encoded path segment, so IDs
containing `/`, `?` or `#` cannot select another route. Empty IDs and `.` or
`..` are refused with exit 2 before the request.

`--input` values are decoded with PHP's JSON rules and error messages, including
the 511-level nesting limit, integers past 64 bits becoming floats, and `null`
meaning no input. Input PHP decodes but cannot send, such as `1e400` or invalid
UTF-8 from `--input-encoding=raw|base64`, is refused with exit 3 after the
compatibility check, without a request, as 2.x reports it.

## Build and test

Requires Rust 1.86 or newer.

```bash
cargo test --locked
cargo build --locked
```

The compatibility checks (`src/compat`) are also compared with the 2.x PHP
classes they port, case by case, while those classes are in this
repository:

```bash
php parity/compat-vectors.php ../src ../tests/fixtures src/compat/fixtures target/php-vectors.json
DW_COMPAT_PHP_VECTORS=target/php-vectors.json cargo test --locked -- --ignored
```

## Parity with 2.x

`parity/parity.py` runs every case in `parity/cases.json` once with the 2.x
CLI and once with this build, each in a fresh config directory. It compares:

- exit codes;
- stdout, with JSON compared as data after normalizing times, IDs, the
  config path and transport error text;
- stderr as normalized text (the same normalizations), plus its category;
- the config file each case leaves behind.

The manifest case records exact reference and candidate hashes for reviewed
additive schema changes. Only those two values at the named command's `sha256`
field are normalized. Other hashes, manifest metadata and formatting still compare.

```bash
parity/fetch-reference.sh      # pinned 2.x CLI, checksum-verified, into target/
cargo build
python3 parity/parity.py       # --build release, --report, --verbose
```

Behaviour merged on PHP main after 2.3.5, such as signal and update command
keys (#61), is compared against PHP main built from this repository's source.
Cases marked `"reference": "php-main"` use it, and so does `--help` for
`workflow:signal`. Set `DW_PARITY_PHP_MAIN` to a checkout with
`composer install --no-dev` done. Set `DW_PARITY_PHP` to the PHP to run it
with: the default is `php`, and `parity/php-docker.sh` runs the local
`php:8.4-cli` image instead. The source run reports this build's version,
commit and build date. Without `DW_PARITY_PHP_MAIN` those cases fail; they
never fall back to 2.3.5. CI's `rust` job runs them.

```bash
DW_PARITY_PHP_MAIN=.. DW_PARITY_PHP=parity/php-docker.sh python3 parity/parity.py
```

Cases with a `mock` entry run against a loopback Server that serves the
published PHP 2.11.8 cluster info with the case's patch applied, so
compatibility warnings and refusals are compared without a broken Server.

Server cases run both CLIs against the published Servers pinned in
`parity/servers.json` (PHP 2.11.8, native 3.5.0 and native 3.0.5). `parity/start-servers.sh`
checks each Compose file's SHA-256, starts it with the image pinned by digest,
waits until it is healthy and prints the `DW_PARITY_SERVERS` value;
`parity/start-servers.sh down` removes them. CI runs these cases in the
`rust-live-parity` job:

```bash
export DW_PARITY_TOKEN=parity-token
export DW_PARITY_SERVERS="$(parity/start-servers.sh)"
python3 parity/parity.py --server-cases-only
parity/start-servers.sh down
```

Cases that create state use `{unique}`, a fresh ULID-shaped value per run,
so the reference and candidate touch different workflows; it normalizes to
`<id>` like other IDs. A case's `normalize_fields` masks values derived
from that input, such as a Server's request fingerprint.

`src/catalog.json` is the 2.x command surface (names, aliases, usage lines,
option definitions and help text), generated from the pinned reference by
`parity/update-catalog.sh`. Lookup, listings, help pages and usage errors
read it, so they match 2.x even for commands not ported yet. The harness
also checks `--help` for every catalog command.

Commands with no 2.x equivalent (the `batch:*` commands, cli#60, the task
queue activity dispatch commands, Server #577, and `workflow:update-outcome`,
Server #1131) are added from
`parity/catalog-additions.json` and marked `rust_only`. The harness
announces them, does not compare their `--help`, and removes only them from
the candidate's listings and completions before comparing, so the rest of
each listing is still checked against 2.x. A namespace header is removed
only when every command in it is Rust-only, so `task-queue` stays.

Catalog regeneration applies the reviewed description/help changes in
`parity/catalog-updates.json`. Comparisons reconcile only those exact fields
on descriptor surfaces; command-result JSON and unrelated definitions remain
unchanged. The run-list help describes one response page instead of promising
that every run has been retrieved.

A case can record an intentional difference with a reason and the exact
fields it excuses; any other differing field still fails. One exists today:
2.x prompts "Do you want to run …?" on stdout even when stdin is not a
terminal; this build prompts only on a terminal.
