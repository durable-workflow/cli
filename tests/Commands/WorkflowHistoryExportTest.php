<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowCommand\HistoryExportCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class WorkflowHistoryExportTest extends TestCase
{
    public function test_export_command_outputs_json_to_stdout(): void
    {
        $command = new HistoryExportCommand();
        $command->setServerClient(new HistoryExportFakeClient([
            'schema' => 'durable-workflow.v2.history-export',
            'workflow' => [
                'instance_id' => 'wf-123',
                'run_id' => 'run-1',
                'workflow_type' => 'orders.process',
            ],
            'events' => [
                ['sequence' => 1, 'event_type' => 'WorkflowStarted'],
                ['sequence' => 2, 'event_type' => 'WorkflowCompleted'],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
            'run-id' => 'run-1',
        ]));

        $decoded = json_decode($tester->getDisplay(), true);

        self::assertIsArray($decoded);
        self::assertSame('durable-workflow.v2.history-export', $decoded['schema']);
        self::assertSame('wf-123', $decoded['workflow']['instance_id']);
        self::assertSame('run-1', $decoded['workflow']['run_id']);
        self::assertCount(2, $decoded['events']);
    }

    public function test_export_command_writes_to_file(): void
    {
        $command = new HistoryExportCommand();
        $command->setServerClient(new HistoryExportFakeClient([
            'schema' => 'durable-workflow.v2.history-export',
            'workflow' => [
                'instance_id' => 'wf-456',
                'run_id' => 'run-2',
            ],
            'events' => [],
        ]));

        $outputFile = sys_get_temp_dir().'/dw-cli-test-export-'.getmypid().'.json';

        try {
            $tester = new CommandTester($command);

            self::assertSame(Command::SUCCESS, $tester->execute([
                'workflow-id' => 'wf-456',
                'run-id' => 'run-2',
                '--output' => $outputFile,
            ]));

            self::assertStringContainsString($outputFile, $tester->getDisplay());
            self::assertFileExists($outputFile);

            $decoded = json_decode(file_get_contents($outputFile), true);

            self::assertIsArray($decoded);
            self::assertSame('wf-456', $decoded['workflow']['instance_id']);
        } finally {
            @unlink($outputFile);
        }
    }

    public function test_export_command_sends_correct_api_path(): void
    {
        $client = new HistoryExportFakeClient([
            'schema' => 'durable-workflow.v2.history-export',
            'workflow' => ['instance_id' => 'wf-789', 'run_id' => 'run-3'],
            'events' => [],
        ]);

        $command = new HistoryExportCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-789',
            'run-id' => 'run-3',
        ]));

        self::assertSame('/workflows/wf-789/runs/run-3/history/export', $client->lastGetPath);
    }
}

class HistoryExportFakeClient extends ServerClient
{
    public string $lastGetPath = '';

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly array $payload,
    ) {}

    public function get(string $path, array $query = []): array
    {
        $this->lastGetPath = $path;

        return $this->payload;
    }
}
