"""Offline checks for the physical-upgrade comparison's narrow exceptions."""
import datetime
import importlib.util
import os
from pathlib import Path
import sqlite3
import unittest

SOURCE = Path(__file__).resolve().parent


def local_module(name, filename):
    spec = importlib.util.spec_from_file_location(name, SOURCE / filename)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


physical = local_module("physical_comparison", "physical_cli.py")
boundary = local_module("physical_boundaries", "boundaries.py")


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
                        "installed_on=?,checksum=?", (self.started.strftime("%Y-%m-%d %H:%M:%S"), bytes.fromhex(physical.JOURNAL_CHECKSUM)))
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

    def test_unbounded_execution_time_is_refused(self):
        self.refuses("UPDATE _sqlx_migrations SET execution_time=999999999999999")


if __name__ == "__main__":
    unittest.main()
