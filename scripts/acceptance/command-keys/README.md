# Installed CLI command-key experiments

These manual adapters exercise shared [#216](https://github.com/durable-workflow/.github/issues/216)
with the published CLI 2.8.0 binary and native Server 3.9.2, linux/amd64.
They build no package and use only disposable synthetic SQLite state. Run one
adapter at a time after the normal host-capacity/owner check. Docker runtime
limits are one CPU, 1 GiB and 256 PIDs, with a loopback-only ephemeral port.

The modern adapter covers admission, committed-response loss, explicit retry,
concurrency, Unicode limits, terminal runs and namespace fences. Its second
phase restarts the actual Server process on the same database and checks the
previously lost receipts and explicitly selected terminal runs. The legacy
adapter makes current-producer API admissions, stops that Server, transforms a
separate copy through the accepted helper, and checks legacy readback and
ambiguity refusals. **The synthetic transform is not a physical 3.8 upgrade.**
Physical upgrade input and the producer's accepted seed procedure have their
own source/qualification gate. Neither adapter claims another SDK's Worker
acceptance.

Prepare an empty, canonical directory owned by the invoking user, mode `0700`,
on disk-backed scratch. Put the checksum/attestation-verified published binary
at `bin/dw` inside it. Expected binary SHA256:
`da366c79c90978a3c0a92d723c66a96a2d0963f9dccbd41c8207f2c41848f814`.
The binary must be executable. The runtime image is pulled separately, without
registry credentials, using the exact index in `cli_216.py`. Independently
verify the index/platform manifest and source revision before execution; do
not equate an engine's image ID with the raw OCI config digest.

Stage these public, reviewed source files in the experiment directory:

| File | Accepted source | SHA256 |
| --- | --- | --- |
| `committed_response_loss.py` | `.github` commit `117118c83093822469b3824ba9587ff4068ed29f`, `conformance/command-keys/committed_response_loss.py` | `bd1e8ccbbf76f63fc2eafa278a023eb181d405b68d9c30016392e395a1eff725` |
| `sqlite_legacy.py` | `sdk-rust` commit `4add871b175517f2031f71c868ce278bbfbba486`, `scripts/acceptance/command-keys/sqlite_legacy.py` | `6fc6054ce087c136a86d7b6b222daeefaa87c58fa0b613b155a5df39e6a26fad` |
| `command-key-legacy-v1-vectors.json` | `server` commit `d66e33bfb5c76f94c5b202f8c9f7a9e8cd75cf1c`, `resources/platform-protocol-specs/command-key-legacy-v1-vectors.json` | `da1990000c611d7377690c7526487d6415fa4f9cb42d299f59585a7c2eebf769` |

The helper reads the adjacent manifest. Both are used only by `legacy_cli.py`;
the proxy is shared by both adapters. Their identities are checked before import.
Do not replace them with a working tree overlay.

Use a separately verified installed official Python SDK 2.17.1 environment only
for Avro fixture encoding/decoding. Its PyPI wheel SHA256 is
`90040cb28906985b227988815b1b53aa3079378673e35c689392ac71edaf7b35`.
Check installed package files against that wheel and retain module-origin and
dependency evidence before using its Python executable. This is a codec
fixture, not Python Worker qualification. Generate `worker-envelopes.json` in
the private experiment directory with that interpreter:

```python
import json
from durable_workflow import serializer

print(json.dumps({
    "empty": serializer.envelope(None, codec="avro"),
    "one": serializer.envelope(1, codec="avro"),
}))
```

Run the source from this directory with isolated host Python. Supply an
absolute verified codec interpreter path in `CLI_216_CODEC_PYTHON` and the
private directory in `CLI_216_WORKDIR`. Provide a clean environment without
cloud, GitHub, Composer or registry credentials. The adapters create synthetic
runtime authentication privately and remove its environment file on exit.

```bash
env -i PATH=/usr/bin:/bin \
  CLI_216_WORKDIR="$experiment" \
  CLI_216_PROXY_SOURCE="$experiment/committed_response_loss.py" \
  CLI_216_CODEC_PYTHON="$codec_python" \
  timeout 180s python3 -I -B scripts/acceptance/command-keys/cli_216.py
```

For the restart phase, preserve the first phase's `admissions.json` as
`modern-admissions.json`, preserve its result/wire records separately, then run
the same command with `CLI_216_PHASE=selected-restart`. Preserve that phase's
records separately too. `CLI_216_PHASE=fresh-selected` adds direct fresh
explicit-run literal-key markers (the loss cells intentionally withhold those
initial response bodies). Use a **new** private
directory for `legacy_cli.py`, with the same staged inputs and environment.
The legacy adapter generates its own admissions and stopped-copy database.

An outer experiment owner must also enforce the timeout and remove only the
exact owned container if the process is interrupted. Names are
`cli-216-392-modern` and `cli-216-392-legacy`; remove their anonymous volumes
too, and verify absence. No listener or production service is retained.

Keep generated databases, response bodies and invocation records private and
bounded in retention. Publish sanitized tuple/source hashes, timestamps,
scenario outcomes, snapshot fingerprints, nonpasses and cleanup to #216.
The wire fixture records only metadata and hashes. A script exit alone does
not classify a failed assertion as a product defect: preserve the first result
and diagnose runner/source/cleanup failures explicitly. A later corrected run
does not erase it. The adapters are manual evidence producers, with no workflow
activation, release authority, task scheduler or automatic retry service.
