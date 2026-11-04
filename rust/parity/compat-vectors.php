<?php

declare(strict_types=1);

// Generates compatibility test vectors from the 2.x PHP classes, so the Rust
// port (src/compat) can be checked against them case by case. From rust/:
//
//   php parity/compat-vectors.php ../src ../tests/fixtures \
//       src/compat/fixtures target/php-vectors.json
//   DW_COMPAT_PHP_VECTORS=target/php-vectors.json cargo test -- --ignored
[$_, $srcDir, $testFixtures, $rustFixtures, $outFile] = $argv;

spl_autoload_register(static function (string $class) use ($srcDir): void {
    $prefix = 'DurableWorkflow\\Cli\\';
    if (str_starts_with($class, $prefix)) {
        $path = $srcDir.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

use DurableWorkflow\Cli\Support\CompatibilityDiagnostics;
use DurableWorkflow\Cli\Support\CompatibilityException;
use DurableWorkflow\Cli\Support\ControlPlaneRequestContract;
use DurableWorkflow\Cli\Support\ExternalTaskInputContract;
use DurableWorkflow\Cli\Support\ExternalTaskResultContract;
use DurableWorkflow\Cli\Support\ServerClient;

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

function enc(mixed $v): string { return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
function dec(string $raw): mixed { return json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }

// ---------- cluster cases ----------
$rc = ['schema' => ControlPlaneRequestContract::SCHEMA, 'version' => 1, 'operations' => []];
$base = [
    'server_id' => 'server-1',
    'version' => '2.4.0',
    'control_plane' => ['version' => '2', 'request_contract' => $rc],
    'worker_protocol' => ['version' => '1.0'],
    'client_compatibility' => [
        'authority' => 'protocol_manifests',
        'top_level_version_role' => 'informational',
        'clients' => ['cli' => ['supported_versions' => '>=2.0.0,<3.0.0']],
    ],
];

function with(array $base, array $path, mixed $value): array {
    $ref = &$base;
    foreach (array_slice($path, 0, -1) as $k) { if (!isset($ref[$k]) || !is_array($ref[$k])) { $ref[$k] = []; } $ref = &$ref[$k]; }
    $ref[end($path)] = $value;
    return $base;
}
function without(array $base, array $path): array {
    $ref = &$base;
    foreach (array_slice($path, 0, -1) as $k) { $ref = &$ref[$k]; }
    unset($ref[end($path)]);
    return $base;
}

function inputFixture(string $dir, string $name): array { return dec(file_get_contents($dir.'/external-task-input/'.$name)); }
function inputArtifact(string $name, array $example): array {
    return [
        'artifact' => 'durable-workflow.v2.external-task-input.'.$name,
        'media_type' => ExternalTaskInputContract::MEDIA_TYPE,
        'schema' => ExternalTaskInputContract::SCHEMA,
        'version' => 1,
        'sha256' => hash('sha256', json_encode($example, JSON_UNESCAPED_SLASHES)),
        'example' => $example,
    ];
}
$wf = inputFixture($testFixtures, 'workflow-task.v1.json');
$act = inputFixture($testFixtures, 'activity-task.v1.json');
$inputManifest = [
    'schema' => ExternalTaskInputContract::CONTRACT_SCHEMA,
    'version' => 1,
    'scope' => [
        'activity_grade_external_execution' => ['task_kinds' => ['activity_task'], 'fixture_keys' => ['activity_task']],
        'worker_protocol_runtime' => ['task_kinds' => ['workflow_task'], 'fixture_keys' => ['workflow_task']],
    ],
    'fixtures' => [
        'workflow_task' => inputArtifact('workflow-task.v1', $wf),
        'activity_task' => inputArtifact('activity-task.v1', $act),
    ],
];

function resultExample(string $status, mixed $kind = null, mixed $class = null): array {
    $e = [
        'schema' => ExternalTaskResultContract::ENVELOPE_SCHEMA,
        'version' => 1,
        'outcome' => ['status' => $status, 'recorded' => true],
        'task' => ['id' => 'acttask_1', 'kind' => 'activity_task', 'attempt' => 1, 'idempotency_key' => 'k/1'],
        'metadata' => ['handler' => 'billing.charge-card', 'duration_ms' => 10.5, 'note' => "Grüße \u{1F600} </tag>"],
    ];
    if ($status === 'succeeded') { $e['result'] = ['payload' => ['codec' => 'avro', 'blob' => 'QUJD'], 'metadata' => null]; }
    else { $e['failure'] = ['kind' => $kind, 'classification' => $class, 'message' => "Fixture\tfailure.", 'details' => null, 'empty' => []]; }
    return $e;
}
function resultArtifact(string $slug, array $example): array {
    return [
        'artifact' => 'durable-workflow.v2.external-task-result.'.$slug.'.v1',
        'media_type' => ExternalTaskResultContract::MEDIA_TYPE,
        'schema' => ExternalTaskResultContract::ENVELOPE_SCHEMA,
        'version' => 1,
        'sha256' => hash('sha256', (string) json_encode($example, JSON_UNESCAPED_SLASHES)),
        'example' => $example,
    ];
}
$resultManifest = [
    'schema' => ExternalTaskResultContract::SCHEMA,
    'version' => 1,
    'fixtures' => [
        'success' => resultArtifact('success', resultExample('succeeded')),
        'failure' => resultArtifact('failure', resultExample('failed', 'timeout', 'deadline_exceeded')),
        'malformed_output' => resultArtifact('malformed-output', resultExample('failed', 'malformed_output', 'malformed_output')),
        'cancellation' => resultArtifact('cancellation', resultExample('failed', 'cancellation', 'cancelled')),
        'handler_crash' => resultArtifact('handler-crash', resultExample('failed', 'handler_crash', 'handler_crash')),
        'decode_failure' => resultArtifact('decode-failure', resultExample('failed', 'decode_failure', 'decode_failure')),
        'unsupported_payload_codec' => resultArtifact('unsupported-payload-codec', resultExample('failed', 'unsupported_payload', 'unsupported_payload_codec')),
        'unsupported_payload_reference' => resultArtifact('unsupported-payload-reference', resultExample('failed', 'unsupported_payload', 'unsupported_payload_reference')),
    ],
];

$cases = []; // name => [raw json, cli version]
$add = function (string $name, array|string $info, string $cli = '2.5.0-dev') use (&$cases): void {
    $cases[$name] = [is_string($info) ? $info : enc($info), $cli];
};

$add('base', $base);
$add('base cli 3.0.0', $base, '3.0.0');
$add('base cli 0.1.5', $base, '0.1.5');
$add('window 0.2', with(with($base, ['version'], '0.2.221'), ['client_compatibility', 'clients', 'cli', 'supported_versions'], '>=0.2,<1.0'), '0.1.5');
$add('worker 2.0', with($base, ['worker_protocol', 'version'], '2.0'));
$add('worker 1.20', with($base, ['worker_protocol', 'version'], '1.20'));
$add('worker float', '{"control_plane":{"version":"2","request_contract":{"schema":"durable-workflow.v2.control-plane-request.contract","version":1,"operations":{}}},"worker_protocol":{"version":1.20}}');
$add('worker int', with($base, ['worker_protocol', 'version'], 1));
$add('worker 0.9', with($base, ['worker_protocol', 'version'], '0.9'));
$add('worker padded', with($base, ['worker_protocol', 'version'], ' 1.5 '));
$add('worker null', with($base, ['worker_protocol', 'version'], null));
$add('worker array', with($base, ['worker_protocol', 'version'], ['1.0']));
$add('worker missing', without($base, ['worker_protocol']));
$add('worker scalar', with($base, ['worker_protocol'], '1.0'));
$add('no control plane', without($base, ['control_plane']));
$add('control plane scalar', with($base, ['control_plane'], '2'));
foreach (['3' => '3', 'int 2' => 2, 'true' => true, 'false' => false, 'null' => null, 'array' => [2], 'padded' => ' 2 ', 'empty' => ''] as $label => $v) {
    $add('control plane version '.$label, with($base, ['control_plane', 'version'], $v));
}
$add('control plane version float', str_replace('"version":"2","request_contract"', '"version":2.0,"request_contract"', enc($base)));
$add('control plane version 2.5', str_replace('"version":"2","request_contract"', '"version":2.5,"request_contract"', enc($base)));
$add('control plane version 1e20', str_replace('"version":"2","request_contract"', '"version":1e20,"request_contract"', enc($base)));
$add('rc missing', without($base, ['control_plane', 'request_contract']));
$add('rc list', with($base, ['control_plane', 'request_contract'], [1, 2]));
$add('rc empty', '{"control_plane":{"version":"2","request_contract":{}}}');
$add('rc legacy shape', with($base, ['control_plane', 'request_contract'], ['start' => ['fields' => ['duplicate_policy' => ['canonical_values' => ['fail']]]]]));
$add('rc schema wrong', with($base, ['control_plane', 'request_contract', 'schema'], 'other.schema'));
$add('rc schema empty', with($base, ['control_plane', 'request_contract', 'schema'], ' '));
$add('rc schema int', with($base, ['control_plane', 'request_contract', 'schema'], 7));
foreach (['2' => 2, 'str 1' => '1', 'str 1abc' => '1abc', 'str 01' => '01', 'true' => true, 'null' => null, 'array' => [1], 'str 2' => '2', 'neg' => -1, 'str empty' => ''] as $label => $v) {
    $add('rc version '.$label, with($base, ['control_plane', 'request_contract', 'version'], $v));
}
$add('rc version float 1.0', str_replace('"version":1,"operations"', '"version":1.0,"operations"', enc($base)));
$add('rc version float 1.5', str_replace('"version":1,"operations"', '"version":1.5,"operations"', enc($base)));
$add('rc operations missing', without($base, ['control_plane', 'request_contract', 'operations']));
$add('rc operations scalar', with($base, ['control_plane', 'request_contract', 'operations'], 'x'));
$add('rc operations list', with($base, ['control_plane', 'request_contract', 'operations'], [1]));
$add('no client compatibility', without($base, ['client_compatibility']));
$add('client compatibility scalar', with($base, ['client_compatibility'], 'x'));
$add('authority missing', without($base, ['client_compatibility', 'authority']));
$add('authority other', with($base, ['client_compatibility', 'authority'], 'server_version'));
$add('authority int', with($base, ['client_compatibility', 'authority'], 5));
$add('authority bool', with($base, ['client_compatibility', 'authority'], false));
$add('supported missing', without($base, ['client_compatibility', 'clients', 'cli', 'supported_versions']));
$add('supported empty', with($base, ['client_compatibility', 'clients', 'cli', 'supported_versions'], '  '));
$add('supported padded', with($base, ['client_compatibility', 'clients', 'cli', 'supported_versions'], "  0.2.x \n"));
$add('supported padded ok', with($base, ['client_compatibility', 'clients', 'cli', 'supported_versions'], ' 2.x '));
$add('supported int', with($base, ['client_compatibility', 'clients', 'cli', 'supported_versions'], 2));
$add('supported garbage', with($base, ['client_compatibility', 'clients', 'cli', 'supported_versions'], 'garbage'));
$add('clients list', with($base, ['client_compatibility', 'clients'], [['supported_versions' => '0.1']]));
$add('server version missing', without($base, ['version']));
$add('server version blank', with(with($base, ['version'], '  '), ['server_version'], ' 9.9.9 '));
$add('server nested', with(without($base, ['version']), ['server'], ['version' => '7.0.1']));
$add('server version bool', with($base, ['version'], true));
$add('server version float', str_replace('"version":"2.4.0"', '"version":2.40', enc($base)));
$add('server version array', with(with($base, ['version'], ['x']), ['server_version'], '1.2.3'));
$add('outside window 0.2.x', with(with($base, ['version'], '9.8.7'), ['client_compatibility', 'clients', 'cli', 'supported_versions'], '0.2.x'), '0.1.5');
// auth composition
$add('auth required protocols', with($base, ['client_compatibility', 'required_protocols', 'auth_composition'], ['schema' => 'x', 'version' => 1]));
$add('auth required via requires', with($base, ['client_compatibility', 'clients', 'cli', 'requires'], ['control_plane.version', 'auth_composition.version']));
$add('auth requires non string', with($base, ['client_compatibility', 'clients', 'cli', 'requires'], [5, ['auth_composition.version']]));
$auth = ['schema' => 'durable-workflow.v2.auth-composition.contract', 'version' => 1];
$add('auth valid', with($base, ['auth_composition_contract'], $auth));
$add('auth v2', with($base, ['auth_composition_contract'], ['schema' => $auth['schema'], 'version' => 2]));
$add('auth v str1', with($base, ['auth_composition_contract'], ['schema' => $auth['schema'], 'version' => '1']));
$add('auth v str 1 padded', with($base, ['auth_composition_contract'], ['schema' => $auth['schema'], 'version' => ' 1']));
$add('auth schema missing', with($base, ['auth_composition_contract'], ['version' => 1]));
$add('auth scalar', with($base, ['auth_composition_contract'], 'yes'));
$add('auth null with requirement', with(with($base, ['auth_composition_contract'], null), ['client_compatibility', 'required_protocols', 'auth_composition'], []));
$add('auth null no requirement', with($base, ['auth_composition_contract'], null));
// external task input
$add('input required no manifest', with($base, ['client_compatibility', 'required_protocols', 'worker_protocol', 'external_task_input_contract'], ['schema' => 'x']));
$add('input required via requires', with($base, ['client_compatibility', 'clients', 'cli', 'requires'], ['worker_protocol.external_task_input_contract.version']));
$add('input manifest null', with($base, ['worker_protocol', 'external_task_input_contract'], null));
$add('input manifest wrong schema', with($base, ['worker_protocol', 'external_task_input_contract'], ['schema' => 'nope', 'version' => '1']));
$add('input fixtures empty', with($base, ['worker_protocol', 'external_task_input_contract'], ['schema' => ExternalTaskInputContract::CONTRACT_SCHEMA, 'version' => 1, 'fixtures' => []]));
$add('input valid', with($base, ['worker_protocol', 'external_task_input_contract'], $inputManifest));
$add('input missing activity scope', with($base, ['worker_protocol', 'external_task_input_contract'], without($inputManifest, ['scope', 'activity_grade_external_execution'])));
$add('input missing workflow fixture', with($base, ['worker_protocol', 'external_task_input_contract'], without($inputManifest, ['fixtures', 'workflow_task'])));
$add('input sha mismatch', with($base, ['worker_protocol', 'external_task_input_contract'], with($inputManifest, ['fixtures', 'activity_task', 'sha256'], 'nope')));
$add('input kind mismatch', with($base, ['worker_protocol', 'external_task_input_contract'], with($inputManifest, ['fixtures', 'workflow_task'], with(inputArtifact('activity-task.v1', $act), ['artifact'], 'durable-workflow.v2.external-task-input.workflow-task.v1'))));
$badWf = without($wf, ['history', 'last_sequence']);
$add('input invalid example', with($base, ['worker_protocol', 'external_task_input_contract'], with($inputManifest, ['fixtures', 'workflow_task'], inputArtifact('workflow-task.v1', $badWf))));
$add('input attempt zero', with($base, ['worker_protocol', 'external_task_input_contract'], with($inputManifest, ['fixtures', 'activity_task'], inputArtifact('activity-task.v1', with($act, ['task', 'attempt'], 0)))));
$add('input headers empty', with($base, ['worker_protocol', 'external_task_input_contract'], with($inputManifest, ['fixtures', 'activity_task'], inputArtifact('activity-task.v1', with($act, ['headers'], [])))));
$add('input example scalar', with($base, ['worker_protocol', 'external_task_input_contract'], with($inputManifest, ['fixtures', 'activity_task', 'example'], 'x')));
$add('input artifact types', with($base, ['worker_protocol', 'external_task_input_contract'], with(with(with($inputManifest, ['fixtures', 'activity_task', 'artifact'], 5), ['fixtures', 'activity_task', 'media_type'], ['a']), ['fixtures', 'activity_task', 'version'], '2')));
$add('input artifact null', with($base, ['worker_protocol', 'external_task_input_contract'], with($inputManifest, ['fixtures', 'activity_task', 'artifact'], null)));
$add('input scope keys non strings', with($base, ['worker_protocol', 'external_task_input_contract'], with($inputManifest, ['scope', 'worker_protocol_runtime', 'fixture_keys'], [1, ['workflow_task']])));
foreach (['schema' => 'wrong', 'version' => 2, 'task.kind' => 'other_task', 'task.id' => 5, 'lease' => 'x', 'payloads.arguments' => [1, 2], 'deadlines.heartbeat' => 5, 'task.external_executor' => 'x', 'workflow' => null] as $field => $value) {
    $path = explode('.', $field);
    $add('input example '.$field, with($base, ['worker_protocol', 'external_task_input_contract'], with($inputManifest, ['fixtures', 'activity_task'], inputArtifact('activity-task.v1', with($act, $path, $value)))));
}
foreach (['history.events' => ['a' => 1], 'workflow.resume' => [], 'history.next_page_token' => 3, 'task.compatibility' => null] as $field => $value) {
    $path = explode('.', $field);
    $add('input wf example '.$field, with($base, ['worker_protocol', 'external_task_input_contract'], with($inputManifest, ['fixtures', 'workflow_task'], inputArtifact('workflow-task.v1', with($wf, $path, $value)))));
}
$add('input missing payloads', with($base, ['worker_protocol', 'external_task_input_contract'], with($inputManifest, ['fixtures', 'activity_task'], inputArtifact('activity-task.v1', without($act, ['payloads'])))));
// external task result
$add('result required via requires', with($base, ['client_compatibility', 'clients', 'cli', 'requires'], ['worker_protocol.external_task_result_contract']));
$add('result fixtures empty', with($base, ['worker_protocol', 'external_task_result_contract'], ['schema' => ExternalTaskResultContract::SCHEMA, 'version' => 1, 'fixtures' => []]));
$add('result fixtures scalar', with($base, ['worker_protocol', 'external_task_result_contract'], ['schema' => ExternalTaskResultContract::SCHEMA, 'version' => 1, 'fixtures' => 'x']));
$add('result valid', with($base, ['worker_protocol', 'external_task_result_contract'], $resultManifest));
$add('result valid with input', with(with($base, ['worker_protocol', 'external_task_result_contract'], $resultManifest), ['worker_protocol', 'external_task_input_contract'], $inputManifest));
$add('result bad kind', with($base, ['worker_protocol', 'external_task_result_contract'], with($resultManifest, ['fixtures', 'failure'], resultArtifact('failure', resultExample('failed', 'weird', ['x'])))));
$add('result success no result', with($base, ['worker_protocol', 'external_task_result_contract'], with($resultManifest, ['fixtures', 'success'], resultArtifact('success', without(resultExample('succeeded'), ['result'])))));
$add('result success null result', with($base, ['worker_protocol', 'external_task_result_contract'], with($resultManifest, ['fixtures', 'success'], resultArtifact('success', with(resultExample('succeeded'), ['result'], null)))));
$add('result wrong status', with($base, ['worker_protocol', 'external_task_result_contract'], with($resultManifest, ['fixtures', 'cancellation'], resultArtifact('cancellation', resultExample('succeeded')))));
$add('result no outcome', with($base, ['worker_protocol', 'external_task_result_contract'], with($resultManifest, ['fixtures', 'handler_crash'], resultArtifact('handler-crash', without(resultExample('failed', 'handler_crash', 'handler_crash'), ['outcome'])))));
$add('result example schema', with($base, ['worker_protocol', 'external_task_result_contract'], with($resultManifest, ['fixtures', 'handler_crash'], resultArtifact('handler-crash', with(resultExample('failed', 'handler_crash', 'handler_crash'), ['version'], '01')))));
$add('result artifact drift', with($base, ['worker_protocol', 'external_task_result_contract'], with(with($resultManifest, ['fixtures', 'failure', 'artifact'], ['x']), ['fixtures', 'failure', 'media_type'], 1.5)));
// top-level shapes
$add('top list', '[1,2]');
$add('top empty object', '{}');
$add('top string', '"hello"');
$add('top null', 'null');
// real servers
foreach (['cluster-info-php-2.11.8.json', 'cluster-info-native-3.0.5.json'] as $file) {
    $raw = file_get_contents($rustFixtures.'/'.$file);
    foreach (['2.5.0-dev', '3.0.0', '1.9.0', '2.0.0-rc.1'] as $cli) {
        $add($file.' '.$cli, $raw, $cli);
    }
}

$envs = [
    ['2', '1.0'],
    ['3', '2.0'],
];

$clusterOut = [];
foreach ($cases as $name => [$raw, $cli]) {
    $decoded = dec($raw);
    $info = is_array($decoded) ? $decoded : [];
    foreach ([false, true] as $includeWorker) {
        foreach ($envs as [$cp, $wp]) {
            putenv('DW_CLI_VERSION='.$cli);
            $client = (new ReflectionClass(ServerClient::class))->newInstanceWithoutConstructor();
            foreach (['clusterInfoCache' => $info, 'controlPlaneVersion' => $cp, 'workerProtocolVersion' => $wp] as $prop => $value) {
                $p = new ReflectionProperty(ServerClient::class, $prop);
                $p->setValue($client, $value);
            }
            $failure = null;
            try {
                $client->assertServerCompatibility($includeWorker);
            } catch (CompatibilityException $e) {
                $failure = ['message' => $e->getMessage(), 'diagnostic' => $e->diagnostic(), 'next_step' => $e->nextStep()];
            }
            $contract = ControlPlaneRequestContract::fromClusterInfo($info);
            $clusterOut[] = [
                'name' => $name,
                'cluster_json' => $raw,
                'cli_version' => $cli,
                'include_worker' => $includeWorker,
                'client_control_plane' => $cp,
                'client_worker_protocol' => $wp,
                'warnings' => CompatibilityDiagnostics::warnings($info, $cli, $includeWorker),
                'failure' => $failure,
                'request_contract_error' => $contract === null ? ControlPlaneRequestContract::compatibilityErrorFromClusterInfo($info) : null,
            ];
        }
    }
}

// ---------- manifests validated directly ----------
$manifestOut = [];
$manifests = [
    'input valid' => $inputManifest,
    'input no scope, missing workflow, sha drift' => with(with(without(without($inputManifest, ['scope', 'activity_grade_external_execution']), ['fixtures', 'workflow_task']), ['fixtures', 'activity_task', 'path'], 'tests/x.json'), ['fixtures', 'activity_task', 'sha256'], 'not-the-example-hash'),
    'result valid' => $resultManifest,
    'result missing decode, sha drift' => with(with(without($resultManifest, ['fixtures', 'decode_failure']), ['fixtures', 'success', 'path'], 'x'), ['fixtures', 'success', 'sha256'], 'not-the-example-hash'),
    'scalar fixtures' => ['fixtures' => 5],
];
foreach ($manifests as $name => $manifest) {
    $manifestOut[] = [
        'name' => $name,
        'manifest_json' => enc($manifest),
        'input_errors' => ExternalTaskInputContract::validateManifest($manifest),
        'result_errors' => ExternalTaskResultContract::validateManifest($manifest),
    ];
}

// ---------- canonical values ----------
$operations = [
    'start' => ['fields' => [
        'duplicate_policy' => [
            'canonical_values' => [' fail ', 'use-existing', 1, '', null],
            'rejected_aliases' => ['use_existing' => 'use-existing', ' padded ' => ' x ', '5' => 'five', '' => 'empty', 'blank' => ' ', 'num' => 3],
        ],
        'open' => ['rejected_aliases' => ['a' => 'b']],
        'scalar' => 'x',
    ]],
    'list' => [['fields' => []]],
    'nofields' => ['fields' => 'x'],
];
$canonicalInfo = enc(with($base, ['control_plane', 'request_contract', 'operations'], $operations));
$canonicalOut = [];
$contract = ControlPlaneRequestContract::fromClusterInfo(dec($canonicalInfo));
foreach (['start', 'list', 'nofields', 'missing', '0'] as $op) {
    foreach (['duplicate_policy', 'open', 'scalar', 'nope'] as $field) {
        foreach (['use_existing', 'fail', ' fail ', 'use-existing', 'bogus', '', '  ', 'padded', '5', 'blank', 'num', null] as $value) {
            foreach ([null, '--duplicate-policy'] as $option) {
                $error = null;
                try {
                    $contract->assertCanonicalValue($op, $field, $value, $option);
                } catch (RuntimeException $e) {
                    $error = $e->getMessage();
                }
                $canonicalOut[] = ['operation' => $op, 'field' => $field, 'value' => $value, 'option' => $option, 'error' => $error];
            }
        }
    }
}

// ---------- versions ----------
$versions = ['2.5.0-dev', '2.5.0', '3.0.0', '2.0.0', '2.0.0-rc.1', '2.0.0-beta.1', '2.0.0-beta.7', 'v2.1', '2', '0.1.5', '1.9.9', '2.0.0-alpha', '2.0.0-RC1', '2.0.0-pl1', '2.0.0+build.5', '  2.3.4  ', 'x', '', '2.0.0-dev', '10.0.0', '2.0.0.1', '2.0.0-p', '2.0.0_1', '02.00.01', '3.0.0-dev', 'vv2.5.0', '2.0.0-rc.1.1', '2.0.0-#', '1.', '.5', 'dev', '2.0.0-abc', '2.0.0-Alpha', '1.0rc1', '1.0-pre'];
$constraints = ['>=2.0.0,<3.0.0', '>=2.0.0-rc.1,<2.0.0', '0.2.x', '2.x', '2.*', '2.5.*', 'v2.x', '*', 'x', '^2.0', '~2.0', '>=0.2,<1.0', '>=0.1,<1.0', '<3', '=2.5.0', '2.5.0', '>= 2.0', 'v2.5.0', '>=v2.0.0', '1.x || 2.x', '1.x||>=2.5.0-dev', ' , ', '||', '>=2.0.0-dev', '<2.5.0-dev.1', '>2.5.0-alpha', '<=2.5.0', '>=2.0.0,<3.0.0 || >=3.1', '2.0.x', 'garbage', '', '>=2.0.0-beta.2,<2.0.0-rc.1', '>=2.0.0-', '=2.0.0-dev', '>2.0.0+x', '>=2.0.0-rc.1', '<3.0.0-0', '2.0.0.x', '>=1.9.9 , <=2.0.0', "\t>=2\t||\t<1 ", '>=99999999999999999999'];
$matchOut = [];
$match = new ReflectionMethod(CompatibilityDiagnostics::class, 'matchesVersionConstraint');
foreach ($versions as $v) {
    foreach ($constraints as $c) {
        $matchOut[] = [$v, $c, $match->invoke(null, $v, $c)];
    }
}
$compareOut = [];
$cmpVersions = ['2.5.0-dev', '2.5.0', '2.5', '2.5.0.0', '2.0.0-rc.1', '2.0.0-RC.1', '2.0.0-rc1', '2.0.0-beta', '2.0.0-b', '2.0.0-alpha', '2.0.0-a', '2.0.0-dev', '2.0.0-pl', '2.0.0-p1', '2.0.0-foo', '2.0.0', '2.0.0-', '2.0.0#1', '#N#', '1.', '.1', '1..2', '1_2', '1+2', '1a', 'a1', '', '9999999999999999999999.1', '2.0.0-rc.1.1', 'x', '1.0-dev', '1.0.0-0', '1-0', '1--0', "1\u{e9}2", "\u{e9}1"];
foreach ($cmpVersions as $a) {
    foreach ($cmpVersions as $b) {
        $compareOut[] = [$a, $b, version_compare($a, $b)];
    }
}
$workerOut = [];
foreach (['1.0', '1.2', '2.0', '1', 'abc', ' 1.0', '1.0.0'] as $c) {
    foreach (['1.0', '1.20', '1.1', '0.9', '2.0', '1', '1.0.0', ' 1.5 ', 'abc', '10.0', '01.03', '1.0 ', '99999999999999999999.1', '1.99999999999999999999'] as $s) {
        $workerOut[] = [$c, $s, ServerClient::isCompatibleWorkerProtocolVersion($c, $s)];
    }
}

// ---------- php value semantics ----------
$encodeOut = [];
foreach ([
    '{"a":1,"b":[1,2,{}],"c":{},"d":{"0":"x","1":"y"},"e":{"1":"x"},"f":"/path\\/x","g":"üñí😀","h":"\u0001\u001f\u007f","i":1.0,"j":0.1,"k":1e20,"l":1.5e-7,"m":-0.0,"n":12345678901234567890,"o":1e17,"p":1e16,"q":123456.789,"r":"\b\f\n\r\t\"\\\\","s":true,"t":null,"u":[],"v":0.0001,"w":0.00001,"x":-1.5e300,"y":{"":1},"z":{"0":1,"2":2}}',
    '[1149865714285714.2, 2251799813685248.5, 4503599627370495.5, 1.4820142857142857, 124435.71428571429, 0.1234567890123456789, 2.2250738585072014e-308, 1.7976931348623157e308, 4.940656458412465e-324, 9007199254740991.0, 1.0000000000000002, 33.333333333333336, 0.7071067811865476]',
    '[]', '{}', '" <>&\'"', '{"a":{"0":1,"1":2,"x":3}}', '{"1":"a","0":"b"}', '{"-0":1,"01":2,"-1":3}', '[0.30000000000000004, 100.0, 1e15, 2.5e-5, 9007199254740993, -9223372036854775808, 9223372036854775807, 9223372036854775808]',
] as $raw) {
    $encodeOut[] = [$raw, json_encode(dec($raw), JSON_UNESCAPED_SLASHES)];
}
$stringOut = [];
foreach (['1.4820142857142857', '124435.71428571429', '33.333333333333336', '2.0', '1.20', '1.5', '0.1', '1e14', '1e15', '123456789012345.0', '1234567890123456789', '1.0e-5', '0.0001', '-0.0', '0.30000000000000004', '1e100', '99999999999999.99', '9.99999999999999e14', '18446744073709551615', '-9223372036854775809', '3.14159265358979', '2.5e-5', '100000000000000000000', '0.5', '-2.5', '1.23456789012345678', '5e-324', 'true', 'false', 'null', '"x"', '12'] as $raw) {
    $stringOut[] = [$raw, (string) dec($raw)];
}
$intOut = [];
foreach (['"1"', '" 1"', '"1abc"', '"1e3"', '"1.9"', '"abc"', '""', '"  "', '"99999999999999999999"', '"-99999999999999999999"', '"-5"', '"+7"', '".5"', '"5."', '"0x1A"', '"1e"', '"1e+"', '"1e+2x"', '1.9', '-1.9', '1e19', '-1e19', '1e30', '18446744073709551617', 'true', 'false', 'null', '[]', '[1]', '{}', '{"a":1}', '"\n12"', '"1 "', '"\f3"', '"\u000b4"', '"-.5e1"', '"1.5e3abc"', '"-"', '"."', '"+.e1"'] as $raw) {
    $intOut[] = [$raw, (int) dec($raw)];
}

file_put_contents($outFile, json_encode([
    'php_version' => PHP_VERSION,
    'cluster' => $clusterOut,
    'manifests' => $manifestOut,
    'canonical' => ['cluster_json' => $canonicalInfo, 'cases' => $canonicalOut],
    'constraints' => $matchOut,
    'version_compare' => $compareOut,
    'worker_protocol' => $workerOut,
    'json_encode' => $encodeOut,
    'float_string' => $stringOut,
    'int_cast' => $intOut,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
echo "ok ".count($clusterOut)." cluster vectors\n";
