#!/usr/bin/env python3
"""Focused cold, warm, and failure contracts for native release prerequisites."""

from __future__ import annotations

import hashlib
import json
import os
import shutil
import subprocess
import unittest
from pathlib import Path

REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
SCRIPT = Path(__file__).with_name("establish-native-prerequisites.sh")
RELEASE_WORKFLOW = REPOSITORY_ROOT / ".github" / "workflows" / "release.yml"


class NativePrerequisiteTest(unittest.TestCase):
    def setUp(self) -> None:
        self.root = (
            REPOSITORY_ROOT
            / "build"
            / f"native-prerequisite-test-{os.getpid()}-{self._testMethodName}"
        )
        shutil.rmtree(self.root, ignore_errors=True)
        self.root.mkdir(parents=True)
        self.bin = self.root / "bin"
        self.bin.mkdir()
        self.archive = self.root / "verified-musl.tar.gz"
        self.archive.write_bytes(b"pinned-musl-test-archive\n")
        self.sha256 = hashlib.sha256(self.archive.read_bytes()).hexdigest()

        (self.bin / "spc").write_text(
            """#!/usr/bin/env bash
set -euo pipefail
test "$1" = doctor
test "$2" = --auto-fix
test -s "downloads/musl-${MUSL_VERSION}.tar.gz"
test -s downloads/.lock.json
printf 'called\\n' > spc-called
""",
            encoding="utf-8",
        )
        (self.bin / "curl").write_text(
            """#!/usr/bin/env bash
set -euo pipefail
output=''
url=''
while [ "$#" -gt 0 ]; do
    case "$1" in
        --output)
            shift
            output="$1"
            ;;
        http*) url="$1" ;;
    esac
    shift
done
printf '%s\\n' "$url" >> curl-calls
if [ "${FAKE_CURL_FAIL_IF_CALLED:-false}" = true ]; then
    exit 90
fi
case "$url" in
    *good*) cp "$FAKE_MUSL_ARCHIVE" "$output" ;;
    *) exit 22 ;;
esac
""",
            encoding="utf-8",
        )
        for executable in (self.bin / "spc", self.bin / "curl"):
            executable.chmod(0o755)

    def tearDown(self) -> None:
        shutil.rmtree(self.root, ignore_errors=True)

    def run_script(
        self,
        platform: str,
        architecture: str,
        *,
        sources: str = "https://mirror.invalid/bad\nhttps://mirror.invalid/good",
        extra_env: dict[str, str] | None = None,
    ) -> subprocess.CompletedProcess[str]:
        env = os.environ.copy()
        env.update(
            {
                "PATH": f"{self.bin}:{env['PATH']}",
                "GITHUB_OUTPUT": str(self.root / "github-output"),
                "MUSL_VERSION": "1.2.5",
                "MUSL_SHA256": self.sha256,
                "MUSL_SOURCE_URLS": sources,
                "NATIVE_PREREQUISITE_EVIDENCE": str(self.root / "evidence.json"),
                "NATIVE_PREREQUISITE_FETCH_ATTEMPTS": "1",
                "NATIVE_PREREQUISITE_CURL_RETRIES": "0",
                "NATIVE_PREREQUISITE_RETRY_DELAY": "0",
                "FAKE_MUSL_ARCHIVE": str(self.archive),
            }
        )
        env.update(extra_env or {})
        return subprocess.run(
            [
                str(SCRIPT),
                platform,
                architecture,
                str(self.bin / "spc"),
                "native-cache",
            ],
            cwd=self.root,
            env=env,
            check=False,
            text=True,
            capture_output=True,
        )

    def evidence(self) -> dict[str, object]:
        return json.loads((self.root / "evidence.json").read_text(encoding="utf-8"))

    def outputs(self) -> dict[str, str]:
        return dict(
            line.split("=", 1)
            for line in (self.root / "github-output")
            .read_text(encoding="utf-8")
            .splitlines()
        )

    def test_x64_cold_path_falls_through_to_verified_mirror(self) -> None:
        result = self.run_script("Linux", "X64")

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(
            ["https://mirror.invalid/bad", "https://mirror.invalid/good"],
            (self.root / "curl-calls").read_text(encoding="utf-8").splitlines(),
        )
        self.assertEqual("cold", self.outputs()["mode"])
        self.assertEqual("https://mirror.invalid/good", self.outputs()["source"])
        self.assertEqual("success", self.evidence()["outcome"])
        lock = json.loads(
            (self.root / "downloads" / ".lock.json").read_text(encoding="utf-8")
        )
        self.assertEqual(
            hashlib.sha1(self.archive.read_bytes()).hexdigest(),
            lock["musl-1.2.5"]["hash"],
        )

    def test_arm64_cold_path_falls_through_to_verified_mirror(self) -> None:
        result = self.run_script("Linux", "ARM64")

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(
            ["https://mirror.invalid/bad", "https://mirror.invalid/good"],
            (self.root / "curl-calls").read_text(encoding="utf-8").splitlines(),
        )
        self.assertEqual("cold", self.outputs()["mode"])
        self.assertEqual("https://mirror.invalid/good", self.outputs()["source"])
        self.assertEqual("success", self.evidence()["outcome"])

    def test_x64_warm_path_verifies_cache_without_network(self) -> None:
        cache = self.root / "native-cache"
        cache.mkdir()
        shutil.copyfile(self.archive, cache / "musl-1.2.5.tar.gz")

        result = self.run_script(
            "Linux",
            "X64",
            extra_env={"FAKE_CURL_FAIL_IF_CALLED": "true"},
        )

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertFalse((self.root / "curl-calls").exists())
        self.assertEqual("warm", self.outputs()["mode"])
        self.assertEqual("trusted-cache", self.outputs()["source"])
        self.assertEqual("success", self.evidence()["outcome"])

    def test_arm64_warm_path_verifies_cache_without_network(self) -> None:
        cache = self.root / "native-cache"
        cache.mkdir()
        shutil.copyfile(self.archive, cache / "musl-1.2.5.tar.gz")

        result = self.run_script(
            "Linux",
            "ARM64",
            extra_env={"FAKE_CURL_FAIL_IF_CALLED": "true"},
        )

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertFalse((self.root / "curl-calls").exists())
        self.assertEqual("warm", self.outputs()["mode"])
        self.assertEqual("trusted-cache", self.outputs()["source"])
        self.assertEqual("success", self.evidence()["outcome"])

    def test_bad_cache_is_discarded_before_cold_fallback(self) -> None:
        cache = self.root / "native-cache"
        cache.mkdir()
        (cache / "musl-1.2.5.tar.gz").write_bytes(b"untrusted\n")

        result = self.run_script("Linux", "X64", sources="https://mirror.invalid/good")

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("Discarding native prerequisite cache entry", result.stdout)
        self.assertEqual(
            self.archive.read_bytes(), (cache / "musl-1.2.5.tar.gz").read_bytes()
        )
        self.assertEqual("cold", self.outputs()["mode"])

    def test_fetch_exhaustion_has_a_specific_diagnostic(self) -> None:
        result = self.run_script(
            "Linux", "ARM64", sources="https://mirror.invalid/unavailable"
        )

        self.assertEqual(69, result.returncode)
        self.assertIn("Native prerequisite fetch exhausted", result.stderr)
        self.assertNotIn("Unsupported native prerequisite platform", result.stderr)
        self.assertEqual("fetch_exhausted", self.evidence()["outcome"])
        self.assertFalse((self.root / "spc-called").exists())

    def test_unsupported_platform_does_not_report_a_fetch_failure(self) -> None:
        result = self.run_script("FreeBSD", "X64")

        self.assertEqual(64, result.returncode)
        self.assertIn("Unsupported native prerequisite platform", result.stderr)
        self.assertNotIn("Native prerequisite fetch exhausted", result.stderr)
        self.assertEqual("unsupported_platform", self.evidence()["outcome"])
        self.assertFalse((self.root / "curl-calls").exists())

    def test_release_cache_is_exact_and_excludes_untrusted_pull_requests(self) -> None:
        workflow = RELEASE_WORKFLOW.read_text(encoding="utf-8")
        helper = SCRIPT.read_text(encoding="utf-8")
        cache_step = workflow.partition(
            "      - name: Restore trusted native prerequisite cache\n"
        )[2].partition(
            "      - name: Establish and verify native build prerequisites\n"
        )[0]
        identity_step = workflow.partition(
            "      - name: Resolve exact phpmicro cache identity\n"
        )[2].partition("      - name: Restore trusted phpmicro cache\n")[0]
        cold_download_step = workflow.partition(
            "      - name: Fetch PHP sources & extension deps on cold build\n"
        )[2].partition(
            "      - name: Build and bind phpmicro on cold build\n"
        )[0]

        self.assertIn("github.event_name != 'pull_request'", cache_step)
        self.assertIn("github.repository == 'durable-workflow/cli'", cache_step)
        self.assertIn("${{ env.MUSL_VERSION }}-${{ env.MUSL_SHA256 }}", cache_step)
        self.assertNotIn("restore-keys:", cache_step)
        self.assertIn("native_prerequisite_identity=", identity_step)
        self.assertIn("native_prerequisite_script_sha256=", identity_step)
        self.assertNotIn("rm -rf downloads", cold_download_step)
        self.assertIn("rm -rf source buildroot", cold_download_step)
        self.assertLess(
            helper.index("https://distfiles.alpinelinux.org/distfiles/v3.21/"),
            helper.index("https://musl.libc.org/releases/"),
        )


if __name__ == "__main__":
    unittest.main()
