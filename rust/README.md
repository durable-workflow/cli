# dw in Rust

This crate is the Rust implementation of `dw`, planned as CLI 3.0.0 (#72).
It keeps the 2.x command names, options, profile file, JSON envelopes and
exit codes, so existing scripts and profiles keep working.

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
parity/fetch-reference.sh /tmp/dw-reference
python3 parity/parity.py --reference /tmp/dw-reference --candidate target/debug/dw
```

Add `--server php=URL --server native=URL --token TOKEN` to also run the
Server cases against running Servers. A case can record an intentional
difference with a reason. One exists today: on native Server, `server:health`
reports boolean checks correctly where 2.x does not (#74).
