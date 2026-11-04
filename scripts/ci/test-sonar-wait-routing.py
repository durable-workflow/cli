#!/usr/bin/env python3
"""Keep PR scanning isolated and route only maintenance pushes without polling."""

from copy import deepcopy
from pathlib import Path
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[2]
BRANCH = "maintenance/2.3.6-base"
REFERENCE = "refs/heads/" + BRANCH
MAIN_CONDITION = "github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository"
MAINTENANCE_CONDITION = "github.event_name == 'push' && github.ref == '" + REFERENCE + "'"
ARGS = "-Dsonar.qualitygate.wait={} -Dsonar.qualitygate.timeout=300"


def triggers(workflow):
    return workflow.get("on", workflow.get(True))


def scanner(workflow):
    [scan] = [step for step in workflow["jobs"]["analysis"]["steps"]
              if step.get("uses", "").startswith("SonarSource/sonarqube-scan-action@")]
    return scan


def require_maintenance_boundary(workflow):
    job = workflow["jobs"]["analysis"]
    if (triggers(workflow) != {"push": {"branches": [BRANCH]}}
            or job.get("if") != MAINTENANCE_CONDITION
            or scanner(workflow).get("with") != {"args": ARGS.format("false")}):
        raise ValueError("Only the exact maintenance push may disable polling")


class SonarWaitRoutingTest(unittest.TestCase):
    def setUp(self):
        self.main = yaml.safe_load((ROOT / ".github/workflows/sonar.yml").read_text())
        self.maintenance = yaml.safe_load(
            (ROOT / ".github/workflows/sonar-maintenance.yml").read_text())

    def assert_main_profile(self, workflow):
        self.assertEqual({"push": {"branches": ["main"]},
                          "pull_request": {"branches": ["main", BRANCH]}}, triggers(workflow))
        self.assertEqual(MAIN_CONDITION, workflow["jobs"]["analysis"]["if"])
        self.assertEqual({"args": ARGS.format("true")}, scanner(workflow)["with"])

    def test_main_and_pr_scanner_keeps_its_existing_isolated_profile(self):
        self.assert_main_profile(self.main)

    def test_maintenance_has_only_the_exact_push_boundary(self):
        require_maintenance_boundary(self.maintenance)
        self.assertEqual({"analysis"}, set(self.maintenance["jobs"]))

    def assert_scanner_isolation(self, workflow):
        self.assertEqual({"contents": "read"}, workflow["permissions"])
        job = workflow["jobs"]["analysis"]
        self.assertEqual({"name", "if", "runs-on", "timeout-minutes", "steps"}, set(job))
        self.assertEqual("ubuntu-latest", job["runs-on"])
        self.assertEqual(15, job["timeout-minutes"])
        self.assertEqual([
            {"uses": "actions/checkout@d23441a48e516b6c34aea4fa41551a30e30af803",
             "with": {"fetch-depth": 0, "persist-credentials": False}},
            {"uses": "SonarSource/sonarqube-scan-action@d209202bc7d53ff1cc128f7f907dac145c9d6ae9",
             "env": {"SONAR_TOKEN": "${{ secrets.SONAR_TOKEN }}"},
             "with": scanner(workflow)["with"]},
        ], job["steps"])

    def test_both_scanners_keep_upload_and_credential_isolation(self):
        for workflow in (self.main, self.maintenance):
            with self.subTest(workflow=workflow["name"]):
                self.assert_scanner_isolation(workflow)

    def test_changed_checkout_token_or_execution_is_refused(self):
        for source in (self.main, self.maintenance):
            for mutation in ("checkout", "token", "execution", "continue"):
                workflow = deepcopy(source)
                job = workflow["jobs"]["analysis"]
                if mutation == "checkout":
                    job["steps"][0]["with"]["persist-credentials"] = True
                elif mutation == "token":
                    scanner(workflow)["env"]["SONAR_TOKEN"] = "${{ secrets.OTHER_TOKEN }}"
                elif mutation == "execution":
                    job["steps"].insert(1, {"run": "node scripts/candidate.js"})
                else:
                    job["continue-on-error"] = True
                with self.subTest(workflow=source["name"], mutation=mutation), self.assertRaises(AssertionError):
                    self.assert_scanner_isolation(workflow)

    def test_overlapping_maintenance_push_route_is_refused(self):
        workflow = deepcopy(self.main)
        triggers(workflow)["push"]["branches"].append(BRANCH)
        with self.assertRaises(AssertionError):
            self.assert_main_profile(workflow)

    def test_event_routing_produces_one_analysis_with_required_wait(self):
        cases = [
            ("push", REFERENCE, "", True, [False]),
            ("push", "refs/heads/main", "", True, [True]),
            ("pull_request", "refs/pull/1/merge", BRANCH, True, [True]),
            ("pull_request", "refs/pull/1/merge", "main", True, [True]),
            ("pull_request", "refs/pull/1/merge", BRANCH, False, []),
            ("push", REFERENCE + "-extra", "", True, []),
            ("push", "refs/heads/maintenance/other", "", True, []),
            ("push", "refs/tags/2.3.7", "", True, []),
            ("workflow_dispatch", REFERENCE, "", True, []),
            ("schedule", REFERENCE, "", True, []),
        ]
        for event, reference, base, same_repository, expected in cases:
            with self.subTest(event=event, reference=reference, base=base):
                actual = []
                for workflow in (self.main, self.maintenance):
                    route = triggers(workflow).get(event)
                    branch = base if event == "pull_request" else reference.removeprefix("refs/heads/")
                    if route is None or branch not in route["branches"]:
                        continue
                    if workflow is self.main and event == "pull_request" and not same_repository:
                        continue
                    actual.append(scanner(workflow)["with"]["args"] == ARGS.format("true"))
                self.assertEqual(expected, actual)

    def test_broader_triggers_or_conditions_are_refused(self):
        for mutation in ("pr", "main", "wildcard", "condition", "wait", "retarget"):
            workflow = deepcopy(self.maintenance)
            route = triggers(workflow)
            if mutation == "pr":
                route["pull_request"] = {"branches": [BRANCH]}
            elif mutation == "main":
                route["push"]["branches"].append("main")
            elif mutation == "wildcard":
                route["push"]["branches"] = ["maintenance/**"]
            elif mutation == "condition":
                workflow["jobs"]["analysis"]["if"] = "github.event_name == 'push'"
            elif mutation == "wait":
                scanner(workflow)["with"]["args"] = ARGS.format("true")
            else:
                scanner(workflow)["with"]["args"] += " -Dsonar.branch.name=main"
            with self.subTest(mutation=mutation), self.assertRaises(ValueError):
                require_maintenance_boundary(workflow)


if __name__ == "__main__":
    unittest.main()
