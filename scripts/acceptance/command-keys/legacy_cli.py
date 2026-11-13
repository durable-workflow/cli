"""CLI-owned synthetic legacy cells; never a physical old-image upgrade."""
import concurrent.futures
from contextlib import closing
import datetime
import hashlib
import stat
import types
import sys
import json
import os
from pathlib import Path
import sqlite3
import threading
import time

SOURCE = Path(__file__).resolve().parent
ROOT = Path(os.environ["CLI_216_WORKDIR"])


def bound_driver():
    path = SOURCE / "cli_216.py"
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        observed = os.fstat(descriptor)
        if (not stat.S_ISREG(observed.st_mode) or observed.st_uid != os.getuid()
                or observed.st_nlink != 1 or observed.st_mode & 0o022 or observed.st_size > 131072):
            raise ValueError("base driver owner/type/mode differs")
        data = os.pread(descriptor, 131073, 0)
        if hashlib.sha256(data).hexdigest() != BASE_SHA:
            raise ValueError("base driver source identity differs")
        module = types.ModuleType("cli216_owner")
        module.__file__ = str(path)
        exec(compile(data, str(path), "exec"), module.__dict__)
        item = module.held(path, BASE_SHA)
        if item.identity != item._identity(observed):
            raise ValueError("base driver changed during held import")
        return module
    finally:
        os.close(descriptor)


BASE_SHA = "0f2afebcfeea0a728a203a59025bf0a94669581ccd0d84bf3f53abc2d971c653"
d = bound_driver()
legacy = d.legacy_helper
d.NAME = "cli-216-392-legacy"
runtime = d.boundary.OwnedContainer(d.docker, d.NAME)
manifest = legacy.manifest()
cases, selections, windows = [], [], []
started = datetime.datetime.now(datetime.timezone.utc).isoformat()


def full_snapshot():
    with closing(sqlite3.connect(d.DB.as_uri() + "?mode=ro", uri=True, timeout=2)) as db:
        return legacy.snapshot(db)


def start():
    envfile = ROOT / "runtime.env"
    envfile.write_text("DW_AUTH_TOKEN=" + d.TOKEN + "\nDW_RUST_MAINTENANCE_LOOP=0\n")
    envfile.chmod(0o600)
    runtime.run("--rm", "-d", "--name", d.NAME, "--platform=linux/amd64", "--init",
             "--cpus=1", "--memory=1g", "--pids-limit=256", "--read-only", "--cap-drop=ALL",
             "--security-opt=no-new-privileges", "--env-file", str(envfile),
             "--mount", "type=bind,source=" + str(ROOT / "data") + ",target=/app/database",
             "--tmpfs", "/app/storage:rw,uid=1000,gid=1000,mode=750,size=64m",
             "--tmpfs", "/tmp:rw,size=16m", "-p", "127.0.0.1::8080", d.IMAGE)
    binding = runtime.inspect()["NetworkSettings"]["Ports"]["8080/tcp"][0]
    d.BASE = "http://127.0.0.1:" + binding["HostPort"]
    for _ in range(100):
        try:
            status, info = d.request("GET", "/api/cluster/info")
            if status == 200:
                break
        except (OSError, ValueError):
            pass
        time.sleep(.1)
    else:
        raise RuntimeError("Server did not become ready")
    d.require(info["version"] == "3.9.2" and info["worker_protocol"]["version"] == "1.20",
              "published corrected Server version/protocol")
    d.PROXY = d.CommittedResponseLoss(d.BASE)
    threading.Thread(target=d.PROXY.serve_forever, daemon=True).start()


def stop():
    if d.PROXY:
        d.PROXY.shutdown()
        d.PROXY.server_close()
        d.PROXY = None
    runtime.stop()
    for _ in range(50):
        if not runtime.exists():
            break
        time.sleep(.1)
    else:
        raise RuntimeError("owned automatic removal did not finish within five seconds")
    d.require(True, "exact owned runtime is absent before offline copy")


def select(kind, fixture, record, stored):
    selections.append({"command_id": record["receipt"]["command_id"], "command_type": kind,
                       "workflow_instance_id": fixture["workflow"], "workflow_run_id": fixture["run"],
                       "caller_key": record["request_id"], "legacy_request_id": stored})


def terminal(kind, f, record, action):
    commands = []
    if kind == "update":
        commands.append({"type": "complete_update", "update_id": record["receipt"]["update_id"],
                         "result": f["empty_envelope"]})
    commands.append({"type": action, "result": f["empty_envelope"]})
    event = "WorkflowCompleted" if action == "complete_workflow" else "WorkflowContinuedAsNew"
    for _ in range(10):
        d.finish(f, d.poll(f), commands)
        if any(row["event_type"] == event for row in d.history(f)):
            break
    d.require(any(row["event_type"] == event for row in d.history(f)), kind + ": original " + action + " recorded")


def seed():
    for kind in manifest["legacy_readback"]["types"]:
        for state, action in [("open_after_restart", None), ("completed_after_restart", "complete_workflow"),
                              ("continued_after_restart", "continue_as_new")]:
            f = d.fixture(kind, "legacy-" + kind + "-" + state)
            record = d.original(kind, f, key=manifest["keys"]["original"])
            if action:
                terminal(kind, f, record, action)
            select(kind, f, record, record["request_id"])
            cases.append({"kind": kind, "fixture": f, "record": record, "state": state, "shape": "readback"})
        for shape in ["two-rows", "legacy-token", "modern-raw"]:
            f = d.fixture(kind, "ambiguity-" + kind + "-" + shape)
            record = d.original(kind, f, key=manifest["keys"]["original"])
            token = manifest["keys"]["tokens"][kind]
            if shape == "two-rows":
                second = d.original(kind, f, key=manifest["keys"]["second"])
                select(kind, f, second, manifest["keys"]["original"])
            elif shape == "legacy-token":
                select(kind, f, record, token)
            cases.append({"kind": kind, "fixture": f, "record": record, "shape": shape,
                          "retry_key": token if shape == "modern-raw" else manifest["keys"]["original"]})
        f = d.fixture(kind, "case-" + kind)
        record = d.original(kind, f, key=manifest["keys"]["case_exact"])
        select(kind, f, record, record["request_id"])
        cases.append({"kind": kind, "fixture": f, "record": record, "shape": "case"})


def readback(case, *, run=None):
    kind, f, record = case["kind"], case["fixture"], case["record"]
    answer = d.message(kind, f["workflow"], record["request_id"], value=manifest["legacy_readback"]["retry_input"], run=run)
    for field, value in record["receipt"].items():
        d.require(answer.get(field) == value, kind + ": legacy original " + field)
    d.require(answer.get("retained") is True and answer.get("intent_verified") is False,
              kind + ": legacy readback is retained and explicitly unverified")
    d.require(answer.get("request_id") == record["request_id"], kind + ": literal legacy caller key")
    if kind == "update" and case.get("state") in ("completed_after_restart", "continued_after_restart"):
        d.require(answer.get("update_status") == "completed" and "result" in answer and answer["result"] is None,
                  "legacy update remains completed null after " + case["state"])
    return answer


def typed_error(answer, reason):
    values = [v for v in answer.values() if isinstance(v, dict)] + [answer]
    result = next((v for v in values if v.get("reason") == reason), None)
    d.require(result is not None, "typed " + reason)
    return result


def unchanged(before, label):
    after = full_snapshot()
    d.require(before == after, label + ": complete logical schema/rows unchanged")
    windows.append({"cell": label, "before_sha256": legacy.fingerprint(before),
                    "after_sha256": legacy.fingerprint(after)})


def exercise():
    for case in cases:
        kind, f, record = case["kind"], case["fixture"], case["record"]
        label = kind + ":" + case["shape"] + ":" + case.get("state", "open")
        before = full_snapshot()
        if case["shape"] in ("readback", "case"):
            with concurrent.futures.ThreadPoolExecutor(max_workers=manifest["legacy_readback"]["concurrency"]) as pool:
                replies = list(pool.map(lambda _: readback(case), range(4)))
            d.require(len({r["command_id"] for r in replies}) == 1, label + ": four callers retain one original identity")
            readback(case, run=f["run"])
            for options in [{"name": "other"}, {"run": "00000000000000000000000000"}]:
                reply = d.message(kind, f["workflow"], record["request_id"], value=999, success=False, **options)
                typed = typed_error(reply, "request_id_conflict")
                d.require(typed.get("retained_command_id") == record["receipt"]["command_id"],
                          label + ": name/run refusal identifies the original command")
                d.require(typed.get("conflict") == ["name" if "name" in options else "target"],
                          label + ": correct conflict component")
            unchanged(before, label)
            if case["shape"] == "case":
                answer = d.message(kind, f["workflow"], manifest["keys"]["case_other"])
                d.require(answer.get("command_id") != record["receipt"]["command_id"] and answer.get("retained") is False,
                          kind + ": SQLite case-different key admits a distinct new command; writes expected")
        else:
            reply = d.message(kind, f["workflow"], case["retry_key"], success=False)
            typed = typed_error(reply, "request_id_ambiguous")
            d.require(typed.get("retained") is True and typed.get("intent_verified") is False
                      and typed.get("retryable") is False, label + ": ambiguity fails closed and unverified")
            d.require(all(typed.get(field) is None for field in ("command_id", "update_id", "retained_command_id")),
                      label + ": ambiguity selects no receipt")
            unchanged(before, label)


if __name__ == "__main__":
    outcome, error, phase, transform = "runner-blocked", None, "preflight", None
    seed_wire = []
    try:
        d.require(hashlib.sha256((ROOT / "bin/dw").read_bytes()).hexdigest() ==
                  "da366c79c90978a3c0a92d723c66a96a2d0963f9dccbd41c8207f2c41848f814", "installed CLI binary identity")
        observed = json.loads(d.docker("image", "inspect", d.IMAGE, "--format", "{{json .}}"))
        d.require(observed["Architecture"] == "amd64" and observed["Os"] == "linux" and
                  observed["Config"]["Labels"]["org.opencontainers.image.revision"] ==
                  "5614274d2629d03f92932a07dfee0fb10349ddd9", "published Server platform/source identity")
        start()
        phase = "API seed"
        seed()
        seed_wire = list(d.PROXY.records)
        stop()
        phase = "offline accepted transform"
        # This is a current-producer fixture. Checkpoint only after observed
        # process absence, then freeze the helper's untouched source inode.
        with closing(sqlite3.connect(d.DB, timeout=2)) as db:
            db.execute("PRAGMA wal_checkpoint(TRUNCATE)").fetchone()
        original = d.DB.with_name("seed.sqlite")
        d.DB.rename(original)
        transform = legacy.transform_copy(original, d.DB, selections, stopped=True)
        d.require(transform["transformed"] == 12, "exact twelve approved legacy transforms; all other schema/rows preserved")
        start()
        phase = "installed legacy readback"
        exercise()
        outcome = "pass"
    except Exception as exc:
        error = (type(exc).__name__ + ": " + str(exc)).replace(d.TOKEN, "[redacted]")
        print(json.dumps({"outcome": outcome, "phase": phase, "error": error}), flush=True)
    finally:
        wire = list(d.PROXY.records) if d.PROXY else []
        if d.PROXY:
            d.PROXY.shutdown()
            d.PROXY.server_close()
        cleanup_error = None
        try:
            runtime.remove()
            for _ in range(50):
                if not runtime.exists():
                    break
                time.sleep(.1)
            if runtime.exists():
                raise RuntimeError("created container removal did not complete")
            for item in d.HELD:
                item.recheck()
        except Exception as failure:
            cleanup_error = (type(failure).__name__ + ": " + str(failure)).replace(d.TOKEN, "[redacted]")
            outcome = "runner-blocked"
        (ROOT / "runtime.env").unlink(missing_ok=True)
        absent = not runtime.exists()
        report = {"cell": "synthetic-legacy-shape", "outcome": outcome, "created_container_ids": runtime.created_ids, "error": error,
                  "cleanup_error": cleanup_error, "phase": phase, "checks": len(d.CHECKS), "started_at": started,
                  "finished_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
                  "driver_sha256": hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
                  "base_driver_sha256": hashlib.sha256((SOURCE / "cli_216.py").read_bytes()).hexdigest(),
                  "helper_sha256": hashlib.sha256((ROOT / "sqlite_legacy.py").read_bytes()).hexdigest(),
                  "manifest_sha256": legacy.MANIFEST_SHA256, "server_index": d.IMAGE,
                  "transform": transform, "windows": windows,
                  "cleanup": "created container/volumes/env removed; held inputs unchanged" if absent and cleanup_error is None else "cleanup/input identity failed"}
        (ROOT / "legacy-result.json").write_text(json.dumps(report, indent=2))
        (ROOT / "legacy-wire.json").write_text(json.dumps({"seed": seed_wire, "readback": wire}, indent=2))
        print(json.dumps(report), flush=True)
    raise SystemExit(0 if outcome == "pass" and absent else 1)
