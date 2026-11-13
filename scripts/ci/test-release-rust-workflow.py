#!/usr/bin/env python3
"""Static rules for the Rust release workflows (#72, #75).

- release-rust.yml: only the publish job holds write access, attestation or
  OIDC permissions, and it runs no repository code: pinned actions,
  preinstalled system tools and inline commands only, on one digest-checked
  handoff. It has no pull request trigger.
- rust-release-build.yml is the only place the tagged source is checked out,
  built or run, and it is read-only.
- release-rust-dry-run.yml runs that build for pull requests with no secrets,
  environment or write permission.
- release.yml and release-rust.yml split versions at the same gate.
"""

from pathlib import Path
import re
import hashlib
import json
import os
import subprocess
import tempfile
import unittest

import yaml


ROOT = Path(__file__).resolve().parents[2]
RUST = ROOT / ".github/workflows/release-rust.yml"
BUILD = ROOT / ".github/workflows/rust-release-build.yml"
DRY_RUN = ROOT / ".github/workflows/release-rust-dry-run.yml"
LOCAL_BUILD = "./.github/workflows/rust-release-build.yml"
PHP = ROOT / ".github/workflows/release.yml"
VERSION_SCRIPT = ROOT / "scripts/ci/release-version.js"

PINNED = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_./-]+@[0-9a-f]{40}$")
CHECKOUT = "actions/checkout@d23441a48e516b6c34aea4fa41551a30e30af803"
DOWNLOAD = "actions/download-artifact@3e5f45b2cfb9172054b4087a40e8e0b5a5461e7c"
ATTEST = "actions/attest-build-provenance@0f67c3f4856b2e3261c31976d6725780e5e4c373"
VALIDATOR_STEP = "Validate the exact producer artifact before use"
INSTALLERS = [ROOT / "scripts/install.sh", ROOT / "scripts/install.ps1", ROOT / "scripts/verify-release.sh"]
# The exact signer identity, anchored at both ends, for durable-workflow/cli.
SIGNER_REGEX = (
    r"^https://github\.com/durable-workflow/cli/\.github/workflows/(release|release-rust)\.yml"
    r"@refs/(heads/main|tags/v?[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.+-]+)?)$"
)
_SHELL_SIGNER = (
    'signer="^https://github\\\\.com/${repo_pattern}/\\\\.github/workflows/(release|release-rust)\\\\.yml'
    '@refs/(heads/main|tags/v?[0-9]+\\\\.[0-9]+\\\\.[0-9]+([-+][0-9A-Za-z.+-]+)?)\\$"'
)
SIGNER_PATTERNS = {
    "install.sh": _SHELL_SIGNER,
    "verify-release.sh": _SHELL_SIGNER,
    "install.ps1": (
        '$signer = "^https://github\\.com/$repoPattern/\\.github/workflows/(release|release-rust)\\.yml'
        '@refs/(heads/main|tags/v?[0-9]+\\.[0-9]+\\.[0-9]+([-+][0-9A-Za-z.+-]+)?)$"'
    ),
}

# Every action each job may use, without its pin. A cache action anywhere,
# or any new action, fails until it is reviewed and added here.
ALLOWED_ACTIONS = {
    "release": {
        "resolve": {"actions/checkout"},
        "preflight": {"actions/checkout"},
        "bundle": {"actions/download-artifact", "actions/upload-artifact"},
        "publish": {"actions/checkout", "actions/download-artifact", "actions/attest-build-provenance"},
        "verify-release": {"actions/checkout"},
        "upgrade-from-2x": set(),
    },
    "build": {
        "build": {
            "actions/checkout", "actions/setup-python", "actions/upload-artifact", "dtolnay/rust-toolchain",
            "durable-workflow/.github/.github/actions/bootstrap-patched-pip",
        },
        "verify": {"actions/checkout", "actions/download-artifact"},
        "assemble": {"actions/checkout", "actions/download-artifact", "actions/upload-artifact"},
    },
    "dry-run": {"resolve": {"actions/checkout"}},
}

# The publish job's inline commands, pinned exactly. Any change (a script
# path, a tar option, a gh extension) fails until reviewed and re-pinned.
PUBLISH_RUN_DIGESTS = {
    "Validate the exact producer artifact before use": "ce06e15b0e2592c8deb55ae4f410e020afb89a6d675d140b0c5b9b4280bf9eaa",
    "Refuse a tag that already has a GitHub Release": "cd035d1140208c847586f081760b4692c65ce3572a373ccbbc7affdaa362433a",
    "Extract only the allowlisted release files": "e7e987063b8155bd594ab9be5311540d73ec5a9f3990287753621d491107a84b",
    "Verify the immutable release tag at the publication boundary": "21a53cce4259e3fb724a0d8e3c893a2b6252eaec8b76f043da750f4a110b3cf2",
    "Create GitHub Release": "da0ca017711cff769a7a3382283b7d81e1d485c0561cd7af9b4edf50f16d55df",
}

# Commands that would execute repository code: interpreters, build tools,
# package managers, and any script path.
PROJECT_CODE = re.compile(
    r"(?:^|[\s;&|(`$])(?:"
    r"(?:python[0-9.]*|pip[0-9.]*|node|npm|npx|php|composer|cargo|rustc|rustup|make|"
    r"bash|sh|pwsh|ruby|perl|go|java|deno|bun|source|\.)(?=\s|$)"
    r"|\./|\.\./|scripts/|rust/|release-control/"
    r")",
    re.MULTILINE,
)

READ_ONLY = {"contents": "read"}
JOB_PERMISSIONS = {
    "build": READ_ONLY,
    "publish": {"actions": "read", "attestations": "write", "contents": "write", "id-token": "write"},
    "verify-release": {"attestations": "read", "contents": "read"},
}
TAGGED_TREE_JOBS = {"build", "verify", "assemble"}
RELEASE_ONLY_JOBS = {"preflight", "build", "bundle", "publish", "verify-release", "upgrade-from-2x"}


ASSET_MUTATION = re.compile(r"gh release (upload|edit)|releases/(.*/)?assets|uploads\.github\.com")


def json_text(value):
    return yaml.safe_dump(value)


def compact(expression):
    return " ".join(str(expression).split())


def version_gate(version):
    return subprocess.run(
        ["node", str(VERSION_SCRIPT), "implementation", version],
        check=True, capture_output=True, text=True,
    ).stdout.strip()


class ReleaseRustWorkflowTest(unittest.TestCase):
    def setUp(self):
        self.text = RUST.read_text(encoding="utf-8")
        self.workflow = yaml.safe_load(self.text)
        self.jobs = self.workflow["jobs"]
        self.build = yaml.safe_load(BUILD.read_text(encoding="utf-8"))
        self.dry_run = yaml.safe_load(DRY_RUN.read_text(encoding="utf-8"))

    def all_jobs(self):
        for label, document in (("release", self.workflow), ("build", self.build), ("dry-run", self.dry_run)):
            for name, job in document["jobs"].items():
                yield f"{label}:{name}", job

    def steps(self, job):
        return self.jobs[job]["steps"]

    # Permissions and secrets.

    def test_workflow_defaults_are_read_only(self):
        for document in (self.workflow, self.build, self.dry_run):
            self.assertEqual(READ_ONLY, document["permissions"])
            self.assertNotIn("env", document)
            self.assertNotIn("defaults", document)

    def test_build_and_dry_run_jobs_hold_nothing_privileged(self):
        for document in (self.build, self.dry_run):
            for name, job in document["jobs"].items():
                self.assertIn(job.get("permissions", READ_ONLY), (READ_ONLY,), name)
                self.assertNotIn("environment", job, name)
                self.assertNotIn("secrets", job, name)

    def test_only_publish_writes_attests_or_mints_tokens(self):
        for name, job in self.jobs.items():
            expected = JOB_PERMISSIONS.get(name)
            if expected is None:
                self.assertNotIn("permissions", job, name)
            else:
                self.assertEqual(expected, job["permissions"], name)
            if name != "publish":
                self.assertNotIn("environment", job, name)

    def test_legacy_php_container_is_bound_to_an_exact_official_image(self):
        image = self.jobs["upgrade-from-2x"]["env"]["LEGACY_PHP_IMAGE"]
        self.assertRegex(image, r"^php:[0-9]+\.[0-9]+\.[0-9]+-cli-bookworm@sha256:[0-9a-f]{64}$")
        run = "\n".join(step.get("run", "") for step in self.steps("upgrade-from-2x"))
        self.assertIn('"$LEGACY_PHP_IMAGE" php "$@"', run)
        self.assertNotIn("sudo", run)

    def test_no_secrets_anywhere(self):
        for path in (RUST, BUILD, DRY_RUN):
            self.assertNotRegex(path.read_text(encoding="utf-8"), r"secrets\.", path.name)
        for name, job in self.all_jobs():
            self.assertNotIn("secrets", job, name)

    # Pull requests.

    def test_triggers_keep_pull_requests_away_from_publication(self):
        self.assertEqual({"push", "workflow_dispatch"}, set(self.workflow[True]))
        self.assertEqual({"workflow_call"}, set(self.build[True]))
        self.assertEqual({"pull_request"}, set(self.dry_run[True]))
        for name in RELEASE_ONLY_JOBS:
            condition = compact(self.jobs[name]["if"])
            self.assertRegex(condition, r"needs\.resolve\.outputs\.mode == 'release'", name)

    def test_both_callers_use_the_same_local_build(self):
        for document in (self.workflow, self.dry_run):
            self.assertEqual(LOCAL_BUILD, document["jobs"]["build"]["uses"])
            self.assertEqual(READ_ONLY, document["jobs"]["build"]["permissions"])
        self.assertEqual("dry-run", self.dry_run["jobs"]["build"]["with"]["mode"])

    # The publish job.

    def test_jobs_use_only_allowlisted_actions(self):
        documents = {"release": self.workflow, "build": self.build, "dry-run": self.dry_run}
        for label, document in documents.items():
            for name, job in document["jobs"].items():
                if "uses" in job:
                    self.assertEqual(LOCAL_BUILD, job["uses"], f"{label}:{name}")
                    continue
                used = {step["uses"].split("@")[0] for step in job["steps"] if "uses" in step}
                self.assertEqual(ALLOWED_ACTIONS[label][name], used, f"{label}:{name}")

    def test_publish_runs_in_the_protected_release_environment(self):
        self.assertEqual("github-release", self.jobs["publish"]["environment"])

    def test_publish_commands_are_pinned_exactly(self):
        runs = {step["name"]: step["run"] for step in self.steps("publish") if "run" in step}
        self.assertEqual(set(PUBLISH_RUN_DIGESTS), set(runs))
        for name, script in runs.items():
            self.assertEqual(PUBLISH_RUN_DIGESTS[name], hashlib.sha256(script.encode()).hexdigest(), name)

    def test_publish_uses_only_the_allowed_pinned_actions_in_order(self):
        actions = [step["uses"] for step in self.steps("publish") if "uses" in step]
        self.assertEqual([CHECKOUT, DOWNLOAD, ATTEST], actions)

    def test_publish_starts_with_the_policy_checkout(self):
        self.assertEqual(
            {"uses": CHECKOUT, "with": {"fetch-depth": 0, "persist-credentials": False, "ref": "${{ github.sha }}"}},
            self.steps("publish")[0],
        )

    def test_publish_downloads_by_id_with_digest_check(self):
        download = self.steps("publish")[1]
        self.assertEqual(DOWNLOAD, download["uses"])
        self.assertEqual("error", download["with"]["digest-mismatch"])
        self.assertEqual("${{ needs.bundle.outputs.artifact-id }}", download["with"]["artifact-ids"])
        self.assertTrue(download["with"]["path"].startswith("isolated-"))

    def test_publish_validator_matches_the_cleared_release_validator(self):
        php = yaml.safe_load(PHP.read_text(encoding="utf-8"))
        [cleared] = [s for s in php["jobs"]["release"]["steps"] if s.get("name") == VALIDATOR_STEP]
        [validator] = [s for s in self.steps("publish") if s.get("name") == VALIDATOR_STEP]
        self.assertEqual(cleared["run"], validator["run"])
        self.assertEqual(2, [s.get("name") for s in self.steps("publish")].index(VALIDATOR_STEP))

    def test_publish_runs_no_repository_code(self):
        runs = [step["run"] for step in self.steps("publish") if "run" in step]
        self.assertEqual(5, len(runs))
        for script in runs:
            self.assertIsNone(PROJECT_CODE.search(script), script)
        for step in self.steps("publish"):
            self.assertNotIn("working-directory", step)
            self.assertNotIn("shell", step)
        self.assertNotIn("defaults", self.jobs["publish"])

    def test_project_code_pattern_catches_interpreters_and_scripts(self):
        for planted in (
            "python3 -I x.py", "node scripts/a.js", "sh install.sh", "bash -c x", "./dw --version",
            "cargo build", "make phar", "php dw.phar", "release-control/scripts/x.sh",
            "x=$(node -e 1)", "source env.sh", "scripts/verify.sh", ". ./env.sh",
        ):
            self.assertIsNotNone(PROJECT_CODE.search(planted), planted)
        for allowed in ("set -euo pipefail", "/usr/bin/sha256sum --strict -c SHA256SUMS", "gh release create x"):
            self.assertIsNone(PROJECT_CODE.search(allowed), allowed)

    def test_publish_never_checks_out_the_tagged_source(self):
        for step in self.steps("publish"):
            self.assertNotIn("needs.resolve.outputs.commit", str(step.get("with", {})))

    def test_publish_extracts_only_allowlisted_regular_files(self):
        [extract] = [s["run"] for s in self.steps("publish") if s.get("name") == "Extract only the allowlisted release files"]
        self.assertIn("--no-same-owner --no-same-permissions", extract)
        self.assertIn("grep -Ev '^[-d]'", extract)
        self.assertIn("/usr/bin/sha256sum --strict -c SHA256SUMS", extract)

    def test_every_asset_is_attested(self):
        [attest] = [s for s in self.steps("publish") if s.get("uses") == ATTEST]
        self.assertEqual({"subject-path": "release/assets/*"}, attest["with"])

    # The tagged source.

    def test_only_the_read_only_build_checks_out_the_tagged_source(self):
        for name, job in self.jobs.items():
            for step in job.get("steps", []):
                self.assertNotIn("needs.resolve.outputs.commit", str(step.get("with", {})), name)
        for name, job in self.build["jobs"].items():
            self.assertIn(name, TAGGED_TREE_JOBS)
            refs = [s["with"]["ref"] for s in job["steps"] if s.get("uses", "").startswith("actions/checkout@")]
            self.assertTrue(refs, name)
            self.assertEqual({"${{ inputs.commit }}"}, set(refs), name)

    def test_post_publication_checks_run_control_code_only(self):
        for name in ("preflight", "verify-release"):
            refs = [s["with"]["ref"] for s in self.steps(name) if s.get("uses", "").startswith("actions/checkout@")]
            self.assertEqual(["${{ github.sha }}"], refs, name)
        self.assertFalse(any(s.get("uses", "").startswith("actions/checkout@") for s in self.steps("upgrade-from-2x")))

    # Hygiene.

    def test_every_action_is_pinned_to_a_commit(self):
        for name, job in self.all_jobs():
            for step in job.get("steps", []):
                if "uses" in step:
                    self.assertRegex(step["uses"], PINNED, f"{name}: {step['uses']}")

    def test_tagged_source_jobs_use_no_caches(self):
        # The build runs the tagged source; it must not read or write the
        # Actions cache that default-branch workflows restore.
        text = BUILD.read_text(encoding="utf-8")
        self.assertNotIn("actions/cache", text)
        self.assertNotRegex(text, r"^\s+cache(-dependency-path)?:", )
        for name, job in self.build["jobs"].items():
            for step in job["steps"]:
                self.assertNotIn("cache", step.get("with", {}), name)

    def test_every_checkout_drops_credentials(self):
        for name, job in self.all_jobs():
            for step in job.get("steps", []):
                if step.get("uses", "").startswith("actions/checkout@"):
                    self.assertIs(False, step["with"]["persist-credentials"], name)

    def test_python_runs_isolated(self):
        for name, job in self.all_jobs():
            for step in job.get("steps", []):
                for match in re.finditer(r"(?:^|[\s;&|(`$])python3?(?=\s)(\s+\S+)?", step.get("run", ""), re.MULTILINE):
                    self.assertEqual("-I", (match.group(1) or "").strip(), f"{name}: {match.group(0)}")

    # Attestation identity.

    def test_published_assets_are_verified_against_the_signing_workflow(self):
        [step] = [s for s in self.steps("verify-release") if s.get("name") == "Verify downloads, checksums and attestations"]
        command = compact(step["run"])
        self.assertIn('--signer-workflow "$GITHUB_REPOSITORY/.github/workflows/release-rust.yml"', command)
        self.assertIn('--source-ref "$CONTROL_REF"', command)
        self.assertIn("--deny-self-hosted-runners", command)
        self.assertEqual("${{ needs.resolve.outputs.control_ref }}", step["env"]["CONTROL_REF"])

    def test_installers_verify_the_signer_identity(self):
        for path in INSTALLERS:
            text = path.read_text(encoding="utf-8").replace("\\\n", " ")
            calls = [line for line in text.splitlines() if "gh attestation verify" in line]
            self.assertTrue(calls, path.name)
            for call in calls:
                self.assertIn("--cert-identity-regex", call, f"{path.name}: {call.strip()}")
                self.assertIn("--deny-self-hosted-runners", call, f"{path.name}: {call.strip()}")
            self.assertIn(SIGNER_PATTERNS[path.name], text, path.name)

    def test_signer_pattern_is_anchored_and_exact(self):
        # The shell pattern as the script evaluates it: built from the repo,
        # anchored at both ends, and nothing else admitted.
        script = (ROOT / "scripts/install.sh").read_text(encoding="utf-8")
        [assignment] = [line.strip() for line in script.splitlines() if line.strip().startswith("signer=")]
        evaluated = subprocess.run(
            ["sh", "-c", f'REPO=durable-workflow/cli; repo_pattern=$(printf "%s" "$REPO" | sed "s/[.]/\\\\./g"); {assignment}; printf "%s" "$signer"'],
            check=True, capture_output=True, text=True,
        ).stdout
        self.assertEqual(SIGNER_REGEX, evaluated)
        pattern = re.compile(evaluated)
        base = "https://github.com/durable-workflow/cli/.github/workflows/"
        for accepted in ("release-rust.yml@refs/tags/2.5.0", "release.yml@refs/heads/main", "release.yml@refs/tags/v2.3.5",
                         "release-rust.yml@refs/tags/2.5.0-rc.1"):
            self.assertIsNotNone(pattern.search(base + accepted), accepted)
        for refused in ("build.yml@refs/heads/main", "release.yml@refs/heads/main-x", "release-rust.yml@refs/tags/2.5.0\nx",
                        "release.yml@refs/heads/topic", "xrelease.yml@refs/heads/main"):
            self.assertIsNone(pattern.search(base + refused), refused)
        for refused in ("https://github.com/evil/cli/.github/workflows/release.yml@refs/heads/main",
                        "https://github.com.evil/durable-workflow/cli/.github/workflows/release.yml@refs/heads/main",
                        "x" + base + "release.yml@refs/heads/main"):
            self.assertIsNone(pattern.search(refused), refused)

    # Concurrency and the latest marker.

    def test_publication_serializes_per_normalized_tag(self):
        # A workflow-level group would let a newer pending run cancel an older
        # pending one; per-tag job concurrency only serializes the same release.
        self.assertNotIn("concurrency", self.workflow)
        self.assertEqual(
            {"group": "rust-release-publish-${{ needs.resolve.outputs.tag }}", "cancel-in-progress": False},
            self.jobs["publish"]["concurrency"],
        )
        # The tag output is the normalized tag (no leading v).
        self.assertIn('echo "tag=$tag"', self.steps("resolve")[1]["run"])
        self.assertIn('tag="$(node scripts/ci/release-version.js normalize "$raw_tag")"', self.steps("resolve")[1]["run"])

    def test_older_releases_never_take_the_latest_marker(self):
        def metadata(*args):
            return subprocess.run(
                ["node", str(VERSION_SCRIPT), "metadata", *args], check=True, capture_output=True, text=True,
            ).stdout.split()
        self.assertEqual(["false", "false"], metadata("2.4.9", "2.5.0"))
        self.assertEqual(["false", "false"], metadata("2.5.1", "v2.6.0"))
        self.assertEqual(["false", "true"], metadata("2.6.0", "2.5.0"))
        self.assertEqual(["false", "true"], metadata("2.5.0"))
        self.assertEqual(["true", "false"], metadata("2.5.0-rc.1", "2.3.5"))
        # Re-publishing the current latest version keeps the marker.
        self.assertEqual(["false", "true"], metadata("2.5.0", "2.5.0"))
        self.assertEqual(["false", "true"], metadata("v2.5.0", "2.5.0"))
        self.assertEqual(["false", "true"], metadata("2.5.0", "v2.5.0"))
        for path, needle in (
            (PHP, 'metadata "$tag" "$current_latest"'),
            (RUST, 'metadata "$tag" "$current_latest"'),
            (ROOT / "scripts/ci/reconcile-release-metadata.sh", 'metadata "$tag" "$current_latest"'),
        ):
            text = path.read_text(encoding="utf-8")
            self.assertIn(needle, text, path.name)
            self.assertIn("releases/latest", text, path.name)

    def test_php_release_attaches_every_asset_at_creation(self):
        # Immutable releases refuse uploads after publication; gh drafts the
        # release while uploading, then publishes it.
        text = PHP.read_text(encoding="utf-8")
        php = yaml.safe_load(text)
        self.assertNotIn("softprops/action-gh-release", text)
        [create] = [s for s in php["jobs"]["release"]["steps"] if s.get("name") == "Create GitHub Release"]
        self.assertNotIn("uses", create)
        self.assertIn('gh release create "$RELEASE_TAG" --repo "$GITHUB_REPOSITORY" "${flags[@]}" dist/*', create["run"])
        self.assertIn("--verify-tag", create["run"])
        self.assertNotIn("--draft", create["run"])
        for document in (php, self.workflow, self.build, self.dry_run):
            for job in document["jobs"].values():
                for step in job.get("steps", []):
                    self.assertNotRegex(json_text(step), ASSET_MUTATION, step.get("name"))
        for script in sorted((ROOT / "scripts").rglob("*")):
            if script.is_file() and not script.name.startswith("test-") and script.suffix in {".sh", ".ps1", ".js", ".py", ""}:
                self.assertNotRegex(script.read_text(encoding="utf-8", errors="replace"), ASSET_MUTATION, str(script))

    def test_asset_mutation_pattern_catches_every_upload_path(self):
        for planted in (
            "gh release upload 2.5.0 x", "gh release edit 2.5.0 --draft", "gh api repos/o/r/releases/1/assets",
            "curl -X POST https://uploads.github.com/repos/o/r/releases/1/assets?name=x",
            "gh api repos/o/r/releases/assets/7 -X PATCH", "gh api -X DELETE /repos/o/r/releases/assets/7",
        ):
            self.assertRegex(planted, ASSET_MUTATION, planted)

    def test_publish_refuses_an_existing_release_before_any_work(self):
        names = [step.get("name") for step in self.steps("publish")]
        # The first step after the policy-mandated checkout, download and validation.
        self.assertEqual(3, names.index("Refuse a tag that already has a GitHub Release"))

    def run_publish_step(self, name, gh_script, env, steps=None):
        [step] = [s for s in (steps or self.steps("publish")) if s.get("name") == name]
        with tempfile.TemporaryDirectory(prefix="cli-publish-step-") as directory:
            authority = Path(directory) / "release-control/scripts/ci"
            authority.mkdir(parents=True)
            (authority / "release-version.js").write_bytes(VERSION_SCRIPT.read_bytes())
            gh = Path(directory) / "gh"
            gh.write_text("#!/bin/sh\n" + gh_script, encoding="utf-8")
            gh.chmod(0o755)
            calls = Path(directory) / "calls"
            full_env = dict(os.environ, PATH=f"{directory}{os.pathsep}{os.environ['PATH']}",
                            GITHUB_REPOSITORY="o/r", GH_CALLS=str(calls), **env)
            result = subprocess.run(["bash", "-euo", "pipefail", "-c", step["run"]], env=full_env,
                                    capture_output=True, text=True, timeout=10, cwd=directory)
            return result, calls.read_text() if calls.exists() else ""

    def test_existing_release_check_fails_closed(self):
        name = "Refuse a tag that already has a GitHub Release"
        cases = {
            'echo "HTTP 502" >&2; exit 1\n': 1,     # API error: stop
            "printf '2.4.0\\n2.5.0\\n'\n": 1,     # release exists (or a draft): stop
            "printf '2.4.0\\n2.5.0-rc.1\\n'\n": 0,  # absent: continue
            "exit 0\n": 0,                          # no releases at all: continue
        }
        for gh_script, code in cases.items():
            result, _ = self.run_publish_step(name, gh_script, {"RELEASE_TAG": "2.5.0"})
            self.assertEqual(code, result.returncode, f"{gh_script!r}: {result.stderr}")

    def test_existing_release_check_survives_a_long_listing(self):
        # Thousands of tags with the match first: a pipe into grep -q would
        # SIGPIPE the writer and read as "absent".
        gh_script = "printf '2.5.0\\n'; i=0; while [ $i -lt 20000 ]; do printf '1.%s.0\\n' $i; i=$((i+1)); done\n"
        result, _ = self.run_publish_step(
            "Refuse a tag that already has a GitHub Release", gh_script, {"RELEASE_TAG": "2.5.0"},
        )
        self.assertEqual(1, result.returncode, result.stderr)

    def assert_latest_recheck(self, steps):
        record = 'echo "$*" >> "$GH_CALLS"\n'
        name = "Create GitHub Release"
        base = {"RELEASE_TAG": "2.5.0", "PRERELEASE": "false", "MAKE_LATEST": "true", "RECHECK_LATEST": "true"}
        for latest_reply, observed, expected in (
            ("printf '2.4.0\\n'", "2.4.0", "--latest=true"),   # unchanged: keep the decision
            ("printf '2.6.0\\n'", "2.4.0", "--latest=false"),  # changed during the run
            ("exit 1", "2.4.0", "--latest=false"),            # API error: no marker
        ):
            gh_script = (
                'if [ "$1" = "api" ]; then ' + latest_reply + '; exit $?; fi\n' + record
            )
            result, calls = self.run_publish_step(name, gh_script, dict(base, OBSERVED_LATEST=observed), steps)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertIn(expected, calls, (latest_reply, calls))
            self.assertIn("release create 2.5.0", calls)
        result, calls = self.run_publish_step(name, record, dict(base, MAKE_LATEST="false", RECHECK_LATEST="false", OBSERVED_LATEST=""), steps)
        self.assertIn("--latest=false", calls)
        self.assertNotIn("api", calls)

    def test_latest_recheck_never_takes_the_marker_on_doubt(self):
        self.assert_latest_recheck(None)

    def test_php_latest_recheck_never_takes_the_marker_on_doubt(self):
        php = yaml.safe_load(PHP.read_text(encoding="utf-8"))
        self.assert_latest_recheck(php["jobs"]["release"]["steps"])

    def test_latest_marker_is_rechecked_at_publication(self):
        php = yaml.safe_load(PHP.read_text(encoding="utf-8"))
        [php_create] = [s for s in php["jobs"]["release"]["steps"] if s.get("name") == "Create GitHub Release"]
        [rust_create] = [s for s in self.steps("publish") if s.get("name") == "Create GitHub Release"]
        for step, outputs in ((php_create, "resolve-release"), (rust_create, "resolve")):
            self.assertEqual(f"${{{{ needs.{outputs}.outputs.observed_latest }}}}", step["env"]["OBSERVED_LATEST"])
            self.assertEqual(f"${{{{ needs.{outputs}.outputs.recheck_latest }}}}", step["env"]["RECHECK_LATEST"])
            self.assertIn('elif [ "$latest_now" != "$OBSERVED_LATEST" ]; then', step["run"])
            self.assertIn("MAKE_LATEST=false", step["run"])
        self.assertIn('echo "observed_latest=$current_latest"', self.steps("resolve")[1]["run"])
        self.assertIn('echo "observed_latest=${current_latest:-}" >> "$GITHUB_OUTPUT"', php["jobs"]["resolve-release"]["steps"][1]["run"])

    # Version gates.

    def test_rust_workflow_refuses_php_versions(self):
        resolve = self.steps("resolve")[1]["run"]
        self.assertIn('implementation="$(node scripts/ci/release-version.js implementation "$tag")"', resolve)
        self.assertIn('if [ "$implementation" != "rust" ]; then', resolve)

    def test_php_workflow_refuses_rust_versions(self):
        php = yaml.safe_load(PHP.read_text(encoding="utf-8"))
        resolve = php["jobs"]["resolve-release"]["steps"][1]["run"]
        self.assertIn('implementation="$(node scripts/ci/release-version.js implementation "$tag")"', resolve)
        self.assertIn('if [ "$implementation" != "php" ]; then', resolve)
        self.assertIn(
            "needs.resolve-release.outputs.implementation == 'php'",
            compact(php["jobs"]["release-preflight"]["if"]),
        )

    def test_gate_splits_at_2_5_0(self):
        cases = {
            "2.3.5": "php", "2.4.99": "php", "1.9.0": "php", "2.4.0-rc.1": "php",
            "2.5.0-rc.1": "rust", "2.5.0": "rust", "2.10.0": "rust", "3.0.0": "rust",
        }
        for version, implementation in cases.items():
            self.assertEqual(implementation, version_gate(version), version)

    # The handoff producer.

    def test_bundle_hands_off_one_file_by_digest(self):
        outputs = self.jobs["bundle"]["outputs"]
        self.assertEqual("${{ steps.privileged-handoff.outputs.artifact-digest }}", outputs["artifact-digest"])
        self.assertEqual("${{ steps.privileged-handoff.outputs.artifact-id }}", outputs["artifact-id"])
        [upload] = [s for s in self.steps("bundle") if s.get("id") == "privileged-handoff"]
        self.assertEqual(
            {"archive": False, "if-no-files-found": "error", "path": "cli-release-handoff.tar"},
            upload["with"],
        )
        self.assertFalse(any(s.get("uses", "").startswith("actions/checkout@") for s in self.steps("bundle")))

    def test_asset_constants_agree_everywhere(self):
        """The Rust release's asset set is written in five places; they must agree."""
        def js(name):
            return set(json.loads(subprocess.run(
                ["node", "-e", f"process.stdout.write(JSON.stringify(require(process.argv[1]).{name}))",
                 str(ROOT / "scripts/ci/verify-cli-release-channel.js")],
                check=True, capture_output=True, text=True,
            ).stdout))

        asset = re.compile(r"(?<![\w./-])(?:assets/)?((?:dw-[a-z0-9_.-]+|dw\.rb|install\.sh|install\.ps1|verify-release\.sh|SHA256SUMS))(?![\w.-])")

        def names(script):
            return {match.group(1) for match in asset.finditer(script)}

        def step(job_steps, name):
            [found] = [s["run"] for s in job_steps if s.get("name") == name]
            return found

        rust = js("RUST_REQUIRED_ASSETS")
        published = names(step(self.steps("publish"), "Extract only the allowlisted release files"))
        verified = names(step(self.steps("verify-release"), "Verify downloads, checksums and attestations"))
        assembled = names(step(self.build["jobs"]["assemble"]["steps"], "Require exactly the published asset set"))
        built = {e["asset"] for e in self.build["jobs"]["build"]["strategy"]["matrix"]["include"]}
        self.assertEqual(rust, published)
        self.assertEqual(rust, verified)
        self.assertEqual(rust, assembled)  # it also writes SHA256SUMS
        self.assertEqual({a for a in rust if a.startswith("dw-") and not a.endswith(".json")}, built)

        # The PHP release's set, as release.yml and the public-asset checker use it.
        php = js("REQUIRED_ASSETS")
        checker = (ROOT / "scripts/verify-public-release-assets.sh").read_text(encoding="utf-8")
        defaults = checker[checker.index('if [ "$#" -eq 0 ]; then'):checker.index("fi", checker.index('if [ "$#" -eq 0 ]; then'))]
        self.assertEqual(php, names(defaults) | ({"dw.phar"} if "dw.phar" in defaults else set()))

    def test_every_asset_list_names_the_same_platforms(self):
        platforms = {"dw-linux-x86_64", "dw-linux-aarch64", "dw-macos-aarch64", "dw-macos-x86_64", "dw-windows-x86_64.exe"}
        matrix = {entry["asset"] for entry in self.build["jobs"]["build"]["strategy"]["matrix"]["include"]}
        self.assertEqual(platforms, matrix)
        assemble = "\n".join(s.get("run", "") for s in self.build["jobs"]["assemble"]["steps"])
        extract = "\n".join(s.get("run", "") for s in self.steps("publish"))
        verify = "\n".join(s.get("run", "") for s in self.steps("verify-release"))
        for asset in platforms:
            inventory = asset.removesuffix(".exe") + ".dependencies.json"
            for label, script, prefix in (("assemble", assemble, ""), ("publish", extract, "assets/"), ("verify-release", verify, "")):
                self.assertRegex(script, rf"(?<![\w.-]){re.escape(prefix + asset)}(?![\w.-])", f"{label}: {asset}")
                self.assertIn(prefix + inventory, script, f"{label}: {inventory}")

    def test_intel_macs_are_built_and_in_the_formula(self):
        [intel] = [e for e in self.build["jobs"]["build"]["strategy"]["matrix"]["include"] if e["asset"] == "dw-macos-x86_64"]
        self.assertEqual({"target": "x86_64-apple-darwin", "runner": "macos-15"}, {k: intel[k] for k in ("target", "runner")})
        with tempfile.TemporaryDirectory(prefix="cli-formula-") as directory:
            for asset in ("dw-macos-aarch64", "dw-macos-x86_64"):
                (Path(directory) / asset).write_bytes(asset.encode())
            subprocess.run(["sh", str(ROOT / "scripts/generate-homebrew-formula.sh"), directory, "2.5.0"], check=True)
            formula = (Path(directory) / "dw.rb").read_text(encoding="utf-8")
        self.assertIn("on_intel do", formula)
        self.assertIn("releases/download/2.5.0/dw-macos-x86_64", formula)
        self.assertIn(hashlib.sha256(b"dw-macos-x86_64").hexdigest(), formula)

    def run_notes_step(self, version, files):
        [step] = [s for s in self.build["jobs"]["assemble"]["steps"] if s.get("name") == "Write the release notes"]
        with tempfile.TemporaryDirectory(prefix="cli-notes-") as directory:
            for name, text in files.items():
                path = Path(directory) / "docs/release-notes" / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(text, encoding="utf-8")
            (Path(directory) / "release").mkdir()
            result = subprocess.run(
                ["bash", "-euo", "pipefail", "-c", step["run"]],
                cwd=directory, capture_output=True, text=True,
                env=dict(os.environ, VERSION=version, MODE="release"),
            )
            notes = Path(directory) / "release/release-notes.md"
            return result.returncode, notes.read_text(encoding="utf-8") if notes.exists() else None

    def test_a_prerelease_uses_only_its_own_notes(self):
        files = {"2.5.0.md": "stable", "2.5.0-rc.1.md": "preview"}
        self.assertEqual((0, "preview"), self.run_notes_step("2.5.0-rc.1", files))
        # A prerelease without its own notes fails closed; it never borrows
        # the stable notes.
        self.assertEqual((1, None), self.run_notes_step("2.5.0-rc.2", files))
        self.assertEqual((0, "stable"), self.run_notes_step("2.5.0", files))
        self.assertEqual(1, self.run_notes_step("2.6.0", files)[0])

    def test_every_rc_notes_file_says_it_is_not_a_usable_release(self):
        files = sorted((ROOT / "docs/release-notes").glob("*-rc.*.md"))
        self.assertTrue(files)
        for path in files:
            notes = path.read_text(encoding="utf-8")
            version = path.name.removesuffix(".md")
            self.assertTrue(notes.startswith(f"Durable Workflow CLI {version} "), path.name)
            for claim in ("not a usable release", "Do not install it", "never selected automatically",
                          "not published to any tap", "immutable", f"`dw upgrade --tag {version}`"):
                self.assertIn(claim, notes, path.name)

    def test_the_crate_version_has_its_notes(self):
        crate = re.search(r'^version = "([^"]+)"', (ROOT / "rust/Cargo.toml").read_text(encoding="utf-8"), re.M).group(1)
        core = crate.split("-")[0]
        if crate != core and not crate.endswith("-dev"):
            self.assertTrue((ROOT / f"docs/release-notes/{crate}.md").is_file(), crate)

    def test_install_sh_selects_the_intel_macos_binary(self):
        # cli#135: 2.5.0 publishes dw-macos-x86_64 and no PHAR, so an Intel
        # Mac gets the binary; an explicit pre-2.5.0 tag is refused clearly.
        with tempfile.TemporaryDirectory() as directory:
            bin_dir = Path(directory, "bin")
            bin_dir.mkdir()
            (bin_dir / "uname").write_text(
                '#!/bin/sh\ncase "$1" in -s) echo Darwin ;; -m) echo x86_64 ;; *) echo Darwin ;; esac\n')
            log = Path(directory, "curl.log")
            (bin_dir / "curl").write_text(f'#!/bin/sh\nprintf "%s\\n" "$*" >> {log}\nexit 22\n')
            for tool in bin_dir.iterdir():
                tool.chmod(0o755)
            env = {"PATH": f"{bin_dir}:/usr/bin:/bin", "HOME": directory,
                   "DURABLE_WORKFLOW_INSTALL_DIR": str(Path(directory, "install"))}

            def run(version=None):
                log.unlink(missing_ok=True)
                run_env = dict(env, **({"VERSION": version} if version else {}))
                result = subprocess.run(["sh", str(ROOT / "scripts/install.sh")], env=run_env,
                                        capture_output=True, text=True, timeout=30)
                return result, log.read_text() if log.exists() else ""

            for version in (None, "stable", "2.5.0", "2.10.0"):
                result, calls = run(version)
                self.assertIn("dw-macos-x86_64", calls, version)
                self.assertNotIn("PHAR", result.stderr, version)
            for version in ("2.3.6", "v2.4.0-rc.1"):
                result, calls = run(version)
                self.assertNotEqual(0, result.returncode, version)
                self.assertIn("published from 2.5.0", result.stderr, version)
                self.assertEqual("", calls, version)

    def test_every_installer_run_puts_its_install_directory_on_path(self):
        # install.sh exits 1 ("not ready") when the install directory is not
        # on PATH; rc.1's verify-release failed this way.
        runs = 0
        for name, job in self.all_jobs():
            for step in job.get("steps", []):
                script = step.get("run", "")
                for match in re.finditer(r'DURABLE_WORKFLOW_INSTALL_DIR="\$([A-Za-z_]+)"', script):
                    runs += 1
                    variable = match.group(1)
                    exported = script.find(f'export PATH="${variable}:')
                    self.assertNotEqual(-1, exported, f"{name}: {step.get('name')} never puts ${variable} on PATH")
                    self.assertLess(exported, match.start(), f"{name}: {step.get('name')} exports PATH after installing")
        self.assertGreaterEqual(runs, 1)

    def test_no_phar_is_published(self):
        assemble = "\n".join(s.get("run", "") for s in self.build["jobs"]["assemble"]["steps"])
        publish = "\n".join(s.get("run", "") for s in self.steps("publish"))
        for script in (assemble, publish):
            self.assertNotIn("dw.phar", script)


if __name__ == "__main__":
    unittest.main()
