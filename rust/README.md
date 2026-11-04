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
| Profiles: file, location, permissions, resolution order | Ported |
| Output modes, error envelope, next steps, exit codes | Ported |
| `env:list`, `env:set`, `env:use`, `env:show`, `env:delete` | Ported |
| `server:health` | Ported |
| Compatibility preflight and `server:info` | Next slice |
| Workflow, namespace and schedule commands | Next slices |
| `--tls-verify=false` against HTTPS | Refused until durable-workflow/sdk-rust#180 |

Server calls go through the published `durable-workflow` crate, pinned to
the latest qualified release.

## Build and test

Requires Rust 1.86 or newer.

```bash
cargo test --locked
cargo build --locked
```

## Parity with 2.x

`parity/parity.py` runs every case in `parity/cases.json` once with the 2.x
CLI and once with this build, each in a fresh config directory. It compares:

- exit codes;
- stdout, with JSON compared as data after normalizing times, IDs, the
  config path and transport error text;
- the stderr category;
- the config file each case leaves behind.

```bash
parity/fetch-reference.sh      # pinned 2.x CLI, checksum-verified, into target/
cargo build
python3 parity/parity.py       # --build release, --report, --verbose
```

To also run the Server cases, set `DW_PARITY_SERVERS` to the running
Servers (for example `php=http://127.0.0.1:18080,native=http://127.0.0.1:18081`)
and `DW_PARITY_TOKEN` to their auth token. A case can record an intentional
difference with a reason. One exists today: on native Server, `server:health`
reports boolean checks correctly where 2.x does not (#74).
