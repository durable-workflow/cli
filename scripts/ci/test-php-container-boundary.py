"""Exercise host-control replacement attempts in disposable source and state."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
LAUNCHER = Path(os.environ.get('CLI_PHP_CI_TEST_LAUNCHER', ROOT / 'scripts/ci/run-php-container.sh'))


@unittest.skipUnless(os.environ.get('CLI_PHP_CI_TEST_IMAGE'), 'requires built PHP CI image')
class PhpContainerBoundaryTests(unittest.TestCase):
    def test_application_cannot_replace_subsequent_host_controls(self):
        for attack in ('launcher-overwrite', 'wrapper-unlink', 'wrapper-rename'):
            with self.subTest(attack=attack), tempfile.TemporaryDirectory(
                    dir=Path(os.environ['CLI_PHP_CI_STATE']) / 'tmp') as directory:
                temporary = Path(directory)
                root, state = temporary / 'source', temporary / 'state'
                scripts = root / 'scripts/ci'
                scripts.mkdir(parents=True)
                (root / 'vendor').mkdir()
                (root / '.phpunit.result.cache').touch()
                launcher = scripts / 'run-php-container.sh'
                launcher.write_bytes(LAUNCHER.read_bytes())
                launcher.chmod(0o755)
                subprocess.run(['git', 'init', '--quiet', str(root)], check=True)
                for name in ('bin', 'home', 'tmp', 'composer-home/cache'):
                    (state / name).mkdir(parents=True, exist_ok=True)
                (state / 'phpunit-result.cache').touch()
                wrapper = state / 'bin/php'
                wrapper.symlink_to(launcher)
                digest = hashlib.sha256(launcher.read_bytes()).hexdigest()
                environment = {'PATH': os.defpath, 'CLI_PHP_CI_ROOT': str(root),
                               'CLI_PHP_CI_STATE': str(state),
                               'CLI_PHP_CI_IMAGE': os.environ['CLI_PHP_CI_TEST_IMAGE'],
                               'TMPDIR': str(state / 'tmp')}
                code = r'''
$payload = "#!/bin/sh\nprintf '%s\\n' CLI_HOST_WRAPPER_REPLACED\n";
[$unused, $attack, $launcher, $wrapper, $root, $state] = $argv;
if ($attack === 'launcher-overwrite') {
    $changed = @file_put_contents($launcher, $payload) !== false;
} elseif ($attack === 'wrapper-unlink') {
    $changed = @unlink($wrapper) && @file_put_contents($wrapper, $payload) !== false;
} else {
    file_put_contents($state.'/tmp/replacement', $payload);
    $changed = @rename($state.'/tmp/replacement', $wrapper);
}
if ($changed) {
    @chmod($wrapper, 0755);
}
$writable = true;
foreach ([$root.'/vendor/output', $root.'/.phpunit.result.cache',
          $state.'/home/output', $state.'/tmp/output', $state.'/composer-home/output'] as $path) {
    $writable = $writable && file_put_contents($path, 'synthetic data') !== false;
}
echo json_encode(['control_replaced' => $changed, 'data_writable' => $writable]);
'''
                attempt = subprocess.run([str(wrapper), '-r', code, '--', attack, str(launcher),
                                          str(wrapper), str(root), str(state)],
                                         cwd=root, env=environment, capture_output=True, text=True, timeout=30)
                self.assertEqual(0, attempt.returncode, attempt.stderr)
                result = json.loads(attempt.stdout)
                # Invoke the next nominal host PHP entry even in the predecessor
                # control, so wrapper substitution has an observable marker.
                following = subprocess.run([str(wrapper), '-r', 'echo "CLI_CONTAINER_PHP";'],
                                           cwd=root, env=environment, capture_output=True, text=True, timeout=30)
                self.assertFalse(result['control_replaced'],
                                 f'{attack}: next host entry returned {following.stdout!r}')
                self.assertTrue(result['data_writable'], 'Necessary dependency/cache outputs must still work')
                self.assertEqual(digest, hashlib.sha256(launcher.read_bytes()).hexdigest())
                self.assertTrue(wrapper.is_symlink())
                self.assertEqual(0, following.returncode, following.stderr)
                self.assertEqual('CLI_CONTAINER_PHP', following.stdout)


if __name__ == '__main__':
    unittest.main()
