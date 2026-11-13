"""Offline checks for the physical-upgrade comparison's narrow exceptions."""
import datetime
import hashlib
import importlib.util
import os
from pathlib import Path
import sqlite3
import shutil
import subprocess
import sys
import tempfile
import unittest
import unittest.mock

SOURCE = Path(__file__).resolve().parent


def local_module(name, filename):
    spec = importlib.util.spec_from_file_location(name, SOURCE / filename)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


physical = local_module("physical_comparison", "physical_impl.py")
boundary = local_module("physical_boundaries", "boundaries.py")
bootstrap = local_module("physical_bootstrap", "run_cli_216.py")


class UpgradeComparisonTests(unittest.TestCase):
    def setUp(self):
        helper_input = boundary.HeldInput(Path(os.environ["CLI_216_TEST_HELPER"]),
                                         "6fc6054ce087c136a86d7b6b222daeefaa87c58fa0b613b155a5df39e6a26fad")
        self.addCleanup(helper_input.close)
        self.helper = helper_input.module("physical_snapshot_helper")
        delta_input = boundary.HeldInput(Path(os.environ["CLI_216_TEST_DELTA"]), physical.DELTA_SHA)
        self.addCleanup(delta_input.close)
        self.delta = delta_input.data
        self.db = sqlite3.connect(":memory:")
        self.addCleanup(self.db.close)
        self.db.executescript("""
            CREATE TABLE dw_server_schema(engine TEXT, version INTEGER);
            INSERT INTO dw_server_schema VALUES ('rust-development', 12);
            CREATE TABLE _sqlx_migrations(version INTEGER, description TEXT, installed_on TEXT,
                success INTEGER, checksum BLOB, execution_time INTEGER);
            CREATE TABLE business(value INTEGER);
            INSERT INTO business VALUES (1);
        """)
        self.db.execute("INSERT INTO _sqlx_migrations VALUES (12,'baseline','2026-01-01 00:00:00',1,?,1)", (bytes(48),))
        self.before = self.helper.snapshot(self.db)
        self.started = datetime.datetime.now(datetime.timezone.utc)
        self.db.executescript(self.delta.decode())
        self.db.execute("UPDATE _sqlx_migrations SET version=13,description='baseline and keyed command intents',"
                        "installed_on=?,checksum=?,execution_time=0", (self.started.strftime("%Y-%m-%d %H:%M:%S"), bytes.fromhex(physical.JOURNAL_CHECKSUM)))
        self.ended = datetime.datetime.now(datetime.timezone.utc)

    def compare(self):
        return physical.compare_upgrade(self.before, self.helper.snapshot(self.db), self.delta,
                                        self.started, self.ended, self.helper)

    def refuses(self, statement):
        self.db.execute(statement)
        with self.assertRaises(ValueError):
            self.compare()

    def test_exact_tagged_upgrade_and_bounded_metadata_pass(self):
        self.compare()

    def test_business_row_change_is_not_a_metadata_exception(self):
        self.refuses("UPDATE business SET value=2")

    def test_unexpected_schema_object_is_refused(self):
        self.refuses("CREATE INDEX unrelated ON business(value)")

    def test_nonempty_new_intent_table_is_refused(self):
        self.refuses("INSERT INTO dw_command_intents VALUES ('command','default','digest')")

    def test_wrong_journal_checksum_is_refused(self):
        self.refuses("UPDATE _sqlx_migrations SET checksum=x'00'")

    def test_wrong_journal_description_is_refused(self):
        self.refuses("UPDATE _sqlx_migrations SET description='other'")

    def test_wrong_journal_version_is_refused(self):
        self.refuses("UPDATE _sqlx_migrations SET version=14")

    def test_extra_journal_row_is_refused(self):
        self.refuses("INSERT INTO _sqlx_migrations SELECT * FROM _sqlx_migrations")

    def test_old_journal_timestamp_is_refused(self):
        self.refuses("UPDATE _sqlx_migrations SET installed_on='2020-01-01 00:00:00'")

    def test_nonzero_execution_time_is_refused(self):
        self.refuses("UPDATE _sqlx_migrations SET execution_time=1")

    def test_unbounded_execution_time_is_refused(self):
        self.refuses("UPDATE _sqlx_migrations SET execution_time=999999999999999")


class PreCaptureTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name).resolve()
        self.boundary_input = boundary.HeldInput(SOURCE / "boundaries.py", bootstrap.BOUNDARY_SHA)
        self.addCleanup(self.boundary_input.close)

    def test_loaded_predecessor_can_relabel_while_candidate_refuses_before_execution(self):
        source = self.root / "implementation.py"
        marker = self.root / "effect"
        original = ("captured = hashlib.sha256(Path(__file__).read_bytes()).hexdigest()\n"
                    "Path(" + repr(str(marker)) + ").touch()\n").encode()
        source.write_bytes(original)
        loaded = compile(original, str(source), "exec")
        source.rename(self.root / "original")
        source.write_bytes(b"replacement = True\n")
        scope = {"__file__": str(source), "hashlib": hashlib, "Path": Path}
        exec(loaded, scope)
        self.assertEqual(scope["captured"], hashlib.sha256(source.read_bytes()).hexdigest())
        self.assertNotEqual(scope["captured"], hashlib.sha256(original).hexdigest())
        self.assertTrue(marker.exists())
        marker.unlink()
        with self.assertRaises(ValueError):
            bootstrap.run_implementation(source, hashlib.sha256(original).hexdigest(), boundary, self.boundary_input)
        self.assertFalse(marker.exists())

    def test_direct_implementation_refuses_before_effectful_initialization(self):
        result = subprocess.run([sys.executable, "-I", "-B", str(SOURCE / "physical_impl.py")],
                                env={"PATH": os.defpath}, capture_output=True, text=True, timeout=5)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Use run_cli_216.py", result.stderr)

    def test_direct_common_entrypoints_refuse_before_initialization(self):
        for filename in ("cli_216.py", "legacy_cli.py"):
            with self.subTest(filename=filename):
                result = subprocess.run([sys.executable, "-I", "-B", str(SOURCE / filename)],
                                        env={"PATH": os.defpath}, capture_output=True, text=True, timeout=5)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("Use run_cli_216.py", result.stderr)

    def test_unknown_loader_choice_refuses(self):
        for choice in ("unknown", "../physical_impl.py"):
            with self.subTest(choice=choice):
                scope = {"__name__": "__main__", "__file__": str(SOURCE / "run_cli_216.py"),
                         "BOOTSTRAP_LAUNCHER_SHA256": "trusted-test"}
                with unittest.mock.patch.dict(os.environ, {"CLI_216_RUNNER": choice}):
                    with self.assertRaisesRegex(SystemExit, "CLI_216_RUNNER must be"):
                        exec(compile((SOURCE / "run_cli_216.py").read_bytes(), scope["__file__"], "exec"), scope)

    def test_direct_outer_dispatcher_refuses_pathname_authority(self):
        result = subprocess.run([sys.executable, "-I", "-B", str(SOURCE / "run_cli_216.py")],
                                env={"PATH": os.defpath}, capture_output=True, text=True, timeout=5)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("literal -c Git bootstrap", result.stderr)

    def test_frozen_git_bootstrap_ignores_preopen_outer_path_substitution(self):
        root = self.root / "repo"
        root.mkdir()
        folder = root / "scripts/acceptance/command-keys"
        folder.mkdir(parents=True)
        launcher = folder / "run_cli_216.py"
        launcher.write_bytes((SOURCE / "run_cli_216.py").read_bytes())
        env = {"PATH": os.defpath, "HOME": str(self.root), "GIT_CONFIG_NOSYSTEM": "1"}
        def git(*args):
            return subprocess.check_output(["git", "-C", str(root), *args], env=env, stderr=subprocess.DEVNULL)
        git("init"); git("add", ".")
        git("-c", "user.name=Fixture", "-c", "user.email=fixture@example.invalid", "commit", "-m", "Frozen dispatcher")
        commit = git("rev-parse", "HEAD").decode().strip()
        marker = self.root / "substitute-executed"
        launcher.write_text("from pathlib import Path\nPath(" + repr(str(marker)) + ").touch()\n")
        subprocess.run([sys.executable, "-I", "-B", str(launcher)], env=env, check=True)
        self.assertTrue(marker.exists())
        marker.unlink()
        code = (SOURCE / "git_bootstrap.py").read_text()
        result = subprocess.run([sys.executable, "-I", "-B", "-c", code, commit], cwd=root,
                                env={**env, "CLI_216_RUNNER": "unknown"}, capture_output=True, text=True, timeout=10)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("CLI_216_RUNNER must be", result.stderr)
        self.assertFalse(marker.exists())

    def test_literal_bound_implementation_executes_checked_bytes(self):
        source = self.root / "implementation.py"
        data = b"value = 'reviewed'\n"
        source.write_bytes(data)
        result = bootstrap.run_implementation(source, hashlib.sha256(data).hexdigest(), boundary, self.boundary_input)
        self.assertEqual(result["value"], "reviewed")


if __name__ == "__main__":
    unittest.main()
