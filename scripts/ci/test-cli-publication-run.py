#!/usr/bin/env python3
"""Focused contracts for exact CLI publication run retention."""

from __future__ import annotations

import hashlib
import importlib.util
import json
import subprocess
import sys
import unittest
from pathlib import Path

RECOVERY_SCRIPT = Path(__file__).with_name("component-release-recovery.py")
REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
REPOSITORY = "durable-workflow/cli"
RELEASE_TAG = "0.1.94"
RELEASE_COMMIT = "36bde75882980e834854a145c9ad0f61ceec4659"
PLAN_TAG = "release-plan/cli-recovery"
DISPLAY_TITLE = f"Release {RELEASE_TAG} for {PLAN_TAG}"
CONTROL_COMMIT = "d2b069acbd23219074f78a3162f2a2394b7beffc"
RUN_ID = 194


def workflow_job(workflow: str, name: str) -> str:
    lines = workflow.splitlines()
    start = lines.index(f"  {name}:")
    end = next(
        (
            index
            for index, line in enumerate(lines[start + 1 :], start=start + 1)
            if line.startswith("  ") and not line.startswith("   ")
        ),
        len(lines),
    )
    return "\n".join(lines[start:end])


def load_recovery_module():
    spec = importlib.util.spec_from_file_location("component_release_recovery_run_test", RECOVERY_SCRIPT)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


class PublicationRunRetentionTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_module()

    def run_record(self, **updates: object) -> dict[str, object]:
        value: dict[str, object] = {
            "databaseId": RUN_ID,
            "event": "workflow_dispatch",
            "displayTitle": DISPLAY_TITLE,
            "headBranch": "main",
            "headSha": CONTROL_COMMIT,
            "status": "in_progress",
            "conclusion": None,
            "url": f"https://github.com/{REPOSITORY}/actions/runs/{RUN_ID}",
            "workflowName": "Release",
        }
        value.update(updates)
        return value

    def select(self, runs: object) -> dict[str, object]:
        return self.recovery.select_publication_run(
            RELEASE_TAG,
            RELEASE_COMMIT,
            "workflow_dispatch",
            "main",
            DISPLAY_TITLE,
            runs,
        )

    def retain(self, run: object, **options: object) -> dict[str, object]:
        return self.recovery.retain_publication_run(
            REPOSITORY,
            RELEASE_TAG,
            RELEASE_COMMIT,
            "main",
            DISPLAY_TITLE,
            RUN_ID,
            run,
            **options,
        )

    def push_run_record(self, **updates: object) -> dict[str, object]:
        value = self.run_record(
            event="push",
            displayTitle=f"Release {RELEASE_TAG} for direct",
            headBranch=RELEASE_TAG,
            headSha=RELEASE_COMMIT,
        )
        value.update(updates)
        return value

    def test_manual_dispatches_require_main_before_repository_source_execution(self) -> None:
        workflows = {
            "release recovery": (
                REPOSITORY_ROOT / ".github/workflows/release-plan-recovery.yml",
                "discover",
            ),
            "release": (REPOSITORY_ROOT / ".github/workflows/release.yml", "resolve-release"),
        }
        protected_dispatch_guard = """    if: >-
      github.event_name != 'workflow_dispatch' ||
      github.ref == 'refs/heads/main'"""

        for label, (path, first_job) in workflows.items():
            with self.subTest(workflow=label):
                source = workflow_job(path.read_text(encoding="utf-8"), first_job)
                pre_steps, separator, steps = source.partition("    steps:\n")
                self.assertEqual("    steps:\n", separator)
                self.assertEqual(1, pre_steps.count(protected_dispatch_guard))
                self.assertIn("actions/checkout@", steps)

    def test_repository_workflow_is_the_pinned_protected_source(self) -> None:
        workflow = (REPOSITORY_ROOT / ".github/workflows/release-plan-recovery.yml").read_text(encoding="utf-8")
        fixture = (RECOVERY_SCRIPT.parent / "cli-release-plan-recovery.fixture.yml").read_text(encoding="utf-8")

        self.assertEqual(fixture, workflow)
        self.assertNotIn("return_run_details", workflow)
        self.assertNotIn(r'\n                  "ref": "main"', workflow)
        quarantine = workflow.index("Quarantine the exact tag-triggered publication run")
        dispatch = workflow.index("Start or resume the exact repository-owned publication run")
        verification_job = workflow.index("  verify-publication:")
        self.assertLess(quarantine, dispatch)
        self.assertLess(dispatch, verification_job)
        self.assertIn('gh run cancel "$run_id"', workflow)
        self.assertIn("retain-tag-push-run", workflow)
        publish_source = workflow[workflow.index("  publish:") : verification_job]
        verification_source = workflow[verification_job:]
        self.assertNotIn("--component cli --plan", publish_source)
        self.assertNotIn("CLI_RELEASE_DEPLOY_KEY", verification_source)
        self.assertIn("persist-credentials: false", verification_source)
        self.assertIn("--component cli --plan recovery-input/release-plan.json", verification_source)
        self.recovery.verify_recovery_workflow_source(
            "cli", workflow, hashlib.sha256(workflow.encode("utf-8")).hexdigest()
        )

        embedded = workflow.partition(" <<'PY'\n")[2].partition("\n          PY")[0]
        dispatch_source = "\n".join(line.removeprefix("          ") for line in embedded.splitlines())
        process = subprocess.run(
            [sys.executable, "-", RELEASE_TAG, RELEASE_COMMIT, PLAN_TAG],
            input=dispatch_source,
            check=False,
            text=True,
            capture_output=True,
        )
        self.assertEqual(0, process.returncode, process.stderr)
        self.assertEqual(
            {
                "ref": "main",
                "inputs": {
                    "tag": RELEASE_TAG,
                    "release_commit": RELEASE_COMMIT,
                    "release_plan": PLAN_TAG,
                },
            },
            json.loads(process.stdout),
        )

    def test_unprivileged_discovery_ignores_tag_push_and_selects_main_dispatch(self) -> None:
        push = self.run_record(
            databaseId=193,
            event="push",
            displayTitle=f"Release {RELEASE_TAG} for direct",
            headBranch=RELEASE_TAG,
            headSha=RELEASE_COMMIT,
            url=f"https://github.com/{REPOSITORY}/actions/runs/193",
        )
        selected = self.select([push, self.run_record()])

        self.assertEqual("dispatch", self.select([push])["action"])
        self.assertEqual("wait", selected["action"])
        self.assertEqual(RUN_ID, selected["run_id"])

    def test_absent_run_dispatches_and_interrupted_retry_resumes_exact_run(self) -> None:
        self.assertEqual("dispatch", self.select([])["action"])
        selected = self.select([self.run_record(status="queued")])
        self.assertEqual("wait", selected["action"])
        self.assertEqual(RUN_ID, selected["run_id"])

    def test_tag_push_run_must_be_cancelled_before_dispatch(self) -> None:
        self.assertEqual("observe", self.recovery.select_tag_push_run(RELEASE_TAG, RELEASE_COMMIT, [])["action"])
        active = self.recovery.select_tag_push_run(
            RELEASE_TAG,
            RELEASE_COMMIT,
            [self.push_run_record()],
        )
        self.assertEqual("cancel", active["action"])

        cancelled_run = self.push_run_record(status="completed", conclusion="cancelled")
        complete = self.recovery.select_tag_push_run(RELEASE_TAG, RELEASE_COMMIT, [cancelled_run])
        self.assertEqual("complete", complete["action"])
        evidence = self.recovery.retain_tag_push_run(
            REPOSITORY,
            RELEASE_TAG,
            RELEASE_COMMIT,
            RUN_ID,
            cancelled_run,
        )
        self.assertEqual("cancelled", evidence["run"]["conclusion"])
        self.assertEqual(RELEASE_COMMIT, evidence["run"]["head_commit"])

    def test_tag_push_quarantine_fails_closed_for_mismatch_or_other_conclusion(self) -> None:
        variants = {
            "wrong source": self.push_run_record(headSha="f" * 40),
            "published first": self.push_run_record(status="completed", conclusion="success"),
            "failed first": self.push_run_record(status="completed", conclusion="failure"),
        }
        for label, run in variants.items():
            with self.subTest(label=label):
                with self.assertRaises(self.recovery.RecoveryError):
                    self.recovery.select_tag_push_run(RELEASE_TAG, RELEASE_COMMIT, [run])

        with self.assertRaisesRegex(self.recovery.RecoveryError, "multiple tag-push runs"):
            self.recovery.select_tag_push_run(
                RELEASE_TAG,
                RELEASE_COMMIT,
                [
                    self.push_run_record(),
                    self.push_run_record(
                        databaseId=195,
                        url=f"https://github.com/{REPOSITORY}/actions/runs/195",
                    ),
                ],
            )

    def test_completed_failure_reruns_but_success_is_retained(self) -> None:
        failed = self.select([self.run_record(status="completed", conclusion="failure")])
        self.assertEqual("rerun", failed["action"])
        complete = self.select([self.run_record(status="completed", conclusion="success")])
        self.assertEqual("complete", complete["action"])

    def test_ambiguous_or_incomplete_runs_fail_closed(self) -> None:
        with self.assertRaisesRegex(self.recovery.RecoveryError, "multiple protected publication runs"):
            self.select(
                [
                    self.run_record(),
                    self.run_record(databaseId=195, url=f"https://github.com/{REPOSITORY}/actions/runs/195"),
                ]
            )
        with self.assertRaisesRegex(self.recovery.RecoveryError, "metadata is incomplete"):
            self.select([self.run_record(headSha="short")])

    def test_dispatch_response_must_return_the_exact_repository_run(self) -> None:
        response = {
            "workflow_run_id": RUN_ID,
            "run_url": f"https://api.github.com/repos/{REPOSITORY}/actions/runs/{RUN_ID}",
            "html_url": f"https://github.com/{REPOSITORY}/actions/runs/{RUN_ID}",
        }
        self.assertEqual(RUN_ID, self.recovery.validate_dispatch_response(REPOSITORY, response))

        for invalid in ({}, {**response, "html_url": "https://github.com/example/other/actions/runs/194"}):
            with self.subTest(invalid=invalid):
                with self.assertRaises(self.recovery.RecoveryError):
                    self.recovery.validate_dispatch_response(REPOSITORY, invalid)

    def test_retained_run_binds_control_authority_and_planned_package_source(self) -> None:
        evidence = self.retain(
            self.run_record(status="completed", conclusion="success"),
            require_success=True,
        )

        self.assertEqual({"tag": RELEASE_TAG, "commit": RELEASE_COMMIT}, evidence["release"])
        self.assertEqual("workflow_dispatch", evidence["run"]["event"])
        self.assertEqual("main", evidence["run"]["control_ref"])
        self.assertEqual(CONTROL_COMMIT, evidence["run"]["control_commit"])
        self.assertEqual(RUN_ID, evidence["run"]["id"])

    def test_retention_rejects_mismatch_failure_and_interrupted_state(self) -> None:
        variants = {
            "wrong event": (self.run_record(event="push"), {}),
            "wrong run": (self.run_record(databaseId=195), {}),
            "failed retry": (
                self.run_record(status="completed", conclusion="failure"),
                {"reject_completed_failure": True},
            ),
            "interrupted final check": (self.run_record(status="in_progress"), {"require_success": True}),
        }
        for label, (run, options) in variants.items():
            with self.subTest(label=label):
                with self.assertRaises(self.recovery.RecoveryError):
                    self.retain(run, **options)


if __name__ == "__main__":
    unittest.main()
