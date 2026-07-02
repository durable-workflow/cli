<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowCommand\DescribeCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\ListCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\SignalCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\StartCommand;
use DurableWorkflow\Cli\Support\ControlPlaneRequestContract;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class WorkflowReadCommandTest extends TestCase
{
    public function test_start_command_sends_business_key_and_renders_it_when_present(): void
    {
        $client = new WorkflowReadFakeServerClient([
            'workflow_id' => 'wf-123',
            'run_id' => 'run-123',
            'business_key' => 'order-123',
            'payload_codec' => 'avro',
            'outcome' => 'started_new',
        ]);

        $command = new StartCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--type' => 'orders.process',
            '--business-key' => 'order-123',
        ]));

        self::assertSame('order-123', $client->lastPostBody['business_key'] ?? null);
        self::assertStringContainsString('Business Key: order-123', $tester->getDisplay());
        self::assertStringContainsString('Payload Codec: avro', $tester->getDisplay());
    }

    public function test_start_command_sends_input_as_arguments_array(): void
    {
        $client = new WorkflowReadFakeServerClient([
            'workflow_id' => 'wf-123',
            'run_id' => 'run-123',
            'payload_codec' => 'avro',
            'outcome' => 'started_new',
        ]);

        $command = new StartCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--type' => 'orders.process',
            '--input' => '["Ada"]',
        ]));

        self::assertSame(['Ada'], $client->lastPostBody['input'] ?? null);
    }

    public function test_start_command_adds_selected_namespace_to_json_output(): void
    {
        $client = new WorkflowReadFakeServerClient([
            'workflow_id' => 'wf-tenant-a',
            'run_id' => 'run-tenant-a',
            'outcome' => 'started_new',
        ]);

        $command = new StartCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--namespace' => 'tenant-a',
            '--type' => 'orders.process',
            '--json' => true,
        ]));

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('tenant-a', $decoded['namespace'] ?? null);
        self::assertSame('wf-tenant-a', $decoded['workflow_id'] ?? null);
    }

    public function test_start_command_reads_input_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dw-cli-input-');
        self::assertIsString($path);
        file_put_contents($path, '["Ada","Lovelace"]');

        try {
            $client = new WorkflowReadFakeServerClient([
                'workflow_id' => 'wf-123',
                'run_id' => 'run-123',
                'outcome' => 'started_new',
            ]);

            $command = new StartCommand();
            $command->setServerClient($client);

            $tester = new CommandTester($command);

            self::assertSame(Command::SUCCESS, $tester->execute([
                '--type' => 'orders.process',
                '--input-file' => $path,
            ]));

            self::assertSame(['Ada', 'Lovelace'], $client->lastPostBody['input'] ?? null);
        } finally {
            @unlink($path);
        }
    }

    public function test_start_command_decodes_base64_input_as_one_argument(): void
    {
        $client = new WorkflowReadFakeServerClient([
            'workflow_id' => 'wf-123',
            'run_id' => 'run-123',
            'outcome' => 'started_new',
        ]);

        $command = new StartCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--type' => 'orders.process',
            '--input' => base64_encode('opaque bytes'),
            '--input-encoding' => 'base64',
        ]));

        self::assertSame(['opaque bytes'], $client->lastPostBody['input'] ?? null);
    }

    public function test_start_command_rejects_ambiguous_input_sources(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dw-cli-input-');
        self::assertIsString($path);
        file_put_contents($path, '["Ada"]');

        try {
            $client = new WorkflowReadFakeServerClient([
                'workflow_id' => 'wf-123',
                'run_id' => 'run-123',
                'outcome' => 'started_new',
            ]);

            $command = new StartCommand();
            $command->setServerClient($client);

            $tester = new CommandTester($command);

            self::assertSame(Command::INVALID, $tester->execute([
                '--type' => 'orders.process',
                '--input' => '["inline"]',
                '--input-file' => $path,
            ]));

            self::assertSame(0, $client->postCalls);
            self::assertStringContainsString('Use either --input or --input-file, not both.', $tester->getDisplay());
        } finally {
            @unlink($path);
        }
    }

    public function test_start_command_sends_the_canonical_duplicate_policy_without_server_specific_translation(): void
    {
        $useExistingClient = new WorkflowReadFakeServerClient([
            'workflow_id' => 'wf-123',
            'run_id' => 'run-123',
            'outcome' => 'returned_existing_active',
        ], self::requestContract());

        $useExistingCommand = new StartCommand();
        $useExistingCommand->setServerClient($useExistingClient);

        $useExistingTester = new CommandTester($useExistingCommand);

        self::assertSame(Command::SUCCESS, $useExistingTester->execute([
            '--type' => 'orders.process',
            '--duplicate-policy' => 'use-existing',
        ]));

        self::assertSame('use-existing', $useExistingClient->lastPostBody['duplicate_policy'] ?? null);
        self::assertStringContainsString('Outcome: returned_existing_active', $useExistingTester->getDisplay());

        $failClient = new WorkflowReadFakeServerClient([
            'workflow_id' => 'wf-124',
            'run_id' => 'run-124',
            'outcome' => 'started_new',
        ], self::requestContract());

        $failCommand = new StartCommand();
        $failCommand->setServerClient($failClient);

        $failTester = new CommandTester($failCommand);

        self::assertSame(Command::SUCCESS, $failTester->execute([
            '--type' => 'orders.process',
            '--duplicate-policy' => 'fail',
        ]));

        self::assertSame('fail', $failClient->lastPostBody['duplicate_policy'] ?? null);
    }

    public function test_start_command_rejects_legacy_duplicate_policy_aliases_from_the_server_contract(): void
    {
        $client = new WorkflowReadFakeServerClient([
            'workflow_id' => 'wf-123',
            'run_id' => 'run-123',
            'outcome' => 'started_new',
        ], self::requestContract());

        $command = new StartCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::INVALID, $tester->execute([
            '--type' => 'orders.process',
            '--duplicate-policy' => 'use_existing',
        ]));

        self::assertSame([], $client->lastPostBody);
        self::assertSame(0, $client->postCalls);
        self::assertStringContainsString(
            'Server contract rejects --duplicate-policy value [use_existing]; use [use-existing].',
            $tester->getDisplay(),
        );
    }

    public function test_start_command_rejects_unknown_duplicate_policy_values_from_the_server_contract(): void
    {
        $client = new WorkflowReadFakeServerClient([
            'workflow_id' => 'wf-123',
            'run_id' => 'run-123',
            'outcome' => 'started_new',
        ], self::requestContract());

        $command = new StartCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::INVALID, $tester->execute([
            '--type' => 'orders.process',
            '--duplicate-policy' => 'terminate-existing',
        ]));

        self::assertSame([], $client->lastPostBody);
        self::assertSame(0, $client->postCalls);
        self::assertStringContainsString(
            'Server contract expects --duplicate-policy to be one of [fail, use-existing]; got [terminate-existing].',
            $tester->getDisplay(),
        );
    }

    public function test_start_command_requires_the_versioned_request_contract_when_validating_duplicate_policy(): void
    {
        $client = new WorkflowReadFakeServerClient([
            'workflow_id' => 'wf-123',
            'run_id' => 'run-123',
            'outcome' => 'started_new',
        ]);

        $command = new StartCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::INVALID, $tester->execute([
            '--type' => 'orders.process',
            '--duplicate-policy' => 'use_existing',
        ]));

        self::assertSame([], $client->lastPostBody);
        self::assertSame(0, $client->postCalls);
        self::assertStringContainsString(
            'Server compatibility error: missing control_plane.request_contract; expected durable-workflow.v2.control-plane-request.contract v1.',
            $tester->getDisplay(),
        );
    }

    public function test_start_command_definition_exposes_all_supported_v2_start_options(): void
    {
        $command = new StartCommand();

        self::assertTrue($command->getDefinition()->hasOption('execution-timeout'));
        self::assertTrue($command->getDefinition()->hasOption('run-timeout'));
        self::assertTrue($command->getDefinition()->hasOption('duplicate-policy'));
    }

    public function test_start_command_sends_execution_and_run_timeout_seconds_when_provided(): void
    {
        $client = new WorkflowReadFakeServerClient([
            'workflow_id' => 'wf-timeout-1',
            'run_id' => 'run-timeout-1',
            'outcome' => 'started_new',
        ]);

        $command = new StartCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--type' => 'orders.process',
            '--execution-timeout' => '300',
            '--run-timeout' => '120',
        ]));

        self::assertSame(300, $client->lastPostBody['execution_timeout_seconds'] ?? null);
        self::assertSame(120, $client->lastPostBody['run_timeout_seconds'] ?? null);
    }

    public function test_start_command_omits_timeout_fields_when_not_provided(): void
    {
        $client = new WorkflowReadFakeServerClient([
            'workflow_id' => 'wf-no-timeout',
            'run_id' => 'run-no-timeout',
            'outcome' => 'started_new',
        ]);

        $command = new StartCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--type' => 'orders.process',
        ]));

        self::assertArrayNotHasKey('execution_timeout_seconds', $client->lastPostBody);
        self::assertArrayNotHasKey('run_timeout_seconds', $client->lastPostBody);
    }

    public function test_start_command_with_wait_and_json_emits_terminal_describe_and_success_exit(): void
    {
        // TD-S020: --wait --json previously returned the start response and exited
        // before honoring --wait. The automation contract is: poll to terminal,
        // then emit the terminal describe as JSON with the matching exit code.
        $client = new WorkflowReadFakeServerClient([
            'workflow_id' => 'wf-wait-json-1',
            'run_id' => 'run-wait-json-1',
            'workflow_type' => 'orders.process',
            'status' => 'completed',
            'status_bucket' => 'completed',
            'is_terminal' => true,
            'closed_at' => '2026-04-16T00:00:00Z',
            'closed_reason' => 'completed',
            'output' => ['order_id' => 42],
            'outcome' => 'started_new',
        ]);

        $command = new StartCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--type' => 'orders.process',
            '--wait' => true,
            '--json' => true,
        ]));

        $display = trim($tester->getDisplay());

        // Output must be a single JSON document — no human text mixed in —
        // so that automation pipelines can parse stdout directly.
        self::assertStringStartsWith('{', $display);
        $parsed = json_decode($display, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('wf-wait-json-1', $parsed['workflow_id']);
        self::assertSame('completed', $parsed['status']);
        self::assertSame('completed', $parsed['status_bucket']);
        self::assertTrue($parsed['is_terminal']);
        self::assertSame(['order_id' => 42], $parsed['output']);

        self::assertStringNotContainsString('Workflow started', $display);
        self::assertStringNotContainsString('Waiting for workflow', $display);
    }

    public function test_start_command_with_wait_and_json_returns_failure_for_non_completed_terminal(): void
    {
        $client = new WorkflowReadFakeServerClient([
            'workflow_id' => 'wf-wait-json-2',
            'run_id' => 'run-wait-json-2',
            'workflow_type' => 'orders.process',
            'status' => 'failed',
            'status_bucket' => 'failed',
            'is_terminal' => true,
            'closed_at' => '2026-04-16T00:00:00Z',
            'closed_reason' => 'activity_failed',
            'outcome' => 'started_new',
        ]);

        $command = new StartCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        // Exit code must be FAILURE so automation callers can branch on
        // the process status without parsing JSON.
        self::assertSame(Command::FAILURE, $tester->execute([
            '--type' => 'orders.process',
            '--wait' => true,
            '--json' => true,
        ]));

        $parsed = json_decode(trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('failed', $parsed['status']);
        self::assertSame('failed', $parsed['status_bucket']);
        self::assertSame('activity_failed', $parsed['closed_reason']);
    }

    public function test_start_command_with_wait_and_json_writes_diagnostics_to_stderr_while_preserving_stdout_json(): void
    {
        $client = new WorkflowReadFakeServerClient([
            'workflow_id' => 'wf-wait-json-diagnostics',
            'run_id' => 'run-wait-json-diagnostics',
            'workflow_type' => 'orders.process',
            'status' => 'pending',
            'status_bucket' => 'running',
            'is_terminal' => false,
            'outcome' => 'started_new',
        ], getPayloadsByPath: [
            '/workflows/wf-wait-json-diagnostics' => [
                [
                    'workflow_id' => 'wf-wait-json-diagnostics',
                    'run_id' => 'run-wait-json-diagnostics',
                    'workflow_type' => 'orders.process',
                    'status' => 'running',
                    'status_bucket' => 'running',
                    'is_terminal' => false,
                    'wait_kind' => 'activity',
                    'wait_reason' => 'waiting for activity task completion',
                ],
                [
                    'workflow_id' => 'wf-wait-json-diagnostics',
                    'run_id' => 'run-wait-json-diagnostics',
                    'workflow_type' => 'orders.process',
                    'status' => 'completed',
                    'status_bucket' => 'completed',
                    'is_terminal' => true,
                    'closed_at' => '2026-04-16T00:00:00Z',
                    'closed_reason' => 'completed',
                    'output' => ['ok' => true],
                ],
            ],
            '/workflows/wf-wait-json-diagnostics/debug' => [
                [
                    'workflow_id' => 'wf-wait-json-diagnostics',
                    'run_id' => 'run-wait-json-diagnostics',
                    'diagnostic_status' => 'pending_work',
                    'execution' => [
                        'status' => 'running',
                        'wait_kind' => 'activity',
                        'wait_reason' => 'waiting for activity task completion',
                    ],
                    'findings' => [
                        [
                            'severity' => 'warning',
                            'code' => 'pending_activity_type_unsupported',
                            'message' => 'Activity [polyglot.python-to-php.echo] is pending on task queue [polyglot-python-to-php], but no active poller on that queue advertises that activity type.',
                            'task_queue' => 'polyglot-python-to-php',
                            'activity_type' => 'polyglot.python-to-php.echo',
                            'activity_execution_id' => 'act-1',
                        ],
                    ],
                    'pending_activities' => [
                        [
                            'activity_execution_id' => 'act-1',
                            'activity_type' => 'polyglot.python-to-php.echo',
                            'status' => 'running',
                            'queue' => 'polyglot-python-to-php',
                            'current_attempt' => [
                                'activity_attempt_id' => 'attempt-1',
                                'attempt_number' => 1,
                                'status' => 'running',
                                'lease_owner' => 'php-worker',
                            ],
                        ],
                    ],
                    'activity_task_queues' => [
                        'polyglot-python-to-php' => [
                            'stats' => [
                                'pollers' => [
                                    'active_count' => 1,
                                    'stale_count' => 0,
                                ],
                            ],
                            'pollers' => [
                                [
                                    'worker_id' => 'php-worker',
                                    'runtime' => 'php',
                                    'status' => 'active',
                                    'is_stale' => false,
                                    'supported_activity_types' => ['polyglot.other.echo'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $command = new class extends StartCommand {
            protected function sleepBetweenWaitPolls(): void {}
        };
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--type' => 'orders.process',
            '--wait' => true,
            '--json' => true,
        ], [
            'capture_stderr_separately' => true,
        ]));

        $stdout = trim($tester->getDisplay());
        $parsedStdout = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('completed', $parsedStdout['status']);
        self::assertSame(['ok' => true], $parsedStdout['output']);
        self::assertStringNotContainsString('workflow_wait_diagnostic', $stdout);

        $stderr = trim($tester->getErrorOutput());
        $parsedStderr = json_decode($stderr, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('workflow_wait_diagnostic', $parsedStderr['event']);
        self::assertSame('wf-wait-json-diagnostics', $parsedStderr['workflow_id']);
        self::assertSame('pending_activity_type_unsupported', $parsedStderr['findings'][0]['code']);
        self::assertSame('polyglot-python-to-php', $parsedStderr['pending_activities'][0]['queue']);
        self::assertSame(
            ['polyglot.other.echo'],
            $parsedStderr['activity_task_queues']['polyglot-python-to-php']['pollers'][0]['supported_activity_types'],
        );
    }

    public function test_start_command_with_wait_without_json_renders_human_terminal_summary(): void
    {
        $client = new WorkflowReadFakeServerClient([
            'workflow_id' => 'wf-wait-3',
            'run_id' => 'run-wait-3',
            'workflow_type' => 'orders.process',
            'status' => 'completed',
            'status_bucket' => 'completed',
            'is_terminal' => true,
            'closed_at' => '2026-04-16T00:00:00Z',
            'closed_reason' => 'completed',
            'output' => ['ok' => true],
            'outcome' => 'started_new',
        ]);

        $command = new StartCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--type' => 'orders.process',
            '--wait' => true,
        ]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('Workflow started', $display);
        self::assertStringContainsString('Waiting for workflow to complete', $display);
        self::assertStringContainsString('Workflow reached terminal state', $display);
        self::assertStringContainsString('Status: completed', $display);
        self::assertStringContainsString('Closed Reason: completed', $display);
    }

    public function test_list_command_renders_business_keys_in_the_table(): void
    {
        $command = new ListCommand();
        $command->setServerClient(new WorkflowReadFakeServerClient([
            'workflows' => [
                [
                    'workflow_id' => 'wf-123',
                    'workflow_type' => 'orders.process',
                    'business_key' => 'order-123',
                    'status' => 'running',
                    'started_at' => '2026-04-12T00:00:00Z',
                    'closed_at' => null,
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('Business Key', $display);
        self::assertStringContainsString('order-123', $display);
    }

    public function test_list_command_renders_selected_namespace_in_table_output(): void
    {
        $command = new ListCommand();
        $command->setServerClient(new WorkflowReadFakeServerClient([
            'workflows' => [
                [
                    'workflow_id' => 'wf-tenant-a',
                    'workflow_type' => 'orders.process',
                    'status' => 'running',
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--namespace' => 'tenant-a',
        ]));

        self::assertStringContainsString('Namespace: tenant-a', $tester->getDisplay());
    }

    public function test_signal_command_renders_selected_namespace_in_table_output(): void
    {
        $command = new SignalCommand();
        $command->setServerClient(new WorkflowReadFakeServerClient([
            'workflow_id' => 'wf-tenant-a',
            'signal_name' => 'approve',
            'outcome' => 'signal_received',
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--namespace' => 'tenant-a',
            'workflow-id' => 'wf-tenant-a',
            'signal-name' => 'approve',
        ]));

        self::assertStringContainsString('Namespace: tenant-a', $tester->getDisplay());
    }

    public function test_list_command_adds_selected_namespace_to_json_output(): void
    {
        $command = new ListCommand();
        $command->setServerClient(new WorkflowReadFakeServerClient([
            'workflows' => [
                [
                    'workflow_id' => 'wf-tenant-a',
                    'workflow_type' => 'orders.process',
                    'status' => 'running',
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--namespace' => 'tenant-a',
            '--output' => 'json',
        ]));

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('tenant-a', $decoded['namespace'] ?? null);
        self::assertSame('tenant-a', $decoded['workflows'][0]['namespace'] ?? null);
    }

    public function test_list_command_adds_selected_namespace_to_jsonl_items(): void
    {
        $command = new ListCommand();
        $command->setServerClient(new WorkflowReadFakeServerClient([
            'workflows' => [
                [
                    'workflow_id' => 'wf-tenant-a',
                    'workflow_type' => 'orders.process',
                    'status' => 'running',
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--namespace' => 'tenant-a',
            '--output' => 'jsonl',
        ]));

        $decoded = json_decode(trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('tenant-a', $decoded['namespace'] ?? null);
        self::assertSame('wf-tenant-a', $decoded['workflow_id'] ?? null);
    }

    public function test_describe_command_colors_status_only_when_output_is_decorated(): void
    {
        $payload = [
            'workflow_id' => 'wf-123',
            'run_id' => 'run-123',
            'workflow_type' => 'orders.process',
            'namespace' => 'default',
            'status' => 'running',
            'status_bucket' => 'running',
            'task_queue' => 'orders',
        ];

        $plainCommand = new DescribeCommand();
        $plainCommand->setServerClient(new WorkflowReadFakeServerClient($payload));
        $plainTester = new CommandTester($plainCommand);

        self::assertSame(Command::SUCCESS, $plainTester->execute(['workflow-id' => 'wf-123']));
        self::assertStringContainsString('Status: running', $plainTester->getDisplay());
        self::assertStringNotContainsString("\033[", $plainTester->getDisplay());

        $decoratedCommand = new DescribeCommand();
        $decoratedCommand->setServerClient(new WorkflowReadFakeServerClient($payload));
        $decoratedTester = new CommandTester($decoratedCommand);

        self::assertSame(Command::SUCCESS, $decoratedTester->execute(['workflow-id' => 'wf-123'], ['decorated' => true]));
        $decoratedDisplay = $decoratedTester->getDisplay();
        self::assertStringContainsString('running', $decoratedDisplay);
        self::assertStringContainsString("\033[", $decoratedDisplay);
    }

    public function test_describe_command_renders_engine_metadata_and_available_actions(): void
    {
        $command = new DescribeCommand();
        $command->setServerClient(new WorkflowReadFakeServerClient([
            'workflow_id' => 'wf-123',
            'run_id' => 'run-123',
            'workflow_type' => 'orders.process',
            'namespace' => 'default',
            'business_key' => 'order-123',
            'status' => 'running',
            'status_bucket' => 'running',
            'run_number' => 1,
            'run_count' => 2,
            'is_current_run' => true,
            'task_queue' => 'orders',
            'compatibility' => 'build-a',
            'payload_codec' => 'avro',
            'execution_timeout_seconds' => 86400,
            'run_timeout_seconds' => 3600,
            'execution_deadline_at' => '2026-04-13T00:00:00Z',
            'run_deadline_at' => '2026-04-12T01:00:00Z',
            'started_at' => '2026-04-12T00:00:00Z',
            'closed_at' => null,
            'last_progress_at' => '2026-04-12T00:01:00Z',
            'closed_reason' => null,
            'wait_kind' => 'signal',
            'wait_reason' => 'awaiting approval',
            'memo' => ['source' => 'cli-test'],
            'search_attributes' => ['tenant' => 'acme'],
            'actions' => [
                'can_signal' => true,
                'can_query' => true,
                'can_update' => false,
                'can_cancel' => true,
                'can_terminate' => false,
                'can_repair' => true,
                'can_archive' => false,
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
        ]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('Business Key: order-123', $display);
        self::assertStringContainsString('Status Bucket: running', $display);
        self::assertStringContainsString('Run Count: 2', $display);
        self::assertStringContainsString('Payload Codec: avro', $display);
        self::assertStringContainsString('Execution Timeout: 86400s', $display);
        self::assertStringContainsString('Run Timeout: 3600s', $display);
        self::assertStringContainsString('Execution Deadline: 2026-04-13T00:00:00Z', $display);
        self::assertStringContainsString('Run Deadline: 2026-04-12T01:00:00Z', $display);
        self::assertStringContainsString('Wait Kind: signal', $display);
        self::assertStringContainsString('Search Attributes: {"tenant":"acme"}', $display);
        self::assertStringContainsString('Actions: can_signal, can_query, can_cancel, can_repair', $display);
    }

    public function test_describe_json_preserves_published_server_update_diagnostics(): void
    {
        $command = new DescribeCommand();
        $command->setServerClient(new WorkflowReadFakeServerClient([
            'workflow_id' => 'wf-updates',
            'run_id' => 'run-updates',
            'status' => 'running',
            'commands' => [
                [
                    'id' => 'cmd-accepted',
                    'sequence' => 7,
                    'type' => 'update',
                    'target_name' => 'approve',
                    'request_id' => 'accepted-request-1',
                    'status' => 'accepted',
                    'outcome' => 'update_accepted',
                    'update_id' => 'upd-accepted',
                    'update_status' => 'accepted',
                    'accepted_at' => '2026-07-02T12:00:00Z',
                ],
                [
                    'id' => 'cmd-refused',
                    'sequence' => 8,
                    'type' => 'update',
                    'target_name' => 'missing_update',
                    'request_id' => 'refused-request-1',
                    'status' => 'rejected',
                    'outcome' => 'rejected_unknown_update',
                    'reason' => 'unknown_update',
                    'rejection_reason' => 'unknown_update',
                    'update_id' => null,
                    'update_status' => null,
                    'rejected_at' => '2026-07-02T12:00:05Z',
                ],
            ],
            'updates' => [
                [
                    'id' => 'upd-accepted',
                    'command_id' => 'cmd-accepted',
                    'workflow_id' => 'wf-updates',
                    'run_id' => 'run-updates',
                    'update_name' => 'approve',
                    'status' => 'accepted',
                    'outcome' => 'update_accepted',
                    'command_sequence' => 7,
                    'workflow_sequence' => 11,
                    'request_id' => 'accepted-request-1',
                    'accepted_at' => '2026-07-02T12:00:00Z',
                ],
                [
                    'id' => 'upd-failed',
                    'command_id' => 'cmd-failed',
                    'workflow_id' => 'wf-updates',
                    'run_id' => 'run-updates',
                    'update_name' => 'approve',
                    'status' => 'failed',
                    'outcome' => 'update_failed',
                    'command_sequence' => 9,
                    'workflow_sequence' => 13,
                    'request_id' => 'failed-request-1',
                    'failure_id' => 'failure-1',
                    'failure_message' => 'Handler rejected approval.',
                    'closed_at' => '2026-07-02T12:02:00Z',
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-updates',
            '--run-id' => 'run-updates',
            '--output' => 'json',
        ]));

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('upd-accepted', $decoded['commands'][0]['update_id']);
        self::assertSame('accepted-request-1', $decoded['commands'][0]['request_id']);
        self::assertSame('unknown_update', $decoded['commands'][1]['reason']);
        self::assertNull($decoded['commands'][1]['update_id']);
        self::assertSame('accepted', $decoded['updates'][0]['status']);
        self::assertSame(11, $decoded['updates'][0]['workflow_sequence']);
        self::assertSame('failed', $decoded['updates'][1]['status']);
        self::assertSame('failure-1', $decoded['updates'][1]['failure_id']);
    }

    private static function requestContract(): ControlPlaneRequestContract
    {
        return new ControlPlaneRequestContract([
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
        ]);
    }
}

class WorkflowReadFakeServerClient extends ServerClient
{
    /**
     * @var array<string, mixed>
     */
    public array $lastPostBody = [];

    public int $postCalls = 0;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<array<string, mixed>>>  $getPayloadsByPath
     */
    public function __construct(
        private readonly array $payload,
        private readonly ?ControlPlaneRequestContract $requestContract = null,
        private array $getPayloadsByPath = [],
    ) {
    }

    public function get(string $path, array $query = []): array
    {
        if (isset($this->getPayloadsByPath[$path])) {
            if (count($this->getPayloadsByPath[$path]) > 1) {
                $payloads = $this->getPayloadsByPath[$path];
                $response = array_shift($payloads);
                $this->getPayloadsByPath[$path] = $payloads;

                return $response;
            }

            return $this->getPayloadsByPath[$path][0];
        }

        return $this->payload;
    }

    public function post(string $path, array $body = []): array
    {
        $this->postCalls++;
        $this->lastPostBody = $body;

        return $this->payload;
    }

    public function controlPlaneRequestContract(): ?ControlPlaneRequestContract
    {
        return $this->requestContract;
    }
}
