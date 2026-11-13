"""Installed CLI 2.8 / native 3.9.2 synthetic admission cohort. No builds."""
import concurrent.futures
import datetime
import hashlib
import json
import os
from pathlib import Path
import secrets
import shutil
import sqlite3
import subprocess
import threading
import time
import urllib.error
import urllib.request

if __name__ == "__main__" and "BOOTSTRAP_HELD_IMPLEMENTATION" not in globals():
    raise SystemExit("Use run_cli_216.py to verify the implementation before execution")

SOURCE = Path(__file__).resolve().parent
BOUNDARY_SHA = '5de532a128931b56bd7ca2232d60e07a2fa65d550cfbf56d9cd78d16ed3a5095'
# Bootstrap the local reviewed boundary module from the same bytes that are
# checked. Subsequent inputs use its held descriptors and sealed memory files.
_boundary_path = SOURCE / 'boundaries.py'
_boundary_fd = os.open(_boundary_path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
try:
    import stat
    import types
    _identity = os.fstat(_boundary_fd)
    if (not stat.S_ISREG(_identity.st_mode) or _identity.st_uid != os.getuid()
            or _identity.st_nlink != 1 or _identity.st_mode & 0o022 or _identity.st_size > 65536):
        raise ValueError('boundary module owner/type/mode differs')
    _boundary_bytes = os.pread(_boundary_fd, 65537, 0)
    if hashlib.sha256(_boundary_bytes).hexdigest() != BOUNDARY_SHA:
        raise ValueError('boundary module source differs')
    boundary = types.ModuleType('cli216_boundaries')
    boundary.__file__ = str(_boundary_path)
    exec(compile(_boundary_bytes, str(_boundary_path), 'exec'), boundary.__dict__)
    if boundary.HeldInput._identity(_boundary_path.lstat()) != boundary.HeldInput._identity(_identity):
        raise ValueError('boundary module changed while importing held bytes')
finally:
    os.close(_boundary_fd)

ROOT = boundary.private_directory(Path(os.environ['CLI_216_WORKDIR']))
PROXY_SOURCE = Path(os.environ['CLI_216_PROXY_SOURCE'])
PROXY_SHA256 = 'bd1e8ccbbf76f63fc2eafa278a023eb181d405b68d9c30016392e395a1eff725'
HELD = [boundary.HeldInput(_boundary_path, BOUNDARY_SHA)]

def held(path, digest, maximum=16 * 1024 * 1024):
    value = boundary.HeldInput(path, digest, maximum=maximum)
    HELD.append(value)
    return value

if "BOOTSTRAP_HELD_IMPLEMENTATION" in globals():
    ENTRY_INPUT = BOOTSTRAP_HELD_IMPLEMENTATION
    HELD.extend([BOOTSTRAP_HELD_BOUNDARY, ENTRY_INPUT])
else:
    ENTRY_INPUT = held(Path(__file__).absolute(), None, 131072)
PROXY_INPUT = held(PROXY_SOURCE, PROXY_SHA256)
shared_proxy = PROXY_INPUT.module('cli216_shared_proxy')
CommittedResponseLoss = shared_proxy.CommittedResponseLoss
HELPER_INPUT = held(ROOT / 'sqlite_legacy.py', '6fc6054ce087c136a86d7b6b222daeefaa87c58fa0b613b155a5df39e6a26fad')
legacy_helper = HELPER_INPUT.module('cli216_snapshot_helper')
MANIFEST_INPUT = held(ROOT / 'command-key-legacy-v1-vectors.json', 'da1990000c611d7377690c7526487d6415fa4f9cb42d299f59585a7c2eebf769')
legacy_helper.MANIFEST = Path(MANIFEST_INPUT.executable)
ENVELOPE_INPUT = held(ROOT / 'worker-envelopes.json', '68dac0826e251d0450d458224fa3d4d8714aba8e432df7e241909b8c4b877767')
ENVELOPES = json.loads(ENVELOPE_INPUT.data)
CLI_INPUT = held(ROOT / 'bin/dw', 'da366c79c90978a3c0a92d723c66a96a2d0963f9dccbd41c8207f2c41848f814', 32 * 1024 * 1024)
IMAGE = 'ghcr.io/durable-workflow/server@sha256:8ffcdb2a36af3129a1987849aafe8715fe0d3bf9a9648467f0b5cb8eeef43ba9'
NAME = 'cli-216-392-modern'
TOKEN = secrets.token_hex(32)
STARTED = datetime.datetime.now(datetime.timezone.utc).isoformat()
CHECKS, INVOCATIONS, ADMISSIONS = [], [], []
BASE = None
PROXY = None
DB = ROOT / 'data/database.sqlite'
ENV = {'PATH': str(ROOT / 'bin') + ':/usr/bin:/bin', 'HOME': str(ROOT / 'home'),
       'DW_CONFIG_HOME': str(ROOT / 'config'), 'TMPDIR': str(ROOT / 'tmp'),
       'DURABLE_WORKFLOW_AUTH_TOKEN': TOKEN}
for directory in ('data', 'home', 'config', 'tmp', 'docker-empty'):
    boundary.private_directory(ROOT / directory)
WORKSPACE = boundary.Workspace(ROOT)
ENV.update({'PATH': WORKSPACE.child_anchor('bin') + ':/usr/bin:/bin',
            'HOME': WORKSPACE.child_anchor('home'), 'DW_CONFIG_HOME': WORKSPACE.child_anchor('config'),
            'TMPDIR': WORKSPACE.child_anchor('tmp')})
HTTP = urllib.request.build_opener(urllib.request.ProxyHandler({}))



def require(condition, label, detail=None):
    if not condition:
        raise AssertionError(label + (': ' + json.dumps(detail, default=str) if detail is not None else ''))
    CHECKS.append(label)
    print(json.dumps({'check': label, 'outcome': 'pass'}), flush=True)


def docker(*args):
    p = subprocess.run(['docker', '--config', '/proc/self/fd/' + str(WORKSPACE.children['docker-empty'].fd), *args], capture_output=True, text=True, timeout=50, env={'PATH': os.defpath, 'HOME': WORKSPACE.child_anchor('home')}, pass_fds=WORKSPACE.inherited())
    if p.returncode:
        raise RuntimeError('docker operation failed: ' + p.stderr.replace(TOKEN, '[redacted]')[:2000])
    return p.stdout.strip()


def request(method, path, body=None, namespace='default'):
    WORKSPACE.recheck()
    headers = {'Authorization': 'Bearer ' + TOKEN, 'X-Namespace': namespace, 'Content-Type': 'application/json'}
    worker = path.startswith('/api/worker/')
    headers['X-Durable-Workflow-Protocol-Version' if worker else 'X-Durable-Workflow-Control-Plane-Version'] = '1.20' if worker else '2'
    r = urllib.request.Request(BASE + path, method=method, headers=headers,
                               data=None if body is None else json.dumps(body).encode())
    try:
        with HTTP.open(r, timeout=15) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as e:
        return e.code, json.load(e)
    finally:
        WORKSPACE.recheck()


def cli(*args, namespace='default', success=True):
    WORKSPACE.recheck()
    CLI_INPUT.recheck()
    p = subprocess.run(['dw', *args, '--server=' + PROXY.url, '--namespace=' + namespace, '--json'],
                       executable=CLI_INPUT.executable, env=ENV, capture_output=True, text=True, timeout=45, pass_fds=(CLI_INPUT.sealed_fd, *WORKSPACE.inherited()))
    CLI_INPUT.recheck()
    WORKSPACE.recheck()
    try:
        body = json.loads(p.stdout)
    except ValueError:
        body = {'stdout': p.stdout, 'stderr': p.stderr}
    INVOCATIONS.append({'arguments': args, 'namespace': namespace, 'exit': p.returncode, 'body': body})
    if (p.returncode == 0) != success:
        raise AssertionError(json.dumps({'args': args, 'exit': p.returncode, 'body': body, 'stderr': p.stderr}))
    return body


def message(kind, workflow, key=None, value=1, name=None, run=None, namespace='default', success=True):
    args = ['workflow:' + kind, workflow, name or ('finish' if kind == 'signal' else 'set'), '--input=' + json.dumps([value])]
    if key is not None:
        args.append('--request-id=' + key)
    if run is not None:
        args.append('--run-id=' + run)
    return cli(*args, namespace=namespace, success=success)


def path_for(kind, fixture, name=None, run=None):
    path = '/api/workflows/' + fixture['workflow']
    if run:
        path += '/runs/' + run
    return path + '/' + kind + '/' + (name or ('finish' if kind == 'signal' else 'set'))


def poll(f):
    status, body = request('POST', '/api/worker/workflow-tasks/poll',
                           {'worker_id': f['worker'], 'task_queue': f['queue'], 'timeout_seconds': 0}, f['namespace'])
    if status != 200:
        raise AssertionError(('poll', status, body))
    return body.get('task')


def finish(f, task, commands):
    if not task:
        raise AssertionError('expected a leased workflow task')
    status, body = request('POST', '/api/worker/workflow-tasks/' + task['task_id'] + '/complete',
                           {'lease_owner': task['lease_owner'], 'workflow_task_attempt': task['workflow_task_attempt'],
                            'commands': commands}, f['namespace'])
    if status != 200:
        raise AssertionError(('complete', status, body))
    return body


def fixture(kind, tag, namespace='default', workflow=None, parameter_type='int'):
    wf = workflow or 'cli216-' + tag + '-' + secrets.token_hex(4)
    f = {'workflow': wf, 'worker': wf + '-w', 'queue': wf + '-q', 'type': wf + '-t', 'namespace': namespace}
    param = {'name': 'value', 'type': parameter_type, 'required': True, 'variadic': False,
             'default_available': False, 'allows_null': parameter_type == 'mixed'}
    contracts = {'signals': ['finish'], 'signal_contracts': [{'name': 'finish', 'parameters': [param]}],
                 'updates': ['set'], 'update_validators': [], 'update_contracts': [{'name': 'set', 'parameters': [param]}]}
    status, body = request('POST', '/api/worker/register', {
        'worker_id': f['worker'], 'task_queue': f['queue'], 'runtime': 'python',
        'supported_workflow_types': [f['type']], 'supported_activity_types': [], 'capabilities': ['workflow_updates'],
        'capability_manifest': {name: {'supported': False, 'minimum_protocol_version': '1.18'}
                                for name in ('local_activities', 'worker_sessions', 'sticky_execution')},
        'workflow_command_contracts': {f['type']: contracts}}, namespace)
    if status != 201:
        raise AssertionError(('register', status, body))
    started = cli('workflow:start', '--workflow-id=' + wf, '--type=' + f['type'], '--task-queue=' + f['queue'], '--input=[]', namespace=namespace)
    f['run'] = started['run_id']
    f['empty_envelope'] = ENVELOPES['empty']
    if kind == 'update':
        finish(f, poll(f), [{'type': 'open_signal_wait', 'signal_name': 'finish'}])
    return f


def history(f):
    status, body = request('GET', '/api/workflows/' + f['workflow'] + '/runs/' + f['run'] + '/history', namespace=f['namespace'])
    if status != 200:
        raise AssertionError(('history', status, body))
    return body['events']


def snapshot():
    with WORKSPACE.connection() as db:
        return legacy_helper.snapshot(db)


def original(kind, f, key=None, lost=False, run=None, value=1):
    path = path_for(kind, f, run=run)
    before = len(PROXY.records)
    if lost:
        PROXY.arm('POST', path)
    answer = message(kind, f['workflow'], key, value=value, run=run, namespace=f['namespace'], success=not lost)
    sent = [r for r in PROXY.records[before:] if r['method'] == 'POST' and r['path'] == path]
    require(len(sent) == 1, kind + ': exactly one admission POST; no implicit resend')
    wire = sent[0]
    require(wire['upstream_status'] == 202 and wire['upstream_complete'],
            kind + ': verified complete upstream admission before any injected loss')
    chosen = answer.get('request_id')
    require(isinstance(chosen, str) and bool(chosen), kind + ': CLI exposes a nonempty chosen command key')
    if key is not None:
        require(chosen == key, kind + ': CLI reports the explicit caller key')
    if lost:
        require(wire['dropped'] and wire['upstream_body_bytes'] > 0,
                kind + ': complete upstream receipt dropped before downstream status')
        admitted = snapshot()
        recovery_start = len(PROXY.records)
        receipt = message(kind, f['workflow'], chosen, value=value, run=run, namespace=f['namespace'])
        recovered = [r for r in PROXY.records[recovery_start:] if r['method'] == 'POST' and r['path'] == path]
        require(len(recovered) == 1, kind + ': one explicit recovery POST')
        require(recovered[0]['request_body_sha256'] == wire['request_body_sha256'],
                kind + ': explicit recovery sends identical request bytes without retaining their contents')
        require(receipt.get('retained') is True and receipt.get('intent_verified') is True,
                kind + ': explicit recovery verifies retained intent')
        require(snapshot() == admitted, kind + ': first explicit recovery changes no database row')
    else:
        receipt = answer
        require(receipt.get('retained') is False and receipt.get('intent_verified') is True,
                kind + ': fresh receipt explicitly reports verified non-retained intent')
    fields = ('command_id', 'run_id', 'command_sequence', 'accepted_at', 'update_id', 'signal_name')
    owner = {'request_id': chosen, 'receipt': {k: receipt[k] for k in fields if k in receipt},
             'path': path, 'dropped': lost, 'wire': dict(wire)}
    ADMISSIONS.append(owner)
    return owner


def retained(kind, f, record, value=1, run=None):
    before = snapshot()
    answer = message(kind, f['workflow'], record['request_id'], value=value, run=run, namespace=f['namespace'])
    receipt = record['receipt']
    for key in ('command_id', 'run_id', 'command_sequence', 'accepted_at', 'update_id', 'signal_name'):
        if key in receipt:
            require(answer.get(key) == receipt[key], kind + ': retained original ' + key, answer)
    require(answer.get('retained') is True and answer.get('intent_verified') is True, kind + ': verified retained intent')
    require(snapshot() == before, kind + ': retained retry changes no database row')
    return answer


def conflict(kind, f, record, part, **options):
    before = snapshot()
    answer = message(kind, f['workflow'], record['request_id'], namespace=f['namespace'], success=False, **options)
    # CLI error JSON nests the Server's typed body under response on some paths.
    candidates = [answer] + [v for v in answer.values() if isinstance(v, dict)]
    typed = next((v for v in candidates if v.get('reason') == 'request_id_conflict'), None)
    require(typed is not None, kind + ': typed conflict for ' + part, answer)
    require(typed.get('conflict') == [('target_or_arguments' if options.get('run') is not None else 'arguments') if part == 'target' else ('arguments' if kind == 'update' and part == 'name' else part)] and typed.get('retained_command_id') == record['receipt']['command_id'], kind + ': conflict identifies original intent component ' + part, typed)
    require(snapshot() == before, kind + ': conflict changes no database row')


def result_is_one(answer):
    # Compare the actual retained Avro result with the held official-codec
    # fixture. No external interpreter can self-certify a decoded value.
    return answer.get('result_envelope') == ENVELOPES['one']


def run_modern():
    require(shutil.which('dw', path=ENV['PATH']) == WORKSPACE.child_anchor('bin') + '/dw', 'ordinary scoped PATH selects installed release')
    require(hashlib.sha256((ROOT / 'bin/dw').read_bytes()).hexdigest() == 'da366c79c90978a3c0a92d723c66a96a2d0963f9dccbd41c8207f2c41848f814', 'published CLI binary SHA matches approved release')
    status, info = request('GET', '/api/cluster/info')
    require(status == 200 and info['version'] == '3.9.2' and info['capabilities']['command_request_keys'] is True, 'published Server 3.9.2 advertises command_request_keys', info.get('version'))
    require(info['worker_protocol']['version'] == '1.20', 'worker protocol 1.20')
    for kind in ('signal', 'update'):
        f = fixture(kind, kind + '-loss')
        record = original(kind, f, lost=True)
        retained(kind, f, record)
        conflict(kind, f, record, 'arguments', value=2)
        conflict(kind, f, record, 'name', name='other')
        conflict(kind, f, record, 'target', run=f['run'])
        key = kind + '-concurrent'
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            replies = list(pool.map(lambda _: message(kind, f['workflow'], key), range(4)))
        require(len({r['command_id'] for r in replies}) == 1 and sum(r.get('retained') is True for r in replies) == 3, kind + ': four simultaneous retries admit exactly one command')
        event_type = 'SignalReceived' if kind == 'signal' else 'UpdateAccepted'
        require(sum(e['event_type'] == event_type for e in history(f)) == 2, kind + ': history has one event per original and concurrent key')
        long_record = original(kind, f, key='k' * 255)
        retained(kind, f, long_record)
        for label, unicode_key in (('two-byte', 'é' * 255), ('four-byte', '🙂' * 255)):
            unicode_record = original(kind, f, key=unicode_key)
            retained(kind, f, unicode_record)
            require(len(unicode_key) == 255, kind + ': admitted 255 code points for ' + label)
        refused_before = snapshot()
        wire_before = len(PROXY.records)
        message(kind, f['workflow'], key='é' * 256, namespace=f['namespace'], success=False)
        require(not any(r['method'] == 'POST' for r in PROXY.records[wire_before:]),
                kind + ': 256-code-point key refused before admission POST')
        require(snapshot() == refused_before, kind + ': overlength refusal changes no row')
        a = message(kind, f['workflow'], key='CaseSensitive')
        b = message(kind, f['workflow'], key='casesensitive')
        require(a['command_id'] != b['command_id'], kind + ': key identity is case sensitive')
        for terminal in ('complete_workflow', 'continue_as_new'):
            f = fixture(kind, kind + '-' + terminal)
            record = original(kind, f, key=kind + '-' + terminal, lost=True)
            commands = []
            if kind == 'update':
                commands.append({'type': 'complete_update', 'update_id': record['receipt']['update_id'], 'result': ENVELOPES['one']})
            commands.append({'type': terminal, 'result': f['empty_envelope']})
            terminal_event = 'WorkflowCompleted' if terminal == 'complete_workflow' else 'WorkflowContinuedAsNew'
            for _ in range(10):
                finish(f, poll(f), commands)
                if any(e['event_type'] == terminal_event for e in history(f)):
                    break
            require(any(e['event_type'] == terminal_event for e in history(f)), kind + ': terminal transition recorded before retry')
            answer = retained(kind, f, record)
            if kind == 'update':
                require(answer.get('update_status') == 'completed' and result_is_one(answer), 'update: original completed typed result survives ' + terminal, answer)
            require(any(e['event_type'] == ('WorkflowCompleted' if terminal == 'complete_workflow' else 'WorkflowContinuedAsNew') for e in history(f)), kind + ': original history proves ' + terminal)
            conflict(kind, f, record, 'arguments', value=2)
            conflict(kind, f, record, 'target', run='00000000000000000000000000')
        f = fixture(kind, kind + '-selected')
        record = original(kind, f, key=kind + '-explicit-run', run=f['run'], lost=True)
        retained(kind, f, record, run=f['run'])
        conflict(kind, f, record, 'target')
    request('POST', '/api/namespaces', {'name': 'cli216-other'})
    for kind in ('signal', 'update'):
        common = 'cli216-ns-' + kind
        a = fixture(kind, common, workflow=common)
        b = fixture(kind, common, namespace='cli216-other', workflow=common + '-other')
        ra = message(kind, a['workflow'], key='same-key')
        rb = message(kind, b['workflow'], key='same-key', namespace=b['namespace'])
        require(ra['command_id'] != rb['command_id'] and ra['namespace'] != rb['namespace'], kind + ': same caller key admits independently in each namespace')
        before = snapshot()
        foreign = message(kind, a['workflow'], key='same-key', namespace=b['namespace'], success=False)
        require(foreign.get('reason') in ('instance_not_found', 'workflow_not_found'), kind + ': foreign namespace cannot recover another workflow receipt', foreign)
        require(foreign.get('command_id') is None and foreign.get('update_id') is None, kind + ': foreign namespace leaks no retained identity')
        require(snapshot() == before, kind + ': foreign namespace refusal changes no database row')


def run_selected_and_restart():
    status, info = request('GET', '/api/cluster/info')
    require(status == 200 and info['version'] == '3.9.2', 'same published Server version after process restart')
    previous = json.loads(WORKSPACE.read('modern-admissions.json'))
    recovered = 0
    for record in previous:
        if not record['dropped']:
            continue
        parts = record['path'].split('/')
        kind, workflow = parts[-2], parts[3]
        f = {'workflow': workflow, 'run': record['receipt']['run_id'], 'namespace': 'default'}
        selected = parts[5] if 'runs' in parts else None
        retained(kind, f, record, run=selected)
        recovered += 1
    require(recovered == 8, 'all eight previous lost-response receipts survive actual runtime process restart')
    for kind in ('signal', 'update'):
        for terminal in ('complete_workflow', 'continue_as_new'):
            f = fixture(kind, 'selected-' + kind + '-' + terminal)
            record = original(kind, f, key='selected-' + kind + '-' + terminal, run=f['run'], lost=True)
            commands = []
            if kind == 'update':
                commands.append({'type': 'complete_update', 'update_id': record['receipt']['update_id'],
                                 'result': ENVELOPES['one']})
            commands.append({'type': terminal, 'result': f['empty_envelope']})
            terminal_event = 'WorkflowCompleted' if terminal == 'complete_workflow' else 'WorkflowContinuedAsNew'
            for _ in range(10):
                finish(f, poll(f), commands)
                if any(e['event_type'] == terminal_event for e in history(f)):
                    break
            require(any(e['event_type'] == terminal_event for e in history(f)), kind + ': selected-run terminal transition recorded')
            answer = retained(kind, f, record, run=f['run'])
            if kind == 'update':
                require(answer.get('update_status') == 'completed' and result_is_one(answer),
                        'selected update: original typed result survives ' + terminal)
            conflict(kind, f, record, 'target')
            conflict(kind, f, record, 'target', run='00000000000000000000000000')


def run_fresh_selected():
    for kind in ('signal', 'update'):
        f = fixture(kind, 'fresh-selected-' + kind)
        record = original(kind, f, key='🧪' * 255, run=f['run'])
        require(record['receipt']['run_id'] == f['run'], kind + ': fresh selected-run receipt names the explicit original run')
        retained(kind, f, record, run=f['run'])


def run_typed():
    vectors = [
        ('int64', 9223372036854775806, '9223372036854775806'),
        ('null', None, False),
        ('unicode', 'é🙂', 'e\u0301🙂'),
        ('map', {'b': 2, 'a': [None, 'é', 9007199254740993]},
                {'b': '2', 'a': [None, 'é', 9007199254740993]}),
        ('integer-float', 1, 1.0),
        ('signed-zero', 0.0, -0.0),
    ]
    for kind in ('signal', 'update'):
        f = fixture(kind, 'typed-' + kind, parameter_type='mixed')
        for label, value, other in vectors:
            record = original(kind, f, key='typed-' + label, value=value, lost=True)
            equal = {'a': value['a'], 'b': value['b']} if label == 'map' else value
            retained(kind, f, record, value=equal)
            conflict(kind, f, record, 'arguments', value=other)
        event = 'SignalReceived' if kind == 'signal' else 'UpdateAccepted'
        require(sum(row['event_type'] == event for row in history(f)) == len(vectors),
                kind + ': typed recovery/conflict admits one event per original key')


if __name__ == '__main__':
    outcome, error = 'runner-blocked', None
    runtime = boundary.OwnedContainer(docker, NAME, WORKSPACE)
    try:
        observed = json.loads(docker('image', 'inspect', IMAGE, '--format', '{{json .}}'))
        require(observed['Architecture'] == 'amd64' and observed['Os'] == 'linux', 'observed native platform is linux/amd64')
        require(observed['Config']['Labels']['org.opencontainers.image.revision'] == '5614274d2629d03f92932a07dfee0fb10349ddd9', 'observed image source matches qualified producer')
        envfile = ROOT / 'runtime.env'
        WORKSPACE.write('runtime.env', ('DW_AUTH_TOKEN=' + TOKEN + '\nDW_RUST_MAINTENANCE_LOOP=0\n').encode())
        runtime.run('--rm', '-d', '--name', NAME, '--platform=linux/amd64', '--init', '--cpus=1', '--memory=1g', '--pids-limit=256',
               '--read-only', '--cap-drop=ALL', '--security-opt=no-new-privileges', '--env-file', '/proc/self/fd/' + str(WORKSPACE.files['runtime.env'].fd), '--entrypoint=/bin/sh',
               *WORKSPACE.mount_arguments(),
               '--tmpfs', '/app/storage:rw,uid=1000,gid=1000,mode=750,size=64m',
               '--tmpfs', '/tmp:rw,size=16m', '-p', '127.0.0.1::8080', IMAGE, *WORKSPACE.native_arguments())
        binding = runtime.inspect()['NetworkSettings']['Ports']['8080/tcp'][0]
        BASE = 'http://127.0.0.1:' + binding['HostPort']
        for _ in range(100):
            try:
                if request('GET', '/api/cluster/info')[0] == 200:
                    break
            except (OSError, ValueError):
                time.sleep(.1)
        else:
            raise RuntimeError('runtime did not become ready')
        PROXY = CommittedResponseLoss(BASE)
        threading.Thread(target=PROXY.serve_forever, daemon=True).start()
        if os.environ.get('CLI_216_PHASE') == 'selected-restart':
            run_selected_and_restart()
        elif os.environ.get('CLI_216_PHASE') == 'typed':
            run_typed()
        elif os.environ.get('CLI_216_PHASE') == 'fresh-selected':
            run_fresh_selected()
        else:
            run_modern()
        outcome = 'pass'
    except Exception as e:
        error = (type(e).__name__ + ': ' + str(e)).replace(TOKEN, '[redacted]')
        print(json.dumps({'outcome': outcome, 'error': error}), flush=True)
    finally:
        if PROXY:
            PROXY.shutdown()
            PROXY.server_close()
        try:
            runtime.remove()
            for _ in range(50):
                if not runtime.exists():
                    break
                time.sleep(.1)
            if runtime.exists():
                raise RuntimeError('created container removal did not complete')
            WORKSPACE.recheck()
            for item in HELD:
                item.recheck()
            cleanup = 'created container and anonymous volumes removed; held inputs unchanged'
        except Exception as failure:
            cleanup = 'cleanup or input identity requires investigation'
            outcome = 'runner-blocked'
            error = (type(failure).__name__ + ': ' + str(failure)).replace(TOKEN, '[redacted]')
        WORKSPACE.unlink('runtime.env')
        report = {'started_at': STARTED, 'finished_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                  'outcome': outcome, 'workspace_identity': WORKSPACE.evidence(), 'created_container_ids': runtime.created_ids, 'checks': CHECKS, 'error': error, 'cleanup': cleanup,
                  'runner_sha256': ENTRY_INPUT.digest, 'launcher_sha256': BOOTSTRAP_LAUNCHER_SHA256,
                  'runner_source_commit': BOOTSTRAP_SOURCE_COMMIT,
                  'proxy_sha256': PROXY_INPUT.digest, 'server_index': IMAGE, 'server_source': '5614274d2629d03f92932a07dfee0fb10349ddd9', 'phase': os.environ.get('CLI_216_PHASE', 'modern')}
        WORKSPACE.write('result.json', json.dumps(report, indent=2).encode())
        WORKSPACE.write('invocations.json', json.dumps(INVOCATIONS, indent=2).replace(TOKEN, '[redacted]').encode())
        WORKSPACE.write('admissions.json', json.dumps(ADMISSIONS, indent=2).encode())
        if PROXY:
            WORKSPACE.write('wire.json', json.dumps(PROXY.records, indent=2).replace(TOKEN, '[redacted]').encode())
        WORKSPACE.recheck()
        print(json.dumps(report), flush=True)
        WORKSPACE.close()
    raise SystemExit(0 if outcome == 'pass' else 1)
