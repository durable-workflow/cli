#!/usr/bin/env python3
"""Installed CLI command-key cohort against one published native Server image.

One run is one phase (`modern` or `legacy`) with one CLI binary, one Server
image and owned synthetic SQLite state. It builds nothing. The modern phase
covers committed-response loss, explicit and generated-key recovery,
concurrency, changed-intent conflicts, Unicode limits, closed, continued and
fenced runs and namespace isolation. The legacy phase admits real commands,
stops the Server, rewrites a stopped copy through the accepted Rust SDK
`sqlite_legacy.py` held-descriptor helper, restarts on that copy, and checks
legacy readback and ambiguity refusals. The synthetic transform is not a
physical 3.8 upgrade.

`--typed-exit-codes` expects the request-ID exit codes (9 conflict, 10
ambiguous); without it a refusal must exit 2 (CLI 2.10.0 and earlier).
"""
import argparse
import concurrent.futures
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import sqlite3
import subprocess
import sys
import threading
import time
import types
import urllib.error
import urllib.request

PROXY_SHA256 = 'bd1e8ccbbf76f63fc2eafa278a023eb181d405b68d9c30016392e395a1eff725'
HELPER_SHA256 = '4446843f79252f23cc58898debf63920c041a1391c9f3c5248e5bea04b481fbf'
MANIFEST_SHA256 = 'da1990000c611d7377690c7526487d6415fa4f9cb42d299f59585a7c2eebf769'
# Official Avro single-object envelopes of null and 1, as the Python SDK
# 2.17.1 serializer with fastavro 1.13.1 produced them for cli#152.
EMPTY = {'codec': 'avro', 'blob': 'wwHioz3/VYAiNwA='}
ONE = {'codec': 'avro', 'blob': 'wwHioz3/VYAiNwQC'}
NIL_RUN = '00000000000000000000000000'

CHECKS, INVOCATIONS = [], []
STATE = {}


def require(condition, label, detail=None):
    if not condition:
        raise AssertionError(label + (': ' + json.dumps(detail, default=str)[:4000] if detail is not None else ''))
    CHECKS.append(label)


def verified(path, digest, name):
    data = Path(path).read_bytes()
    if hashlib.sha256(data).hexdigest() != digest:
        raise SystemExit(name + ' differs from its accepted SHA256')
    return data


def module(data, name, path):
    value = types.ModuleType(name)
    value.__file__ = str(path)
    exec(compile(data, '<' + name + '>', 'exec'), value.__dict__)
    return value


class Runtime:
    """One owned Server container on a numeric loopback port."""

    def __init__(self, options, work, token):
        self.options, self.work, self.token = options, work, token
        self.ids, self.current, self.base = [], None, None

    def docker(self, *args, check=True):
        result = subprocess.run(['docker', *args], capture_output=True, text=True, timeout=60)
        if check and result.returncode:
            raise RuntimeError('docker ' + args[0] + ' failed: ' + result.stderr.replace(self.token, '[redacted]')[:2000])
        return result.stdout.strip()

    def start(self, database):
        name = self.options.container_prefix + '-' + self.options.phase + '-' + secrets.token_hex(4)
        envfile = self.work / 'runtime.env'
        with open(os.open(envfile, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600), 'w') as output:
            output.write('DW_AUTH_TOKEN=' + self.token + '\n')
        try:
            self.current = self.docker(
                'create', '--init', '--read-only', '--name', name, '--cpus', '1', '--memory', '1g',
                '--pids-limit', '256', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
                '--label', 'durable-workflow.cli-command-keys=' + self.options.owner,
                # Run as the invoking user so the owned private data
                # directory needs no wider permissions on any host.
                '--user', '%d:%d' % (os.getuid(), os.getgid()),
                '--tmpfs', '/tmp:mode=1777,size=64m',
                '--tmpfs', '/app/storage:uid=%d,gid=%d,mode=0700,size=64m' % (os.getuid(), os.getgid()),
                '-v', str(self.work / 'data') + ':/cohort:rw', '-p', '127.0.0.1::8080',
                '--env-file', str(envfile), '-e', 'DW_BIND_ADDRESS=0.0.0.0:8080',
                '-e', 'DW_RUST_MAINTENANCE_LOOP=0', '-e', 'DB_CONNECTION=sqlite',
                '-e', 'DB_DATABASE=/cohort/' + database, self.options.image)
            self.ids.append(self.current)
            self.docker('start', self.current)
        finally:
            envfile.unlink()
        host, port = self.docker('port', self.current, '8080/tcp').splitlines()[0].rsplit(':', 1)
        require(host == '127.0.0.1' and 0 < int(port) < 65536, 'runtime is bound to numeric loopback only')
        self.base = 'http://127.0.0.1:' + port
        deadline = time.monotonic() + 60
        while True:
            try:
                status, info = request('GET', '/api/cluster/info')
                if status == 200:
                    return info
            except (OSError, ValueError):
                pass
            if time.monotonic() > deadline:
                raise RuntimeError('runtime did not become ready')
            time.sleep(.1)

    def stop(self):
        if self.current is None:
            return
        self.docker('stop', '--time', '10', self.current)
        self.docker('rm', '-v', self.current)
        self.current = None

    def remove_all(self):
        for identity in self.ids:
            self.docker('rm', '-fv', identity, check=False)
        for identity in self.ids:
            result = subprocess.run(['docker', 'inspect', identity], capture_output=True, text=True, timeout=30)
            if result.returncode == 0:
                raise RuntimeError('owned container ' + identity + ' is still present')
        self.current = None


HTTP = urllib.request.build_opener(urllib.request.ProxyHandler({}))


def request(method, path, body=None, namespace='default'):
    headers = {'Authorization': 'Bearer ' + STATE['token'], 'X-Namespace': namespace,
               'Content-Type': 'application/json'}
    worker = path.startswith('/api/worker/')
    headers['X-Durable-Workflow-Protocol-Version' if worker else 'X-Durable-Workflow-Control-Plane-Version'] = '1.20' if worker else '2'
    raw = urllib.request.Request(STATE['runtime'].base + path, method=method, headers=headers,
                                 data=None if body is None else json.dumps(body).encode())
    try:
        with HTTP.open(raw, timeout=15) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        return error.code, json.load(error)


def cli(*args, namespace='default', exit_code=0):
    result = subprocess.run([STATE['cli'], *args, '--server=' + STATE['proxy'].url, '--namespace=' + namespace, '--json'],
                            env=STATE['env'], capture_output=True, text=True, timeout=45)
    try:
        body = json.loads(result.stdout)
    except ValueError:
        body = {'stdout': result.stdout[:2000], 'stderr': result.stderr[:2000]}
    INVOCATIONS.append({'arguments': args, 'namespace': namespace, 'exit': result.returncode, 'body': body})
    if result.returncode != exit_code:
        raise AssertionError(json.dumps({'args': args, 'expected_exit': exit_code, 'exit': result.returncode,
                                         'body': body, 'stderr': result.stderr[:2000]}))
    return body


def message(kind, workflow, key=None, value=1, name=None, run=None, namespace='default', exit_code=0):
    args = ['workflow:' + kind, workflow, name or ('finish' if kind == 'signal' else 'set'), '--input=' + json.dumps([value])]
    if key is not None:
        args.append('--request-id=' + key)
    if run is not None:
        args.append('--run-id=' + run)
    return cli(*args, namespace=namespace, exit_code=exit_code)


def path_for(kind, fixture, run=None):
    path = '/api/workflows/' + fixture['workflow']
    if run:
        path += '/runs/' + run
    return path + '/' + kind + '/' + ('finish' if kind == 'signal' else 'set')


def poll(f):
    status, body = request('POST', '/api/worker/workflow-tasks/poll',
                           {'worker_id': f['worker'], 'task_queue': f['queue'], 'timeout_seconds': 0}, f['namespace'])
    require(status == 200, 'worker poll answered', body)
    return body.get('task')


def finish(f, task, commands):
    require(bool(task), 'a workflow task was leased')
    status, body = request('POST', '/api/worker/workflow-tasks/' + task['task_id'] + '/complete',
                           {'lease_owner': task['lease_owner'], 'workflow_task_attempt': task['workflow_task_attempt'],
                            'commands': commands}, f['namespace'])
    require(status == 200, 'workflow task completed', body)


def fixture(kind, tag, namespace='default', workflow=None):
    wf = workflow or 'cli216b-' + tag + '-' + secrets.token_hex(4)
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
    require(status == 201, 'synthetic worker registered', body)
    started = cli('workflow:start', '--workflow-id=' + wf, '--type=' + f['type'], '--task-queue=' + f['queue'],
                  '--input=[]', namespace=namespace)
    f['run'] = started['run_id']
    if kind == 'update':
        finish(f, poll(f), [{'type': 'open_signal_wait', 'signal_name': 'finish'}])
    return f


def history(f, run=None):
    status, body = request('GET', '/api/workflows/' + f['workflow'] + '/runs/' + (run or f['run']) + '/history',
                           namespace=f['namespace'])
    require(status == 200, 'history read', body)
    return body['events']


def snapshot():
    path = STATE['work'] / 'data' / STATE['database']
    with sqlite3.connect(path.as_uri() + '?mode=ro', uri=True, timeout=2) as connection:
        connection.execute('BEGIN')
        return STATE['helper'].snapshot(connection)


def typed(answer, reason):
    # Prefer the Server's own refusal body; the envelope copies only some fields.
    candidates = [answer.get('server_response') or {}, answer] + [value for value in answer.values() if isinstance(value, dict)]
    return next((value for value in candidates if value.get('reason') == reason), None)


def refusal_exit(reason):
    if not STATE['typed']:
        return 2
    return {'request_id_conflict': 9, 'request_id_ambiguous': 10}[reason]


def original(kind, f, key=None, lost=False, run=None):
    path = path_for(kind, f, run=run)
    before = len(STATE['proxy'].records)
    if lost:
        STATE['proxy'].arm('POST', path)
    answer = message(kind, f['workflow'], key, run=run, namespace=f['namespace'], exit_code=3 if lost else 0)
    sent = [r for r in STATE['proxy'].records[before:] if r['method'] == 'POST' and r['path'] == path]
    require(len(sent) == 1, kind + ': exactly one admission POST; no implicit resend')
    wire = sent[0]
    require(wire['upstream_status'] == 202 and wire['upstream_complete'], kind + ': upstream admission completed')
    chosen = answer.get('request_id')
    require(isinstance(chosen, str) and chosen != '', kind + ': CLI reports the command key it sent', answer)
    if key is None:
        require(re.fullmatch('cli-' + kind + '-[0-9a-f]{32}', chosen) is not None, kind + ': generated key shape')
    else:
        require(chosen == key, kind + ': CLI reports the explicit key')
    if lost:
        require(wire['dropped'] and wire['upstream_body_bytes'] > 0, kind + ': committed upstream receipt was dropped')
        require(any(item.get('id') == 'request_id.retry' for item in answer.get('recommendations', [])),
                kind + ': lost response is reported as an unknown outcome', answer)
        admitted = snapshot()
        start = len(STATE['proxy'].records)
        receipt = message(kind, f['workflow'], chosen, run=run, namespace=f['namespace'])
        recovered = [r for r in STATE['proxy'].records[start:] if r['method'] == 'POST' and r['path'] == path]
        require(len(recovered) == 1 and recovered[0]['request_body_sha256'] == wire['request_body_sha256'],
                kind + ': recovery with the reported key sends identical bytes')
        require(receipt.get('retained') is True and receipt.get('intent_verified') is True,
                kind + ': recovery returns the verified retained receipt', receipt)
        require(snapshot() == admitted, kind + ': recovery writes nothing')
    else:
        receipt = answer
        require(receipt.get('retained') is False and receipt.get('intent_verified') is True,
                kind + ': fresh receipt is verified and not retained', receipt)
    fields = ('command_id', 'run_id', 'command_sequence', 'accepted_at', 'update_id', 'signal_name')
    return {'request_id': chosen, 'receipt': {k: receipt[k] for k in fields if k in receipt}}


def retained(kind, f, record, run=None):
    before = snapshot()
    answer = message(kind, f['workflow'], record['request_id'], run=run, namespace=f['namespace'])
    for key, value in record['receipt'].items():
        require(answer.get(key) == value, kind + ': retained original ' + key, answer)
    require(answer.get('retained') is True and answer.get('intent_verified') is True, kind + ': verified retained intent')
    require(snapshot() == before, kind + ': retained retry writes nothing')
    return answer


def conflict(kind, f, record, part, **options):
    before = snapshot()
    answer = message(kind, f['workflow'], record['request_id'], namespace=f['namespace'],
                     exit_code=refusal_exit('request_id_conflict'), **options)
    body = typed(answer, 'request_id_conflict')
    require(body is not None, kind + ': typed conflict for ' + part, answer)
    expected = part
    if part == 'target':
        expected = 'target_or_arguments' if options.get('run') is not None else 'arguments'
    elif kind == 'update' and part == 'name':
        expected = 'arguments'
    require(body.get('conflict') == [expected] and body.get('retained_command_id') == record['receipt']['command_id'],
            kind + ': conflict names the retained command and ' + part, body)
    require(answer.get('request_id') == record['request_id'], kind + ': conflict envelope keeps the key')
    if STATE['typed']:
        require([item.get('id') for item in answer.get('recommendations', [])] == ['request_id.conflict'],
                kind + ': conflict recommendation', answer)
    require(snapshot() == before, kind + ': conflict writes nothing')


def terminal(kind, f, record, action):
    commands = []
    if kind == 'update':
        commands.append({'type': 'complete_update', 'update_id': record['receipt']['update_id'],
                         'result': STATE['update_result']})
    commands.append({'type': action, 'result': EMPTY})
    event = 'WorkflowCompleted' if action == 'complete_workflow' else 'WorkflowContinuedAsNew'
    for _ in range(10):
        finish(f, poll(f), commands)
        if any(row['event_type'] == event for row in history(f)):
            break
    require(any(row['event_type'] == event for row in history(f)), kind + ': original run recorded ' + action)


def fenced(kind, f, key):
    """A fresh key cannot admit a command on a closed or historical run.

    The Server durably records the refused command (`command_status:
    rejected`), so this checks admissions in history rather than zero writes.
    """
    event_type = 'SignalReceived' if kind == 'signal' else 'UpdateAccepted'
    admitted = sum(row['event_type'] == event_type for row in history(f))
    answer = message(kind, f['workflow'], key, run=f['run'], namespace=f['namespace'], exit_code=2)
    require(answer.get('accepted') is not True and answer.get('command_status') in (None, 'rejected')
            and answer.get('update_status') in (None, 'rejected'), kind + ': fenced run refuses the fresh command', answer)
    require(sum(row['event_type'] == event_type for row in history(f)) == admitted,
            kind + ': fenced refusal admits nothing to the run')
    return answer.get('reason')


def run_modern(info):
    require(info['capabilities']['command_request_keys'] is True, 'Server advertises command_request_keys')
    STATE['update_result'] = ONE
    for kind in ('signal', 'update'):
        event_type = 'SignalReceived' if kind == 'signal' else 'UpdateAccepted'
        f = fixture(kind, kind + '-loss')
        record = original(kind, f, lost=True)  # generated key recovered from the error envelope
        retained(kind, f, record)
        conflict(kind, f, record, 'arguments', value=2)
        conflict(kind, f, record, 'name', name='other')
        conflict(kind, f, record, 'target', run=f['run'])
        explicit = original(kind, f, key=kind + '-explicit-loss', lost=True)
        retained(kind, f, explicit)
        key = kind + '-concurrent'
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            replies = list(pool.map(lambda _: message(kind, f['workflow'], key), range(4)))
        require(len({r['command_id'] for r in replies}) == 1 and sum(r.get('retained') is True for r in replies) == 3,
                kind + ': four simultaneous first sends admit exactly one command', replies)
        before = snapshot()
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            again = list(pool.map(lambda _: message(kind, f['workflow'], key), range(4)))
        require(all(r.get('retained') is True and r['command_id'] == replies[0]['command_id'] for r in again),
                kind + ': four concurrent identical retries return the original receipt')
        require(snapshot() == before, kind + ': concurrent retries write nothing')
        require(sum(e['event_type'] == event_type for e in history(f)) == 3,
                kind + ': history has one admission per key')
        for label, unicode_key in (('ascii', 'k' * 255), ('two-byte', 'é' * 255), ('four-byte', '🙂' * 255)):
            unicode_record = original(kind, f, key=unicode_key)
            with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
                list(pool.map(lambda _: retained(kind, f, unicode_record), range(4)))
            require(len(unicode_key) == 255, kind + ': 255-character ' + label + ' key retained')
        before, wire = snapshot(), len(STATE['proxy'].records)
        message(kind, f['workflow'], key='é' * 256, namespace=f['namespace'], exit_code=2)
        require(not any(r['method'] == 'POST' for r in STATE['proxy'].records[wire:]),
                kind + ': 256-character key refused before any POST')
        require(snapshot() == before, kind + ': overlength refusal writes nothing')
        a = message(kind, f['workflow'], key='CaseSensitive')
        b = message(kind, f['workflow'], key='casesensitive')
        require(a['command_id'] != b['command_id'], kind + ': keys are case sensitive')
        for action in ('complete_workflow', 'continue_as_new'):
            f = fixture(kind, kind + '-' + action)
            record = original(kind, f, key=kind + '-' + action, lost=True)
            terminal(kind, f, record, action)
            answer = retained(kind, f, record)
            if kind == 'update':
                require(answer.get('update_status') == 'completed' and answer.get('result_envelope') == ONE,
                        'update: completed typed result survives ' + action, answer)
            conflict(kind, f, record, 'arguments', value=2)
            conflict(kind, f, record, 'target', run=NIL_RUN)
            reason = fenced(kind, f, kind + '-' + action + '-fresh')
            require(reason in ('run_not_active', 'run_closed', 'workflow_closed', 'historical_run_command_rejected',
                               'workflow_not_running', 'run_not_current'), kind + ': ' + action + ' fence reason', reason)
            if action == 'continue_as_new':
                status, described = request('GET', '/api/workflows/' + f['workflow'])
                successor = described.get('run_id')
                require(status == 200 and successor and successor != f['run'], kind + ': continued run has a successor')
                successor_events = [e['event_type'] for e in history(f, successor)]
                require(event_type not in successor_events, kind + ': retained retry admitted nothing to the successor')
        f = fixture(kind, kind + '-selected')
        record = original(kind, f, key=kind + '-explicit-run', run=f['run'], lost=True)
        retained(kind, f, record, run=f['run'])
        conflict(kind, f, record, 'target')
    status, _ = request('POST', '/api/namespaces', {'name': 'cli216b-other', 'description': 'synthetic', 'retention_days': 30})
    require(status in (200, 201), 'synthetic second namespace created')
    for kind in ('signal', 'update'):
        common = 'cli216b-ns-' + kind
        a = fixture(kind, common, workflow=common)
        b = fixture(kind, common, namespace='cli216b-other', workflow=common + '-other')
        ra = message(kind, a['workflow'], key='same-key')
        rb = message(kind, b['workflow'], key='same-key', namespace=b['namespace'])
        require(ra['command_id'] != rb['command_id'], kind + ': one key admits independently per namespace')
        before = snapshot()
        foreign = message(kind, a['workflow'], key='same-key', namespace=b['namespace'], exit_code=5)
        require(foreign.get('command_id') is None and foreign.get('update_id') is None,
                kind + ': another namespace cannot read the retained receipt', foreign)
        require(snapshot() == before, kind + ': foreign refusal writes nothing')


def run_legacy(manifest):
    STATE['update_result'] = EMPTY
    selections, cases = [], []

    def select(kind, f, record, stored):
        selections.append({'command_id': record['receipt']['command_id'], 'command_type': kind,
                           'workflow_instance_id': f['workflow'], 'workflow_run_id': f['run'],
                           'caller_key': record['request_id'], 'legacy_request_id': stored})

    for kind in manifest['legacy_readback']['types']:
        for state, action in [('open', None), ('completed', 'complete_workflow'), ('continued', 'continue_as_new')]:
            f = fixture(kind, 'legacy-' + kind + '-' + state)
            record = original(kind, f, key=manifest['keys']['original'])
            if action:
                terminal(kind, f, record, action)
            select(kind, f, record, record['request_id'])
            cases.append({'kind': kind, 'fixture': f, 'record': record, 'state': state, 'shape': 'readback'})
        for shape in ['two-rows', 'legacy-token', 'modern-raw']:
            f = fixture(kind, 'ambiguity-' + kind + '-' + shape)
            record = original(kind, f, key=manifest['keys']['original'])
            token = manifest['keys']['tokens'][kind]
            if shape == 'two-rows':
                second = original(kind, f, key=manifest['keys']['second'])
                select(kind, f, second, manifest['keys']['original'])
            elif shape == 'legacy-token':
                select(kind, f, record, token)
            cases.append({'kind': kind, 'fixture': f, 'record': record, 'shape': shape,
                          'retry_key': token if shape == 'modern-raw' else manifest['keys']['original']})
    STATE['proxy'].shutdown()
    STATE['proxy'].server_close()
    STATE['runtime'].stop()
    source = STATE['work'] / 'data' / 'seed.sqlite'
    with sqlite3.connect(source) as connection:
        require(connection.execute('PRAGMA wal_checkpoint(TRUNCATE)').fetchone()[0] == 0, 'stopped seed checkpointed')
    transform = STATE['helper'].transform_copy(source, STATE['work'] / 'data' / 'legacy.sqlite', selections, stopped=True)
    require(transform['transformed'] == len(selections), 'helper rewrote exactly the selected admissions', transform)
    STATE['database'] = 'legacy.sqlite'
    info = STATE['runtime'].start('legacy.sqlite')
    require(info['version'] == STATE['options'].server_version, 'same Server version on the legacy copy')
    STATE['proxy'] = STATE['proxy_type'](STATE['runtime'].base)
    threading.Thread(target=STATE['proxy'].serve_forever, kwargs={'poll_interval': .01}, daemon=True).start()
    retry = manifest['legacy_readback']['retry_input']

    def readback(case, run=None):
        kind, f, record = case['kind'], case['fixture'], case['record']
        answer = message(kind, f['workflow'], record['request_id'], value=retry, run=run)
        for field, value in record['receipt'].items():
            require(answer.get(field) == value, kind + ': legacy original ' + field, answer)
        require(answer.get('retained') is True and answer.get('intent_verified') is False,
                kind + ': legacy readback is retained and explicitly unverified', answer)
        if kind == 'update' and case['state'] != 'open':
            require(answer.get('update_status') == 'completed', 'legacy update stays completed after ' + case['state'])
        return answer

    for case in cases:
        kind, f, record = case['kind'], case['fixture'], case['record']
        label = kind + ':' + case['shape'] + ':' + case.get('state', 'open')
        before = snapshot()
        if case['shape'] == 'readback':
            with concurrent.futures.ThreadPoolExecutor(max_workers=manifest['legacy_readback']['concurrency']) as pool:
                replies = list(pool.map(lambda _: readback(case), range(manifest['legacy_readback']['concurrency'])))
            require(len({r['command_id'] for r in replies}) == 1, label + ': concurrent readers get one original identity')
            readback(case, run=f['run'])
            human = subprocess.run([STATE['cli'], 'workflow:' + kind, f['workflow'],
                                    'finish' if kind == 'signal' else 'set', '--input=' + json.dumps([retry]),
                                    '--request-id=' + record['request_id'], '--server=' + STATE['proxy'].url,
                                    '--namespace=default'], env=STATE['env'], capture_output=True, text=True, timeout=45)
            require(human.returncode == 0 and 'Intent Verified: no' in human.stdout
                    and 'Intent Verified: yes' not in human.stdout, label + ': human output does not claim verified intent',
                    human.stdout[-2000:])
            if STATE['typed']:
                require('legacy receipt' in human.stdout, label + ': human output names the legacy receipt')
            for options in [{'name': 'other'}, {'run': NIL_RUN}]:
                reply = message(kind, f['workflow'], record['request_id'], value=999,
                                exit_code=refusal_exit('request_id_conflict'), **options)
                body = typed(reply, 'request_id_conflict')
                require(body is not None and body.get('retained_command_id') == record['receipt']['command_id'],
                        label + ': changed name/run refusal names the original command', reply)
        else:
            reply = message(kind, f['workflow'], case['retry_key'], value=retry,
                            exit_code=refusal_exit('request_id_ambiguous'))
            body = typed(reply, 'request_id_ambiguous')
            require(body is not None and body.get('retained') is True and body.get('intent_verified') is False
                    and body.get('retryable') is False, label + ': ambiguity fails closed and unverified', reply)
            require(all(body.get(field) is None for field in ('command_id', 'update_id', 'retained_command_id')),
                    label + ': ambiguity selects no receipt')
            require(reply.get('request_id') == case['retry_key'], label + ': ambiguity envelope keeps the key')
            if STATE['typed']:
                require([item.get('id') for item in reply.get('recommendations', [])] == ['request_id.ambiguous'],
                        label + ': ambiguity recommendation', reply)
        require(snapshot() == before, label + ': complete logical rows unchanged')
    return transform


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--phase', choices=['modern', 'legacy'], required=True)
    parser.add_argument('--cli', type=Path, required=True)
    parser.add_argument('--cli-sha256', required=True)
    parser.add_argument('--typed-exit-codes', action='store_true')
    parser.add_argument('--inputs', type=Path, required=True,
                        help='directory with committed_response_loss.py, sqlite_legacy.py and command-key-legacy-v1-vectors.json')
    parser.add_argument('--server-index', required=True)
    parser.add_argument('--server-source', required=True)
    parser.add_argument('--server-version', required=True)
    parser.add_argument('--work-dir', type=Path, required=True)
    parser.add_argument('--container-prefix', default='cli-command-keys')
    parser.add_argument('--owner', default='manual')
    options = parser.parse_args()
    if not __debug__:
        parser.error('run without Python optimization')
    if (re.fullmatch(r'sha256:[0-9a-f]{64}', options.server_index) is None
            or re.fullmatch(r'[0-9a-f]{40}', options.server_source) is None
            or re.fullmatch(r'[0-9a-f]{64}', options.cli_sha256) is None
            or re.fullmatch(r'[a-z0-9][a-z0-9.-]{0,40}', options.container_prefix) is None):
        parser.error('exact immutable identities and a plain container prefix are required')
    if not options.work_dir.is_absolute() or options.work_dir.exists():
        parser.error('the work directory must be a new absolute path')
    options.image = 'ghcr.io/durable-workflow/server@' + options.server_index
    cli_bytes = verified(options.cli, options.cli_sha256, 'CLI binary')
    proxy = module(verified(options.inputs / 'committed_response_loss.py', PROXY_SHA256, 'response-loss proxy'), 'shared_proxy',
                   options.inputs / 'committed_response_loss.py')
    helper = module(verified(options.inputs / 'sqlite_legacy.py', HELPER_SHA256, 'SQLite helper'), 'sqlite_legacy',
                    options.inputs / 'sqlite_legacy.py')
    manifest_bytes = verified(options.inputs / 'command-key-legacy-v1-vectors.json', MANIFEST_SHA256, 'legacy manifest')
    work = options.work_dir
    work.mkdir(mode=0o700)
    for name in ('data', 'home', 'config', 'tmp', 'bin'):
        (work / name).mkdir(mode=0o700)
    (work / 'command-key-legacy-v1-vectors.json').write_bytes(manifest_bytes)
    helper.MANIFEST = work / 'command-key-legacy-v1-vectors.json'
    binary = work / 'bin' / 'dw'
    with open(os.open(binary, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o500), 'wb') as output:
        output.write(cli_bytes)
    token = secrets.token_hex(32)
    STATE.update(token=token, work=work, helper=helper, cli=str(binary), typed=options.typed_exit_codes,
                 options=options, proxy_type=proxy.CommittedResponseLoss,
                 database='database.sqlite' if options.phase == 'modern' else 'seed.sqlite',
                 env={'PATH': '/usr/bin:/bin', 'HOME': str(work / 'home'), 'DW_CONFIG_HOME': str(work / 'config'),
                      'TMPDIR': str(work / 'tmp'), 'DURABLE_WORKFLOW_AUTH_TOKEN': token})
    runtime = Runtime(options, work, token)
    STATE['runtime'] = runtime
    started = datetime.datetime.now(datetime.timezone.utc).isoformat()
    outcome, error, transform, cleanup = 'fail', None, None, None
    version = subprocess.run([str(binary), '--version'], capture_output=True, text=True, timeout=20,
                             env=STATE['env']).stdout.strip()
    try:
        image = json.loads(runtime.docker('image', 'inspect', options.image))[0]
        labels = image['Config']['Labels']
        require(image['Os'] == 'linux' and image['Architecture'] == 'amd64', 'Server image is linux/amd64')
        require(labels['org.opencontainers.image.revision'] == options.server_source, 'Server image source revision')
        require(labels['dev.durable-workflow.release.tag'] == options.server_version, 'Server image release tag')
        info = runtime.start(STATE['database'])
        require(info['version'] == options.server_version, 'Server reports the published version')
        STATE['proxy'] = proxy.CommittedResponseLoss(runtime.base)
        threading.Thread(target=STATE['proxy'].serve_forever, kwargs={'poll_interval': .01}, daemon=True).start()
        if options.phase == 'modern':
            run_modern(info)
        else:
            transform = run_legacy(json.loads(manifest_bytes))
        outcome = 'pass'
    except Exception as failure:  # report the first failure, then clean up
        error = (type(failure).__name__ + ': ' + str(failure)).replace(token, '[redacted]')
    finally:
        if STATE.get('proxy'):
            STATE['proxy'].shutdown()
            STATE['proxy'].server_close()
        try:
            runtime.remove_all()
            for path in (work / 'data').iterdir():
                path.unlink()
            cleanup = 'pass'
        except Exception as failure:
            cleanup = 'fail: ' + str(failure)
            outcome = 'fail'
    report = {'phase': options.phase, 'outcome': outcome, 'error': error, 'checks': len(CHECKS),
              'invocations': len(INVOCATIONS), 'started_at': started,
              'finished_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
              'cli_version': version, 'cli_sha256': options.cli_sha256, 'typed_exit_codes': options.typed_exit_codes,
              'server_index': options.server_index, 'server_source': options.server_source,
              'server_version': options.server_version, 'proxy_sha256': PROXY_SHA256,
              'helper_sha256': HELPER_SHA256, 'manifest_sha256': MANIFEST_SHA256,
              'runner_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
              'container_ids': runtime.ids, 'cleanup': cleanup, 'transform': transform}
    (work / 'checks.json').write_text(json.dumps(CHECKS, indent=1))
    (work / 'invocations.json').write_text(json.dumps(INVOCATIONS, indent=1).replace(token, '[redacted]'))
    print(json.dumps(report), flush=True)
    return 0 if outcome == 'pass' else 1


if __name__ == '__main__':
    sys.exit(main())
