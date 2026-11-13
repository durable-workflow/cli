"""Offline counterexamples for SPC identity, immutable calls and publication."""
import hashlib
import importlib.util
import os
from pathlib import Path
import shutil
import shlex
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[1] / "scripts/ci/spc-artifact.py"
spec = importlib.util.spec_from_file_location("spc_artifact", SOURCE)
spc = importlib.util.module_from_spec(spec)
spec.loader.exec_module(spc)


class ArtifactTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name).resolve()
        self.root.chmod(0o700)
        self.cache = self.root / "spc"
        self.bytes = Path("/bin/sh").resolve().read_bytes()
        self.cache.write_bytes(self.bytes)
        self.cache.chmod(0o500)
        self.sha = hashlib.sha256(self.bytes).hexdigest()

    def holder(self):
        if not hasattr(os, "memfd_create"):
            self.skipTest("Linux immutable descriptor required")
        value = spc.HeldExecutable(self.cache, self.sha, len(self.bytes))
        self.addCleanup(value.close)
        return value

    def test_each_predecessor_call_executes_substitute_while_holder_never_does(self):
        for selected in ("download", "build", "micro:combine"):
            with self.subTest(selected=selected):
                self.cache.chmod(0o600)
                self.cache.write_bytes(self.bytes)
                self.cache.chmod(0o500)
                holder = self.holder()
                marker = self.root / "marker"
                for command in ("download", "build", "micro:combine"):
                    if command == selected:
                        self.cache.unlink()
                        self.cache.write_text("#!/bin/sh\nprintf substituted > " + str(marker) + "\n")
                        self.cache.chmod(0o700)
                        subprocess.run([str(self.cache)], check=True)
                        self.assertTrue(marker.exists())
                        marker.unlink()
                    self.assertEqual(holder.run(["-c", 'printf "%s" "$1"', "fixture", command], cwd=self.root), 0)
                    self.assertFalse(marker.exists())
                holder.recheck()
                self.assertEqual(os.pread(holder.fd, len(self.bytes) + 1, 0), self.bytes)

    def test_capture_rejects_replacement_before_first_call(self):
        self.cache.chmod(0o600)
        self.cache.write_bytes(b"different")
        with self.assertRaises(spc.Refused):
            self.holder()

    def test_sealed_descriptor_refuses_in_place_mutation(self):
        holder = self.holder()
        with self.assertRaises(OSError):
            os.pwrite(holder.fd, b"X", 0)
        with self.assertRaises(OSError):
            os.ftruncate(holder.fd, 0)
        holder.recheck()

    def test_child_cannot_replace_or_write_private_execution_mount(self):
        holder = self.holder()
        script = '''
if (printf substituted > /dev/shm/cli-spc) 2>/dev/null; then exit 91; fi
if chmod 700 /dev/shm/cli-spc 2>/dev/null; then exit 92; fi
if rm /dev/shm/cli-spc 2>/dev/null; then exit 93; fi
if unshare -Ur true 2>/dev/null; then exit 94; fi
if mount -o remount,rw /dev 2>/dev/null; then exit 95; fi
if [ "$(awk '/^CapEff:/{print $2}' /proc/self/status)" != 0000000000000000 ]; then exit 96; fi
'''
        self.assertEqual(holder.run(['-c', script], cwd=self.root), 0)
        holder.recheck()

    def test_bubblewrap_rejects_a_user_controlled_helper_or_link(self):
        path = self.root / 'bwrap'
        path.write_bytes(Path(spc.BWRAP).read_bytes())
        path.chmod(0o700)
        with patch.object(spc, 'BWRAP', str(path)):
            with self.assertRaises(spc.Refused):
                spc.TrustedBwrap()
        path.unlink()
        path.symlink_to(spc.BWRAP)
        with patch.object(spc, 'BWRAP', str(path)):
            with self.assertRaises(spc.Refused):
                spc.TrustedBwrap()

    def test_execution_environment_drops_startup_and_credential_hooks(self):
        with patch.dict(os.environ, {'LD_PRELOAD':'untrusted', 'PYTHONPATH':'untrusted', 'COMPOSER_AUTH':'marker',
                                     'SOURCE_DATE_EPOCH':'123'}, clear=True):
            self.assertEqual(spc.execution_environment(), {'LC_ALL':'C','SOURCE_DATE_EPOCH':'123'})

    def test_source_inode_can_change_without_changing_the_execution_image(self):
        holder = self.holder()
        self.cache.chmod(0o600)
        self.cache.write_bytes(b"changed original inode")
        self.assertEqual(holder.run(["-c", "exit 0"], cwd=self.root), 0)
        holder.recheck()

    def test_symlink_and_hardlink_sources_refuse(self):
        original = self.root / "original"
        self.cache.rename(original)
        self.cache.symlink_to(original)
        with self.assertRaises(spc.Refused):
            self.holder()
        self.cache.unlink()
        os.link(original, self.cache)
        with self.assertRaises(spc.Refused):
            self.holder()

    def test_atomic_publication_preserves_an_appeared_destination(self):
        destination = self.root / "new-cache"
        link = os.link
        def appeared(*args, **kwargs):
            destination.write_bytes(b"foreign")
            return link(*args, **kwargs)
        with patch.object(spc.os, "link", appeared):
            with self.assertRaises(FileExistsError):
                spc.publish(b"verified", destination)
        self.assertEqual(destination.read_bytes(), b"foreign")
        self.assertEqual(list(self.root.glob('.spc-publish-*')), [])
        # The predecessor's check then replace overwrote that appeared inode.
        staged = self.root / "old-staged"
        staged.write_bytes(b"verified")
        os.replace(staged, destination)
        self.assertEqual(destination.read_bytes(), b"verified")

    def test_publication_preserves_existing_symlink_and_directory(self):
        target = self.root / "target"
        target.write_bytes(b"foreign")
        destination = self.root / "destination"
        destination.symlink_to(target)
        with self.assertRaises(FileExistsError):
            spc.publish(b"verified", destination)
        self.assertTrue(destination.is_symlink())
        self.assertEqual(target.read_bytes(), b"foreign")
        destination.unlink()
        destination.mkdir()
        with self.assertRaises(FileExistsError):
            spc.publish(b"verified", destination)
        self.assertEqual(list(destination.iterdir()), [])

    def test_publication_is_complete_inert_data(self):
        destination = self.root / "new-cache"
        spc.publish(b"verified", destination)
        self.assertEqual(destination.read_bytes(), b"verified")
        self.assertEqual(stat.S_IMODE(destination.stat().st_mode), 0o400)
        self.assertEqual(destination.stat().st_nlink, 1)
        self.assertEqual(list(self.root.glob('.spc-publish-*')), [])

    def test_pipeline_uses_one_holder_for_retries_build_and_combine(self):
        class Holder:
            def __init__(self):self.calls = []
            def run(self, arguments, *, cwd):
                self.calls.append((arguments, cwd))
                return 1 if len(self.calls) == 1 else 0
        holder = Holder()
        with patch.object(spc.time, 'sleep') as sleep:
            spc.pipeline(holder, '8.4', 'curl,phar', 5, 4, '/input.phar', '/output', self.root)
        self.assertEqual([row[0][0] for row in holder.calls], ['download', 'download', 'build', 'micro:combine'])
        self.assertEqual(holder.calls[0][0], ['download', '--with-php=8.4', '--for-extensions=curl,phar', '--prefer-pre-built', '--without-suggestions', '--retry=5'])
        self.assertEqual(holder.calls[2][0], ['build', 'curl,phar', '--build-micro'])
        self.assertEqual(holder.calls[3][0], ['micro:combine', '/input.phar', '--output=/output'])
        sleep.assert_called_once_with(30)

    def test_download_failure_never_starts_build_or_combine(self):
        class Holder:
            def __init__(self):self.calls = []
            def run(self, arguments, *, cwd):self.calls.append(arguments);return 1
        holder = Holder()
        with self.assertRaises(spc.Refused):
            spc.pipeline(holder, '8.4', 'curl', 0, 1, '/input', '/output', self.root)
        self.assertEqual([row[0] for row in holder.calls], ['download'])

    def test_macos_refusal_is_explicit_and_all_acquisition_identities_remain(self):
        with patch.object(spc.sys, 'platform', 'darwin'):
            with self.assertRaisesRegex(spc.Refused, 'native Rust CLI or a qualified Linux legacy build'):
                spc.can_execute('macos-aarch64')
        self.assertEqual(set(spc.ARTIFACTS), {'linux-x86_64', 'linux-aarch64', 'macos-x86_64', 'macos-aarch64'})


class AcquisitionTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name).resolve()
        (self.root / 'bin').mkdir(mode=0o700)
        mock = self.root / 'bin/curl'
        mock.write_text("""#!/usr/bin/env bash
set -euo pipefail
while [[ $# -gt 0 ]]; do
 case "$1" in
 --dump-header) headers="$2"; shift 2 ;;
 --output) output="$2"; shift 2 ;;
 *) url="$1"; shift ;;
 esac
done
printf '%s\\n' "$url" >> "$SPC_TEST_ROOT/calls"
printf 'untrusted artifact' > "$output"
case "$SPC_TEST_CASE" in
 outside) location=https://127.0.0.1/private ;;
 insecure) location=http://release-assets.githubusercontent.com/asset ;;
 userinfo) location=https://release-assets.githubusercontent.com@127.0.0.1/private ;;
 loop) location=https://release-assets.githubusercontent.com/loop ;;
 oversized-headers) head -c 26000000 /dev/zero > "$headers" ;;
 duplicate)
 printf 'HTTP/1.1 302 Found\\r\\nLocation: https://release-assets.githubusercontent.com/a\\r\\nLocation: https://release-assets.githubusercontent.com/b\\r\\n\\r\\n' > "$headers"
 printf 302; exit 0 ;;
 allowed-corrupt)
 if [[ "$url" == https://release-assets.githubusercontent.com/* ]]; then
 printf 'HTTP/1.1 200 OK\\r\\n\\r\\n' > "$headers"; printf 200; exit 0
 fi
 location=https://release-assets.githubusercontent.com/asset ;;
 *) exit 90 ;;
esac
printf 'HTTP/1.1 302 Found\\r\\nLocation: %s\\r\\n\\r\\n' "$location" > "$headers"
printf 302
""")
        mock.chmod(0o700)

    def acquire(self, scenario='outside', artifact_platform='linux-x86_64'):
        return subprocess.run(['bash', str(SOURCE.with_name('ensure-spc.sh')), artifact_platform, str(self.root / 'spc')],
                              env={'PATH':str(self.root / 'bin') + os.pathsep + os.defpath, 'HOME':str(self.root),
                                   'SPC_TEST_ROOT':str(self.root), 'SPC_TEST_CASE':scenario},
                              capture_output=True, text=True, timeout=15)

    def refuses_redirect(self, scenario, count, reason):
        result = self.acquire(scenario)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(reason, result.stderr)
        self.assertEqual(len((self.root / 'calls').read_text().splitlines()), count)
        self.assertFalse((self.root / 'spc').exists())
        self.assertEqual(list(self.root.glob('.spc-download.*')), [])

    def test_outside_host(self):self.refuses_redirect('outside', 1, 'outside the reviewed set')
    def test_insecure_scheme(self):self.refuses_redirect('insecure', 1, 'outside the reviewed set')
    def test_userinfo_authority(self):self.refuses_redirect('userinfo', 1, 'outside the reviewed set')
    def test_duplicate_location(self):self.refuses_redirect('duplicate', 1, 'one Location header')
    def test_redirect_loop(self):self.refuses_redirect('loop', 4, 'redirect limit')
    def test_allowed_host_corrupt_body(self):self.refuses_redirect('allowed-corrupt', 2, 'artifact size')
    def test_oversized_headers(self):self.refuses_redirect('oversized-headers', 1, 'download failed')

    def test_unknown_platform_before_network(self):
        self.assertNotEqual(self.acquire(artifact_platform='unknown').returncode, 0)
        self.assertFalse((self.root / 'calls').exists())

    def test_symlink_cache_before_network(self):
        target = self.root / 'other';target.write_bytes(b'unchanged')
        (self.root / 'spc').symlink_to(target)
        self.assertNotEqual(self.acquire().returncode, 0)
        self.assertEqual(target.read_bytes(), b'unchanged')
        self.assertFalse((self.root / 'calls').exists())

    def test_hardlink_cache_before_network(self):
        target = self.root / 'other';target.write_bytes(b'unchanged')
        os.link(target, self.root / 'spc')
        self.assertNotEqual(self.acquire().returncode, 0)
        self.assertEqual(target.read_bytes(), b'unchanged')
        self.assertFalse((self.root / 'calls').exists())

    def test_same_size_tampered_cache_before_network(self):
        (self.root / 'spc').write_bytes(b'x' * spc.ARTIFACTS['linux-x86_64'][4])
        result = self.acquire()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('artifact digest', result.stderr)
        self.assertFalse((self.root / 'calls').exists())

    def test_poisoned_cache_predecessor_executes_but_acquisition_refuses(self):
        marker = self.root / 'marker'
        path = self.root / 'spc'
        path.write_text('#!/bin/sh\nprintf marker > ' + shlex.quote(str(marker)) + '\n')
        path.chmod(0o700)
        subprocess.run(['bash', str(SOURCE.parents[2] / 'tests/fixtures/unverified-spc-cache.sh'), str(path)], check=True)
        self.assertTrue(marker.exists());marker.unlink()
        self.assertNotEqual(self.acquire().returncode, 0)
        self.assertFalse(marker.exists())

    def test_macos_build_refuses_before_acquisition_or_phar_work(self):
        uname = self.root / 'bin/uname'
        uname.write_text('#!/bin/sh\ncase "$1" in -s) echo Darwin ;; -m) echo arm64 ;; esac\n')
        uname.chmod(0o700)
        result = subprocess.run(['bash', str(SOURCE.parents[1] / 'build.sh'), 'binary'],
                                env={'PATH':str(self.root / 'bin') + os.pathsep + os.defpath, 'HOME':str(self.root),
                                     'SPC_TEST_ROOT':str(self.root), 'SPC_TEST_CASE':'outside'},
                                capture_output=True, text=True, timeout=5)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('native Rust CLI or a qualified Linux legacy build', result.stderr)
        self.assertFalse((self.root / 'calls').exists())

    def test_build_uses_only_one_complete_held_pipeline(self):
        source = (SOURCE.parents[1] / 'build.sh').read_text()
        self.assertIn('"$TOOLS_DIR/spc" check', source)
        self.assertIn('"$TOOLS_DIR/spc" run', source)
        self.assertNotIn('./spc ', source)
        self.assertNotIn('spc-bin/nightly', source)


if __name__ == '__main__':
    unittest.main()
