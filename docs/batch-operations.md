# Resumable batch operations

`dw batch:cancel` and `dw batch:signal` act on every workflow a visibility
query selects, and survive interruption. Before anything is sent, the
selection is written to a **manifest**: a private file on your machine.
Each target's outcome is added to the manifest as it happens.
`dw batch:show` reads the manifest, and `dw batch:resume` continues it
without relisting and without resending anything that may already have
taken effect.

These commands are new in the Rust CLI. The 2.x `workflow:cancel
--all-matching` still works as before; it keeps its results only in memory.

## Start a batch

```bash
dw batch:cancel --query='customer-42' --reason=offboarded --manifest=cancel-42.jsonl
dw batch:signal approve --query='queue-7' -i '{"by":"ops"}' --manifest=approve-7.jsonl
```

- `--query` is a visibility query; `--type` and `--status` (default
  `running`) narrow it. At most `--limit` workflows are selected (default
  100, at most 1000).
- `--manifest` must name a **new** file. `dw` creates it readable only by you
  (mode 0600) and refuses a path that already exists.
- `dw` asks before sending, unless you pass `--yes`. With `--json` or
  `--output=json`, `--yes` is required.
- Targets are sent one at a time, at most `--rate` requests per second
  (default 10).

The manifest holds the Server URL, namespace, operation, its parameters (the
cancellation reason, or the signal name and its input exactly as sent) and
every selected target. It never holds your token. Keep it as long as you
need the record: it can contain workflow IDs and signal input.

### What is selected

Each workflow is pinned to the **run** its listing reported, and only that
run is cancelled or signalled. If the workflow continues as a new run in the
meantime, the successor is not touched. Visibility pages are not a
transactional snapshot: the manifest records what was seen while paging,
and resume never lists again.

Each target has its own command key, `batch-<manifest-id>-<index>`. Signals
send it as `request_id`, and resume sends the same key again. Cancellation
has no command-key field on the Server; the key is recorded so that every
target can be correlated.

## Target states

| State | Meaning | Resume |
|---|---|---|
| `acknowledged` | The Server accepted the command. | Not sent again. |
| `skipped` | Nothing to do: the run is closed or not found (404, 409, 410), or the listing gave no run ID. | Not sent again. |
| `refused` | A permanent refusal, such as invalid input or a request-ID conflict. | Not sent again. |
| `pressure` | The Server asked the client to slow down (429, or 503 with `Retry-After`). | Sent again with the same key, up to 3 attempts per target in total. |
| `unknown` | Sent, but `dw` does not know whether the Server admitted it: the response was lost, the Server failed (5xx), or `dw` stopped while waiting. | **Never sent again automatically.** |
| `pending` | Not sent yet, or refused before admission (401/403). | Sent. |

During a run, `dw` honours `Retry-After` (up to 60 seconds) and retries a
`pressure` target in place. It **stops the batch** at the first `unknown`
outcome, at an authentication refusal, or when a target runs out of attempts
under pressure. The remaining targets stay `pending` for a later resume.

The exit code is 0 when every target ended `acknowledged` or `skipped`, and 1
otherwise.

## Inspect a batch

```bash
dw batch:show cancel-42.jsonl
dw batch:show cancel-42.jsonl --json
```

`batch:show` reads only the file. It makes no network requests, so you can
run it anywhere the manifest is.

## Resume a batch

```bash
dw batch:resume cancel-42.jsonl
dw batch:resume cancel-42.jsonl --yes --json
```

Resume uses your normal connection settings. It refuses to run unless they
name the **same Server and namespace** the manifest recorded, and every
request is authenticated again. It sends only `pending` targets and
`pressure` targets with attempts left, in their original order, with their
original keys and parameters. Only one `dw` process can run a manifest at a
time.

## Resolving `unknown` targets

An `unknown` target may or may not have taken effect. Before you do anything
about it:

1. Look at the workflow: `dw workflow:describe <workflow-id> --run-id=<run-id>`.
   A cancelled run shows a closed status.
2. For a signal, check the history for the signal and its command receipt:
   `dw workflow:history <workflow-id> <run-id>`, and look for the target's
   `request_id` (`batch-<manifest-id>-<index>`).
3. If it did not take effect and you still want it, send it once by hand,
   for example `dw workflow:signal <workflow-id> <signal> --run-id=<run-id>
   --request-id=<the same key>`.

A request ID alone does not prove that repeating a signal is safe. Rely on
retained admission and intent-conflict guarantees only when the Server
advertises them; until it does, `dw` never resends an `unknown` target.

## Manifest format

The file is JSON Lines with schema `durable-workflow.cli.batch-manifest/v1`.

- **Line 1, the header:** `schema`, `manifest_id`, `created_at`,
  `cli_version`, `server`, `namespace`, `operation` (`cancel` or `signal`),
  `parameters`, `selection` (query, filters, limit and a note about
  snapshots) and `targets`. Each target has `index`, `workflow_id`, `run_id`
  and `command_key`.
- **Every later line is an event.** `{"event":"dispatching","index":n,"attempt":k,"at":…}`
  is written before a request leaves. `{"event":"outcome","index":n,"attempt":k,"state":…,"http_status":…,"reason":…,"message":…,"at":…}`
  is written after it returns.

Each line is synced to disk before `dw` continues. A target whose last event
is `dispatching` is `unknown`. A final line cut short by a crash is ignored
and removed on the next resume.
