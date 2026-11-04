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


if __name__ == "__main__":
    unittest.main()
