"""Two-tree reproducibility data and control boundaries."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


def fixture(parent, predecessor=False):
    root, state, runner = parent/'source', parent/'state', parent/'runner'
    for name in ('vendor', 'build', 'src', 'scripts/ci', 'docker/php-ci'):
        (root/name).mkdir(parents=True, exist_ok=True)
    for name in ('bin', 'profile', 'artifacts', 'home', 'tmp', 'composer-home/cache'):
        (state/name).mkdir(parents=True, exist_ok=True)
    runner.mkdir()
    for name in ('run-php-phar-container.sh', 'run-php-repro-container.sh', 'prepare-php-repro-tree.sh', 'exec-box.php'):
        shutil.copyfile(ROOT/'scripts/ci'/name, root/'scripts/ci'/name)
        (root/'scripts/ci'/name).chmod((ROOT/'scripts/ci'/name).stat().st_mode & 0o777)
    (root/'docker/php-ci/phar.ini').write_bytes((ROOT/'docker/php-ci/phar.ini').read_bytes())
    (root/'src/GeneratedBuildInfo.php').touch()
    (root/'box.json.dist').write_bytes((ROOT/'box.json.dist').read_bytes())
    (root/'scripts/build.sh').write_text('synthetic archived control')
    subprocess.run(['git', 'init', '--quiet', str(root)], check=True)
    attempt = runner/'dw-cli-repro.fixture'
    tree = attempt/'source.1'
    shutil.copytree(root, tree, ignore=shutil.ignore_patterns('.git'))
    (attempt/'dw.phar.1').write_text('first comparison artifact')
    (attempt/'source.2').mkdir()
    (state/'artifacts/box.phar').write_text('synthetic artifact')
    (state/'artifacts/box.phar').chmod(0o400)
    for name in ('php', 'composer', 'box'):
        (state/'bin'/name).symlink_to(root/'scripts/ci/run-php-repro-container.sh')
        (state/'profile'/name).symlink_to(root/'scripts/ci/run-php-phar-container.sh')
    environment = {'PATH':os.defpath, 'CLI_PHP_CI_ROOT':str(root), 'CLI_PHP_CI_STATE':str(state),
                   'CLI_PHP_CI_IMAGE':'sha256:'+'f'*64, 'CLI_PHP_CI_REPRO':'1', 'RUNNER_TEMP':str(runner),
                   'TMPDIR':str(state/'tmp'), 'CLI_TEST_ATTEMPT':str(attempt), 'SOURCE_DATE_EPOCH':'1234567890'}
    subprocess.run(['bash', str(root/'scripts/ci/prepare-php-repro-tree.sh'), str(tree)],
                   env=environment, check=True, capture_output=True, timeout=10)
    if predecessor:
        classic = root/'scripts/ci/run-php-phar-container.sh'
        source = classic.read_text().replace('target=$root,readonly', 'target=$root').replace('target=$state,readonly', 'target=$state')
        source = source.replace('declare -A mounted=()',
                                'options+=(--mount "type=bind,source=$CLI_TEST_ATTEMPT,target=$CLI_TEST_ATTEMPT")\ndeclare -A mounted=()')
        classic.write_text(source)
    return root, state, tree, environment


class ReproLauncherTests(unittest.TestCase):
    def invoke(self, arguments=(), mutate=None):
        with tempfile.TemporaryDirectory() as directory:
            root, state, tree, env = fixture(Path(directory))
            docker = state/'bin/docker'
            docker.write_text('#!/usr/bin/env python3\nimport json,sys\nprint(json.dumps(sys.argv[1:]))\n')
            docker.chmod(0o755)
            env['PATH'] = str(state/'bin')+os.pathsep+os.defpath
            if mutate:
                mutate(root, state, tree, env)
            args = [str(tree/'build/.tools/box') if x == '{box}' else x for x in arguments]
            result = subprocess.run([str(state/'bin/php'), *args], cwd=tree, env=env,
                                    capture_output=True, text=True, timeout=10)
            return result, str(root), str(state), str(tree)

    def test_selected_tree_is_readonly_and_parent_is_not_exposed(self):
        result, _root, state, tree = self.invoke()
        self.assertEqual(0, result.returncode, result.stderr)
        options = json.loads(result.stdout)
        mounts = [options[i+1] for i, item in enumerate(options[:-1]) if item == '--mount']
        self.assertIn(f'type=bind,source={tree},target={tree},readonly', mounts)
        self.assertIn(f'type=bind,source={state},target={state},readonly', mounts)
        parent = str(Path(tree).parent)
        self.assertFalse(any(f'source={parent},' in item for item in mounts))
        for name in ('vendor', 'build', 'src/GeneratedBuildInfo.php'):
            self.assertIn(f'type=bind,source={tree}/{name},target={tree}/{name}', mounts)

    def test_mutable_box_cache_is_replaced_with_the_readonly_artifact_guard(self):
        result, _root, state, tree = self.invoke(('-d', 'phar.readonly=0', '{box}', 'compile', '--no-parallel'))
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(['php', '-d', 'phar.readonly=0', tree+'/scripts/ci/exec-box.php', state+'/artifacts/box.phar', 'compile', '--no-parallel'], json.loads(result.stdout)[-7:])
        result, _, _, _ = self.invoke(('{box}', '--version'))
        self.assertNotEqual(0, result.returncode)
        self.assertEqual('', result.stdout)

    def test_linked_or_malformed_control_metadata_refuses_before_docker(self):
        def linked(root, state, _tree, _env):
            (state/'repro-tree').unlink()
            (state/'repro-tree').symlink_to(root/'scripts/build.sh')
        def hardlinked(root, state, _tree, _env):
            os.link(state/'repro-tree', root/'alias')
        def multiple(_root, state, _tree, _env):
            path=state/'repro-tree';path.chmod(0o600);path.write_text('one\ntwo\n');path.chmod(0o400)
        def profile(_root, _state, _tree, env):
            env['CLI_PHP_CI_REPRO']='unreviewed'
        for mutation in (linked, hardlinked, multiple, profile):
            result, _, _, _ = self.invoke(mutate=mutation)
            self.assertNotEqual(0, result.returncode)
            self.assertEqual('', result.stdout)

    def test_prepared_box_config_pins_time_and_preserves_source_settings(self):
        with tempfile.TemporaryDirectory() as directory:
            root,_state,tree,_env=fixture(Path(directory))
            source=json.loads((root/'box.json.dist').read_text())
            prepared=json.loads((tree/'box.json').read_text())
            self.assertEqual('@1234567890',prepared.pop('timestamp'))
            self.assertEqual(source,prepared)
            self.assertEqual(0o400,(tree/'box.json').stat().st_mode & 0o777)

    def test_tree_preparation_refuses_linked_or_shared_runtime_storage(self):
        with tempfile.TemporaryDirectory() as directory:
            root, state, tree, env = fixture(Path(directory))
            shared = state/'tmp/dw-cli-repro.shared/source.1'
            shared.mkdir(parents=True)
            env['RUNNER_TEMP']=str(state/'tmp')
            command=['bash',str(root/'scripts/ci/prepare-php-repro-tree.sh'),str(shared)]
            self.assertNotEqual(0, subprocess.run(command,env=env,capture_output=True,timeout=10).returncode)
            tree.rename(tree.parent/'hidden')
            tree.symlink_to(tree.parent/'hidden')
            env['RUNNER_TEMP']=str(tree.parent.parent)
            command[-1]=str(tree)
            self.assertNotEqual(0, subprocess.run(command,env=env,capture_output=True,timeout=10).returncode)


@unittest.skipUnless(os.environ.get('CLI_PHP_CI_TEST_IMAGE'), 'requires inspected PHP image')
class ReproRuntimeBoundaryTests(unittest.TestCase):
    def test_archive_controls_wrappers_metadata_and_comparison_artifact_are_protected(self):
        for predecessor in (True, False):
            for attack in ('control', 'wrapper', 'metadata', 'comparison'):
                with self.subTest(predecessor=predecessor,attack=attack), tempfile.TemporaryDirectory(
                        dir=Path(os.environ['CLI_PHP_CI_STATE'])/'tmp') as directory:
                    root,state,tree,env=fixture(Path(directory),predecessor)
                    env['CLI_PHP_CI_IMAGE']=os.environ['CLI_PHP_CI_TEST_IMAGE']
                    before=hashlib.sha256((tree/'scripts/build.sh').read_bytes()).hexdigest()
                    code=r'''
[$unused,$attack,$root,$state,$tree]=$argv;
$target=['control'=>$tree.'/scripts/build.sh','wrapper'=>$state.'/bin/php',
         'metadata'=>$state.'/repro-tree','comparison'=>dirname($tree).'/dw.phar.1'][$attack];
if ($attack==='wrapper') { @unlink($target); }
if ($attack==='metadata') { @chmod($target,0600); }
$changed=@file_put_contents($target,'replacement')!==false;
$writable=true;
foreach ([$tree.'/vendor/output',$tree.'/build/output',$tree.'/src/GeneratedBuildInfo.php',
          $state.'/tmp/output',$state.'/home/output',$state.'/composer-home/output'] as $path) {
    $writable=$writable && file_put_contents($path,'synthetic output')!==false;
}
echo json_encode(['changed'=>$changed,'writable'=>$writable]);
'''
                    command=[str(state/'bin/php'),'-r',code,'--',attack,str(root),str(state),str(tree)]
                    result=subprocess.run(command,cwd=tree,env=env,capture_output=True,text=True,timeout=30)
                    self.assertEqual(0,result.returncode,result.stderr)
                    observation=json.loads(result.stdout)
                    self.assertEqual(predecessor,observation['changed'])
                    self.assertTrue(observation['writable'])
                    if not predecessor:
                        following=subprocess.run([str(state/'bin/php'),'-r','echo "CLI_REPRO_REAL_PHP";'],
                                                 cwd=tree,env=env,capture_output=True,text=True,timeout=30)
                        self.assertEqual(0,following.returncode,following.stderr)
                        self.assertEqual('CLI_REPRO_REAL_PHP',following.stdout)
                        self.assertEqual(before,hashlib.sha256((tree/'scripts/build.sh').read_bytes()).hexdigest())
                        self.assertEqual('first comparison artifact',(tree.parent/'dw.phar.1').read_text())


    def test_mutated_archive_box_cache_cannot_replace_the_pinned_executable(self):
        for predecessor in (True, False):
            with self.subTest(predecessor=predecessor), tempfile.TemporaryDirectory(
                    dir=Path(os.environ['CLI_PHP_CI_STATE'])/'tmp') as directory:
                root,state,tree,env=fixture(Path(directory))
                env['CLI_PHP_CI_IMAGE']=os.environ['CLI_PHP_CI_TEST_IMAGE']
                artifact=state/'artifacts/box.phar'
                artifact.chmod(0o600)
                artifact.write_bytes((Path(os.environ['CLI_PHP_CI_STATE'])/'artifacts/box.phar').read_bytes())
                artifact.chmod(0o400)
                cache=tree/'build/.tools/box'
                cache.parent.mkdir()
                cache.write_text('<?php echo "CLI_MUTABLE_BOX_EXECUTED";')
                if predecessor:
                    launcher=root/'scripts/ci/run-php-repro-container.sh'
                    source=launcher.read_text().replace(
                        'arguments=(-d phar.readonly=0 "$root/scripts/ci/exec-box.php" "$state/artifacts/box.phar" "${@:4}")',
                        'arguments=("$@")')
                    launcher.write_text(source)
                result=subprocess.run([str(state/'bin/php'),'-d','phar.readonly=0',str(cache),'--version'],
                                      cwd=tree,env=env,capture_output=True,text=True,timeout=30)
                self.assertEqual(0,result.returncode,result.stderr)
                if predecessor:
                    self.assertEqual('CLI_MUTABLE_BOX_EXECUTED',result.stdout)
                else:
                    self.assertIn('Box version 4.6.5',result.stdout)
                    self.assertNotIn('CLI_MUTABLE_BOX_EXECUTED',result.stdout)


if __name__=='__main__':
    unittest.main()
