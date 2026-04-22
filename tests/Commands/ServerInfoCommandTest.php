<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\ServerCommand\InfoCommand;
use DurableWorkflow\Cli\Support\ControlPlaneRequestContract;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ServerInfoCommandTest extends TestCase
{
    public function test_it_renders_worker_protocol_capabilities(): void
    {
        $command = new InfoCommand();
        $command->setServerClient(new ServerInfoFakeClient([
            'server_id' => 'server-1',
            'version' => '0.1.0',
            'default_namespace' => 'default',
            'capabilities' => [
                'workflow_tasks' => true,
                'payload_codecs' => ['avro'],
                'payload_codecs_engine_specific' => [
                    'php' => ['workflow-serializer-y', 'workflow-serializer-base64'],
                ],
                'response_compression' => [],
            ],
            'worker_fleet' => [
                'namespace' => 'default',
                'active_workers' => 2,
                'active_worker_scopes' => 3,
                'build_ids' => ['build-a', 'build-b'],
                'queues' => ['external-workflows'],
            ],
            'client_compatibility' => [
                'authority' => 'protocol_manifests',
                'top_level_version_role' => 'informational',
                'fail_closed' => true,
            ],
            'control_plane' => [
                'version' => '2',
                'header' => 'X-Durable-Workflow-Control-Plane-Version',
                'response_contract' => [
                    'schema' => 'durable-workflow.v2.control-plane-response',
                    'version' => 1,
                    'contract' => [
                        'schema' => 'durable-workflow.v2.control-plane-response.contract',
                        'version' => 1,
                        'legacy_field_policy' => 'reject_non_canonical',
                    ],
                ],
                'request_contract' => [
                    'schema' => ControlPlaneRequestContract::SCHEMA,
                    'version' => ControlPlaneRequestContract::VERSION,
                    'operations' => [
                        'start' => [
                            'fields' => [
                                'duplicate_policy' => [
                                    'canonical_values' => ['fail', 'use-existing'],
                                    'rejected_aliases' => [
                                        'use_existing' => 'use-existing',
                                    ],
                                ],
                            ],
                        ],
                        'update' => [
                            'fields' => [
                                'wait_for' => [
                                    'canonical_values' => ['accepted', 'completed'],
                                ],
                            ],
                            'removed_fields' => [
                                'wait_policy' => 'Use wait_for.',
                            ],
                        ],
                    ],
                ],
            ],
            'worker_protocol' => [
                'version' => '1',
                'server_capabilities' => [
                    'long_poll_timeout' => 30,
                    'supported_workflow_task_commands' => [
                        'complete_workflow',
                        'fail_workflow',
                        'continue_as_new',
                        'schedule_activity',
                    ],
                    'workflow_task_poll_request_idempotency' => true,
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('Worker Protocol:', $display);
        self::assertStringContainsString('Worker Fleet:', $display);
        self::assertStringContainsString('Client Compatibility:', $display);
        self::assertStringContainsString('Authority: protocol_manifests', $display);
        self::assertStringContainsString('Top-level Version Role: informational', $display);
        self::assertStringContainsString('Fail Closed: yes', $display);
        self::assertStringContainsString('Control Plane:', $display);
        self::assertStringContainsString('Namespace: default', $display);
        self::assertStringContainsString('Active Workers: 2', $display);
        self::assertStringContainsString('Active Scopes: 3', $display);
        self::assertStringContainsString('Build IDs: build-a, build-b', $display);
        self::assertStringContainsString('Queues: external-workflows', $display);
        self::assertStringContainsString('Header: X-Durable-Workflow-Control-Plane-Version', $display);
        self::assertStringContainsString(
            'Response Contract: durable-workflow.v2.control-plane-response v1',
            $display,
        );
        self::assertStringContainsString(
            'Request Contract: durable-workflow.v2.control-plane-request.contract v1',
            $display,
        );
        self::assertStringContainsString('Start duplicate_policy: fail, use-existing', $display);
        self::assertStringContainsString(
            'Start rejected duplicate_policy aliases: use_existing -> use-existing',
            $display,
        );
        self::assertStringContainsString('Update wait_for: accepted, completed', $display);
        self::assertStringContainsString(
            'Removed update fields: wait_policy (Use wait_for.)',
            $display,
        );
        self::assertStringContainsString('Version: 1', $display);
        self::assertStringContainsString('Long Poll Timeout: 30', $display);
        self::assertStringContainsString(
            'Workflow Task Commands: complete_workflow, fail_workflow, continue_as_new, schedule_activity',
            $display,
        );
        self::assertStringContainsString('Workflow Task Poll Idempotency: yes', $display);

        // Flat list capabilities render inline.
        self::assertStringContainsString('payload_codecs: avro', $display);

        // Associative capability maps render as nested lines so
        // engine-specific payload codec splits stay readable
        // (TD-S037 regression guard).
        self::assertStringContainsString('payload_codecs_engine_specific:', $display);
        self::assertStringContainsString('php: workflow-serializer-y, workflow-serializer-base64', $display);

        // Empty list capabilities render as "none" rather than blank.
        self::assertStringContainsString('response_compression: none', $display);

        // Nested map renders without "Array" or array-to-string warnings.
        self::assertStringNotContainsString('Array', $display);
    }

    public function test_it_honors_json_output(): void
    {
        $command = new InfoCommand();
        $command->setServerClient(new ServerInfoFakeClient([
            'server_id' => 'server-1',
            'version' => '0.1.0',
            'default_namespace' => 'default',
            'capabilities' => [
                'workflow_tasks' => true,
            ],
            'control_plane' => [
                'version' => '2',
                'request_contract' => [
                    'schema' => ControlPlaneRequestContract::SCHEMA,
                    'version' => ControlPlaneRequestContract::VERSION,
                    'operations' => [],
                ],
            ],
            'worker_protocol' => [
                'version' => '1',
                'server_capabilities' => [
                    'long_poll_timeout' => 30,
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute(['--output' => 'json']));

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('server-1', $decoded['server_id']);
        self::assertSame('0.1.0', $decoded['version']);
        self::assertTrue($decoded['capabilities']['workflow_tasks']);
        self::assertSame(30, $decoded['worker_protocol']['server_capabilities']['long_poll_timeout']);
    }
}

class ServerInfoFakeClient extends ServerClient
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly array $payload,
    ) {
    }

    public function get(string $path, array $query = []): array
    {
        return $this->payload;
    }
}
