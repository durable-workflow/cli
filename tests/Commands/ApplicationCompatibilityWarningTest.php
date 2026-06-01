<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Application;
use DurableWorkflow\Cli\BuildInfo;
use DurableWorkflow\Cli\Support\AuthCompositionContract;
use DurableWorkflow\Cli\Support\ControlPlaneRequestContract;
use DurableWorkflow\Cli\Support\ExternalTaskInputContract;
use DurableWorkflow\Cli\Support\ExternalTaskResultContract;
use DurableWorkflow\Cli\Support\ResolvedConnection;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;
use Tests\Support\ExternalTaskInputContractTest;
use Tests\Support\ExternalTaskResultContractTest;

class ApplicationCompatibilityWarningTest extends TestCase
{
    private const CONNECTION_ENV_VARS = [
        'DURABLE_WORKFLOW_SERVER_URL',
        'DURABLE_WORKFLOW_NAMESPACE',
        'DURABLE_WORKFLOW_AUTH_TOKEN',
        'DURABLE_WORKFLOW_TLS_VERIFY',
        'DW_CONFIG_HOME',
        'DW_ENV',
    ];

    /** @var list<array{process: Process, directory: string}> */
    private array $fakeServers = [];

    /** @var array<string, array{process: string|false, env_exists: bool, env_value: mixed}> */
    private array $originalEnv = [];

    private string $configHome = '';

    protected function setUp(): void
    {
        $this->snapshotEnv('DW_CLI_VERSION');
        foreach (self::CONNECTION_ENV_VARS as $name) {
            $this->snapshotEnv($name);
        }

        $this->setEnv('DW_CLI_VERSION', '0.1.5');
        foreach (self::CONNECTION_ENV_VARS as $name) {
            $this->clearEnv($name);
        }

        $this->configHome = sys_get_temp_dir().'/dw-cli-config-'.bin2hex(random_bytes(8));
        if (! mkdir($this->configHome, 0777, true) && ! is_dir($this->configHome)) {
            self::fail('Unable to create isolated dw config directory.');
        }
        $this->setEnv('DW_CONFIG_HOME', $this->configHome);
    }

    protected function tearDown(): void
    {
        foreach ($this->fakeServers as $server) {
            if ($server['process']->isRunning()) {
                $server['process']->stop(0.2);
            }

            foreach (glob($server['directory'].'/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($server['directory']);
        }

        $this->fakeServers = [];

        if ($this->configHome !== '') {
            foreach (glob($this->configHome.'/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($this->configHome);
            $this->configHome = '';
        }

        foreach ($this->originalEnv as $name => $state) {
            $this->restoreEnv($name, $state);
        }

        $this->originalEnv = [];
    }

    public function test_first_server_command_does_not_warn_when_only_server_app_version_differs(): void
    {
        $application = $this->applicationWithClient(new ApplicationCompatibilityFakeClient(
            self::clusterInfo(serverVersion: '9.8.7', supportedCliVersions: '0.1.x'),
        ));
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run([
            'command' => 'server:health',
        ]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('Server is ok', $display);
        self::assertStringNotContainsString('Compatibility warning:', $display);
    }

    public function test_first_server_command_warns_from_client_compatibility_metadata(): void
    {
        $currentVersion = BuildInfo::version();

        $application = $this->applicationWithClient(new ApplicationCompatibilityFakeClient(
            self::clusterInfo(serverVersion: '9.8.7', supportedCliVersions: '0.2.x'),
        ));
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run([
            'command' => 'server:health',
        ]));

        $display = $tester->getDisplay();
        self::assertStringContainsString(
            sprintf('Compatibility warning: dw %s is outside server-advertised cli supported_versions [0.2.x] for server 9.8.7. Compatibility window: 0.2.x. Next step: upgrade dw, pin dw to a supported release, or connect to a compatible server.', $currentVersion),
            $display,
        );
        self::assertStringContainsString('Run `dw doctor` for details.', $display);
        self::assertStringContainsString('Server is ok', $display);
    }

    public function test_non_worker_command_does_not_warn_when_worker_protocol_metadata_is_absent(): void
    {
        $clusterInfo = self::clusterInfo(serverVersion: '9.8.7', supportedCliVersions: '0.1.x');
        unset($clusterInfo['worker_protocol']);

        $application = $this->applicationWithClient(new ApplicationCompatibilityFakeClient($clusterInfo));
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run([
            'command' => 'server:health',
        ]));

        self::assertStringNotContainsString('worker_protocol.version', $tester->getDisplay());
    }

    public function test_warns_when_server_requires_auth_composition_contract_but_omits_manifest(): void
    {
        $clusterInfo = self::clusterInfo(serverVersion: '9.8.7', supportedCliVersions: '0.1.x');
        $clusterInfo['client_compatibility']['required_protocols'] = [
            'auth_composition' => [
                'schema' => AuthCompositionContract::SCHEMA,
                'version' => AuthCompositionContract::VERSION,
            ],
        ];

        $application = $this->applicationWithClient(new ApplicationCompatibilityFakeClient($clusterInfo));
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run([
            'command' => 'server:health',
        ]));

        self::assertStringContainsString(
            'Compatibility warning: server did not advertise auth_composition_contract; dw CLI expects durable-workflow.v2.auth-composition.contract v1.',
            $tester->getDisplay(),
        );
    }

    public function test_warns_when_server_auth_composition_contract_version_drifts(): void
    {
        $clusterInfo = self::clusterInfo(serverVersion: '9.8.7', supportedCliVersions: '0.1.x');
        $clusterInfo['auth_composition_contract'] = [
            'schema' => AuthCompositionContract::SCHEMA,
            'version' => 2,
        ];

        $application = $this->applicationWithClient(new ApplicationCompatibilityFakeClient($clusterInfo));
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run([
            'command' => 'server:health',
        ]));

        self::assertStringContainsString(
            'Compatibility warning: server advertises auth_composition_contract [durable-workflow.v2.auth-composition.contract v2]; dw CLI expects durable-workflow.v2.auth-composition.contract v1.',
            $tester->getDisplay(),
        );
    }

    public function test_warns_when_server_requires_external_task_result_contract_but_omits_manifest(): void
    {
        $clusterInfo = self::clusterInfo(serverVersion: '9.8.7', supportedCliVersions: '0.1.x');
        $clusterInfo['client_compatibility']['required_protocols'] = [
            'worker_protocol' => [
                'external_task_result_contract' => [
                    'schema' => ExternalTaskResultContract::SCHEMA,
                    'version' => ExternalTaskResultContract::VERSION,
                ],
            ],
        ];

        $application = $this->applicationWithClient(new ApplicationCompatibilityFakeClient($clusterInfo));
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run([
            'command' => 'server:health',
        ]));

        self::assertStringContainsString(
            'Compatibility warning: server did not advertise worker_protocol.external_task_result_contract; dw CLI expects durable-workflow.v2.external-task-result.contract v1.',
            $tester->getDisplay(),
        );
    }

    public function test_warns_when_server_requires_external_task_input_contract_but_omits_manifest(): void
    {
        $clusterInfo = self::clusterInfo(serverVersion: '9.8.7', supportedCliVersions: '0.1.x');
        $clusterInfo['client_compatibility']['required_protocols'] = [
            'worker_protocol' => [
                'external_task_input_contract' => [
                    'schema' => ExternalTaskInputContract::CONTRACT_SCHEMA,
                    'version' => ExternalTaskInputContract::VERSION,
                ],
            ],
        ];

        $application = $this->applicationWithClient(new ApplicationCompatibilityFakeClient($clusterInfo));
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run([
            'command' => 'server:health',
        ]));

        self::assertStringContainsString(
            'Compatibility warning: server did not advertise worker_protocol.external_task_input_contract; dw CLI expects durable-workflow.v2.external-task-input.contract v1.',
            $tester->getDisplay(),
        );
    }

    public function test_warns_when_server_external_task_input_fixture_artifacts_are_incomplete(): void
    {
        $clusterInfo = self::clusterInfo(serverVersion: '9.8.7', supportedCliVersions: '0.1.x');
        $manifest = ExternalTaskInputContractTest::manifest();
        unset($manifest['fixtures']['activity_task']);
        $clusterInfo['worker_protocol']['external_task_input_contract'] = $manifest;

        $application = $this->applicationWithClient(new ApplicationCompatibilityFakeClient($clusterInfo));
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run([
            'command' => 'server:health',
        ]));

        self::assertStringContainsString(
            'Compatibility warning: server worker_protocol.external_task_input_contract is missing consumable fixture artifact coverage: missing fixture [activity_task].',
            $tester->getDisplay(),
        );
    }

    public function test_warns_when_server_external_task_result_fixture_artifacts_are_incomplete(): void
    {
        $clusterInfo = self::clusterInfo(serverVersion: '9.8.7', supportedCliVersions: '0.1.x');
        $manifest = ExternalTaskResultContractTest::manifest();
        unset($manifest['fixtures']['handler_crash']);
        $clusterInfo['worker_protocol']['external_task_result_contract'] = $manifest;

        $application = $this->applicationWithClient(new ApplicationCompatibilityFakeClient($clusterInfo));
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run([
            'command' => 'server:health',
        ]));

        self::assertStringContainsString(
            'Compatibility warning: server worker_protocol.external_task_result_contract is missing consumable fixture artifact coverage: missing fixture [handler_crash].',
            $tester->getDisplay(),
        );
    }

    public function test_worker_command_warns_when_worker_protocol_metadata_is_absent(): void
    {
        $clusterInfo = self::clusterInfo(serverVersion: '9.8.7', supportedCliVersions: '0.1.x');
        unset($clusterInfo['worker_protocol']);

        $application = $this->applicationWithClient(new ApplicationCompatibilityFakeClient($clusterInfo));
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run([
            'command' => 'worker:list',
        ]));

        self::assertStringContainsString(
            'Compatibility warning: server did not advertise worker_protocol.version; worker commands expect 1.0.',
            $tester->getDisplay(),
        );
    }

    public function test_worker_command_does_not_warn_when_server_advertises_compatible_newer_worker_protocol(): void
    {
        $clusterInfo = self::clusterInfo(serverVersion: '9.8.7', supportedCliVersions: '0.1.x');
        $clusterInfo['worker_protocol']['version'] = '1.8';

        $application = $this->applicationWithClient(new ApplicationCompatibilityFakeClient($clusterInfo));
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run([
            'command' => 'worker:list',
        ]));

        self::assertStringNotContainsString('worker_protocol.version', $tester->getDisplay());
    }

    public function test_worker_command_warns_when_server_advertises_incompatible_worker_protocol(): void
    {
        $clusterInfo = self::clusterInfo(serverVersion: '9.8.7', supportedCliVersions: '0.1.x');
        $clusterInfo['worker_protocol']['version'] = '2.0';

        $application = $this->applicationWithClient(new ApplicationCompatibilityFakeClient($clusterInfo));
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run([
            'command' => 'worker:list',
        ]));

        self::assertStringContainsString(
            'Compatibility warning: server advertises worker_protocol.version [2.0]; worker commands support protocol 1.0 on compatible same-major server minors.',
            $tester->getDisplay(),
        );
    }

    public function test_compatibility_probe_does_not_poison_command_connection_flags(): void
    {
        $workflowListConnections = [];
        $clusterInfo = self::clusterInfo(serverVersion: '9.8.7', supportedCliVersions: '0.1.x');
        $application = new Application(
            static function (ResolvedConnection $resolved) use ($clusterInfo, &$workflowListConnections): ApplicationCompatibilityRecordingClient {
                return new ApplicationCompatibilityRecordingClient(
                    $clusterInfo,
                    $resolved,
                    $workflowListConnections,
                );
            },
        );
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run([
            'command' => 'workflow:list',
            '--server' => 'https://flag.example',
            '--namespace' => 'tenant-a',
            '--output' => 'json',
        ]));

        self::assertSame([[
            'server' => 'https://flag.example',
            'namespace' => 'tenant-a',
        ]], $workflowListConnections);

        $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('tenant-a', $decoded['namespace']);
        self::assertSame('tenant-a', $decoded['workflows'][0]['namespace']);
        self::assertSame('wf-tenant-a', $decoded['workflows'][0]['workflow_id']);
    }

    public function test_compatibility_probe_does_not_seed_non_factory_client_before_flags_are_bound(): void
    {
        $defaultServer = $this->startFakeHttpServer('default');
        $flagServer = $this->startFakeHttpServer('flag');
        $this->setEnv('DURABLE_WORKFLOW_SERVER_URL', $defaultServer['base_url']);
        $this->setEnv('DURABLE_WORKFLOW_NAMESPACE', 'default');

        $application = new Application();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run([
            'command' => 'schedule:list',
            '--server' => $flagServer['base_url'],
            '--namespace' => 'tenant-a',
            '--output' => 'json',
        ]));

        $defaultRequests = $this->fakeServerRequests($defaultServer['log']);
        $flagRequests = $this->fakeServerRequests($flagServer['log']);
        $defaultScheduleRequests = self::requestsForPath($defaultRequests, '/api/schedules');
        $flagScheduleRequests = self::requestsForPath($flagRequests, '/api/schedules');

        self::assertNotEmpty(self::requestsForPath($defaultRequests, '/api/cluster/info'));
        self::assertSame([], $defaultScheduleRequests);
        self::assertCount(1, $flagScheduleRequests);
        self::assertSame('tenant-a', $flagScheduleRequests[0]['namespace']);
        self::assertSame('flag', $flagScheduleRequests[0]['server']);

        $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('tenant-a', $decoded['namespace']);
        self::assertSame('tenant-a', $decoded['schedules'][0]['namespace']);
        self::assertSame('sched-tenant-a', $decoded['schedules'][0]['schedule_id']);
    }

    public function test_version_output_warns_from_explicit_target_client_compatibility_metadata(): void
    {
        $currentVersion = BuildInfo::version();

        putenv('DURABLE_WORKFLOW_SERVER_URL=https://server.example');
        $_ENV['DURABLE_WORKFLOW_SERVER_URL'] = 'https://server.example';

        $application = $this->applicationWithClient(new ApplicationCompatibilityFakeClient(
            self::clusterInfo(serverVersion: '9.8.7', supportedCliVersions: '0.2.x'),
        ));
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run([
            '--version' => true,
        ]));

        $display = $tester->getDisplay();
        self::assertStringContainsString(sprintf('dw %s', $currentVersion), $display);
        self::assertStringContainsString(
            sprintf('Compatibility warning: dw %s is outside server-advertised cli supported_versions [0.2.x] for server 9.8.7. Compatibility window: 0.2.x. Next step: upgrade dw, pin dw to a supported release, or connect to a compatible server.', $currentVersion),
            $display,
        );
        self::assertStringNotContainsString('server app version', $display);
    }

    private function applicationWithClient(ApplicationCompatibilityFakeClient $client): Application
    {
        $application = new Application(static fn ($resolved) => $client);
        $application->setAutoExit(false);

        return $application;
    }

    /**
     * @return array{base_url: string, log: string}
     */
    private function startFakeHttpServer(string $name): array
    {
        $directory = sys_get_temp_dir().'/dw-cli-http-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            self::fail('Unable to create fake HTTP server directory.');
        }

        $router = $directory.'/router.php';
        $log = $directory.'/requests.jsonl';
        file_put_contents($router, $this->fakeHttpServerRouter());

        $port = self::reserveTcpPort();
        $process = new Process(
            [PHP_BINARY, '-S', '127.0.0.1:'.$port, $router],
            $directory,
            [
                'DW_CLI_FAKE_SERVER_NAME' => $name,
                'DW_CLI_FAKE_REQUEST_LOG' => $log,
            ],
            null,
            null,
        );
        $process->start();

        $this->fakeServers[] = [
            'process' => $process,
            'directory' => $directory,
        ];

        $baseUrl = 'http://127.0.0.1:'.$port;
        $this->waitForFakeHttpServer($process, $baseUrl);
        @unlink($log);

        return [
            'base_url' => $baseUrl,
            'log' => $log,
        ];
    }

    private function fakeHttpServerRouter(): string
    {
        $clusterInfo = var_export(self::clusterInfo(serverVersion: '9.8.7', supportedCliVersions: '0.1.x'), true);

        return <<<PHP
<?php

\$clusterInfo = {$clusterInfo};

function dw_cli_header_value(array \$headers, string \$name): ?string
{
    foreach (\$headers as \$header => \$value) {
        if (strcasecmp((string) \$header, \$name) === 0) {
            return is_array(\$value) ? (string) reset(\$value) : (string) \$value;
        }
    }

    return null;
}

\$headers = function_exists('getallheaders') ? getallheaders() : [];
if (! is_array(\$headers)) {
    \$headers = [];
}
if (\$headers === []) {
    foreach (\$_SERVER as \$key => \$value) {
        if (! is_string(\$value) || ! str_starts_with((string) \$key, 'HTTP_')) {
            continue;
        }

        \$header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string) \$key, 5)))));
        \$headers[\$header] = \$value;
    }
}
\$path = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
\$namespace = dw_cli_header_value(\$headers, 'X-Namespace');
\$log = getenv('DW_CLI_FAKE_REQUEST_LOG') ?: '';

if (\$log !== '') {
    file_put_contents(\$log, json_encode([
        'server' => getenv('DW_CLI_FAKE_SERVER_NAME') ?: 'unknown',
        'method' => \$_SERVER['REQUEST_METHOD'] ?? 'GET',
        'path' => \$path,
        'query' => \$_SERVER['QUERY_STRING'] ?? '',
        'namespace' => \$namespace,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
}

header('Content-Type: application/json');

if (\$path === '/api/cluster/info') {
    echo json_encode(\$clusterInfo, JSON_UNESCAPED_SLASHES);
    return true;
}

if (\$path === '/api/schedules') {
    echo json_encode([
        'schedules' => [[
            'schedule_id' => 'sched-'.(\$namespace ?? 'missing'),
            'workflow_type' => 'ConformanceWorkflow',
            'paused' => false,
        ]],
    ], JSON_UNESCAPED_SLASHES);
    return true;
}

http_response_code(404);
echo json_encode(['message' => 'unexpected path '.\$path], JSON_UNESCAPED_SLASHES);
return true;
PHP;
    }

    private static function reserveTcpPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::fail(sprintf('Unable to reserve TCP port: %s', $errstr));
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if (! is_string($name) || ! str_contains($name, ':')) {
            self::fail('Unable to determine reserved TCP port.');
        }

        return (int) substr(strrchr($name, ':'), 1);
    }

    private function waitForFakeHttpServer(Process $process, string $baseUrl): void
    {
        $deadline = microtime(true) + 5.0;
        $context = stream_context_create([
            'http' => [
                'ignore_errors' => true,
                'timeout' => 0.2,
            ],
        ]);

        do {
            if (! $process->isRunning()) {
                self::fail('Fake HTTP server exited early: '.$process->getErrorOutput());
            }

            if (@file_get_contents($baseUrl.'/api/cluster/info', false, $context) !== false) {
                return;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail('Fake HTTP server did not become ready: '.$process->getErrorOutput());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fakeServerRequests(string $log): array
    {
        if (! is_file($log)) {
            return [];
        }

        $lines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $requests
     * @return list<array<string, mixed>>
     */
    private static function requestsForPath(array $requests, string $path): array
    {
        return array_values(array_filter(
            $requests,
            static fn (array $request): bool => ($request['path'] ?? null) === $path,
        ));
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
    }

    private function clearEnv(string $name): void
    {
        putenv($name);
        unset($_ENV[$name]);
    }

    private function snapshotEnv(string $name): void
    {
        $this->originalEnv[$name] = [
            'process' => getenv($name),
            'env_exists' => array_key_exists($name, $_ENV),
            'env_value' => $_ENV[$name] ?? null,
        ];
    }

    /**
     * @param  array{process: string|false, env_exists: bool, env_value: mixed}  $state
     */
    private function restoreEnv(string $name, array $state): void
    {
        if (is_string($state['process'])) {
            putenv($name.'='.$state['process']);
        } else {
            putenv($name);
        }

        if ($state['env_exists']) {
            $_ENV[$name] = $state['env_value'];
        } else {
            unset($_ENV[$name]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function clusterInfo(string $serverVersion, string $supportedCliVersions): array
    {
        return [
            'server_id' => 'server-1',
            'version' => $serverVersion,
            'control_plane' => [
                'version' => '2',
                'request_contract' => [
                    'schema' => ControlPlaneRequestContract::SCHEMA,
                    'version' => ControlPlaneRequestContract::VERSION,
                    'operations' => [],
                ],
            ],
            'worker_protocol' => [
                'version' => '1.0',
            ],
            'client_compatibility' => [
                'authority' => 'protocol_manifests',
                'top_level_version_role' => 'informational',
                'clients' => [
                    'cli' => [
                        'supported_versions' => $supportedCliVersions,
                    ],
                ],
            ],
        ];
    }
}

class ApplicationCompatibilityFakeClient extends ServerClient
{
    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    public function __construct(private readonly array $clusterInfo) {}

    /**
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        if ($path === '/cluster/info') {
            return $this->clusterInfo;
        }

        if ($path === '/health') {
            return [
                'status' => 'ok',
                'timestamp' => '2026-04-21T00:00:00Z',
                'checks' => [],
            ];
        }

        if ($path === '/workers') {
            return [
                'workers' => [],
            ];
        }

        return [];
    }
}

class ApplicationCompatibilityRecordingClient extends ServerClient
{
    /** @var list<array{server: string, namespace: string}> */
    private array $workflowListConnections;

    /**
     * @param  array<string, mixed>  $clusterInfo
     * @param  list<array{server: string, namespace: string}>  $workflowListConnections
     */
    public function __construct(
        private readonly array $clusterInfo,
        private readonly ResolvedConnection $resolved,
        array &$workflowListConnections,
    ) {
        $this->workflowListConnections = &$workflowListConnections;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        if ($path === '/cluster/info') {
            return $this->clusterInfo;
        }

        if ($path === '/workflows') {
            $this->workflowListConnections[] = [
                'server' => $this->resolved->server,
                'namespace' => $this->resolved->namespace,
            ];

            return [
                'workflows' => [[
                    'workflow_id' => 'wf-'.$this->resolved->namespace,
                    'workflow_type' => 'ConformanceWorkflow',
                    'status' => 'running',
                ]],
            ];
        }

        return [];
    }
}
