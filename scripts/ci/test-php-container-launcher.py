#!/usr/bin/env python3
"""Exercise the actual launcher without a Docker daemon or credentials."""

import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
LAUNCHER = ROOT / 'scripts/ci/run-php-container.sh'


class PhpContainerLauncherTest(unittest.TestCase):
    def invoke(self, command='php', arguments=(), **overrides):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory)
            (state / 'bin').mkdir()
            (state / 'bin' / command).symlink_to(LAUNCHER)
            docker = state / 'bin/docker'
            docker.write_text('#!/usr/bin/env python3\nimport json,sys\nprint(json.dumps(sys.argv[1:]))\n')
            docker.chmod(0o755)
            bundle = state / 'ca.pem'
            bundle.write_text('synthetic mount only')
            environment = {
                'PATH': str(state / 'bin') + os.pathsep + os.defpath,
                'CLI_PHP_CI_ROOT': str(ROOT),
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
            return result, str(bundle), str(state)

    def test_credentials_and_php_overrides_do_not_enter_runtime(self):
        result, bundle, state = self.invoke(arguments=('-r', 'echo PHP_VERSION;'))
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
        self.assertFalse(any('docker.sock' in mount for mount in mounts))
        self.assertIn('--cap-drop', invocation)
        self.assertIn('no-new-privileges', invocation)
        self.assertEqual(['php', '-r', 'echo PHP_VERSION;'], invocation[-3:])

    def test_composer_verifier_reads_container_phar(self):
        result, _, _ = self.invoke(arguments=('/guard/verify.php', '{composer}', str(ROOT)),
                                    GITHUB_ACTION_PATH='/guard')
        self.assertEqual(0, result.returncode, result.stderr)
        invocation = json.loads(result.stdout)
        self.assertEqual(['php', '/guard/verify.php', '/usr/local/bin/composer', str(ROOT)], invocation[-4:])
        self.assertIn('type=bind,source=/guard,target=/guard,readonly', invocation)

    def test_composer_uses_container_executable(self):
        result, _, _ = self.invoke(command='composer', arguments=('test',))
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(['composer', 'test'], json.loads(result.stdout)[-2:])

    def test_mutable_image_refuses_before_docker(self):
        result, _, _ = self.invoke(CLI_PHP_CI_IMAGE='cli-php-ci:mutable')
        self.assertNotEqual(0, result.returncode)
        self.assertIn('inspected immutable image config ID', result.stderr)
        self.assertEqual('', result.stdout)


if __name__ == '__main__':
    unittest.main()
