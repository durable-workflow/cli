#!/usr/bin/env python3
"""Executable coverage for protected CLI release tag publication."""

from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).with_name("publish-planned-tag.py")
REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
PLAN_TAG = "release-plan/cli-recovery-test"
RELEASE_TAG = "0.1.94"
RELEASE_COMMIT = "36bde75882980e834854a145c9ad0f61ceec4659"


def git(*arguments: str, cwd: Path | None = None, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["git", *arguments],
        cwd=cwd,
        check=check,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )


class PlannedTagPublicationTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory(prefix="cli-planned-tag-test-")
        self.root = Path(self.temporary.name)
        self.source = self.root / "source"
        self.remote = self.root / "remote.git"
        git("init", "--quiet", "--initial-branch=main", str(self.source))
        git("init", "--quiet", "--bare", str(self.remote))
        git("config", "user.name", "Release Test", cwd=self.source)
        git("config", "user.email", "release-test@example.invalid", cwd=self.source)
        self.first = self.commit("first")
        self.second = self.commit("second")

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def commit(self, value: str, package: str = "durable-workflow/cli") -> str:
        (self.source / "value.txt").write_text(f"{value}\n", encoding="utf-8")
        (self.source / "composer.json").write_text(
            json.dumps({"name": package}, indent=2) + "\n",
            encoding="utf-8",
        )
        git("add", "composer.json", "value.txt", cwd=self.source)
        git("commit", "--quiet", "-m", value, cwd=self.source)
        return git("rev-parse", "HEAD", cwd=self.source).stdout.strip()

    def write_plan(
        self,
        commit: str,
        *,
        version: str = RELEASE_TAG,
        plan: str = "cli-recovery-test",
        name: str = "plan.json",
    ) -> Path:
        path = self.root / name
        path.write_text(
            json.dumps(
                {
                    "schema": "durable-workflow.release-plan/v1",
                    "plan": plan,
                    "components": {"cli": {"version": version, "commit": commit}},
                },
                indent=2,
            )
            + "\n",
            encoding="utf-8",
        )
        return path

    def publish(
        self,
        commit: str,
        *,
        plan: Path | None = None,
        evidence_name: str = "evidence.json",
        cwd: Path | None = None,
        remote: Path | None = None,
    ) -> subprocess.CompletedProcess[str]:
        plan_path = plan or self.write_plan(commit)
        return subprocess.run(
            [
                sys.executable,
                str(SCRIPT),
                "--remote",
                str(remote or self.remote),
                "--tag",
                RELEASE_TAG,
                "--commit",
                commit,
                "--plan-tag",
                PLAN_TAG,
                "--plan",
                str(plan_path),
                "--evidence",
                str(self.root / evidence_name),
            ],
            cwd=cwd or self.source,
            check=False,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )

    def evidence(self, name: str = "evidence.json") -> dict[str, object]:
        return json.loads((self.root / name).read_text(encoding="utf-8"))

    def remote_tag(self, remote: Path | None = None) -> str:
        return git(
            "--git-dir",
            str(remote or self.remote),
            "rev-parse",
            f"refs/tags/{RELEASE_TAG}",
        ).stdout.strip()

    def test_creates_exact_tag_and_identical_retry_is_idempotent(self) -> None:
        created = self.publish(self.first)
        self.assertEqual(0, created.returncode, created.stderr)
        self.assertEqual(self.first, self.remote_tag())
        self.assertEqual("created", self.evidence()["action"])

        verified = self.publish(self.first, evidence_name="retry.json")
        self.assertEqual(0, verified.returncode, verified.stderr)
        self.assertEqual(self.first, self.remote_tag())
        self.assertEqual("verified", self.evidence("retry.json")["action"])

    def test_rejects_occupied_mismatched_tag_without_mutation(self) -> None:
        git("push", str(self.remote), f"{self.second}:refs/tags/{RELEASE_TAG}", cwd=self.source)

        rejected = self.publish(self.first)

        self.assertEqual(1, rejected.returncode)
        self.assertEqual(self.second, self.remote_tag())
        evidence = self.evidence()
        self.assertEqual("source-tag", evidence["phase"])
        self.assertEqual(self.first, evidence["planned_commit"])

    def test_rejects_plan_version_or_commit_mismatch_before_mutation(self) -> None:
        variants = {
            "version": self.write_plan(self.first, version="0.1.95", name="version.json"),
            "commit": self.write_plan(self.second, name="commit.json"),
            "plan": self.write_plan(self.first, plan="different-plan", name="plan-name.json"),
        }
        for label, plan in variants.items():
            with self.subTest(label=label):
                rejected = self.publish(self.first, plan=plan, evidence_name=f"{label}.evidence.json")
                self.assertEqual(1, rejected.returncode)
                self.assertEqual("plan-identity", self.evidence(f"{label}.evidence.json")["phase"])
                self.assertEqual(
                    "",
                    git("ls-remote", str(self.remote), f"refs/tags/{RELEASE_TAG}").stdout,
                )

    def test_rejects_source_package_mismatch_before_mutation(self) -> None:
        conflicting = self.commit("wrong package", "example/not-cli")
        rejected = self.publish(conflicting)

        self.assertEqual(1, rejected.returncode)
        evidence = self.evidence()
        self.assertEqual("source-identity", evidence["phase"])
        self.assertEqual("terminal-source-identity-conflict", evidence["classification"])
        self.assertEqual("example/not-cli", evidence["package"])
        self.assertEqual(
            "",
            git("ls-remote", str(self.remote), f"refs/tags/{RELEASE_TAG}").stdout,
        )

    def test_records_repository_authority_rejection_without_credentials(self) -> None:
        leaked_token = "ghp_" + "abcdefghijklmnopqrstuvwxyz" + "1234567890"
        hook = self.remote / "hooks" / "pre-receive"
        hook.write_text(
            "#!/usr/bin/env bash\n"
            "printf '%s\\n' 'release policy refused the exact planned tag' >&2\n"
            f"printf '%s\\n' 'Authorization: Bearer {leaked_token}' >&2\n"
            "printf '%02048d' 0 >&2\n"
            "exit 1\n",
            encoding="utf-8",
        )
        os.chmod(hook, 0o755)

        rejected = self.publish(self.first)

        self.assertEqual(1, rejected.returncode)
        evidence = self.evidence()
        self.assertEqual("repository-authority", evidence["phase"])
        self.assertIn("write deploy key", str(evidence["effective_permission_boundary"]))
        self.assertIn("[REDACTED]", str(evidence["remote_diagnostic"]))
        self.assertNotIn(leaked_token, json.dumps(evidence))
        self.assertNotIn(leaked_token, rejected.stderr)

    def test_exact_recovery_tuple_creates_0_1_94_from_planned_commit(self) -> None:
        self.assertEqual(
            "commit",
            git("cat-file", "-t", RELEASE_COMMIT, cwd=REPOSITORY_ROOT).stdout.strip(),
        )
        exact_remote = self.root / "exact.git"
        git("init", "--quiet", "--bare", str(exact_remote))
        exact_plan = self.write_plan(RELEASE_COMMIT, name="exact-plan.json")

        created = self.publish(
            RELEASE_COMMIT,
            plan=exact_plan,
            evidence_name="exact-evidence.json",
            cwd=REPOSITORY_ROOT,
            remote=exact_remote,
        )

        self.assertEqual(0, created.returncode, created.stderr)
        self.assertEqual(RELEASE_COMMIT, self.remote_tag(exact_remote))
        evidence = self.evidence("exact-evidence.json")
        self.assertEqual(
            {"version": RELEASE_TAG, "commit": RELEASE_COMMIT},
            evidence["plan_identity"],
        )
        self.assertEqual(f"refs/tags/{RELEASE_TAG}", evidence["source_tag"]["ref"])
        self.assertEqual(RELEASE_COMMIT, evidence["source_tag"]["commit"])
        self.assertEqual(
            {"manifest_path": "composer.json", "package": "durable-workflow/cli"},
            evidence["source_identity"],
        )


if __name__ == "__main__":
    unittest.main()
