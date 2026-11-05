import copy
import hashlib
import json
from pathlib import Path
import unittest

import parity


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


if __name__ == "__main__":
    unittest.main()
