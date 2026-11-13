# Installed CLI command-key experiments

These manual adapters exercise shared [#216](https://github.com/durable-workflow/.github/issues/216)
with the published CLI 2.8.0 binary and native Server 3.9.2, linux/amd64.
They build no package and use only disposable synthetic SQLite state. Run one
adapter at a time after the normal host-capacity/owner check. Docker runtime
limits are one CPU, 1 GiB and 256 PIDs, with a loopback-only ephemeral port.

The modern adapter covers admission, committed-response loss, explicit retry,
concurrency, Unicode limits, terminal runs and namespace fences. Its typed phase
covers int64, null, Unicode, reordered maps, integer/float and signed-zero intent. Its second
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

The complete-snapshot helper and manifest are used by both adapters. Its
transform is used only by `legacy_cli.py`. The proxy is shared too. Inputs must
be canonical, owned, single-link regular files, with no group/world write bits.
The runner holds their descriptors, executes/imports sealed or in-memory checked
bytes and rechecks identities after use. Do not use working tree overlays or
symlink/hardlink staging. Container operations use the exact ID returned by the
runner's creation call; an existing or reused foreign name grants no authority.

Prepare the official-codec fixture with a separately verified installed Python
SDK 2.17.1. Its PyPI wheel SHA256 is
`90040cb28906985b227988815b1b53aa3079378673e35c689392ac71edaf7b35`.
Compare installed package files against that wheel and retain module-origin and
dependency evidence. This is codec preparation, not Python Worker qualification.
Write `worker-envelopes.json` in the experiment directory using that verified
interpreter and the following code (no trailing newline):

```python
import hashlib
import importlib.metadata
import json
from pathlib import Path
from durable_workflow import serializer

Path("worker-envelopes.json").write_text(json.dumps({
    "empty": serializer.envelope(None, codec="avro"),
    "one": serializer.envelope(1, codec="avro"),
    "serializer_sha256": hashlib.sha256(Path(serializer.__file__).read_bytes()).hexdigest(),
    "sdk_version": importlib.metadata.version("durable-workflow"),
    "fastavro_version": importlib.metadata.version("fastavro"),
}))
```

Use official fastavro 1.13.1 for this frozen fixture. Expected file SHA256 is
`68dac0826e251d0450d458224fa3d4d8714aba8e432df7e241909b8c4b877767`.
The runner holds these exact bytes and compares the actual retained Avro result
with this known fixture. It never executes an external codec program or accepts
its self-reported decoded result.

Obtain the outer bootstrap from the frozen reviewed Git commit, independently
verify its SHA256 from the owning review receipt, then pass those literal bytes
to isolated Python with `-c`. Do not execute any dispatcher or bootstrap by a
host pathname. The bootstrap obtains the dispatcher from the same frozen Git
object and checks its separately fixed SHA256 before compile/exec. Host-path
substitution of the outer dispatcher cannot replace those Git bytes.

```bash
reviewed_source_commit=FULL_REVIEWED_COMMIT_FROM_THE_PR
bootstrap="$(git --no-replace-objects show "$reviewed_source_commit:scripts/acceptance/command-keys/git_bootstrap.py")"$'\n'
expected_bootstrap_sha256=4b34ceb7b8c1366bc46405310bc4779df6a3ee63eb274af41868265028a5bc12
actual_bootstrap_sha256="$(printf '%s' "$bootstrap" | sha256sum | awk '{print $1}')"
test "$actual_bootstrap_sha256" = "$expected_bootstrap_sha256"
```

Run from that repository with the private directory in
`CLI_216_WORKDIR`. Provide a clean environment without cloud, GitHub, Composer
or registry credentials. Runtime authentication is synthetic and private.

```bash
env -i PATH=/usr/bin:/bin \
  CLI_216_WORKDIR="$experiment" \
  CLI_216_PROXY_SOURCE="$experiment/committed_response_loss.py" \
  timeout 180s python3 -I -B -c "$bootstrap" "$reviewed_source_commit"
```

For the restart phase, preserve the first phase's `admissions.json` as
`modern-admissions.json`, preserve its result/wire records separately, then run
the same command with `CLI_216_PHASE=selected-restart`. Preserve that phase's
records separately too. `CLI_216_PHASE=fresh-selected` adds direct fresh
explicit-run literal-key markers (the loss cells intentionally withhold those
initial response bodies). `CLI_216_PHASE=typed` runs the separate typed-intent
cases. Use a **new** private
directory with `CLI_216_RUNNER=legacy`, with the same staged inputs and environment.
The legacy adapter generates its own admissions and stopped-copy database.

An outer experiment owner must also enforce the timeout and remove only the
exact created container ID if the process is interrupted. Names are
`cli-216-392-modern` and `cli-216-392-legacy`; remove their anonymous volumes
too, and verify that ID is absent. Never remove a container by a reused name.
No listener or production service is retained.

Keep generated databases, response bodies and invocation records private and
bounded in retention. Publish sanitized tuple/source hashes, timestamps,
scenario outcomes, snapshot fingerprints, nonpasses and cleanup to #216.
The wire fixture records only metadata and hashes. A script exit alone does
not classify a failed assertion as a product defect: preserve the first result
and diagnose runner/source/cleanup failures explicitly. A later corrected run
does not erase it. The adapters are manual evidence producers, with no workflow
activation, release authority, task scheduler or automatic retry service.

The offline boundary controls use no Docker, network or credentials:

```bash
CLI_216_TEST_HELPER="$experiment/sqlite_legacy.py" \
  python3 -I -B scripts/acceptance/command-keys/test_boundaries.py -v
```

They cover executable/module substitution, symlink/hardlink and same-byte inode
replacement, a forged codec, sealed-content writes, foreign-name survival and
schema-only mutations that the predecessor's row-only comparison accepted.
Modern and legacy zero-write checks use the same bounded typed logical snapshot,
including catalog SQL, columns, indexes, triggers, views and all table rows.

## Physical 3.8 upgrade

`run_cli_216.py` verifies and holds literal-hash modern, legacy or physical implementation bytes
before executing any implementation code. Its reported runner digest comes
from those checked bytes, with a post-cleanup identity recheck. Direct execution
of the implementation refuses before effectful initialization. It consumes the
producer-owned real 3.8 API seed after the
[accepted handoff](https://github.com/durable-workflow/server/pull/1210#issuecomment-6860138558).
It does not call the synthetic transform or seed a replacement baseline.
The accepted fixture contract is Server commit
`a09f156399416216d2f097c257a2164f83c7eda7`. The original seed actually executed
commit `09a4ccda3da11853606af031a8970bbde4b2d6ec`, driver SHA256
`01c0fa1251ac3c809723e58d71817881fe2c5c9498579bc4c2e6097324a3618b`.
Keep these two provenance meanings distinct.

Use another fresh private directory with the common staged inputs above and:

| Input | Source/identity |
| --- | --- |
| `CLI_216_PHYSICAL_BASELINE` | Absolute original stopped-backup path supplied by its owner; SHA256 `7d1826f7681342bb3a5974509648e2e8d75e7a21d262f65f3ec1f58727ccbc0c` |
| `CLI_216_PHYSICAL_PACKET` | Absolute original seed JSON path supplied by its owner; SHA256 `8c728ce827df3a11621dfb0e82cdd404bbab48784182a5e92c8d632d49047fc5` |
| `physical-contract.json` | The legacy manifest at accepted Server `a09f1563`; SHA256 `ceb4695643e48c7bfe3f81eea380c6ab9aa0b973da830cb552d070344047319f` |
| `0013_command_intents.sql` | `rust/migrations/sqlite/0013_command_intents.sql` at published Server source `5614274d2629d03f92932a07dfee0fb10349ddd9`; SHA256 `fc3ad1730682c09704027dcbabc17cd6ded9c642a3ff20d7988d5f4b4a5726fd` |

The original backup and packet are held read-only and rechecked after use.
Only a newly created owned copy is mounted into the published 3.9.2 image.
The image runs its actual `schema-upgrade` command, requiring SQLite 12 → 13.
The comparison preserves all old business rows and schema objects, allowing
only tagged DDL plus explicitly validated schema-version/journal metadata.
The intent table must be empty. The new journal checksum, description,
version, success, timestamp interval and exact integer-zero execution time are checked.

```bash
env -i PATH=/usr/bin:/bin \
  CLI_216_WORKDIR="$experiment" \
  CLI_216_PROXY_SOURCE="$experiment/committed_response_loss.py" \
  CLI_216_RUNNER=physical \
  CLI_216_PHYSICAL_BASELINE="$original_backup" \
  CLI_216_PHYSICAL_PACKET="$original_packet" \
  timeout 180s python3 -I -B -c "$bootstrap" "$reviewed_source_commit"
```

Created names are `cli-216-392-upgrade` and `cli-216-392-physical`; cleanup
authority remains their captured IDs. The upgrade container is retained until
its actual exit/log result is read, then removed. The runtime exercises the
installed CLI's original keyed-update readbacks and name/run fences for all
three old-run states. Original NULL-key signals are checked only for preserved
identity/history, never manufactured retained-key or intent equality.
Full request-window snapshots must agree. A later post-stop maintenance
snapshot is a different observation and must not be relabeled as that window.

The comparison's offline controls require the staged helper and tagged SQL:

```bash
CLI_216_TEST_HELPER="$experiment/sqlite_legacy.py" \
  CLI_216_TEST_DELTA="$experiment/0013_command_intents.sql" \
  python3 -I -B scripts/acceptance/command-keys/test_physical_cli.py -v
```

These controls are not a physical upgrade result. Source review, current
normal gates, fresh actual execution and cleanup still qualify that cell.

The manual experiment holds owned mode-0700 root/data directories and a
single-link mode-0600 database descriptor throughout each phase. SQLite opens
through the held real disk-file descriptor, preserving normal WAL/SHM behavior
without reopening the mutable database name.
Docker acquires the guarded canonical data bind. A literal in-container shell
preflight checks the actual mounted directory and database device/inode,
UID/GID, mode and link count before executing the native binary. Host checks
immediately before/after acquisition and inspection retain their held identities.
The env-file and Docker config client use inherited `/proc/self/fd` descriptors;
HOME/config/tmp/bin consumer directories are separately held and inherited too.
Exact-ID cleanup uses that held Docker configuration even after a workspace
pathname failure. This requires the local Linux Docker daemon.
Identity checks immediately surround snapshots, container creation/inspection,
API and installed CLI calls, cleanup, and report emission. Reports are written
through held no-follow descriptors, then their path, parent, and contents are
rechecked before printing a terminal result. A swap fails the run; exact-ID
container cleanup remains available even after a workspace identity failure.

The synthetic transform uses a separate stopped sibling copy, verifies its
complete result against the accepted helper's fingerprints, and copies those
bytes into the existing held runtime database inode. It does not rename a new
file over the database that later containers exercise. The physical upgrade
also mutates only that held copied database. Reports include root/data/database
identities so the parent can compare them across modern process restarts.
Offline workspace controls demonstrate predecessor pathname substitution and
require refusal for root/data/database swaps before upgrade or runtime start,
report path/parent swaps during emission, in-place report edits, symlinks and
extra hard links. A positive WAL control preserves actual mutable SQLite use.

Opt-in actual-container boundary controls (separate from installed qualification):

```bash
CLI_216_CONTAINER_CONTROLS=1 \
  python3 -I -B scripts/acceptance/command-keys/test_workspace_container.py -v
```

These use five sequential `cli-152-boundary-*` containers with the same pinned
native image, one CPU, 512 MiB, 128 PIDs, no network or ports. Directory/file
substitution must exit 78 before native execution. The positive mount control
executes the native `schema-check` command and expects its SQLite-unsupported
diagnostic, proving the preflight reached the native binary without starting a
runtime or upgrading a database. Env/config controls use the real Docker reader
and retain exact-ID cleanup after substitution. Check host capacity first and
set TMPDIR to owned disk-backed scratch. No image build or credentials are used.
