"""Exercise the development runtime in a staged standalone dw executable."""
import argparse
import hashlib
import http.client
import json
import os
from pathlib import Path
import queue
import re
import signal
import subprocess
import tempfile
import threading
import time


def start(binary, root, environment):
    process = subprocess.Popen(
        [str(binary), 'server:start-dev', '--port=0', '--data-dir', str(root/'data')],
        cwd=root, env=environment, stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
    )
    address = queue.Queue()
    output = []

    def read():
        for line in process.stdout:
            output.append(line)
            match = re.fullmatch(r'Durable Workflow development Server listening on http://127\.0\.0\.1:([0-9]{1,5})\n', line)
            if match and 0 < int(match[1]) <= 65535:
                address.put(int(match[1]))

    reader = threading.Thread(target=read, daemon=True)
    reader.start()
    try:
        port = address.get(timeout=20)
    except queue.Empty as error:
        process.kill()
        process.wait(timeout=5)
        reader.join(timeout=5)
        raise RuntimeError('embedded runtime did not start: '+''.join(output)+process.stderr.read()) from error
    return process, reader, port


def stop(process, reader):
    if process.poll() is None:
        if os.name == 'posix':
            process.send_signal(signal.SIGINT)
        else:
            process.terminate()
        try:
            process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait(timeout=5)
            raise RuntimeError('development Server did not stop') from None
    reader.join(timeout=5)
    if os.name == 'posix' and process.returncode != 130:
        raise RuntimeError(f'development Server shutdown returned {process.returncode}')


def readiness(port, expected_status=200):
    # Fixed numeric loopback destination. Proxy/environment settings and a
    # runtime-provided URL cannot redirect the acceptance requests elsewhere.
    connection = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
    try:
        connection.request('GET', '/api/ready')
        response = connection.getresponse()
        body = response.read(65536)
        document = json.loads(body)
        if response.status != expected_status or document.get('development') is not True:
            raise RuntimeError('unexpected development Server readiness')
        if expected_status == 503 and b'storage_admission_unavailable' not in body:
            raise RuntimeError('unsupported file admission did not fail closed')
    finally:
        connection.close()


def cli(binary, root, environment, port, arguments):
    target = [] if port is None else [f'--server=http://127.0.0.1:{port}',
                                     '--token=dev-token']
    result = subprocess.run(
        [str(binary), *arguments, *target, '--output=json'],
        cwd=root, env=environment, stdin=subprocess.DEVNULL,
        capture_output=True, text=True, timeout=15, check=False,
    )
    if result.returncode:
        raise RuntimeError('CLI request failed: '+result.stdout+result.stderr)
    return json.loads(result.stdout)


def profile(binary, root, environment, port):
    saved = cli(binary, root, environment, None, ['env:set', 'local',
        f'--server=http://127.0.0.1:{port}', '--token=dev-token', '--make-default'])
    if saved.get('name') != 'local' or saved.get('current') is not True:
        raise RuntimeError('local profile was not selected')
    diagnostic = cli(binary, root, environment, None, ['doctor'])
    connection = diagnostic.get('connection', {})
    if (diagnostic.get('server', {}).get('reachable') is not True
            or connection.get('profile', {}).get('name') != 'local'
            or connection.get('server') != f'http://127.0.0.1:{port}'):
        raise RuntimeError('doctor did not reach the Server through the saved profile')


def read_workflow(binary, root, environment, run_id=None):
    listing = cli(binary, root, environment, None, ['workflow:list'])
    if not any(row.get('workflow_id') == 'cli-asset-smoke'
               for row in listing.get('workflows', [])):
        raise RuntimeError('new workflow was not listed through the profile')
    workflow = cli(binary, root, environment, None,
                   ['workflow:describe', 'cli-asset-smoke'])
    if workflow.get('workflow_id') != 'cli-asset-smoke':
        raise RuntimeError('new workflow was not visible')
    for name in ['run_id', 'workflow_type', 'status']:
        if not isinstance(workflow.get(name), str) or not workflow[name]:
            raise RuntimeError('missing workflow identity/state: '+name)
    if run_id is not None and workflow['run_id'] != run_id:
        raise RuntimeError('workflow run changed over restart')
    history = cli(binary, root, environment, None,
                  ['workflow:history', 'cli-asset-smoke', workflow['run_id']])
    if not isinstance(history.get('events'), list) or not history['events']:
        raise RuntimeError('workflow history was not visible through the profile')
    return workflow, history['events']


def private_state_refusals(binary, root, environment):
    # Seed each case through the real command first. A refusal caused merely by
    # an incorrectly prepared ACL/directory would not test the malicious entry.
    cases = [('directory', 'symlink')]
    cases += [(name, kind) for name in ['server.lock', 'database.sqlite',
                                      'database.sqlite-wal', 'database.sqlite-shm']
              for kind in ['symlink', 'hardlink']]
    if os.name == 'posix':
        cases += [('directory', 'shared'), ('database.sqlite', 'shared')]
    for index, (name, kind) in enumerate(cases):
        case = root/f'refusal-{index}'
        case.mkdir()
        process, reader, _ = start(binary, case, environment)
        stop(process, reader)
        data = case/'data'
        outside = case/'outside'
        if name == 'directory':
            if kind == 'symlink':
                data.rename(outside)
                data.symlink_to(outside, target_is_directory=True)
                witness = outside/'database.sqlite'
            else:
                data.chmod(0o777)
                witness = data/'database.sqlite'
        else:
            entry = data/name
            entry.unlink(missing_ok=True)
            outside.write_bytes(b'')
            outside.chmod(0o600)
            if kind == 'symlink':
                entry.symlink_to(outside)
            elif kind == 'hardlink':
                os.link(outside, entry)
            else:
                entry.write_bytes(b'')
                entry.chmod(0o666)
            witness = outside
        before = hashlib.sha256(witness.read_bytes()).hexdigest()
        result = subprocess.run([str(binary), 'server:start-dev', '--port=0',
                                 '--data-dir', str(data)], cwd=case, env=environment,
                                stdin=subprocess.DEVNULL, capture_output=True,
                                text=True, timeout=10, check=False)
        if result.returncode != 1 or 'listening on' in result.stdout:
            raise RuntimeError(f'unsafe data entry was not refused: {name}/{kind}')
        if hashlib.sha256(witness.read_bytes()).hexdigest() != before:
            raise RuntimeError(f'unsafe data entry modified its target: {name}/{kind}')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--binary', type=Path, required=True)
    binary = parser.parse_args().binary.resolve(strict=True)
    with tempfile.TemporaryDirectory(prefix='dw-native-asset-') as temporary:
        root = Path(temporary)
        (root/'empty-bin').mkdir()
        environment = {'HOME':str(root), 'DW_CONFIG_HOME':str(root/'config'),
                       'PATH':str(root/'empty-bin'), 'TMPDIR':str(root),
                       'TEMP':str(root), 'TMP':str(root)}
        if os.name == 'nt' and 'SystemRoot' in os.environ:
            environment['SystemRoot'] = os.environ['SystemRoot']
        process, reader, port = start(binary, root, environment)
        try:
            readiness(port)
            if os.name == 'nt':
                # The held directory handles must deny rename, including the
                # parent used by SQLite after the initial path checks.
                for path in [root/'data', root]:
                    try:
                        path.rename(path.with_name(path.name+'-replaced'))
                    except OSError:
                        pass
                    else:
                        raise RuntimeError('a held development directory could be replaced')
            profile(binary, root, environment, port)
            cli(binary, root, environment, None, ['workflow:start', '--type=cli.asset-smoke',
                '--workflow-id=cli-asset-smoke', '--input={}'])
            before, history_before = read_workflow(binary, root, environment)
        finally:
            stop(process, reader)
        process, reader, port = start(binary, root, environment)
        try:
            readiness(port)
            profile(binary, root, environment, port)
            after, history_after = read_workflow(binary, root, environment, before['run_id'])
            for name in ['workflow_id', 'run_id', 'workflow_type', 'status']:
                if before.get(name) != after.get(name):
                    raise RuntimeError('workflow identity/state changed over restart: '+name)
            if history_before != history_after:
                raise RuntimeError('workflow history changed over restart')
        finally:
            stop(process, reader)
        observation = root/'observation.json'
        observation.write_text(json.dumps({'schema':'durable-workflow.storage-pressure.v1',
            'source':'cli-asset-test', 'state':'normal', 'observed_at':int(time.time())}))
        observation.chmod(0o600)
        configured = {**environment, 'DW_STORAGE_ADMISSION_FILE':str(observation),
                      'DW_STORAGE_ADMISSION_SOURCE':'cli-asset-test',
                      'DW_STORAGE_ADMISSION_MAX_AGE_SECONDS':'300'}
        process, reader, port = start(binary, root, configured)
        try:
            readiness(port, 200 if os.name == 'posix' else 503)
        finally:
            stop(process, reader)
        private_state_refusals(binary, root, environment)
    print('Built-in native Server: empty PATH, profile, doctor, start/list/describe/history, restart and platform admission PASS')


if __name__ == '__main__':
    main()
