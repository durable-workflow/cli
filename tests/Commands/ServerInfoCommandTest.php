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
                    'invocable_carrier' => [
                        'schema' => 'durable-workflow.v2.invocable-carrier.contract',
                        'version' => 1,
                        'carrier_type' => 'invocable_http',
                    ],
                ],
                'invocable_carrier_contract' => [
                    'schema' => 'durable-workflow.v2.invocable-carrier.contract',
                    'version' => 1,
                    'carrier_type' => 'invocable_http',
                    'scope' => [
                        'task_kinds' => ['activity_task'],
                    ],
                    'request' => [
                        'content_type' => 'application/vnd.durable-workflow.external-task-input+json',
                    ],
                    'response' => [
                        'content_type' => 'application/vnd.durable-workflow.external-task-result+json',
                    ],
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
        self::assertStringContainsString(
            'Invocable Carrier: durable-workflow.v2.invocable-carrier.contract v1 (invocable_http)',
            $display,
        );
        self::assertStringContainsString(
            'Invocable Carrier Contract: durable-workflow.v2.invocable-carrier.contract v1',
            $display,
        );
        self::assertStringContainsString('Invocable Carrier Type: invocable_http', $display);
        self::assertStringContainsString('Invocable Task Kinds: activity_task', $display);
        self::assertStringContainsString(
            'Invocable Request Content-Type: application/vnd.durable-workflow.external-task-input+json',
            $display,
        );
        self::assertStringContainsString(
            'Invocable Response Content-Type: application/vnd.durable-workflow.external-task-result+json',
            $display,
        );

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

    public function test_it_renders_role_topology_summary(): void
    {
        $command = new InfoCommand();
        $command->setServerClient(new ServerInfoFakeClient([
            'server_id' => 'server-1',
            'version' => '0.2.16',
            'default_namespace' => 'default',
            'control_plane' => [
                'version' => '2',
                'request_contract' => [
                    'schema' => ControlPlaneRequestContract::SCHEMA,
                    'version' => ControlPlaneRequestContract::VERSION,
                    'operations' => [],
                ],
            ],
            'topology' => [
                'schema' => 'durable-workflow.v2.role-topology',
                'version' => 4,
                'supported_shapes' => [
                    'embedded',
                    'standalone_server',
                    'split_control_execution',
                ],
                'current_shape' => 'split_control_execution',
                'current_process_class' => 'matching_node',
                'current_roles' => [
                    'matching',
                ],
                'execution_mode' => 'remote_worker_protocol',
                'matching_role' => [
                    'queue_wake_enabled' => false,
                    'shape' => 'dedicated',
                    'wake_owner' => 'dedicated_repair_pass',
                    'task_dispatch_mode' => 'poll',
                    'partition_primitives' => [
                        'connection',
                        'queue',
                        'compatibility',
                        'namespace',
                    ],
                    'backpressure_model' => 'lease_ownership',
                ],
                'role_catalog' => [
                    'matching' => [
                        'plane' => 'control',
                        'hosted_by_current_node' => true,
                        'runs_user_code' => false,
                        'accepts_external_http' => true,
                        'steady_state_interface' => 'worker_poll_and_repair',
                    ],
                ],
                'authority_boundaries' => [
                    'matching' => [
                        'writes' => [
                            'workflow_tasks.leases',
                            'activity_tasks.leases',
                        ],
                    ],
                ],
                'scaling_boundaries' => [
                    'matching' => 'ready_task_rate_and_poller_count',
                    'execution_plane' => 'workflow_and_activity_task_rate',
                ],
                'failure_domains' => [
                    'matching_down' => [
                        'operator_signal' => 'ready_depth_rises_while_claim_rate_falls',
                        'effect' => 'claim_falls_back_to_direct_ready_task_discovery',
                    ],
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('Topology:', $display);
        self::assertStringContainsString(
            'Manifest: durable-workflow.v2.role-topology v4',
            $display,
        );
        self::assertStringContainsString(
            'Supported Shapes: embedded, standalone_server, split_control_execution',
            $display,
        );
        self::assertStringContainsString('Current Shape: split_control_execution', $display);
        self::assertStringContainsString('Current Process Class: matching_node', $display);
        self::assertStringContainsString('Current Roles: matching', $display);
        self::assertStringContainsString('Execution Mode: remote_worker_protocol', $display);
        self::assertStringContainsString(
            'Matching Role: dedicated (queue_wake_enabled=no, wake_owner=dedicated_repair_pass, task_dispatch_mode=poll)',
            $display,
        );
        self::assertStringContainsString(
            'Matching Partitions: connection, queue, compatibility, namespace',
            $display,
        );
        self::assertStringContainsString('Matching Backpressure: lease_ownership', $display);
        self::assertStringContainsString('Current Role Traits:', $display);
        self::assertStringContainsString(
            'matching: plane=control, external_http=yes, runs_user_code=no, interface=worker_poll_and_repair',
            $display,
        );
        self::assertStringContainsString('Current Write Boundaries:', $display);
        self::assertStringContainsString(
            'matching: workflow_tasks.leases, activity_tasks.leases',
            $display,
        );
        self::assertStringContainsString('Scaling Boundaries:', $display);
        self::assertStringContainsString(
            'matching: ready_task_rate_and_poller_count',
            $display,
        );
        self::assertStringContainsString('Failure Domains:', $display);
        self::assertStringContainsString(
            'matching_down: signal=ready_depth_rises_while_claim_rate_falls, effect=claim_falls_back_to_direct_ready_task_discovery',
            $display,
        );
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
