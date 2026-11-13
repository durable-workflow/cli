"""Installed CLI readback on a copy of the accepted real 3.8 API seed."""
from contextlib import closing
from copy import deepcopy
import concurrent.futures
import datetime
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import stat
import time
import types

SOURCE = Path(__file__).resolve().parent
LEGACY_SHA = "cc4018a807ff01e557676fa7ff4cb73f30872b004d04b42338fe96f65e18aec2"
BASELINE_SHA = "7d1826f7681342bb3a5974509648e2e8d75e7a21d262f65f3ec1f58727ccbc0c"
PACKET_SHA = "8c728ce827df3a11621dfb0e82cdd404bbab48784182a5e92c8d632d49047fc5"
CONTRACT_SHA = "ceb4695643e48c7bfe3f81eea380c6ab9aa0b973da830cb552d070344047319f"
DELTA_SHA = "fc3ad1730682c09704027dcbabc17cd6ded9c642a3ff20d7988d5f4b4a5726fd"
JOURNAL_CHECKSUM = "53b752529a1f7addaefe62ab61160dd42b3990f805cfdc4ecd1b48927a6c2f7ce42526fc04522ac887a37208f343fc73"


def load_legacy():
    path = SOURCE / "legacy_cli.py"
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        observed = os.fstat(descriptor)
        if (not stat.S_ISREG(observed.st_mode) or observed.st_uid != os.getuid()
                or observed.st_nlink != 1 or observed.st_mode & 0o022 or observed.st_size > 131072):
            raise ValueError("legacy adapter owner/type/mode differs")
        data = os.pread(descriptor, 131073, 0)
        if hashlib.sha256(data).hexdigest() != LEGACY_SHA:
            raise ValueError("legacy adapter source differs")
        module = types.ModuleType("cli216_physical_common")
        module.__file__ = str(path)
        exec(compile(data, str(path), "exec"), module.__dict__)
        held = module.d.held(path, LEGACY_SHA)
        if held.identity != held._identity(observed):
            raise ValueError("legacy adapter changed during held import")
        return module
    finally:
        os.close(descriptor)


def compare_upgrade(before, after, delta, started, ended, helper):
    """Allow only the tagged DDL and narrowly validated migration metadata."""
    if before["tables"]["dw_server_schema"]["rows"] != [[["text", "rust-development"], ["integer", 12]]]:
        raise ValueError("baseline is not native SQLite schema 12")
    expected = deepcopy(before)
    with closing(sqlite3.connect(":memory:")) as db:
        db.execute("CREATE TABLE dw_server_schema(engine TEXT, version INTEGER)")
        db.executescript(delta.decode())
        additions = helper.snapshot(db)
    expected["schema"].extend(row for row in additions["schema"] if row[2] == "dw_command_intents")
    expected["schema"].sort(key=lambda row: (row[0], row[1]))
    expected["tables"]["dw_command_intents"] = additions["tables"]["dw_command_intents"]
    expected["tables"]["dw_server_schema"]["rows"] = [[["text", "rust-development"], ["integer", 13]]]
    journal = after["tables"]["_sqlx_migrations"]
    columns = ["version", "description", "installed_on", "success", "checksum", "execution_time"]
    if journal["columns"] != columns or len(journal["rows"]) != 1:
        raise ValueError("upgraded migration journal shape differs")
    row = journal["rows"][0]
    old = before["tables"]["_sqlx_migrations"]["rows"]
    if len(old) != 1 or old[0][0] != ["integer", 12]:
        raise ValueError("baseline migration journal shape differs")
    if (row[0] != ["integer", 13] or row[1] != ["text", old[0][1][1] + " and keyed command intents"]
            or row[3] != ["integer", 1] or row[4] != ["blob", JOURNAL_CHECKSUM]
            or row[5] != ["integer", 0]
            or row[2][0] != "text"):
        raise ValueError("migration journal content differs from tagged schema 13")
    installed = datetime.datetime.fromisoformat(row[2][1]).replace(tzinfo=datetime.timezone.utc)
    if not started - datetime.timedelta(seconds=1) <= installed <= ended + datetime.timedelta(seconds=1):
        raise ValueError("migration journal time is outside the actual upgrade window")
    expected["tables"]["_sqlx_migrations"]["rows"] = deepcopy(journal["rows"])
    if expected != after:
        raise ValueError("upgrade changed an original business row/schema or unexpected metadata")


def physical_cases(packet, common):
    d = common.d
    for original in packet["cases"]:
        kind, state = original["kind"], original["state"]
        receipt = original["receipt"]
        fixture = {"workflow": original["workflow_id"], "run": receipt["run_id"], "namespace": "default"}
        d.require(d.history(fixture) == original["history"]["events"], kind + ": original " + state + " API history preserved")
        if kind == "signal":
            # Real 3.8 ordinary signals stored no caller key. Never issue a
            # manufactured retained retry or infer intent equality for them.
            with closing(sqlite3.connect(d.DB.as_uri() + "?mode=ro", uri=True)) as db:
                key = db.execute("SELECT request_id FROM workflow_commands WHERE id=?", (receipt["command_id"],)).fetchone()
            d.require(key == (None,), "physical signal: original NULL key is compatibility only")
            continue
        record = {"request_id": original["original_body"]["request_id"],
                  "receipt": {key: receipt[key] for key in ("command_id", "run_id", "command_sequence", "update_id", "accepted_at")}}
        case = {"kind": kind, "fixture": fixture, "record": record,
                "state": {"open": "open_after_restart", "completed": "completed_after_restart", "continued": "continued_after_restart"}[state]}
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            replies = list(pool.map(lambda _: common.readback(case), range(4)))
        d.require(len({reply["command_id"] for reply in replies}) == 1, "physical update: concurrent " + state + " retries retain original command")
        common.readback(case, run=fixture["run"])
        for options in [{"name": "other"}, {"run": "00000000000000000000000000"}]:
            answer = d.message("update", fixture["workflow"], record["request_id"], value=999, success=False, **options)
            refusal = common.typed_error(answer, "request_id_conflict")
            d.require(refusal.get("retained_command_id") == receipt["command_id"] and
                      refusal.get("conflict") == ["name" if "name" in options else "target"],
                      "physical update: correct original identity/name/run fence")


if __name__ == "__main__":
    if "BOOTSTRAP_HELD_IMPLEMENTATION" not in globals() or "BOOTSTRAP_HELD_BOUNDARY" not in globals():
        raise SystemExit("Use run_cli_216.py to verify the implementation before execution")
    common = load_legacy()
    d = common.d
    entry = BOOTSTRAP_HELD_IMPLEMENTATION
    d.HELD.extend([BOOTSTRAP_HELD_BOUNDARY, entry])
    baseline = d.held(Path(os.environ["CLI_216_PHYSICAL_BASELINE"]), BASELINE_SHA, 64 * 1024 * 1024)
    packet_input = d.held(Path(os.environ["CLI_216_PHYSICAL_PACKET"]), PACKET_SHA)
    contract_input = d.held(d.ROOT / "physical-contract.json", CONTRACT_SHA)
    delta_input = d.held(d.ROOT / "0013_command_intents.sql", DELTA_SHA)
    packet = json.loads(packet_input.data)
    if packet["schema"] != "durable-workflow.command-key-physical-seed.v1" or packet["manifest_sha256"] != CONTRACT_SHA:
        raise ValueError("physical seed interface differs")
    contract = json.loads(contract_input.data)
    if packet["expected_external_baseline"] != contract["physical_upgrade"]["baseline"] or len(packet["cases"]) != 6:
        raise ValueError("physical baseline/case identities differ")
    d.NAME = "cli-216-392-physical"
    common.runtime = d.boundary.OwnedContainer(d.docker, d.NAME, d.WORKSPACE)
    upgrade = d.boundary.OwnedContainer(d.docker, "cli-216-392-upgrade", d.WORKSPACE)
    outcome, error, phase = "runner-blocked", None, "preflight"
    started = datetime.datetime.now(datetime.timezone.utc)
    before, after = None, None
    try:
        observed = json.loads(d.docker("image", "inspect", d.IMAGE, "--format", "{{json .}}"))
        d.require(observed["Architecture"] == "amd64" and observed["Os"] == "linux" and
                  observed["Config"]["Labels"]["org.opencontainers.image.revision"] ==
                  "5614274d2629d03f92932a07dfee0fb10349ddd9", "physical candidate published platform/source identity")
        d.WORKSPACE.recheck()
        if os.fstat(d.WORKSPACE.database.fd).st_size != 0:
            raise ValueError("physical upgrade needs a newly created empty DB inode")
        d.WORKSPACE.database.write(baseline.data)
        d.WORKSPACE.recheck()
        before = common.full_snapshot()
        phase = "explicit physical schema upgrade"
        upgrade_started = datetime.datetime.now(datetime.timezone.utc)
        upgrade.run("-d", "--name", upgrade.name, "--platform=linux/amd64", "--init", "--cpus=1", "--memory=1g",
                    "--pids-limit=256", "--network=none", "--read-only", "--cap-drop=ALL", "--security-opt=no-new-privileges",
                    "--entrypoint=/bin/sh", "--env", "DB_CONNECTION=sqlite", "--env", "DB_DATABASE=/app/database/database.sqlite",
                    *d.WORKSPACE.mount_arguments(),
                    "--tmpfs", "/tmp:rw,size=16m", d.IMAGE, *d.WORKSPACE.native_arguments("schema-upgrade"))
        upgrade.inspect()
        d.require(d.docker("wait", upgrade.id) == "0", "tagged image schema-upgrade exits zero")
        state = upgrade.inspect()["State"]
        d.require(state["ExitCode"] == 0 and not state["OOMKilled"], "upgrade process exits cleanly without OOM")
        upgrade.inspect()
        result = json.loads(d.docker("logs", upgrade.id))
        d.require(result == {"backend": "sqlite", "from_schema_version": 12, "schema_version": 13, "status": "upgraded"},
                  "actual schema transition is 12 to 13")
        upgrade_ended = datetime.datetime.now(datetime.timezone.utc)
        after = common.full_snapshot()
        compare_upgrade(before, after, delta_input.data, upgrade_started, upgrade_ended, common.legacy)
        d.require(True, "every original business row/schema preserved; only tagged DDL and validated version/journal metadata changed")
        upgrade.remove()
        phase = "installed CLI physical readback"
        common.start()
        window = common.full_snapshot()
        physical_cases(packet, common)
        common.unchanged(window, "physical upgrade installed request window")
        outcome = "pass"
    except Exception as failure:
        error = (type(failure).__name__ + ": " + str(failure)).replace(d.TOKEN, "[redacted]")
    finally:
        if d.PROXY:
            d.PROXY.shutdown()
            d.PROXY.server_close()
        cleanup_error = None
        try:
            for owned in (upgrade, common.runtime):
                owned.remove()
                for _ in range(50):
                    if not owned.exists():
                        break
                    time.sleep(.1)
                if owned.exists():
                    raise RuntimeError("created physical container removal did not finish")
            d.WORKSPACE.recheck()
            for item in d.HELD:
                item.recheck()
        except Exception as failure:
            outcome = "runner-blocked"
            cleanup_error = (type(failure).__name__ + ": " + str(failure)).replace(d.TOKEN, "[redacted]")
        d.WORKSPACE.unlink("runtime.env")
        report = {"cell": "physical-3.8-upgrade", "outcome": outcome, "workspace_identity": d.WORKSPACE.evidence(), "error": error, "cleanup_error": cleanup_error,
                  "phase": phase, "started_at": started.isoformat(), "finished_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
                  "checks": len(d.CHECKS), "driver_sha256": entry.digest, "launcher_sha256": BOOTSTRAP_LAUNCHER_SHA256,
                  "runner_source_commit": BOOTSTRAP_SOURCE_COMMIT,
                  "accepted_contract_source": "a09f156399416216d2f097c257a2164f83c7eda7",
                  "actual_seed_source": "09a4ccda3da11853606af031a8970bbde4b2d6ec",
                  "actual_seed_driver_sha256": "01c0fa1251ac3c809723e58d71817881fe2c5c9498579bc4c2e6097324a3618b",
                  "baseline_sha256": baseline.digest, "packet_sha256": packet_input.digest, "contract_sha256": contract_input.digest,
                  "before_sha256": common.legacy.fingerprint(before) if before else None,
                  "after_upgrade_sha256": common.legacy.fingerprint(after) if after else None,
                  "request_windows": common.windows, "server_index": d.IMAGE,
                  "created_container_ids": upgrade.created_ids + common.runtime.created_ids,
                  "cleanup": "created IDs/volumes/env removed; original held inputs unchanged" if cleanup_error is None else "requires investigation"}
        d.WORKSPACE.write("physical-result.json", json.dumps(report, indent=2).encode())
        d.WORKSPACE.recheck()
        print(json.dumps(report), flush=True)
        d.WORKSPACE.close()
    raise SystemExit(0 if outcome == "pass" else 1)
