<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowCommand\DescribeCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\ListCommand;
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
     */
    public function __construct(
        private readonly array $payload,
        private readonly ?ControlPlaneRequestContract $requestContract = null,
    ) {
    }

    public function get(string $path, array $query = []): array
    {
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
