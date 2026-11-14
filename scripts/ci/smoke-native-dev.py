"""Exercise the development runtime in a staged standalone dw executable."""
import argparse
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
    result = subprocess.run(
        [str(binary), *arguments, f'--server=http://127.0.0.1:{port}',
         '--token=dev-token', '--output=json'],
        cwd=root, env=environment, stdin=subprocess.DEVNULL,
        capture_output=True, text=True, timeout=15, check=False,
    )
    if result.returncode:
        raise RuntimeError('CLI request failed: '+result.stdout+result.stderr)
    return json.loads(result.stdout)


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
            cli(binary, root, environment, port, ['workflow:start', '--type=cli.asset-smoke',
                '--workflow-id=cli-asset-smoke', '--input={}'])
            before = cli(binary, root, environment, port, ['workflow:describe', 'cli-asset-smoke'])
            if before.get('workflow_id') != 'cli-asset-smoke':
                raise RuntimeError('new workflow was not visible')
            for name in ['run_id', 'workflow_type', 'status']:
                if not isinstance(before.get(name), str) or not before[name]:
                    raise RuntimeError('missing workflow identity/state: '+name)
        finally:
            stop(process, reader)
        process, reader, port = start(binary, root, environment)
        try:
            readiness(port)
            after = cli(binary, root, environment, port, ['workflow:describe', 'cli-asset-smoke'])
            for name in ['workflow_id', 'run_id', 'workflow_type', 'status']:
                if before.get(name) != after.get(name):
                    raise RuntimeError('workflow identity/state changed over restart: '+name)
            if 'cli-asset-smoke' not in json.dumps(after):
                raise RuntimeError('workflow did not survive restart')
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
    print('Built-in native Server: empty PATH, readiness, workflow admission, restart and platform admission PASS')


if __name__ == '__main__':
    main()
