<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowCommand\ListRunsCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\ShowRunCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class WorkflowRunCommandTest extends TestCase
{
    public function test_list_runs_renders_table_with_run_details(): void
    {
        $command = new ListRunsCommand();
        $command->setServerClient(new WorkflowRunFakeServerClient([
            'workflow_id' => 'wf-123',
            'run_count' => 2,
            'runs' => [
                [
                    'run_id' => 'run-001',
                    'run_number' => 1,
                    'workflow_type' => 'orders.process',
                    'status' => 'completed',
                    'task_queue' => 'orders',
                    'started_at' => '2026-04-12T00:00:00Z',
                    'closed_at' => '2026-04-12T00:05:00Z',
                ],
                [
                    'run_id' => 'run-002',
                    'run_number' => 2,
                    'workflow_type' => 'orders.process',
                    'status' => 'running',
                    'task_queue' => 'orders',
                    'started_at' => '2026-04-12T00:06:00Z',
                    'closed_at' => null,
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
        ]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('wf-123', $display);
        self::assertStringContainsString('Run Count: 2', $display);
        self::assertStringContainsString('run-001', $display);
        self::assertStringContainsString('run-002', $display);
        self::assertStringContainsString('completed', $display);
        self::assertStringContainsString('running', $display);
        self::assertStringContainsString('orders', $display);
    }

    public function test_list_runs_renders_empty_message_when_no_runs(): void
    {
        $command = new ListRunsCommand();
        $command->setServerClient(new WorkflowRunFakeServerClient([
            'workflow_id' => 'wf-empty',
            'run_count' => 0,
            'runs' => [],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-empty',
        ]));

        self::assertStringContainsString('No runs found.', $tester->getDisplay());
    }

    public function test_list_runs_json_output(): void
    {
        $payload = [
            'workflow_id' => 'wf-123',
            'run_count' => 1,
            'runs' => [
                [
                    'run_id' => 'run-001',
                    'run_number' => 1,
                    'workflow_type' => 'orders.process',
                    'status' => 'completed',
                    'task_queue' => 'orders',
                    'started_at' => '2026-04-12T00:00:00Z',
                    'closed_at' => '2026-04-12T00:05:00Z',
                ],
            ],
        ];

        $command = new ListRunsCommand();
        $command->setServerClient(new WorkflowRunFakeServerClient($payload));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
            '--json' => true,
        ]));

        $decoded = json_decode($tester->getDisplay(), true);

        self::assertSame('wf-123', $decoded['workflow_id']);
        self::assertSame(1, $decoded['run_count']);
        self::assertCount(1, $decoded['runs']);
    }

    public function test_show_run_renders_detailed_run_information(): void
    {
        $command = new ShowRunCommand();
        $command->setServerClient(new WorkflowRunFakeServerClient([
            'workflow_id' => 'wf-123',
            'run_id' => 'run-001',
            'workflow_type' => 'orders.process',
            'namespace' => 'default',
            'business_key' => 'order-456',
            'status' => 'completed',
            'status_bucket' => 'completed',
            'run_number' => 1,
            'run_count' => 2,
            'is_current_run' => false,
            'task_queue' => 'orders',
            'compatibility' => 'build-a',
            'payload_codec' => 'avro',
            'execution_timeout_seconds' => 86400,
            'run_timeout_seconds' => 3600,
            'execution_deadline_at' => '2026-04-13T00:00:00Z',
            'run_deadline_at' => '2026-04-12T01:00:00Z',
            'started_at' => '2026-04-12T00:00:00Z',
            'closed_at' => '2026-04-12T00:05:00Z',
            'last_progress_at' => '2026-04-12T00:04:00Z',
            'closed_reason' => null,
            'wait_kind' => null,
            'wait_reason' => null,
            'input' => ['order_id' => 456],
            'output' => ['status' => 'shipped'],
            'memo' => ['source' => 'api'],
            'search_attributes' => ['tenant' => 'acme'],
            'actions' => [
                'can_signal' => false,
                'can_query' => true,
                'can_cancel' => false,
                'can_terminate' => false,
                'can_repair' => false,
                'can_archive' => true,
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
            'run-id' => 'run-001',
        ]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('Workflow Run', $display);
        self::assertStringContainsString('Workflow ID: wf-123', $display);
        self::assertStringContainsString('Run ID: run-001', $display);
        self::assertStringContainsString('Type: orders.process', $display);
        self::assertStringContainsString('Business Key: order-456', $display);
        self::assertStringContainsString('Status: completed', $display);
        self::assertStringContainsString('Status Bucket: completed', $display);
        self::assertStringContainsString('Run Number: 1', $display);
        self::assertStringContainsString('Current Run: no', $display);
        self::assertStringContainsString('Payload Codec: avro', $display);
        self::assertStringContainsString('Execution Timeout: 86400s', $display);
        self::assertStringContainsString('Run Timeout: 3600s', $display);
        self::assertStringContainsString('Input: {"order_id":456}', $display);
        self::assertStringContainsString('Output: {"status":"shipped"}', $display);
        self::assertStringContainsString('Memo: {"source":"api"}', $display);
        self::assertStringContainsString('Search Attributes: {"tenant":"acme"}', $display);
        self::assertStringContainsString('Actions: can_query, can_archive', $display);
    }

    public function test_show_run_json_output(): void
    {
        $payload = [
            'workflow_id' => 'wf-123',
            'run_id' => 'run-001',
            'workflow_type' => 'orders.process',
            'status' => 'completed',
        ];

        $command = new ShowRunCommand();
        $command->setServerClient(new WorkflowRunFakeServerClient($payload));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
            'run-id' => 'run-001',
            '--json' => true,
        ]));

        $decoded = json_decode($tester->getDisplay(), true);

        self::assertSame('wf-123', $decoded['workflow_id']);
        self::assertSame('run-001', $decoded['run_id']);
        self::assertSame('completed', $decoded['status']);
    }

    public function test_show_run_renders_current_run_as_yes(): void
    {
        $command = new ShowRunCommand();
        $command->setServerClient(new WorkflowRunFakeServerClient([
            'workflow_id' => 'wf-123',
            'run_id' => 'run-002',
            'status' => 'running',
            'is_current_run' => true,
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
            'run-id' => 'run-002',
        ]));

        self::assertStringContainsString('Current Run: yes', $tester->getDisplay());
    }

    public function test_list_runs_uses_correct_api_path(): void
    {
        $client = new WorkflowRunFakeServerClient([
            'workflow_id' => 'wf-abc',
            'run_count' => 0,
            'runs' => [],
        ]);

        $command = new ListRunsCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);
        $tester->execute(['workflow-id' => 'wf-abc']);

        self::assertSame('/workflows/wf-abc/runs', $client->lastGetPath);
    }

    public function test_show_run_uses_correct_api_path(): void
    {
        $client = new WorkflowRunFakeServerClient([
            'workflow_id' => 'wf-abc',
            'run_id' => 'run-xyz',
            'status' => 'completed',
        ]);

        $command = new ShowRunCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);
        $tester->execute([
            'workflow-id' => 'wf-abc',
            'run-id' => 'run-xyz',
        ]);

        self::assertSame('/workflows/wf-abc/runs/run-xyz', $client->lastGetPath);
    }
}

class WorkflowRunFakeServerClient extends ServerClient
{
    public ?string $lastGetPath = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly array $payload,
    ) {
    }

    public function get(string $path, array $query = []): array
    {
        $this->lastGetPath = $path;

        return $this->payload;
    }

    public function post(string $path, array $body = []): array
    {
        return $this->payload;
    }
}
