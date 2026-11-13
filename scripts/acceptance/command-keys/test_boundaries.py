"""Offline counterexamples for the manual acceptance runner's trust boundary."""
import ast
from contextlib import closing
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("tested_boundary", SOURCE / "boundaries.py")
boundary = importlib.util.module_from_spec(spec)
spec.loader.exec_module(boundary)


class InputTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name).resolve()
        self.addCleanup(self.temporary.cleanup)

    def file(self, value=b"original"):
        path = self.root / "input"
        path.write_bytes(value)
        path.chmod(0o600)
        return path

    def hold(self, path):
        result = boundary.HeldInput(path, hashlib.sha256(path.read_bytes()).hexdigest())
        self.addCleanup(result.close)
        return result

    def test_symlink_refused(self):
        original = self.file()
        link = self.root / "link"
        link.symlink_to(original)
        with self.assertRaises(ValueError):
            self.hold(link)

    def test_hardlink_refused(self):
        original = self.file()
        os.link(original, self.root / "second")
        with self.assertRaises(ValueError):
            self.hold(original)

    def test_same_bytes_new_inode_refused(self):
        path = self.file()
        held = self.hold(path)
        path.rename(self.root / "old")
        self.file()
        with self.assertRaises(ValueError):
            held.recheck()

    def test_entrypoint_replacement_cannot_relabel_terminal_digest(self):
        path = self.file(b"reviewed entrypoint")
        captured = boundary.HeldInput(path, None)
        self.addCleanup(captured.close)
        expected = hashlib.sha256(path.read_bytes()).hexdigest()
        path.rename(self.root / "loaded")
        self.file(b"replacement source")
        # The predecessor's terminal pathname hash relabeled the run.
        self.assertNotEqual(hashlib.sha256(path.read_bytes()).hexdigest(), expected)
        self.assertEqual(captured.digest, expected)
        with self.assertRaises(ValueError):
            captured.recheck()

    def test_inplace_change_refused(self):
        path = self.file()
        held = self.hold(path)
        path.write_bytes(b"modified")
        with self.assertRaises(ValueError):
            held.recheck()

    def test_sealed_content_cannot_be_changed(self):
        held = self.hold(self.file())
        with self.assertRaises(OSError):
            os.pwrite(held.sealed_fd, b"evil", 0)

    def test_executable_path_swap_never_executes_replacement(self):
        path = self.file(Path(sys.executable).resolve().read_bytes())
        path.chmod(0o700)
        held = self.hold(path)
        path.rename(self.root / "verified")
        marker = self.root / "replacement-executed"
        replacement = self.file(("#!/bin/sh\ntouch " + str(marker) + "\nexit 9\n").encode())
        replacement.chmod(0o700)
        # The predecessor re-resolved the pathname and executes the marker.
        self.assertEqual(subprocess.run([str(path)], check=False).returncode, 9)
        self.assertTrue(marker.exists())
        marker.unlink()
        self.assertEqual(subprocess.run(["python3", "-I", "-S", "-c", "raise SystemExit(0)"],
                                        executable=held.executable, pass_fds=(held.sealed_fd,), check=False).returncode, 0)
        self.assertFalse(marker.exists())
        with self.assertRaises(ValueError):
            held.recheck()

    def test_module_swap_after_check_uses_held_bytes_and_refuses_result(self):
        path = self.file(b"value = 'reviewed'\n")
        held = self.hold(path)
        marker = self.root / "unreviewed-executed"
        original_check = held.recheck

        def replace_after_check():
            original_check()
            path.write_text("from pathlib import Path\nPath(" + repr(str(marker)) + ").touch()\n")

        count = 0

        def guarded_check():
            nonlocal count
            count += 1
            (replace_after_check if count == 1 else original_check)()

        with patch.object(held, "recheck", side_effect=guarded_check):
            with self.assertRaises(ValueError):
                held.module("held_race_module")
        self.assertFalse(marker.exists())
        self.assertEqual(sys.modules["held_race_module"].value, "reviewed")

    def test_forged_codec_cannot_certify_a_result(self):
        marker = self.root / "codec-executed"
        forged = self.root / "codec"
        forged.write_text("#!/bin/sh\ntouch " + str(marker) + "\nprintf '1\\n'\n")
        forged.chmod(0o700)
        # The predecessor accepted any absolute codec program as authority.
        self.assertEqual(subprocess.check_output([str(forged)], text=True).strip(), "1")
        marker.unlink()
        tree = ast.parse((SOURCE / "cli_216.py").read_text())
        function = next(n for n in tree.body if isinstance(n, ast.FunctionDef) and n.name == "result_is_one")
        scope = {"ENVELOPES": {"one": {"codec": "avro", "blob": "reviewed"}}}
        exec(compile(ast.Module(body=[function], type_ignores=[]), "result-check", "exec"), scope)
        with patch.dict(os.environ, {"CLI_216_CODEC_PYTHON": str(forged)}):
            self.assertFalse(scope["result_is_one"]({"result_envelope": {"codec": "avro", "blob": "forged"}}))
        self.assertFalse(marker.exists())


class FakeDocker:
    def __init__(self):
        self.objects, self.calls, self.sequence = {}, [], 0

    def __call__(self, *args):
        self.calls.append(args)
        if args[0] == "ps":
            expression = args[-1]
            if expression.startswith("name=^"):
                return next((key for key, name in self.objects.items() if expression == "name=^" + name + "$"), "")
            value = expression.removeprefix("id=")
            return value if value in self.objects else ""
        if args[0] == "run":
            value = hex(10 + self.sequence)[2:] * 64
            self.sequence += 1
            self.objects[value] = "cli-test"
            return value
        if args[0] == "inspect":
            return json.dumps({"Id": args[1], "Name": "/" + self.objects[args[1]]})
        if args[0] == "rm":
            target = args[-1]
            if target not in self.objects:
                target = next(key for key, name in self.objects.items() if name == target)
            del self.objects[target]
            return target
        raise AssertionError(args)


class OwnershipTests(unittest.TestCase):
    def test_preexisting_name_survives_failed_launch_cleanup(self):
        predecessor = FakeDocker()
        predecessor.objects["b" * 64] = "cli-test"
        predecessor("rm", "-fv", "cli-test")
        self.assertFalse(predecessor.objects)
        docker = FakeDocker()
        docker.objects["b" * 64] = "cli-test"
        owned = boundary.OwnedContainer(docker, "cli-test")
        with self.assertRaises(ValueError):
            owned.run("--name", "cli-test")
        owned.remove()
        self.assertIn("b" * 64, docker.objects)
        self.assertFalse(any(call[0] == "rm" for call in docker.calls))

    def test_reused_foreign_name_survives_cleanup(self):
        docker = FakeDocker()
        owned = boundary.OwnedContainer(docker, "cli-test")
        owned.run("--name", "cli-test")
        docker.objects.clear()
        docker.objects["b" * 64] = "cli-test"
        owned.remove()
        self.assertIn("b" * 64, docker.objects)
        self.assertFalse(any(call[0] == "rm" for call in docker.calls))

    def test_cleanup_uses_only_created_id(self):
        docker = FakeDocker()
        owned = boundary.OwnedContainer(docker, "cli-test")
        owned.run("--name", "cli-test")
        owned.remove()
        self.assertIn(("rm", "-fv", "a" * 64), docker.calls)


    def test_stopped_owned_id_can_start_a_new_process(self):
        docker = FakeDocker()
        owned = boundary.OwnedContainer(docker, "cli-test")
        owned.run("--name", "cli-test")
        docker.objects.clear()
        owned.run("--name", "cli-test")
        self.assertEqual(owned.created_ids, ["a" * 64, "b" * 64])
        owned.remove()
        self.assertIn(("rm", "-fv", "b" * 64), docker.calls)


class SnapshotTests(unittest.TestCase):
    def test_schema_only_changes_do_not_pass_zero_write_comparison(self):
        path = Path(os.environ["CLI_216_TEST_HELPER"])
        held = boundary.HeldInput(path, "6fc6054ce087c136a86d7b6b222daeefaa87c58fa0b613b155a5df39e6a26fad")
        self.addCleanup(held.close)
        helper = held.module("tested_snapshot_helper")
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        database = Path(temporary.name).resolve() / "snapshot.sqlite"
        tree = ast.parse((SOURCE / "cli_216.py").read_text())
        function = next(n for n in tree.body if isinstance(n, ast.FunctionDef) and n.name == "snapshot")
        data = database.parent / "data"
        data.mkdir(mode=0o700)
        database = data / "database.sqlite"
        database.parent.parent.chmod(0o700)
        workspace = boundary.Workspace(database.parent.parent)
        self.addCleanup(workspace.close)
        scope = {"WORKSPACE": workspace, "legacy_helper": helper}
        exec(compile(ast.Module(body=[function], type_ignores=[]), "snapshot-check", "exec"), scope)
        with closing(sqlite3.connect(database)) as db:
            db.execute("CREATE TABLE fixture(value INTEGER)")
            db.execute("INSERT INTO fixture VALUES (1)")

            def predecessor():
                # aa11347 snapshot compared only table names and row hashes.
                return {name: db.execute('SELECT * FROM "' + name + '"').fetchall()
                        for name, in db.execute("SELECT name FROM sqlite_master WHERE type='table'")}

            db.commit()
            old = predecessor()
            current = scope["snapshot"]()
            for statement in ["CREATE INDEX fixture_index ON fixture(value)",
                              "CREATE VIEW fixture_view AS SELECT value FROM fixture",
                              "CREATE TRIGGER fixture_trigger AFTER INSERT ON fixture BEGIN SELECT 1; END",
                              "ALTER TABLE fixture RENAME COLUMN value TO renamed"]:
                db.execute(statement)
                db.commit()
                self.assertEqual(old, predecessor())
                self.assertNotEqual(current, scope["snapshot"]())


class WorkspaceTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.parent = Path(self.temporary.name).resolve()
        self.root = self.parent / "work"
        self.root.mkdir(mode=0o700)
        (self.root / "data").mkdir(mode=0o700)
        self.workspace = boundary.Workspace(self.root)
        self.addCleanup(self.workspace.close)
        self.database = self.root / "data/database.sqlite"
        with self.workspace.connection(readonly=False) as db:
            db.execute("CREATE TABLE fixture(value INTEGER)")
            db.execute("INSERT INTO fixture VALUES (1)")
            db.commit()

    def replace(self, target):
        path = {"root": self.root, "data": self.root / "data", "database": self.database}[target]
        renamed = path.with_name(path.name + "-original")
        path.rename(renamed)
        if target == "database":
            path.write_bytes(renamed.read_bytes())
            path.chmod(0o600)
        else:
            import shutil
            shutil.copytree(renamed, path)
        # The predecessor could reopen the new pathname and observe the same
        # schema/rows, even though Docker would exercise a different object.
        with closing(sqlite3.connect(self.database)) as db:
            self.assertEqual(db.execute("SELECT * FROM fixture").fetchall(), [(1,)])

    def test_parent_or_db_swap_after_copy_refuses_before_upgrade(self):
        # Each subcase needs an independent held workspace.
        for target in ("root", "data", "database"):
            with self.subTest(target=target):
                if target != "root":
                    self.setUp()
                self.workspace.database.recheck()
                self.replace(target)
                docker = FakeDocker()
                owned = boundary.OwnedContainer(docker, "cli-test", self.workspace)
                with self.assertRaises(ValueError):
                    owned.run("--name", "cli-test")
                self.assertFalse(docker.calls)

    def test_parent_or_db_swap_after_comparison_refuses_runtime_start(self):
        for target in ("data", "database"):
            with self.subTest(target=target):
                if target == "database":
                    self.setUp()
                with self.workspace.connection() as db:
                    self.assertEqual(db.execute("SELECT * FROM fixture").fetchall(), [(1,)])
                self.replace(target)
                with self.assertRaises(ValueError):
                    self.workspace.mount_arguments()
                with self.assertRaises(ValueError):
                    with self.workspace.connection():
                        self.fail("replaced database was opened")

    def test_root_swap_refuses_report_emission(self):
        self.replace("root")
        with self.assertRaises(ValueError):
            self.workspace.write("physical-result.json", b'{"outcome":"pass"}')
        self.assertFalse((self.root / "physical-result.json").exists())

    def test_report_replacement_is_refused_before_and_during_write(self):
        self.workspace.write("physical-result.json", b"original")
        path = self.root / "physical-result.json"
        path.rename(self.root / "original-report")
        path.write_bytes(b"replacement")
        path.chmod(0o600)
        with self.assertRaises(ValueError):
            self.workspace.write("physical-result.json", b"pass")
        self.assertEqual(path.read_bytes(), b"replacement")

    def test_report_path_swap_during_emission_cannot_publish_pass(self):
        original = os.pwrite
        path = self.root / "physical-result.json"
        def swapped(fd, data, offset):
            result = original(fd, data, offset)
            path.rename(self.root / "held-report")
            path.write_bytes(b"forged")
            path.chmod(0o600)
            return result
        with patch.object(boundary.os, "pwrite", swapped):
            with self.assertRaises(ValueError):
                self.workspace.write("physical-result.json", b'{"outcome":"pass"}')
        self.assertEqual(path.read_bytes(), b"forged")

    def test_report_parent_swap_during_emission_is_refused(self):
        original = os.pwrite
        def swapped(fd, data, offset):
            result = original(fd, data, offset)
            self.replace("root")
            return result
        with patch.object(boundary.os, "pwrite", swapped):
            with self.assertRaises(ValueError):
                self.workspace.write("physical-result.json", b'{"outcome":"pass"}')

    def test_report_in_place_edit_is_refused(self):
        self.workspace.write("physical-result.json", b"reviewed")
        (self.root / "physical-result.json").write_bytes(b"tampered")
        with self.assertRaises(ValueError):
            self.workspace.recheck()

    def test_db_symlink_and_extra_link_are_refused(self):
        original = self.database.with_name("original.sqlite")
        self.database.rename(original)
        self.database.symlink_to(original)
        with self.assertRaises(ValueError):
            self.workspace.recheck()
        self.database.unlink()
        original.rename(self.database)
        os.link(self.database, self.database.with_name("second.sqlite"))
        with self.assertRaises(ValueError):
            self.workspace.recheck()

    def test_transformed_copy_sqlite_read_uses_held_disk_identity_not_memfd(self):
        path = self.root / "data/transformed.sqlite"
        with closing(sqlite3.connect(path)) as db:
            db.execute("CREATE TABLE transformed(value INTEGER)")
            db.execute("INSERT INTO transformed VALUES (7)")
            db.commit()
        path.chmod(0o600)
        held = boundary.HeldInput(path, hashlib.sha256(path.read_bytes()).hexdigest())
        self.addCleanup(held.close)
        with self.assertRaises(sqlite3.OperationalError):
            with closing(sqlite3.connect(Path(held.executable).as_uri() + "?mode=ro&immutable=1", uri=True)) as db:
                db.execute("SELECT * FROM transformed").fetchall()
        held.recheck()
        with closing(sqlite3.connect("file:/proc/self/fd/" + str(held.fd) + "?mode=ro&immutable=1", uri=True)) as db:
            self.assertEqual(db.execute("SELECT * FROM transformed").fetchall(), [(7,)])
        held.recheck()
        path.rename(path.with_name("original-transformed.sqlite"))
        path.write_bytes(held.data)
        path.chmod(0o600)
        with self.assertRaises(ValueError):
            held.recheck()

    def test_sqlite_swap_open_swap_back_fools_path_stat_but_not_held_fd(self):
        connect = sqlite3.connect
        def raced(*arguments, **keywords):
            original = self.database.with_name("opened-original.sqlite")
            self.database.rename(original)
            self.database.write_bytes(original.read_bytes())
            self.database.chmod(0o600)
            with closing(connect(self.database)) as replacement:
                replacement.execute("UPDATE fixture SET value=99")
                replacement.commit()
            try:
                opened = connect(*arguments, **keywords)
            finally:
                self.database.unlink()
                original.rename(self.database)
            # The predecessor's post-open name points back at the original,
            # even if the returned connection already opened the substitute.
            if "/database.sqlite?" in str(arguments[0]):
                named = opened.execute("PRAGMA database_list").fetchone()[2]
                self.assertEqual(boundary.HeldDirectory.key(os.stat(named)), self.workspace.database.identity)
            self.workspace.recheck()
            return opened
        with patch.object(sqlite3, "connect", raced):
            with closing(sqlite3.connect("file:" + self.workspace.data.anchor + "/database.sqlite?mode=ro", uri=True)) as old:
                self.assertEqual(old.execute("SELECT * FROM fixture").fetchall(), [(99,)])
            with self.workspace.connection() as current:
                self.assertEqual(current.execute("SELECT * FROM fixture").fetchall(), [(1,)])
        self.workspace.recheck()

    def test_mutable_database_keeps_inode_and_wal_semantics(self):
        identity = self.workspace.database.identity
        with self.workspace.connection(readonly=False) as db:
            db.execute("PRAGMA journal_mode=WAL")
            db.execute("INSERT INTO fixture VALUES (2)")
            db.commit()
            with self.workspace.connection() as reader:
                self.assertEqual(reader.execute("SELECT * FROM fixture").fetchall(), [(1,), (2,)])
        self.workspace.recheck()
        self.assertEqual(self.workspace.database.identity, identity)

    def test_canonical_docker_mount_requires_literal_native_identity_preflight(self):
        arguments = self.workspace.mount_arguments()
        mounts = [{"Destination": "/app/database", "Source": str(self.root / "data"), "Type": "bind", "RW": True}]
        self.assertEqual(arguments, ("--mount", "type=bind,source=" + str(self.root / "data") + ",target=/app/database"))
        native = self.workspace.native_arguments("schema-check")
        self.assertEqual(native[1], boundary.NATIVE_PREFLIGHT)
        self.assertEqual(native[-1], "schema-check")
        self.assertEqual(native[-2], ":".join(str(value) for value in [self.database.stat().st_dev,
                         self.database.stat().st_ino, os.getuid(), os.getgid(), 600, 1]))
        self.workspace.inspect_mounts({"Mounts": mounts})
        mounts[0]["Source"] = str(self.root)
        with self.assertRaises(ValueError):
            self.workspace.inspect_mounts({"Mounts": mounts})

    def test_consumer_state_directory_swaps_are_refused(self):
        for name in ("home", "config", "tmp", "docker-empty", "bin"):
            with self.subTest(name=name):
                path = self.root / name
                path.rename(self.root / (name + "-held"))
                path.mkdir(mode=0o700)
                with self.assertRaises(ValueError):
                    self.workspace.recheck()
                path.rmdir()
                (self.root / (name + "-held")).rename(path)
        self.workspace.recheck()

    def test_env_cleanup_uses_held_parent_after_root_rename(self):
        self.workspace.write("runtime.env", b"SYNTHETIC=value")
        original = self.root.with_name("held-root")
        self.root.rename(original)
        self.root.mkdir(mode=0o700)
        (self.root / "runtime.env").write_bytes(b"foreign")
        self.workspace.unlink("runtime.env")
        self.assertFalse((original / "runtime.env").exists())
        self.assertEqual((self.root / "runtime.env").read_bytes(), b"foreign")

    def test_failed_workspace_check_does_not_block_exact_id_cleanup(self):
        docker = FakeDocker()
        owned = boundary.OwnedContainer(docker, "cli-test")
        owned.run("--name", "cli-test")
        owned.workspace = self.workspace
        self.replace("data")
        owned.remove()
        self.assertIn(("rm", "-fv", "a" * 64), docker.calls)


if __name__ == "__main__":
    unittest.main()
