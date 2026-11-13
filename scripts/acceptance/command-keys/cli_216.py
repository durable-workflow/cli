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
import importlib.util

PROXY_SOURCE = Path(os.environ['CLI_216_PROXY_SOURCE']).resolve()
PROXY_SHA256 = 'bd1e8ccbbf76f63fc2eafa278a023eb181d405b68d9c30016392e395a1eff725'
if hashlib.sha256(PROXY_SOURCE.read_bytes()).hexdigest() != PROXY_SHA256:
    raise SystemExit('Shared proxy bytes do not match the independently reviewed fixture')
spec = importlib.util.spec_from_file_location('cli216_shared_proxy', PROXY_SOURCE)
shared_proxy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(shared_proxy)
CommittedResponseLoss = shared_proxy.CommittedResponseLoss

ROOT = Path(os.environ['CLI_216_WORKDIR'])
if not ROOT.is_absolute() or ROOT.resolve() != ROOT or ROOT.is_symlink():
    raise SystemExit('Require an absolute canonical private experiment directory')
owned = ROOT.stat()
if owned.st_uid != os.getuid() or owned.st_mode & 0o777 != 0o700:
    raise SystemExit('Experiment directory must be owned by this user and mode 0700')
CODEC_PYTHON = Path(os.environ['CLI_216_CODEC_PYTHON'])
if not CODEC_PYTHON.is_absolute():
    raise SystemExit('Provide the verified installed codec Python as an absolute path')
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
    (ROOT / directory).mkdir(exist_ok=True)
(ROOT / 'data').chmod(0o700)


def require(condition, label, detail=None):
    if not condition:
        raise AssertionError(label + (': ' + json.dumps(detail, default=str) if detail is not None else ''))
    CHECKS.append(label)
    print(json.dumps({'check': label, 'outcome': 'pass'}), flush=True)


def docker(*args):
    p = subprocess.run(['docker', '--config', str(ROOT / 'docker-empty'), *args], capture_output=True, text=True, timeout=50, env={'PATH': os.defpath, 'HOME': str(ROOT / 'home')})
    if p.returncode:
        raise RuntimeError('docker operation failed: ' + p.stderr.replace(TOKEN, '[redacted]')[:2000])
    return p.stdout.strip()


def request(method, path, body=None, namespace='default'):
    headers = {'Authorization': 'Bearer ' + TOKEN, 'X-Namespace': namespace, 'Content-Type': 'application/json'}
    worker = path.startswith('/api/worker/')
    headers['X-Durable-Workflow-Protocol-Version' if worker else 'X-Durable-Workflow-Control-Plane-Version'] = '1.20' if worker else '2'
    r = urllib.request.Request(BASE + path, method=method, headers=headers,
                               data=None if body is None else json.dumps(body).encode())
    try:
        with urllib.request.urlopen(r, timeout=15) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as e:
        return e.code, json.load(e)


def cli(*args, namespace='default', success=True):
    p = subprocess.run(['dw', *args, '--server=' + PROXY.url, '--namespace=' + namespace, '--json'],
                       env=ENV, capture_output=True, text=True, timeout=45)
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


def fixture(kind, tag, namespace='default', workflow=None):
    wf = workflow or 'cli216-' + tag + '-' + secrets.token_hex(4)
    f = {'workflow': wf, 'worker': wf + '-w', 'queue': wf + '-q', 'type': wf + '-t', 'namespace': namespace}
    param = {'name': 'value', 'type': 'int', 'required': True, 'variadic': False,
             'default_available': False, 'allows_null': False}
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
    f['empty_envelope'] = json.loads((ROOT / 'worker-envelopes.json').read_text())['empty']
    if kind == 'update':
        finish(f, poll(f), [{'type': 'open_signal_wait', 'signal_name': 'finish'}])
    return f


def history(f):
    status, body = request('GET', '/api/workflows/' + f['workflow'] + '/runs/' + f['run'] + '/history', namespace=f['namespace'])
    if status != 200:
        raise AssertionError(('history', status, body))
    return body['events']


def snapshot():
    with sqlite3.connect('file:' + str(DB) + '?mode=ro', uri=True) as db:
        tables = [r[0] for r in db.execute("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")]
        data = {}
        for table in tables:
            quoted = '"' + table.replace('"', '""') + '"'
            rows = db.execute('SELECT * FROM ' + quoted).fetchall()
            data[table] = {'count': len(rows), 'sha256': hashlib.sha256(repr(sorted(rows, key=repr)).encode()).hexdigest()}
        return data


def original(kind, f, key=None, lost=False, run=None):
    path = path_for(kind, f, run=run)
    before = len(PROXY.records)
    if lost:
        PROXY.arm('POST', path)
    answer = message(kind, f['workflow'], key, run=run, namespace=f['namespace'], success=not lost)
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
        receipt = message(kind, f['workflow'], chosen, run=run, namespace=f['namespace'])
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


def decoded_result(answer):
    envelope = answer.get('result_envelope')
    p = subprocess.run([str(CODEC_PYTHON), '-I', '-B', '-c',
        'import json,sys; from durable_workflow import serializer; print(json.dumps(serializer.decode_envelope(json.load(sys.stdin))))'],
        input=json.dumps(envelope), capture_output=True, text=True, timeout=10,
        env={'PATH': '/usr/bin:/bin', 'HOME': str(ROOT / 'home'), 'TMPDIR': str(ROOT / 'tmp')})
    if p.returncode:
        raise AssertionError('official Avro fixture decode failed')
    return json.loads(p.stdout)


def run_modern():
    require(shutil.which('dw', path=ENV['PATH']) == str(ROOT / 'bin/dw'), 'ordinary scoped PATH selects installed release')
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
                commands.append({'type': 'complete_update', 'update_id': record['receipt']['update_id'], 'result': json.loads((ROOT / 'worker-envelopes.json').read_text())['one']})
            commands.append({'type': terminal, 'result': f['empty_envelope']})
            terminal_event = 'WorkflowCompleted' if terminal == 'complete_workflow' else 'WorkflowContinuedAsNew'
            for _ in range(10):
                finish(f, poll(f), commands)
                if any(e['event_type'] == terminal_event for e in history(f)):
                    break
            require(any(e['event_type'] == terminal_event for e in history(f)), kind + ': terminal transition recorded before retry')
            answer = retained(kind, f, record)
            if kind == 'update':
                require(answer.get('update_status') == 'completed' and decoded_result(answer) == 1, 'update: original completed typed result survives ' + terminal, answer)
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
    previous = json.loads((ROOT / 'modern-admissions.json').read_text())
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
                                 'result': json.loads((ROOT / 'worker-envelopes.json').read_text())['one']})
            commands.append({'type': terminal, 'result': f['empty_envelope']})
            terminal_event = 'WorkflowCompleted' if terminal == 'complete_workflow' else 'WorkflowContinuedAsNew'
            for _ in range(10):
                finish(f, poll(f), commands)
                if any(e['event_type'] == terminal_event for e in history(f)):
                    break
            require(any(e['event_type'] == terminal_event for e in history(f)), kind + ': selected-run terminal transition recorded')
            answer = retained(kind, f, record, run=f['run'])
            if kind == 'update':
                require(answer.get('update_status') == 'completed' and decoded_result(answer) == 1,
                        'selected update: original typed result survives ' + terminal)
            conflict(kind, f, record, 'target')
            conflict(kind, f, record, 'target', run='00000000000000000000000000')


def run_fresh_selected():
    for kind in ('signal', 'update'):
        f = fixture(kind, 'fresh-selected-' + kind)
        record = original(kind, f, key='🧪' * 255, run=f['run'])
        require(record['receipt']['run_id'] == f['run'], kind + ': fresh selected-run receipt names the explicit original run')
        retained(kind, f, record, run=f['run'])


if __name__ == '__main__':
    outcome, error = 'runner-blocked', None
    try:
        observed = json.loads(docker('image', 'inspect', IMAGE, '--format', '{{json .}}'))
        require(observed['Architecture'] == 'amd64' and observed['Os'] == 'linux', 'observed native platform is linux/amd64')
        require(observed['Config']['Labels']['org.opencontainers.image.revision'] == '5614274d2629d03f92932a07dfee0fb10349ddd9', 'observed image source matches qualified producer')
        envfile = ROOT / 'runtime.env'
        envfile.write_text('DW_AUTH_TOKEN=' + TOKEN + '\nDW_RUST_MAINTENANCE_LOOP=0\n')
        envfile.chmod(0o600)
        docker('run', '--rm', '-d', '--name', NAME, '--platform=linux/amd64', '--init', '--cpus=1', '--memory=1g', '--pids-limit=256',
               '--read-only', '--cap-drop=ALL', '--security-opt=no-new-privileges', '--env-file', str(envfile),
               '--mount', 'type=bind,source=' + str(ROOT / 'data') + ',target=/app/database',
               '--tmpfs', '/app/storage:rw,uid=1000,gid=1000,mode=750,size=64m',
               '--tmpfs', '/tmp:rw,size=16m', '-p', '127.0.0.1::8080', IMAGE)
        binding = json.loads(docker('inspect', NAME, '--format', '{{json .NetworkSettings.Ports}}'))['8080/tcp'][0]
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
            docker('rm', '-fv', NAME)
            cleanup = 'container and anonymous volumes removed'
        except Exception:
            cleanup = 'container removal needs investigation'
        (ROOT / 'runtime.env').unlink(missing_ok=True)
        report = {'started_at': STARTED, 'finished_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                  'outcome': outcome, 'checks': CHECKS, 'error': error, 'cleanup': cleanup,
                  'runner_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
                  'proxy_sha256': hashlib.sha256(PROXY_SOURCE.read_bytes()).hexdigest(), 'server_index': IMAGE, 'server_source': '5614274d2629d03f92932a07dfee0fb10349ddd9', 'phase': os.environ.get('CLI_216_PHASE', 'modern')}
        (ROOT / 'result.json').write_text(json.dumps(report, indent=2))
        (ROOT / 'invocations.json').write_text(json.dumps(INVOCATIONS, indent=2).replace(TOKEN, '[redacted]'))
        (ROOT / 'admissions.json').write_text(json.dumps(ADMISSIONS, indent=2))
        if PROXY:
            (ROOT / 'wire.json').write_text(json.dumps(PROXY.records, indent=2).replace(TOKEN, '[redacted]'))
        print(json.dumps(report), flush=True)
    raise SystemExit(0 if outcome == 'pass' else 1)
