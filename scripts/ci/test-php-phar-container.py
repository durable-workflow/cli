"""PHAR caller mount, environment and real control-replacement regressions."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
LAUNCHER = ROOT / 'scripts/ci/run-php-phar-container.sh'


def fixture(parent, predecessor=False):
    root, state = parent/'source', parent/'state'
    for name in ('vendor', 'build', 'src', 'scripts/ci', 'docker/php-ci'):
        (root/name).mkdir(parents=True, exist_ok=True)
    for name in ('bin', 'home', 'tmp', 'composer-home/cache', 'artifacts'):
        (state/name).mkdir(parents=True, exist_ok=True)
    (root/'src/GeneratedBuildInfo.php').touch()
    (root/'docker/php-ci/phar.ini').write_bytes((ROOT/'docker/php-ci/phar.ini').read_bytes())
    (state/'artifacts/box.phar').write_text('synthetic immutable artifact')
    launcher = root/'scripts/ci/run-php-phar-container.sh'
    source = LAUNCHER.read_text()
    if predecessor:
        source = source.replace('target=$root,readonly', 'target=$root').replace('target=$state,readonly', 'target=$state')
    launcher.write_text(source)
    launcher.chmod(0o755)
    for name in ('php', 'composer', 'box'):
        (state/'bin'/name).symlink_to(launcher)
    subprocess.run(['git', 'init', '--quiet', str(root)], check=True)
    return root, state, launcher


class PharLauncherTests(unittest.TestCase):
    def invoke(self, command='php', arguments=(), mutate=None):
        with tempfile.TemporaryDirectory() as directory:
            root, state, _ = fixture(Path(directory))
            docker = state/'bin/docker'
            docker.write_text('#!/usr/bin/env python3\nimport json,sys\nprint(json.dumps(sys.argv[1:]))\n')
            docker.chmod(0o755)
            environment = {'PATH':str(state/'bin')+os.pathsep+os.defpath, 'CLI_PHP_CI_ROOT':str(root),
                           'CLI_PHP_CI_STATE':str(state), 'CLI_PHP_CI_IMAGE':'sha256:'+'f'*64,
                           'COMPOSER_AUTH':'synthetic-secret', 'GITHUB_TOKEN':'synthetic-secret',
                           'PHP_INI_SCAN_DIR':'untrusted', 'DW_TOKEN':'synthetic-secret',
                           'GITHUB_SHA':'a'*40, 'SOURCE_DATE_EPOCH':'123'}
            if mutate:
                mutate(root, state, environment)
            args = [str(state/'bin/composer') if x == '{composer}' else x for x in arguments]
            result = subprocess.run([str(state/'bin'/command), *args], cwd=root, env=environment,
                                    capture_output=True, text=True, timeout=10)
            return result, str(root), str(state)

    def test_only_data_outputs_are_writable_and_credentials_are_excluded(self):
        result, root, state = self.invoke()
        self.assertEqual(0, result.returncode, result.stderr)
        options = json.loads(result.stdout)
        mounts = [options[i+1] for i, item in enumerate(options[:-1]) if item == '--mount']
        self.assertIn(f'type=bind,source={root},target={root},readonly', mounts)
        self.assertIn(f'type=bind,source={state},target={state},readonly', mounts)
        for path in (root+'/build', root+'/vendor', root+'/src/GeneratedBuildInfo.php', state+'/home', state+'/tmp', state+'/composer-home'):
            self.assertIn(f'type=bind,source={path},target={path}', mounts)
        self.assertIn(f'type=bind,source={root}/docker/php-ci/phar.ini,target=/usr/local/etc/php/conf.d/zz-cli-phar.ini,readonly', mounts)
        environment = [options[i+1] for i, item in enumerate(options[:-1]) if item == '--env']
        for name in ('COMPOSER_AUTH', 'GITHUB_TOKEN', 'PHP_INI_SCAN_DIR', 'DW_TOKEN'):
            self.assertNotIn(name, environment)
        self.assertIn('GITHUB_SHA', environment)
        self.assertIn('SOURCE_DATE_EPOCH', environment)
        self.assertIn('no-new-privileges', options)
        self.assertIn('--cap-drop', options)

    def test_box_runs_the_readonly_artifact_guard_and_composer_guard_sees_real_phar(self):
        result, root, state = self.invoke('box', ('compile', '--no-parallel'))
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(['php', root+'/scripts/ci/exec-box.php', state+'/artifacts/box.phar', 'compile', '--no-parallel'], json.loads(result.stdout)[-5:])
        result, _, _ = self.invoke('php', ('/guard.php', '{composer}'))
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(['php', '/guard.php', '/usr/local/bin/composer'], json.loads(result.stdout)[-3:])

    def test_mutable_image_and_linked_output_refuse_before_docker(self):
        def image(_root, _state, environment):
            environment['CLI_PHP_CI_IMAGE'] = 'mutable:tag'
        def linked(root, _state, _environment):
            (root/'build').rmdir()
            (root/'build').symlink_to(root/'scripts')
        for mutation in (image, linked):
            result, _, _ = self.invoke(mutate=mutation)
            self.assertNotEqual(0, result.returncode)
            self.assertEqual('', result.stdout)


@unittest.skipUnless(os.environ.get('CLI_PHP_CI_TEST_IMAGE'), 'requires inspected PHP image')
class PharRuntimeBoundaryTests(unittest.TestCase):
    def test_predecessor_replaces_controls_but_candidate_preserves_controls_and_writes(self):
        for predecessor in (True, False):
            for attack in ('launcher', 'wrapper', 'artifact'):
                with self.subTest(predecessor=predecessor, attack=attack), tempfile.TemporaryDirectory(
                        dir=Path(os.environ['CLI_PHP_CI_STATE'])/'tmp') as directory:
                    root, state, launcher = fixture(Path(directory), predecessor)
                    wrapper = state/'bin/php'
                    digest = hashlib.sha256(launcher.read_bytes()).hexdigest()
                    environment = {'PATH':os.defpath, 'CLI_PHP_CI_ROOT':str(root), 'CLI_PHP_CI_STATE':str(state),
                                   'CLI_PHP_CI_IMAGE':os.environ['CLI_PHP_CI_TEST_IMAGE'], 'TMPDIR':str(state/'tmp')}
                    code = r'''
[$unused, $attack, $launcher, $wrapper, $root, $state] = $argv;
$payload = "#!/bin/sh\necho CLI_PHAR_CONTROL_REPLACED\n";
if ($attack === 'launcher') {
    $changed = @file_put_contents($launcher, $payload) !== false;
} elseif ($attack === 'wrapper') {
    $changed = @unlink($wrapper) && @file_put_contents($wrapper, $payload) !== false;
    if ($changed) { chmod($wrapper, 0755); }
} else {
    $changed = @file_put_contents($state.'/artifacts/box.phar', 'replaced') !== false;
}
$writable = true;
foreach ([$root.'/vendor/output', $root.'/build/output', $root.'/src/GeneratedBuildInfo.php',
          $state.'/home/output', $state.'/tmp/output', $state.'/composer-home/output'] as $path) {
    $writable = $writable && file_put_contents($path, 'synthetic output') !== false;
}
echo json_encode(['changed'=>$changed, 'writable'=>$writable]);
'''
                    result = subprocess.run([str(wrapper), '-r', code, '--', attack, str(launcher), str(wrapper), str(root), str(state)],
                                            cwd=root, env=environment, capture_output=True, text=True, timeout=30)
                    self.assertEqual(0, result.returncode, result.stderr)
                    observation = json.loads(result.stdout)
                    self.assertEqual(predecessor, observation['changed'])
                    self.assertTrue(observation['writable'])
                    following = subprocess.run([str(wrapper), '-r', 'echo "CLI_REAL_CONTAINER_PHP";'], cwd=root,
                                               env=environment, capture_output=True, text=True, timeout=30)
                    self.assertEqual(0, following.returncode, following.stderr)
                    if predecessor and attack != 'artifact':
                        self.assertEqual('CLI_PHAR_CONTROL_REPLACED', following.stdout.strip())
                    else:
                        self.assertEqual('CLI_REAL_CONTAINER_PHP', following.stdout)
                    if not predecessor:
                        self.assertEqual(digest, hashlib.sha256(launcher.read_bytes()).hexdigest())
                        self.assertTrue(wrapper.is_symlink())
                        self.assertEqual('synthetic immutable artifact', (state/'artifacts/box.phar').read_text())


if __name__ == '__main__':
    unittest.main()
