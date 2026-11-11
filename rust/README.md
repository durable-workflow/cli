# dw in Rust

This crate is the Rust implementation of `dw` (#72). It will ship as a 2.x
minor release, because it keeps the 2.x command names, options, profile
file, JSON envelopes and exit codes, so existing scripts and profiles keep
working. A 2.x version also stays inside the CLI range that PHP Server 2.x
advertises.

It is not released yet. The 2.x PHP CLI remains the supported `dw` until
every command group reaches parity.

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
| Remaining command groups (activities, worker-plane tasks, system, `service-call`, `bridge`, `debug`, `watch`) | Next slices ([gap list](https://github.com/durable-workflow/cli/issues/72#issuecomment-6801944950)) |
| `--tls-verify=false` against HTTPS: no certificate or hostname checks, as in 2.x, plus a stderr warning on every such command (also with `--json` and `-q`) | Ported, intentional addition |

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

Cases with a `mock` entry run against a loopback Server that serves the
published PHP 2.11.8 cluster info with the case's patch applied, so
compatibility warnings and refusals are compared without a broken Server.

To also run the Server cases, start the published Servers pinned in
`parity/servers.json`, set `DW_PARITY_SERVERS` to them (for example
`php=http://127.0.0.1:18080,native=http://127.0.0.1:18081`) and
`DW_PARITY_TOKEN` to their auth token.

`src/catalog.json` is the 2.x command surface (names, aliases, usage lines,
option definitions and help text), generated from the pinned reference by
`parity/update-catalog.sh`. Lookup, listings, help pages and usage errors
read it, so they match 2.x even for commands not ported yet. The harness
also checks `--help` for every catalog command.

Catalog regeneration applies the reviewed description/help changes in
`parity/catalog-updates.json`. Comparisons reconcile only those exact fields
on descriptor surfaces; command-result JSON and unrelated definitions remain
unchanged. The run-list help describes one response page instead of promising
that every run has been retrieved.

A case can record an intentional difference with a reason and the exact
fields it excuses; any other differing field still fails. One exists today:
2.x prompts "Do you want to run …?" on stdout even when stdin is not a
terminal; this build prompts only on a terminal.
