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

    public function test_it_renders_extended_worker_protocol_manifest_fields(): void
    {
        $command = new InfoCommand();
        $command->setServerClient(new ServerInfoFakeClient([
            'server_id' => 'server-1',
            'version' => '0.2.0',
            'worker_protocol' => [
                'version' => '1.0',
                'server_capabilities' => [
                    'long_poll_timeout' => 30,
                    'poll_status' => true,
                    'history_page_size_default' => 200,
                    'history_page_size_max' => 1000,
                    'query_tasks' => true,
                    'activity_retry_policy' => true,
                    'activity_timeouts' => true,
                    'child_workflow_retry_policy' => true,
                    'child_workflow_timeouts' => true,
                    'parent_close_policy' => true,
                    'non_retryable_failures' => true,
                    'response_compression' => ['gzip', 'deflate'],
                    'history_compression' => [
                        'supported_encodings' => ['identity', 'gzip'],
                        'compression_threshold' => 50,
                    ],
                    'local_activities' => [
                        'schema' => 'durable-workflow.v2.local-activity.contract',
                        'version' => 1,
                        'supported' => true,
                        'execution' => [
                            'mode' => 'local',
                            'same_process' => true,
                            'ordinary_activity_task_created' => false,
                        ],
                        'routing' => [
                            'admission' => 'activity_class_must_resolve_in_the_workflow_worker_process',
                            'queue_bypassed' => true,
                            'rejected_options' => ['connection', 'queue', 'worker_session'],
                        ],
                    ],
                    'worker_session_verbs' => ['create', 'heartbeat', 'close'],
                    'worker_sessions' => [
                        'feature' => 'worker_sessions',
                        'contract_version' => '1.0',
                        'supported' => true,
                        'minimum_protocol_version' => '1.0',
                        'command_field' => 'worker_session',
                        'activity_options_field' => 'worker_session',
                        'ownership' => 'single_worker_lease_owner',
                        'lifecycle' => [
                            'creation' => 'lazy_create_on_first_admitted_activity_or_explicit_worker_create',
                            'renewal' => 'activity_heartbeat_or_explicit_session_heartbeat',
                        ],
                        'admission' => [
                            'queue_routing_first' => true,
                            'requires_registered_worker' => true,
                        ],
                        'limits' => [
                            'max_concurrent_worker_sessions' => 'worker_registration',
                            'max_concurrent_activities' => 'session',
                        ],
                        'default_max_concurrent_activities' => 1,
                    ],
                    'external_execution_surface' => [
                        'schema' => 'durable-workflow.v2.external-execution-surface.contract',
                        'version' => 1,
                        'name' => 'activity_grade_external_execution',
                    ],
                    'external_executor_config' => [
                        'schema' => 'durable-workflow.v2.external-executor-config.contract',
                        'version' => 1,
                        'config_schema' => 'durable-workflow.external-executor.config',
                        'config_schema_version' => 1,
                    ],
                    'external_task_input' => [
                        'schema' => 'durable-workflow.v2.external-task-input.contract',
                        'version' => 1,
                    ],
                    'external_task_result' => [
                        'schema' => 'durable-workflow.v2.external-task-result.contract',
                        'version' => 1,
                    ],
                ],
                'external_execution_surface_contract' => [
                    'schema' => 'durable-workflow.v2.external-execution-surface.contract',
                    'version' => 1,
                    'product_boundary' => [
                        'name' => 'activity_grade_external_execution',
                    ],
                    'contract_seams' => [
                        'input_envelope' => [],
                        'result_envelope' => [],
                        'invocable_http_carrier' => [],
                    ],
                ],
                'external_executor_config_contract' => [
                    'schema' => 'durable-workflow.v2.external-executor-config.contract',
                    'version' => 1,
                    'config_schema' => [
                        'schema' => 'durable-workflow.external-executor.config',
                        'version' => 1,
                    ],
                    'steady_state_surface' => 'config_file',
                    'runtime' => [
                        'configured' => false,
                        'status' => 'not_configured',
                    ],
                ],
                'external_task_input_contract' => [
                    'schema' => 'durable-workflow.v2.external-task-input.contract',
                    'version' => 1,
                    'unknown_field_policy' => 'ignore_additive_reject_unknown_required',
                    'scope' => [
                        'activity_grade_external_execution' => [
                            'task_kinds' => ['activity_task'],
                        ],
                        'worker_protocol_runtime' => [
                            'task_kinds' => ['workflow_task'],
                        ],
                    ],
                    'envelopes' => [
                        'workflow_task' => [],
                        'activity_task' => [],
                    ],
                    'fixtures' => [
                        'workflow_task' => [],
                        'activity_task' => [],
                    ],
                    'payload_support' => [
                        'inline' => 'codec-tagged payloads',
                        'external_storage' => 'external references fail closed when unsupported',
                    ],
                ],
                'external_task_result_contract' => [
                    'schema' => 'durable-workflow.v2.external-task-result.contract',
                    'version' => 1,
                    'unknown_field_policy' => 'ignore_additive_reject_unknown_required',
                    'stderr_policy' => 'logs_only_no_machine_meaning',
                    'envelopes' => [
                        'success' => [],
                        'failure' => [],
                        'malformed_output' => [],
                    ],
                    'fixtures' => [
                        'success' => [],
                        'handler_crash' => [],
                    ],
                    'payload_support' => [
                        'result_payload' => 'codec-tagged results',
                        'failure_details' => 'codec-tagged failure details',
                    ],
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertContains('cluster:info', $command->getAliases());
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('Poll Status: yes', $display);
        self::assertStringContainsString('Query Tasks: yes', $display);
        self::assertStringContainsString('Activity Retry Policy: yes', $display);
        self::assertStringContainsString('Activity Timeouts: yes', $display);
        self::assertStringContainsString('Child Workflow Retry Policy: yes', $display);
        self::assertStringContainsString('Parent Close Policy: yes', $display);
        self::assertStringContainsString('Non-retryable Failures: yes', $display);
        self::assertStringContainsString('History Page Size (default): 200', $display);
        self::assertStringContainsString('Response Compression: gzip, deflate', $display);
        self::assertStringContainsString('History Compression: identity, gzip', $display);
        self::assertStringContainsString('History Compression Threshold: 50 events', $display);
        self::assertStringContainsString(
            'Local Activities: durable-workflow.v2.local-activity.contract v1 (supported=yes)',
            $display,
        );
        self::assertStringContainsString(
            'Local Activity Execution: mode=local, same_process=yes, ordinary_activity_task_created=no',
            $display,
        );
        self::assertStringContainsString(
            'Local Activity Routing: admission=activity_class_must_resolve_in_the_workflow_worker_process, queue_bypassed=yes, rejected_options=connection, queue, worker_session',
            $display,
        );
        self::assertStringContainsString('Worker Session Verbs: create, heartbeat, close', $display);
        self::assertStringContainsString(
            'Worker Sessions: feature=worker_sessions, contract_version=1.0, supported=yes, minimum_protocol_version=1.0, command_field=worker_session, activity_options_field=worker_session, ownership=single_worker_lease_owner',
            $display,
        );
        self::assertStringContainsString(
            'Worker Session Lifecycle: creation=lazy_create_on_first_admitted_activity_or_explicit_worker_create, renewal=activity_heartbeat_or_explicit_session_heartbeat',
            $display,
        );
        self::assertStringContainsString(
            'Worker Session Limits: max_concurrent_worker_sessions=worker_registration, max_concurrent_activities=session',
            $display,
        );
        self::assertStringContainsString(
            'External Execution Surface: durable-workflow.v2.external-execution-surface.contract v1 (name=activity_grade_external_execution)',
            $display,
        );
        self::assertStringContainsString(
            'External Executor Config: durable-workflow.v2.external-executor-config.contract v1 (config=durable-workflow.external-executor.config v1)',
            $display,
        );
        self::assertStringContainsString(
            'External Task Input: durable-workflow.v2.external-task-input.contract v1',
            $display,
        );
        self::assertStringContainsString(
            'External Task Result: durable-workflow.v2.external-task-result.contract v1',
            $display,
        );
        self::assertStringContainsString(
            'External Execution Surface Contract: durable-workflow.v2.external-execution-surface.contract v1',
            $display,
        );
        self::assertStringContainsString(
            'External Execution Surface Boundary: activity_grade_external_execution',
            $display,
        );
        self::assertStringContainsString(
            'External Execution Surface Entries: input_envelope, result_envelope, invocable_http_carrier',
            $display,
        );
        self::assertStringContainsString(
            'External Executor Config Contract: durable-workflow.v2.external-executor-config.contract v1',
            $display,
        );
        self::assertStringContainsString(
            'External Executor Config Schema: durable-workflow.external-executor.config v1',
            $display,
        );
        self::assertStringContainsString('External Executor Config Runtime: configured=no, status=not_configured', $display);
        self::assertStringContainsString(
            'External Task Input Contract Scope: activity_grade_external_execution=activity_task, worker_protocol_runtime=workflow_task',
            $display,
        );
        self::assertStringContainsString(
            'External Task Input Contract Envelopes: workflow_task, activity_task',
            $display,
        );
        self::assertStringContainsString(
            'External Task Result Contract Envelopes: success, failure, malformed_output',
            $display,
        );
        self::assertStringContainsString(
            'External Task Result Contract Stderr Policy: logs_only_no_machine_meaning',
            $display,
        );
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

    public function test_it_renders_coordination_health_summary(): void
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
            'coordination_health' => [
                'schema' => 'durable-workflow.v2.coordination-health.contract',
                'version' => 1,
                'namespace_scope' => 'all_namespaces',
                'status' => 'warning',
                'http_status' => 200,
                'generated_at' => '2026-04-29T18:42:00Z',
                'categories' => [
                    'compatibility' => 1,
                    'notifications' => 0,
                ],
                'warning_checks' => [
                    'worker_compatibility',
                ],
                'error_checks' => [],
                'checks' => [
                    [
                        'name' => 'worker_compatibility',
                        'status' => 'warning',
                        'category' => 'compatibility',
                        'message' => 'No active worker supports the required compatibility family.',
                    ],
                    [
                        'name' => 'queue_wake',
                        'status' => 'ok',
                        'category' => 'notifications',
                        'message' => 'Ready-task wake path is healthy.',
                    ],
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('Coordination Health:', $display);
        self::assertStringContainsString(
            'Manifest: durable-workflow.v2.coordination-health.contract v1',
            $display,
        );
        self::assertStringContainsString('Namespace Scope: all_namespaces', $display);
        self::assertStringContainsString('Status: warning (http 200)', $display);
        self::assertStringContainsString('Generated At: 2026-04-29T18:42:00Z', $display);
        self::assertStringContainsString('Categories: compatibility=1, notifications=0', $display);
        self::assertStringContainsString('Warning Checks: worker_compatibility', $display);
        self::assertStringContainsString('Error Checks: none', $display);
        self::assertStringContainsString('Checks:', $display);
        self::assertStringContainsString(
            'worker_compatibility: status=warning, category=compatibility, message=No active worker supports the required compatibility family.',
            $display,
        );
        self::assertStringContainsString(
            'queue_wake: status=ok, category=notifications, message=Ready-task wake path is healthy.',
            $display,
        );
    }

    public function test_server_info_schema_pins_topology_contract_keys(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/server-info.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $topology = $schema['properties']['topology']['properties'];
        $matchingRole = $topology['matching_role']['properties'];

        self::assertSame(['string', 'null'], $topology['schema']['type']);
        self::assertSame(['string', 'integer', 'null'], $topology['version']['type']);
        self::assertSame(['array', 'null'], $topology['supported_shapes']['type']);
        self::assertSame('string', $topology['supported_shapes']['items']['type']);
        self::assertSame(['string', 'null'], $topology['current_shape']['type']);
        self::assertSame(['array', 'null'], $topology['current_roles']['type']);
        self::assertSame('string', $topology['current_roles']['items']['type']);
        self::assertSame(['string', 'null'], $topology['execution_mode']['type']);
        self::assertSame(['boolean', 'null'], $matchingRole['queue_wake_enabled']['type']);
        self::assertSame(['string', 'null'], $matchingRole['shape']['type']);
        self::assertSame(['string', 'null'], $matchingRole['wake_owner']['type']);
        self::assertSame(['string', 'null'], $matchingRole['task_dispatch_mode']['type']);
        self::assertSame(['array', 'null'], $matchingRole['partition_primitives']['type']);
        self::assertSame('string', $matchingRole['partition_primitives']['items']['type']);
        self::assertSame(['string', 'null'], $matchingRole['backpressure_model']['type']);
    }

    public function test_server_info_schema_pins_coordination_health_contract_keys(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/server-info.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $coordinationHealth = $schema['properties']['coordination_health']['properties'];
        $check = $coordinationHealth['checks']['items']['properties'];

        self::assertSame(['string', 'null'], $coordinationHealth['schema']['type']);
        self::assertSame(['string', 'integer', 'null'], $coordinationHealth['version']['type']);
        self::assertSame(['string', 'null'], $coordinationHealth['namespace_scope']['type']);
        self::assertSame(['string', 'null'], $coordinationHealth['status']['type']);
        self::assertSame(['integer', 'null'], $coordinationHealth['http_status']['type']);
        self::assertSame(['string', 'null'], $coordinationHealth['generated_at']['type']);
        self::assertSame(['array', 'null'], $coordinationHealth['warning_checks']['type']);
        self::assertSame('string', $coordinationHealth['warning_checks']['items']['type']);
        self::assertSame(['array', 'null'], $coordinationHealth['error_checks']['type']);
        self::assertSame('string', $coordinationHealth['error_checks']['items']['type']);
        self::assertSame(['array', 'null'], $coordinationHealth['checks']['type']);
        self::assertSame(['string', 'null'], $check['name']['type']);
        self::assertSame(['string', 'null'], $check['status']['type']);
        self::assertSame(['string', 'null'], $check['category']['type']);
        self::assertSame(['string', 'null'], $check['message']['type']);
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
