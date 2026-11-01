#!/usr/bin/env python3

import os
from pathlib import Path
import subprocess
import tempfile
import unittest

import yaml


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/release.yml"
CACHE_REVISION = "caa296126883cff596d87d8935842f9db880ef25"
MAIN = "a" * 40
RELEASE = "b" * 40


def compact(expression: str) -> str:
    return " ".join(expression.split())


class ReleaseCachePolicyTest(unittest.TestCase):
    def setUp(self) -> None:
        self.workflow = yaml.safe_load(WORKFLOW.read_text(encoding="utf-8"))

    def native_jobs(self):
        for name in ("build-binary", "build-binary-windows"):
            yield self.workflow["jobs"][name]

    def test_restores_have_no_automatic_save_post_action(self) -> None:
        actions = [
            step
            for job in self.native_jobs()
            for step in job["steps"]
            if step.get("uses", "").startswith("actions/cache")
        ]
        self.assertEqual(6, len(actions))
        self.assertEqual(
            {f"actions/cache/{mode}@{CACHE_REVISION}" for mode in ("restore", "save")},
            {step["uses"] for step in actions},
        )
        for job in self.native_jobs():
            restores = {
                step["id"]: step
                for step in job["steps"]
                if step.get("uses", "").startswith("actions/cache/restore@")
            }
            saves = [
                step for step in job["steps"]
                if step.get("uses", "").startswith("actions/cache/save@")
            ]
            self.assertEqual(len(restores), len(saves))
            for save in saves:
                cache = (
                    "native-prerequisite-cache"
                    if save["with"]["key"].startswith("native-prerequisite-")
                    else "phpmicro-cache"
                )
                self.assertEqual(restores[cache]["with"], save["with"])
                self.assertNotIn("restore-keys", save["with"])

    def test_only_verified_default_branch_cache_warming_can_save(self) -> None:
        saves = [
            (job, index, step)
            for job in self.native_jobs()
            for index, step in enumerate(job["steps"])
            if step.get("uses", "").startswith("actions/cache/save@")
        ]
        self.assertEqual(3, len(saves))
        for job, index, step in saves:
            native = step["with"]["key"].startswith("native-prerequisite-")
            cache = "native-prerequisite-cache" if native else "phpmicro-cache"
            expected = (
                ("runner.os == 'Linux' && " if native else "")
                + "github.repository == 'durable-workflow/cli' && "
                + "needs.resolve-release.outputs.cache_warm == 'true' && "
                + "github.ref == 'refs/heads/main' && "
                + "needs.resolve-release.outputs.commit == github.sha && "
                + "needs.resolve-release.outputs.control_commit == github.sha && "
                + "(github.event_name == 'push' || "
                + "github.event_name == 'schedule' || "
                + "github.event_name == 'workflow_dispatch') && "
                + f"steps.{cache}.outputs.cache-hit != 'true'"
            )
            self.assertEqual(expected, compact(step["if"]))
            guard = "native-prerequisites" if native else "runtime-policy"
            guard_index = next(
                i for i, candidate in enumerate(job["steps"])
                if candidate.get("id") == guard
            )
            self.assertGreater(index, guard_index)
            self.assertNotIn("continue-on-error", job["steps"][guard_index])
            self.assertNotIn("if", job["steps"][guard_index])

    def resolve(self, event, ref, operation="", commit="", tag=""):
        step = next(
            step
            for step in self.workflow["jobs"]["resolve-release"]["steps"]
            if step.get("id") == "resolve"
        )
        sha = RELEASE if ref.startswith("refs/tags/") else MAIN
        with tempfile.TemporaryDirectory(prefix="cli-release-cache-") as directory:
            output = Path(directory) / "outputs"
            env = dict(os.environ)
            env.update({
                "GITHUB_EVENT_NAME": event,
                "GITHUB_REF_NAME": ref.removeprefix("refs/heads/").removeprefix("refs/tags/"),
                "GITHUB_OUTPUT": str(output),
                "DISPATCH_OPERATION": operation,
                "DISPATCH_TAG": tag,
                "DISPATCH_COMMIT": commit,
                "PUSH_COMMIT": sha,
                "CONTROL_REF": ref,
                "CONTROL_COMMIT": sha,
            })
            result = subprocess.run(
                ["bash", "-euo", "pipefail", "-c", step["run"]],
                cwd=ROOT,
                env=env,
                capture_output=True,
                text=True,
                timeout=10,
            )
            values = dict(
                line.split("=", 1)
                for line in output.read_text().splitlines()
            ) if output.exists() else {}
            return result, values, sha

    def test_warm_events_use_the_same_protected_source_and_control(self) -> None:
        for event, operation in (
            ("push", ""),
            ("schedule", ""),
            ("workflow_dispatch", "phpmicro-cache-warm"),
        ):
            with self.subTest(event=event):
                result, values, sha = self.resolve(
                    event, "refs/heads/main", operation, RELEASE, "2.3.3"
                )
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertEqual("true", values["cache_warm"])
                self.assertEqual("", values["tag"])
                self.assertEqual(sha, values["commit"])
                self.assertEqual(sha, values["control_commit"])
                self.assertEqual("refs/heads/main", values["control_ref"])

    def test_tag_and_manual_releases_never_select_cache_warming(self) -> None:
        for event, ref, operation, commit, tag in (
            ("push", "refs/tags/2.3.3", "", "", ""),
            ("workflow_dispatch", "refs/heads/main", "release", RELEASE, "2.3.3"),
        ):
            with self.subTest(event=event):
                result, values, _ = self.resolve(event, ref, operation, commit, tag)
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertEqual("false", values["cache_warm"])
                self.assertEqual(RELEASE, values["commit"])
                self.assertEqual("2.3.3", values["tag"])

    def test_untrusted_refs_and_incomplete_releases_are_rejected(self) -> None:
        for event, ref, operation, commit, tag in (
            ("schedule", "refs/heads/topic", "", "", ""),
            ("workflow_dispatch", "refs/heads/topic", "phpmicro-cache-warm", "", ""),
            ("workflow_dispatch", "refs/heads/topic", "release", RELEASE, "2.3.3"),
            ("workflow_dispatch", "refs/heads/main", "release", RELEASE, ""),
            ("workflow_dispatch", "refs/heads/main", "release", "short", "2.3.3"),
        ):
            with self.subTest(event=event, ref=ref, operation=operation, commit=commit):
                result, values, _ = self.resolve(event, ref, operation, commit, tag)
                self.assertNotEqual(0, result.returncode)
                self.assertEqual({}, values)


if __name__ == "__main__":
    unittest.main()
