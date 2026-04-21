<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\DebugCommand;
use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class DebugCommandTest extends TestCase
{
    public function test_debug_workflow_outputs_server_diagnostic_json(): void
    {
        $client = new DebugFakeClient($this->payload());
        $command = new DebugCommand();
        $command->setServerClient($client);
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'target' => 'workflow',
            'workflow-id' => 'wf-debug',
            '--output' => 'json',
        ]));

        $decoded = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('/workflows/wf-debug/debug', $client->lastPath);
        self::assertSame('wf-debug', $decoded['workflow_id']);
        self::assertSame('pending_work', $decoded['diagnostic_status']);
        self::assertSame('task-1', $decoded['pending_workflow_tasks'][0]['task_id']);
        self::assertSame('RuntimeException', $decoded['recent_failures'][0]['exception_class']);
    }

    public function test_debug_workflow_can_target_a_specific_run(): void
    {
        $client = new DebugFakeClient($this->payload());
        $command = new DebugCommand();
        $command->setServerClient($client);
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'target' => 'workflow',
            'workflow-id' => 'wf-debug',
            '--run-id' => 'run-1',
            '--output' => 'json',
        ]));

        self::assertSame('/workflows/wf-debug/runs/run-1/debug', $client->lastPath);
    }

    public function test_debug_workflow_human_output_includes_connection_and_sections(): void
    {
        $command = new DebugCommand();
        $command->setServerClient(new DebugFakeClient($this->payload()));
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'target' => 'workflow',
            'workflow-id' => 'wf-debug',
            '--server' => 'https://server.example',
            '--namespace' => 'orders',
        ]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('Connection: https://server.example namespace=orders workflow=wf-debug run=run-1', $display);
        self::assertStringContainsString('Pending Workflow Tasks:', $display);
        self::assertStringContainsString('task-1', $display);
        self::assertStringContainsString('Pending Activities:', $display);
        self::assertStringContainsString('activity-1', $display);
        self::assertStringContainsString('Recent Failures:', $display);
        self::assertStringContainsString('RuntimeException', $display);
    }

    public function test_debug_rejects_unknown_target(): void
    {
        $command = new DebugCommand();
        $command->setServerClient(new DebugFakeClient($this->payload()));
        $tester = new CommandTester($command);

        self::assertSame(ExitCode::INVALID, $tester->execute([
            'target' => 'task-queue',
            'workflow-id' => 'wf-debug',
            '--output' => 'json',
        ]));

        $decoded = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(ExitCode::INVALID, $decoded['exit_code']);
        self::assertStringContainsString('supports only: workflow', $decoded['error']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'generated_at' => '2026-04-21T00:00:00.000000Z',
            'workflow_id' => 'wf-debug',
            'run_id' => 'run-1',
            'namespace' => 'default',
            'diagnostic_status' => 'pending_work',
            'execution' => [
                'status' => 'running',
                'workflow_type' => 'tests.await-approval-workflow',
                'task_queue' => 'debug-queue',
                'last_event' => [
                    'sequence' => 4,
                    'event_type' => 'WorkflowTaskStarted',
                    'timestamp' => '2026-04-21T00:00:00.000000Z',
                ],
                'next_scheduled_event' => [
                    'task_type' => 'workflow',
                    'task_status' => 'leased',
                    'lease_expires_at' => '2026-04-21T00:01:00.000000Z',
                ],
            ],
            'pending_workflow_tasks' => [[
                'task_id' => 'task-1',
                'status' => 'leased',
                'lease_owner' => 'worker-1',
                'lease_expires_at' => '2026-04-21T00:01:00.000000Z',
            ]],
            'pending_activities' => [[
                'activity_execution_id' => 'activity-1',
                'activity_type' => 'send-email',
                'status' => 'running',
                'current_attempt' => [
                    'lease_owner' => 'worker-2',
                    'lease_expires_at' => '2026-04-21T00:01:00.000000Z',
                ],
            ]],
            'task_queue' => [
                'name' => 'debug-queue',
                'stats' => [
                    'approximate_backlog_count' => 1,
                    'approximate_backlog_age' => '3s',
                    'workflow_tasks' => [
                        'ready_count' => 0,
                        'leased_count' => 1,
                        'expired_lease_count' => 0,
                    ],
                    'activity_tasks' => [
                        'ready_count' => 0,
                        'leased_count' => 1,
                        'expired_lease_count' => 0,
                    ],
                    'pollers' => [
                        'active_count' => 1,
                        'stale_count' => 0,
                    ],
                ],
            ],
            'recent_failures' => [[
                'exception_class' => 'RuntimeException',
                'message' => 'Replay failed.',
                'created_at' => '2026-04-21T00:00:00.000000Z',
            ]],
            'findings' => [],
        ];
    }
}

class DebugFakeClient extends ServerClient
{
    public ?string $lastPath = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly array $payload) {}

    /**
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $this->lastPath = $path;

        return $this->payload;
    }
}
