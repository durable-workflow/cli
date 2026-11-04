#!/usr/bin/env python3
"""Keep analysis uploads and scope the unsupported wait to one push/ref."""

from pathlib import Path
import re
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[2]
REFERENCE = "refs/heads/maintenance/2.3.6-base"


def wait_enabled(arguments, event, reference):
    if arguments == "-Dsonar.qualitygate.wait=true -Dsonar.qualitygate.timeout=300":
        return True
    match = re.fullmatch(
        r"-Dsonar\.qualitygate\.wait=\$\{\{ !\(github\.event_name == '([^']+)' && "
        r"github\.ref == '([^']+)'\) \}\} -Dsonar\.qualitygate\.timeout=300",
        arguments,
    )
    if match is None or match.groups() != ("push", REFERENCE):
        raise ValueError("Only the admitted exact event/ref exception is supported")
    return not (event == match[1] and reference == match[2])


class SonarWaitRoutingTest(unittest.TestCase):
    def setUp(self):
        self.workflow = yaml.safe_load((ROOT / ".github/workflows/sonar.yml").read_text())
        self.job = self.workflow["jobs"]["analysis"]
        [self.scan] = [step for step in self.job["steps"]
                       if step.get("uses", "").startswith("SonarSource/sonarqube-scan-action@")]

    def test_only_the_exact_maintenance_push_stops_waiting(self):
        cases = [
            ("push", REFERENCE, False),
            ("push", "refs/heads/main", True),
            ("pull_request", REFERENCE, True),
            ("pull_request", "refs/pull/1/merge", True),
            ("push", "refs/heads/maintenance/other", True),
            ("push", "refs/heads/maintenance/2.3.6-base-extra", True),
            ("push", "refs/tags/2.3.7", True),
            ("workflow_dispatch", REFERENCE, True),
            ("schedule", REFERENCE, True),
            ("", "", True),
        ]
        for event, reference, expected in cases:
            with self.subTest(event=event, reference=reference):
                self.assertIs(expected, wait_enabled(self.scan["with"]["args"], event, reference))

    def test_analysis_upload_and_existing_security_boundary_remain(self):
        self.assertNotIn("if", self.scan)
        self.assertNotIn("continue-on-error", self.scan)
        self.assertNotIn("continue-on-error", self.job)
        self.assertEqual(
            "github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository",
            self.job["if"],
        )
        self.assertEqual("${{ secrets.SONAR_TOKEN }}", self.scan["env"]["SONAR_TOKEN"])
        self.assertNotIn("sonar.branch.name", self.scan["with"]["args"])
        self.assertNotIn("sonar.projectKey", self.scan["with"]["args"])

    def test_main_and_maintenance_keep_push_and_pr_analysis(self):
        triggers = self.workflow.get("on", self.workflow.get(True))
        for event in ("push", "pull_request"):
            self.assertEqual(["main", "maintenance/2.3.6-base"], triggers[event]["branches"])

    def test_broad_or_unrelated_exceptions_are_rejected(self):
        for arguments in (
            "-Dsonar.qualitygate.wait=false -Dsonar.qualitygate.timeout=300",
            "-Dsonar.qualitygate.wait=${{ github.event_name == 'push' }} -Dsonar.qualitygate.timeout=300",
            "-Dsonar.qualitygate.wait=${{ !(github.event_name == 'pull_request' && github.ref == 'refs/heads/main') }} -Dsonar.qualitygate.timeout=300",
        ):
            with self.subTest(arguments=arguments), self.assertRaises(ValueError):
                wait_enabled(arguments, "push", REFERENCE)


if __name__ == "__main__":
    unittest.main()
