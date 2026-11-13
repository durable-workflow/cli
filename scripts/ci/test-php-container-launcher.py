#!/usr/bin/env python3
"""Exercise the actual launcher without a Docker daemon or credentials."""

import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
LAUNCHER = ROOT / 'scripts/ci/run-php-container.sh'
SETUP = ROOT / 'scripts/ci/setup-php-container.sh'


class PhpContainerSetupIsolationTest(unittest.TestCase):
    def test_workspace_python_imports_do_not_run_before_apt_source_guard(self):
        for origin in ['working-directory', 'PYTHONPATH']:
            with self.subTest(origin=origin), tempfile.TemporaryDirectory() as directory:
                temporary = Path(directory)
                project = temporary / 'project'
                scripts = project / 'scripts/ci'
                scripts.mkdir(parents=True)
                setup = scripts / SETUP.name
                setup.write_bytes(SETUP.read_bytes())
                (scripts / 'php-container-images.json').write_bytes(
                    (ROOT / 'scripts/ci/php-container-images.json').read_bytes())
                marker = temporary / 'workspace-imported'
                poison = project if origin == 'working-directory' else temporary / 'python-path'
                poison.mkdir(exist_ok=True)
                (poison / 'json.py').write_text(
                    'from pathlib import Path\n'
                    f'Path({str(marker)!r}).write_text("executed")\n'
                    'raise RuntimeError("Workspace Python import executed")\n')
                binaries = temporary / 'bin'
                binaries.mkdir()
                (binaries / 'python3').symlink_to(sys.executable)
                docker = binaries / 'docker'
                docker.write_text('#!/bin/sh\nexit 99\n')
                docker.chmod(0o755)
                environment = {'PATH': str(binaries) + os.pathsep + os.defpath}
                if origin == 'PYTHONPATH':
                    environment['PYTHONPATH'] = str(poison)
                positive = subprocess.run([sys.executable, '-c', 'import json'], cwd=project,
                                          env=environment, capture_output=True)
                self.assertNotEqual(0, positive.returncode)
                self.assertTrue(marker.exists(), 'The unisolated positive control must execute the marker')
                marker.unlink()

                result = subprocess.run(['bash', str(setup), '8.2'], cwd=project,
                                        env=environment, capture_output=True)
                self.assertEqual(1, result.returncode, 'The missing APT source must stop setup before Docker')
                self.assertFalse(marker.exists(), 'The real setup must not execute workspace/PYTHONPATH code')


class PhpContainerLauncherTest(unittest.TestCase):
    def invoke(self, command='php', arguments=(), symlink_data=None, **overrides):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory)
            root = state / 'source'
            (root / 'vendor').mkdir(parents=True)
            (root / '.phpunit.result.cache').touch()
            (state / 'phpunit-result.cache').touch()
            subprocess.run(['git', 'init', '--quiet', str(root)], check=True)
            (state / 'bin').mkdir()
            for name in ['home', 'tmp', 'composer-home']:
                (state / name).mkdir()
            if symlink_data:
                selected = root / 'vendor' if symlink_data == 'vendor' else state / symlink_data
                if selected.is_dir():
                    selected.rmdir()
                else:
                    selected.unlink()
                selected.symlink_to(root)
            (state / 'bin' / command).symlink_to(LAUNCHER)
            docker = state / 'bin/docker'
            docker.write_text('#!/usr/bin/env python3\nimport json,sys\nprint(json.dumps(sys.argv[1:]))\n')
            docker.chmod(0o755)
            bundle = state / 'ca.pem'
            bundle.write_text('synthetic mount only')
            environment = {
                'PATH': str(state / 'bin') + os.pathsep + os.defpath,
                'CLI_PHP_CI_ROOT': str(root),
                'CLI_PHP_CI_STATE': str(state),
                'CLI_PHP_CI_IMAGE': 'sha256:' + 'f' * 64,
                'SSL_CERT_FILE': str(bundle),
                'COMPOSER_CAFILE': str(bundle),
                'COMPOSER_HOME': str(state / 'composer-home'),
                'DW_CI_CA_SHA256': '1' * 64,
                'GITHUB_TOKEN': 'synthetic-must-not-forward',
                'COMPOSER_AUTH': 'synthetic-must-not-forward',
                'DW_TOKEN': 'synthetic-must-not-forward',
                'PHP_INI_SCAN_DIR': '/synthetic-must-not-forward',
                'DURABLE_WORKFLOW_CLI_SMOKE_ADMIN_TOKEN': 'synthetic-smoke',
                **overrides,
            }
            arguments = [str(state / 'bin/composer') if x == '{composer}' else x for x in arguments]
            result = subprocess.run([str(state / 'bin' / command), *arguments], cwd=ROOT,
                                    env=environment, capture_output=True, text=True)
            return result, str(bundle), str(state), str(root)

    def test_credentials_and_php_overrides_do_not_enter_runtime(self):
        result, bundle, state, root = self.invoke(arguments=('-r', 'echo PHP_VERSION;'))
        self.assertEqual(0, result.returncode, result.stderr)
        invocation = json.loads(result.stdout)
        passed = [invocation[i + 1] for i, part in enumerate(invocation[:-1]) if part == '--env']
        for name in ['COMPOSER_HOME', 'DW_CI_CA_SHA256', 'DURABLE_WORKFLOW_CLI_SMOKE_ADMIN_TOKEN']:
            self.assertIn(name, passed)
        for name in ['GITHUB_TOKEN', 'COMPOSER_AUTH', 'DW_TOKEN', 'PHP_INI_SCAN_DIR']:
            self.assertNotIn(name, passed)
        self.assertIn('HOME=' + state + '/home', passed)
        mounts = [invocation[i + 1] for i, part in enumerate(invocation[:-1]) if part == '--mount']
        self.assertEqual(1, mounts.count(f'type=bind,source={bundle},target={bundle},readonly'))
        self.assertIn(f'type=bind,source={root},target={root},readonly', mounts)
        self.assertIn(f'type=bind,source={state},target={state},readonly', mounts)
        self.assertIn(f'type=bind,source={root}/vendor,target={root}/vendor', mounts)
        for directory in ['home', 'tmp', 'composer-home']:
            self.assertIn(f'type=bind,source={state}/{directory},target={state}/{directory}', mounts)
        self.assertFalse(any('docker.sock' in mount for mount in mounts))
        self.assertIn('--cap-drop', invocation)
        self.assertIn('no-new-privileges', invocation)
        self.assertEqual(['php', '-r', 'echo PHP_VERSION;'], invocation[-3:])

    def test_composer_verifier_reads_container_phar(self):
        result, _, _, _ = self.invoke(arguments=('/guard/verify.php', '{composer}', str(ROOT)),
                                    GITHUB_ACTION_PATH='/guard')
        self.assertEqual(0, result.returncode, result.stderr)
        invocation = json.loads(result.stdout)
        self.assertEqual(['php', '/guard/verify.php', '/usr/local/bin/composer', str(ROOT)], invocation[-4:])
        self.assertIn('type=bind,source=/guard,target=/guard,readonly', invocation)

    def test_composer_uses_container_executable(self):
        result, _, _, _ = self.invoke(command='composer', arguments=('test',))
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(['composer', 'test'], json.loads(result.stdout)[-2:])

    def test_symlinked_writable_mounts_refuse_before_docker(self):
        for selected in ['vendor', 'home', 'tmp', 'composer-home', 'phpunit-result.cache']:
            with self.subTest(selected=selected):
                result, _, _, _ = self.invoke(symlink_data=selected)
                self.assertNotEqual(0, result.returncode)
                self.assertEqual('', result.stdout)

    def test_mutable_image_refuses_before_docker(self):
        result, _, _, _ = self.invoke(CLI_PHP_CI_IMAGE='cli-php-ci:mutable')
        self.assertNotEqual(0, result.returncode)
        self.assertIn('inspected immutable image config ID', result.stderr)
        self.assertEqual('', result.stdout)


if __name__ == '__main__':
    unittest.main()
