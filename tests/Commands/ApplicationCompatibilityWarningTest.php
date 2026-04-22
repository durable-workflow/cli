<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Application;
use DurableWorkflow\Cli\Support\AuthCompositionContract;
use DurableWorkflow\Cli\Support\ControlPlaneRequestContract;
use DurableWorkflow\Cli\Support\ExternalTaskInputContract;
use DurableWorkflow\Cli\Support\ExternalTaskResultContract;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Tests\Support\ExternalTaskInputContractTest;
use Tests\Support\ExternalTaskResultContractTest;

class ApplicationCompatibilityWarningTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('DW_CLI_VERSION=0.1.5');
    }

    protected function tearDown(): void
    {
        putenv('DW_CLI_VERSION');
        putenv('DURABLE_WORKFLOW_SERVER_URL');
        unset($_ENV['DURABLE_WORKFLOW_SERVER_URL']);
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
        $application = $this->applicationWithClient(new ApplicationCompatibilityFakeClient(
            self::clusterInfo(serverVersion: '9.8.7', supportedCliVersions: '0.2.x'),
        ));
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run([
            'command' => 'server:health',
        ]));

        $display = $tester->getDisplay();
        self::assertStringContainsString(
            'Compatibility warning: dw 0.1.5 is outside server-advertised cli supported_versions [0.2.x].',
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

    public function test_version_output_warns_from_explicit_target_client_compatibility_metadata(): void
    {
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
        self::assertStringContainsString('dw 0.1.5', $display);
        self::assertStringContainsString(
            'Compatibility warning: dw 0.1.5 is outside server-advertised cli supported_versions [0.2.x].',
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
