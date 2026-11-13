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
        scope = {"DB": database, "sqlite3": sqlite3, "legacy_helper": helper}
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


if __name__ == "__main__":
    unittest.main()
