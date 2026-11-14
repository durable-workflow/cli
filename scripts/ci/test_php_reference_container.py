"""Rust PHP-reference mounts and control-replacement regressions."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
LAUNCHER = ROOT / 'scripts/ci/run-php-reference-container.sh'


def fixture(parent, predecessor=False):
    root, state = parent/'source', parent/'state'
    for name in ('vendor', 'rust/target/debug', 'scripts/ci', 'docker/php-ci'):
        (root/name).mkdir(parents=True, exist_ok=True)
    for name in ('bin', 'home', 'tmp/dw-parity-fixture', 'composer-home/cache'):
        (state/name).mkdir(parents=True, exist_ok=True)
    (root/'rust/target/php-vectors.json').write_text('{}')
    (root/'rust/target/debug/dw').write_text('synthetic compiled binary')
    (root/'docker/php-ci/reference.ini').write_bytes((ROOT/'docker/php-ci/reference.ini').read_bytes())
    launcher = root/'scripts/ci/run-php-reference-container.sh'
    source = LAUNCHER.read_text()
    if predecessor:
        source = source.replace('target=$root,readonly', 'target=$root').replace('target=$state,readonly', 'target=$state')
    launcher.write_text(source)
    launcher.chmod(0o755)
    for name in ('php', 'composer'):
        (state/'bin'/name).symlink_to(launcher)
    subprocess.run(['git', 'init', '--quiet', str(root)], check=True)
    return root, state, launcher


class ReferenceLauncherTests(unittest.TestCase):
    def invoke(self, arguments=(), mutate=None):
        with tempfile.TemporaryDirectory() as directory:
            root, state, _ = fixture(Path(directory))
            docker = state/'bin/docker'
            docker.write_text('#!/usr/bin/env python3\nimport json,sys\nprint(json.dumps(sys.argv[1:]))\n')
            docker.chmod(0o755)
            environment = {'PATH':str(state/'bin')+os.pathsep+os.defpath, 'CLI_PHP_CI_ROOT':str(root),
                           'CLI_PHP_CI_STATE':str(state), 'CLI_PHP_CI_IMAGE':'sha256:'+'f'*64,
                           'COMPOSER_AUTH':'synthetic-secret', 'GITHUB_TOKEN':'synthetic-secret',
                           'PHP_INI_SCAN_DIR':'untrusted', 'DW_TOKEN':'synthetic-secret',
                           'DURABLE_WORKFLOW_AUTH_TOKEN':'synthetic-secret',
                           'DW_CONFIG_HOME':str(state/'tmp/dw-parity-fixture'), 'DW_CLI_VERSION':'synthetic-version'}
            if mutate:
                mutate(root, state, environment)
            args = [str(state/'bin/composer') if x == '{composer}' else x for x in arguments]
            result = subprocess.run([str(state/'bin/php'), *args], cwd=root, env=environment,
                                    capture_output=True, text=True, timeout=10)
            return result, str(root), str(state)

    def test_only_vector_file_and_private_data_are_writable(self):
        result, root, state = self.invoke()
        self.assertEqual(0, result.returncode, result.stderr)
        options = json.loads(result.stdout)
        mounts = [options[i+1] for i, item in enumerate(options[:-1]) if item == '--mount']
        self.assertIn(f'type=bind,source={root},target={root},readonly', mounts)
        self.assertIn(f'type=bind,source={state},target={state},readonly', mounts)
        for path in (root+'/vendor', root+'/rust/target/php-vectors.json', state+'/home', state+'/tmp', state+'/composer-home'):
            self.assertIn(f'type=bind,source={path},target={path}', mounts)
        self.assertNotIn(f'type=bind,source={root}/rust/target,target={root}/rust/target', mounts)
        self.assertIn(f'type=bind,source={root}/docker/php-ci/reference.ini,target=/usr/local/etc/php/conf.d/zz-cli-reference.ini,readonly', mounts)
        environment = [options[i+1] for i, item in enumerate(options[:-1]) if item == '--env']
        for name in ('COMPOSER_AUTH', 'GITHUB_TOKEN', 'PHP_INI_SCAN_DIR', 'DW_TOKEN', 'DURABLE_WORKFLOW_AUTH_TOKEN'):
            self.assertNotIn(name, environment)
        for name in ('DW_CONFIG_HOME', 'DW_CLI_VERSION'):
            self.assertIn(name, environment)
        self.assertIn('no-new-privileges', options)
        self.assertIn('--cap-drop', options)

    def test_composer_guard_sees_actual_container_phar(self):
        result, _, _ = self.invoke(('/guard.php', '{composer}'))
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(['php', '/guard.php', '/usr/local/bin/composer'], json.loads(result.stdout)[-3:])

    def test_mutable_image_and_linked_vector_refuse_before_docker(self):
        def image(_root, _state, environment):
            environment['CLI_PHP_CI_IMAGE'] = 'mutable:tag'
        def linked(root, _state, _environment):
            vector = root/'rust/target/php-vectors.json'
            vector.unlink()
            vector.symlink_to(root/'rust/target/debug/dw')
        def hardlinked(root, _state, _environment):
            vector = root/'rust/target/php-vectors.json'
            vector.unlink()
            os.link(root/'rust/target/debug/dw', vector)
        def parent_link(root, _state, _environment):
            (root/'rust').rename(root/'elsewhere')
            (root/'rust').symlink_to(root/'elsewhere')
        for mutation in (image, linked, hardlinked, parent_link):
            result, _, _ = self.invoke(mutate=mutation)
            self.assertNotEqual(0, result.returncode)
            self.assertEqual('', result.stdout)

    def test_config_outside_private_parity_tree_or_linked_refuses(self):
        def outside(root, _state, environment):
            environment['DW_CONFIG_HOME'] = str(root/'vendor')
        def linked(root, state, environment):
            path = state/'tmp/dw-parity-link'
            path.symlink_to(root/'vendor')
            environment['DW_CONFIG_HOME'] = str(path)
        for mutation in (outside, linked):
            result, _, _ = self.invoke(mutate=mutation)
            self.assertNotEqual(0, result.returncode)
            self.assertEqual('', result.stdout)


@unittest.skipUnless(os.environ.get('CLI_PHP_CI_TEST_IMAGE'), 'requires inspected PHP image')
class ReferenceRuntimeBoundaryTests(unittest.TestCase):
    def test_predecessor_replaces_controls_but_candidate_preserves_controls_and_writes(self):
        for predecessor in (True, False):
            for attack in ('launcher', 'wrapper', 'native'):
                with self.subTest(predecessor=predecessor, attack=attack), tempfile.TemporaryDirectory(
                        dir=Path(os.environ['CLI_PHP_CI_STATE'])/'tmp') as directory:
                    root, state, launcher = fixture(Path(directory), predecessor)
                    wrapper, native = state/'bin/php', root/'rust/target/debug/dw'
                    digest = hashlib.sha256(launcher.read_bytes()).hexdigest()
                    environment = {'PATH':os.defpath, 'CLI_PHP_CI_ROOT':str(root), 'CLI_PHP_CI_STATE':str(state),
                                   'CLI_PHP_CI_IMAGE':os.environ['CLI_PHP_CI_TEST_IMAGE'], 'TMPDIR':str(state/'tmp'),
                                   'DW_CONFIG_HOME':str(state/'tmp/dw-parity-fixture')}
                    code = r'''
[$unused, $attack, $launcher, $wrapper, $native, $root, $state] = $argv;
$payload = "#!/bin/sh\necho CLI_REFERENCE_CONTROL_REPLACED\n";
if ($attack === 'launcher') {
    $changed = @file_put_contents($launcher, $payload) !== false;
} elseif ($attack === 'wrapper') {
    $changed = @unlink($wrapper) && @file_put_contents($wrapper, $payload) !== false;
    if ($changed) { chmod($wrapper, 0755); }
} else {
    $changed = @file_put_contents($native, $payload) !== false;
}
$writable = true;
foreach ([$root.'/vendor/output', $root.'/rust/target/php-vectors.json',
          $state.'/tmp/dw-parity-fixture/config.json', $state.'/home/output',
          $state.'/tmp/output', $state.'/composer-home/output'] as $path) {
    $writable = $writable && file_put_contents($path, '{}') !== false;
}
echo json_encode(['changed'=>$changed, 'writable'=>$writable]);
'''
                    result = subprocess.run([str(wrapper), '-r', code, '--', attack, str(launcher), str(wrapper), str(native), str(root), str(state)],
                                            cwd=root, env=environment, capture_output=True, text=True, timeout=30)
                    self.assertEqual(0, result.returncode, result.stderr)
                    observation = json.loads(result.stdout)
                    self.assertEqual(predecessor, observation['changed'])
                    self.assertTrue(observation['writable'])
                    following = subprocess.run([str(wrapper), '-r', 'echo "CLI_REAL_CONTAINER_PHP";'], cwd=root,
                                               env=environment, capture_output=True, text=True, timeout=30)
                    self.assertEqual(0, following.returncode, following.stderr)
                    if predecessor and attack != 'native':
                        self.assertEqual('CLI_REFERENCE_CONTROL_REPLACED', following.stdout.strip())
                    else:
                        self.assertEqual('CLI_REAL_CONTAINER_PHP', following.stdout)
                    if not predecessor:
                        self.assertEqual(digest, hashlib.sha256(launcher.read_bytes()).hexdigest())
                        self.assertTrue(wrapper.is_symlink())
                        self.assertEqual('synthetic compiled binary', native.read_text())


if __name__ == '__main__':
    unittest.main()
