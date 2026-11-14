import copy
import hashlib
import json
import os
from pathlib import Path
import shutil
import tempfile
import unittest
import urllib.error
import urllib.request

import parity


class NamespaceRequestFixtureTest(unittest.TestCase):
    def test_lifecycle_fixtures_echo_method_and_json_without_mutating_state(self):
        routes = {"/" + method.lower(): {"method": method, "echo_request": True}
                  for method in ("POST", "PUT", "DELETE")}
        server = parity.MockServer({}, routes)
        try:
            for method, body in (("POST", {"description": "é", "retention_days": 0}),
                                 ("PUT", []), ("DELETE", None)):
                data = json.dumps(body).encode() if body is not None else None
                request = urllib.request.Request(server.url + "/" + method.lower(), data=data, method=method)
                with urllib.request.urlopen(request, timeout=3) as response:
                    self.assertEqual({"request": {"method": method, "body": body}}, json.load(response))
            with self.assertRaises(urllib.error.HTTPError) as error:
                urllib.request.urlopen(server.url + "/post", timeout=3)
            self.assertEqual(404, error.exception.code)
            error.exception.close()
        finally:
            server.close()

    def test_echo_body_is_bounded(self):
        server = parity.MockServer({}, {"/bounded": {"method": "POST", "echo_request": True}})
        try:
            request = urllib.request.Request(server.url + "/bounded", data=b"x" * 65537, method="POST")
            with self.assertRaises(urllib.error.HTTPError) as error:
                urllib.request.urlopen(request, timeout=3)
            self.assertEqual(413, error.exception.code)
            error.exception.close()
        finally:
            server.close()


class SchemaManifestParityTest(unittest.TestCase):
    def setUp(self):
        with open(parity.CASES, encoding="utf-8") as handle:
            self.case = next(case for case in json.load(handle)["cases"]
                             if case["name"] == "schema:manifest")
        self.root = Path(parity.CRATE).parent
        self.candidate = (self.root / "schemas/output/manifest.json").read_text(encoding="utf-8")
        self.reference = self.candidate
        for update in self.case["schema_sha256_updates"].values():
            self.reference = self.reference.replace(update["candidate"], update["reference"])

    def differences(self, reference=None, candidate=None, case=None):
        result = {key: None for key in ("exit_code", "stderr_category", "stderr", "stdout_json", "config")}
        return parity.compare(
            dict(result, stdout_text=self.reference if reference is None else reference),
            dict(result, stdout_text=self.candidate if candidate is None else candidate),
            self.case if case is None else case,
        )

    def test_exact_reviewed_schema_hash_updates_match(self):
        self.assertEqual([], self.differences())
        manifest = json.loads(self.candidate)
        for command, update in self.case["schema_sha256_updates"].items():
            entry = manifest["commands"][command]
            digest = hashlib.sha256((self.root / entry["schema"]).read_bytes()).hexdigest()
            self.assertEqual(update["candidate"], "sha256:" + digest)

    def test_unreviewed_hash_or_old_candidate_hash_is_a_difference(self):
        for update in self.case["schema_sha256_updates"].values():
            for replacement in ("sha256:" + "0" * 64, update["reference"]):
                with self.subTest(replacement=replacement):
                    self.assertEqual(["stdout_text"], self.differences(
                        candidate=self.candidate.replace(update["candidate"], replacement)))
            self.assertEqual(["stdout_text"], self.differences(
                reference=self.reference.replace(update["reference"], update["candidate"])))

    def test_other_manifest_bytes_remain_significant(self):
        for old, new in (("patch-stable-additive", "changed"),
                         ('"version": 3', '"version": 4'),
                         ("workflow-signal.schema.json", "wrong.schema.json"),
                         ('"output": "--json"', '"output": "--wrong"'),
                         ('  "commands": {', ' "commands": {')):
            with self.subTest(old=old):
                self.assertIn(old, self.candidate)
                self.assertEqual(["stdout_text"], self.differences(
                    candidate=self.candidate.replace(old, new, 1)))

    def test_hash_in_wrong_field_or_repeated_elsewhere_does_not_match(self):
        update = self.case["schema_sha256_updates"]["workflow:signal"]
        for candidate in (
            self.candidate.replace('"sha256": "' + update["candidate"],
                                   '"wrong": "' + update["candidate"]),
            self.candidate.replace('"stability": "patch-stable-additive"',
                                   '"stability": "' + update["candidate"] + '"'),
        ):
            self.assertEqual(["stdout_text"], self.differences(candidate=candidate))

    def test_unrelated_hash_changes_still_fail(self):
        digest = json.loads(self.candidate)["commands"]["workflow:list"]["sha256"]
        self.assertEqual(["stdout_text"], self.differences(
            candidate=self.candidate.replace(digest, "sha256:" + "0" * 64)))

    def test_other_cases_cannot_enable_manifest_hash_normalization(self):
        case = copy.deepcopy(self.case)
        case["argv"] = ["schema:show", "workflow:signal"]
        self.assertEqual(["stdout_text"], self.differences(case=case))
        del case["schema_sha256_updates"]
        self.assertEqual(["stdout_text"], self.differences(case=case))

    def test_invalid_manifest_is_a_difference(self):
        for candidate in (self.candidate + "invalid", "[]", "null"):
            self.assertEqual(["stdout_text"], self.differences(candidate=candidate))


class CatalogMetadataParityTest(unittest.TestCase):
    def setUp(self):
        self.updates = {"target:command": {
            "description": {"reference": "before", "candidate": "after"},
            "help": {"reference": "before help", "candidate": "after help"},
        }}
        self.reference = {"name": "target:command", "description": "before", "help": "before help",
                          "definition": {"arguments": ["unchanged"]}}
        self.candidate = dict(self.reference, description="after", help="after help")

    def normalize(self, value, side, argv=None):
        return parity.normalize_catalog_metadata(value, {"argv": argv or ["list"]}, side, self.updates)

    def test_only_exact_owned_metadata_fields_are_reconciled(self):
        self.assertEqual(self.normalize(self.reference, "reference"), self.normalize(self.candidate, "candidate"))
        for field, value in [("description", "unexpected"), ("name", "other:command"),
                             ("definition", {"arguments": ["changed"]})]:
            candidate = dict(self.candidate, **{field: value})
            self.assertNotEqual(self.normalize(self.reference, "reference"), self.normalize(candidate, "candidate"))

    def test_command_results_cannot_enable_catalog_normalization(self):
        self.assertEqual(self.reference, self.normalize(self.reference, "reference", ["target:command", "--json"]))

    def test_nested_argument_definitions_with_non_string_names_are_preserved(self):
        for name in [{"argument": "name"}, [], 7, None]:
            value = {"name": name, "definition": {}}
            self.assertEqual(value, self.normalize(value, "candidate"))

    def test_short_descriptor_entries_reconcile_only_the_owned_description(self):
        reference = {"name": "target:command", "description": "before", "extra": "kept"}
        candidate = dict(reference, description="after")
        argv = ["list", "--short", "--format=json"]
        self.assertEqual(self.normalize(reference, "reference", argv), self.normalize(candidate, "candidate", argv))
        candidate["extra"] = "changed"
        self.assertNotEqual(self.normalize(reference, "reference", argv), self.normalize(candidate, "candidate", argv))

    def test_text_requires_the_owned_command_and_a_unique_known_value(self):
        reference = "target:command before\n"
        candidate = "target:command after\n"
        self.assertEqual(self.normalize(reference, "reference"), self.normalize(candidate, "candidate"))
        for altered in ["other:command after\n", "target:command unexpected\n", candidate + "after\n"]:
            self.assertNotEqual(self.normalize(reference, "reference"), self.normalize(altered, "candidate"))

    def test_markdown_style_removal_keeps_the_exact_owned_help_change(self):
        self.updates["target:command"]["help"] = {
            "reference": "<info>before help</info>", "candidate": "<info>after help</info>"}
        self.assertEqual(self.normalize("target:command\nbefore help\n", "reference"),
                         self.normalize("target:command\nafter help\n", "candidate"))

    def test_completion_reconciles_only_the_exact_reviewed_description_row(self):
        case = {"argv": ["_complete", "-sfish"]}
        def normalize(value, side, descriptions=None):
            return parity.normalize_catalog_metadata(
                value, case, side, self.updates,
                {"target:command": "after"} if descriptions is None else descriptions)
        reference = "target:command\tbefore\nother:command\tkept\n"
        candidate = "target:command\tafter\nother:command\tkept\n"
        expected = normalize(reference, "reference")
        self.assertEqual(expected, normalize(candidate, "candidate"))
        for altered in [candidate.replace("after", "unexpected"),
                        candidate.replace("kept", "changed"),
                        candidate.replace("target:command", "unknown:command"),
                        candidate.replace("\tafter", " after"),
                        candidate.replace("\tafter", "\tafter extra")]:
            self.assertNotEqual(expected, normalize(altered, "candidate"))
        # A catalog change does not update an unchanged completion table.
        self.assertEqual(reference, normalize(reference, "reference", {"target:command": "before"}))
        self.assertEqual({"result": "after"}, normalize({"result": "after"}, "candidate"))


class HumanHelpDifferenceScopeTest(unittest.TestCase):
    def test_display_changes_cannot_hide_exit_json_or_config_regressions(self):
        with open(parity.CASES, encoding="utf-8") as handle:
            cases = json.load(handle)["cases"] + parity.help_cases()
        for case in cases:
            expected = case.get("expected_difference", {}).get("*", {})
            if "cli#159" not in expected.get("reason", ""):
                continue
            fields = expected["fields"]
            self.assertIn(fields, [["stdout_text"], ["stderr"]])
            self.assertIsNotNone(parity.intentional_reason(case, fields))
            for extra in ["exit_code", "stdout_json", "config", "stderr_category"]:
                self.assertIsNone(parity.intentional_reason(case, [*fields, extra]))


class PhpMainReferenceTest(unittest.TestCase):
    def setUp(self):
        self.directories = list(parity.BIN_DIRECTORIES)
        self.environment = {name: os.environ.get(name) for name in ("DW_PARITY_PHP_MAIN", "DW_PARITY_PHP")}

    def tearDown(self):
        parity.BIN_DIRECTORIES[:] = self.directories
        for name, value in self.environment.items():
            if value is None:
                os.environ.pop(name, None)
            else:
                os.environ[name] = value

    def checkout(self):
        root = Path(tempfile.mkdtemp(prefix="dw-php-main-"))
        self.addCleanup(shutil.rmtree, root, True)
        (root / "bin").mkdir()
        (root / "vendor").mkdir()
        (root / "bin" / "dw").write_text("<?php\n")
        (root / "vendor" / "autoload.php").write_text("<?php\n")
        return root

    def test_unconfigured_reference_is_absent(self):
        os.environ.pop("DW_PARITY_PHP_MAIN", None)
        self.assertIsNone(parity.php_main_runner({"version": "2.5.0"}))

    def test_runner_uses_the_checkout_and_the_candidate_identity(self):
        root = self.checkout()
        os.environ["DW_PARITY_PHP_MAIN"] = str(root)
        os.environ["DW_PARITY_PHP"] = "php"
        runner = parity.php_main_runner({"version": "2.5.0-rc.3", "commit": "abc", "build_date": "today"})
        self.assertEqual(["php", str(root / "bin" / "dw")], runner.command)
        self.assertEqual({"DW_PARITY_PHP_MAIN": str(root), "DW_CLI_VERSION": "2.5.0-rc.3",
                          "DW_CLI_COMMIT": "abc", "DW_CLI_BUILD_DATE": "today"}, runner.env)
        self.assertEqual("Run <bin>/dw workflow:signal",
                         parity.normalize_text(f"Run {root}/bin/dw workflow:signal", "/nowhere"))

    def test_checkout_without_dependencies_is_refused(self):
        root = self.checkout()
        (root / "vendor" / "autoload.php").unlink()
        os.environ["DW_PARITY_PHP_MAIN"] = str(root)
        with self.assertRaises(SystemExit):
            parity.php_main_runner({})

    def test_signal_help_compares_against_php_main(self):
        cases = {case["name"]: case for case in parity.help_cases()}
        self.assertEqual("php-main", cases["--help for workflow:signal"].get("reference"))
        self.assertNotIn("expected_difference", cases["--help for workflow:signal"])
        self.assertNotIn("reference", cases["--help for workflow:list"])

    def test_php_main_cases_do_not_excuse_the_2x_release_gap(self):
        with open(parity.CASES, encoding="utf-8") as handle:
            cases = json.load(handle)["cases"]
        tagged = [case for case in cases if case.get("reference") == "php-main"]
        self.assertTrue(any(case["argv"][0] == "workflow:signal" for case in tagged))
        for case in tagged:
            for reason in case.get("expected_difference", {}).values():
                self.assertNotIn("2.3.5", reason["reason"], case["name"])
                self.assertNotIn("cli#61", reason["reason"], case["name"])


class RustOnlyCommandTest(unittest.TestCase):
    NAMES = {"batch:cancel", "batch:show"}

    def test_the_catalog_marks_the_batch_activity_dispatch_and_update_outcome_commands_rust_only(self):
        self.assertEqual(["batch:cancel", "batch:resume", "batch:show", "batch:signal", "batch:update",
                          "task-queue:activity-dispatch", "task-queue:pause-activities",
                          "task-queue:resume-activities", "workflow:update-outcome"],
                         parity.rust_only_commands())

    def test_rust_only_help_is_skipped_but_2x_help_is_kept(self):
        names = [case["argv"][0] for case in parity.help_cases()]
        self.assertNotIn("batch:cancel", names)
        self.assertIn("workflow:cancel", names)

    def test_listings_lose_only_the_rust_only_lines(self):
        text = (" activity\n  activity:list  List activities\n \x1b[33mbatch\x1b[39m\n"
                "  \x1b[32mbatch:cancel\x1b[39m  Cancel\n  batch:show     Show\n"
                "  bridge:webhook Send\nbatching is a word\n")
        stripped = parity.strip_rust_only({"stdout_text": text, "stdout_json": None}, self.NAMES)
        self.assertEqual(" activity\n  activity:list  List activities\n  bridge:webhook Send\nbatching is a word\n",
                         stripped["stdout_text"])

    def test_a_namespace_shared_with_2x_commands_keeps_its_header(self):
        text = (" \x1b[33mtask-queue\x1b[39m\n  task-queue:activity-dispatch  Show\n"
                "  task-queue:describe          Describe\n  task-queue:pause-activities  Pause\n")
        stripped = parity.strip_rust_only({"stdout_text": text, "stdout_json": None},
                                          {"task-queue:activity-dispatch", "task-queue:pause-activities"})
        self.assertEqual(" \x1b[33mtask-queue\x1b[39m\n  task-queue:describe          Describe\n",
                         stripped["stdout_text"])

    def test_json_listings_lose_the_commands_and_their_empty_namespace(self):
        listing = [{"commands": [{"name": "batch:cancel"}, {"name": "env:list"}],
                    "namespaces": [{"id": "batch", "commands": ["batch:cancel", "batch:show"]},
                                   {"id": "env", "commands": ["env:list"]}]}]
        stripped = parity.strip_rust_only({"stdout_text": None, "stdout_json": listing}, self.NAMES)
        self.assertEqual([{"commands": [{"name": "env:list"}],
                           "namespaces": [{"id": "env", "commands": ["env:list"]}]}],
                         stripped["stdout_json"])


if __name__ == "__main__":
    unittest.main()
