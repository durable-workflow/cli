<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\TaskQueueCommand\DescribeCommand;
use DurableWorkflow\Cli\Commands\TaskQueueCommand\ListCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TaskQueueDescribeCommandTest extends TestCase
{
    public function test_list_command_renders_json_output(): void
    {
        $command = new ListCommand();
        $command->setServerClient(new FakeTaskQueueServerClient([
            'task_queues' => [
                [
                    'name' => 'external-workflows',
                    'admission' => [
                        'workflow_tasks' => [
                            'status' => 'accepting',
                            'remaining_server_capacity' => 3,
                        ],
                    ],
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--json' => true,
        ]));

        $decoded = json_decode($tester->getDisplay(), true);

        self::assertIsArray($decoded);
        self::assertSame('external-workflows', $decoded['task_queues'][0]['name']);
        self::assertSame('accepting', $decoded['task_queues'][0]['admission']['workflow_tasks']['status']);
    }

    public function test_list_command_renders_admission_columns_for_human_output(): void
    {
        $command = new ListCommand();
        $command->setServerClient(new FakeTaskQueueServerClient([
            'task_queues' => [
                [
                    'name' => 'external-workflows',
                    'admission' => [
                        'workflow_tasks' => [
                            'status' => 'accepting',
                            'server_remaining_active_lease_capacity' => 4,
                        ],
                        'activity_tasks' => [
                            'status' => 'throttled',
                            'server_remaining_active_lease_capacity' => 0,
                        ],
                        'query_tasks' => [
                            'status' => 'full',
                            'remaining_pending_capacity' => 0,
                        ],
                    ],
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('Workflow Admission', $display);
        self::assertStringContainsString('Activity Admission', $display);
        self::assertStringContainsString('Query Admission', $display);
        self::assertStringContainsString('accepting (4 left)', $display);
        self::assertStringContainsString('throttled (0 left)', $display);
        self::assertStringContainsString('full (0 left)', $display);
    }

    public function test_it_renders_backlog_poller_and_current_lease_sections(): void
    {
        $command = new DescribeCommand();
        $command->setServerClient(new FakeTaskQueueServerClient([
            'name' => 'external-workflows',
            'stats' => [
                'approximate_backlog_count' => 1,
                'approximate_backlog_age' => '45s',
                'workflow_tasks' => [
                    'ready_count' => 1,
                    'leased_count' => 1,
                    'expired_lease_count' => 1,
                ],
                'activity_tasks' => [
                    'ready_count' => 0,
                    'leased_count' => 0,
                    'expired_lease_count' => 0,
                ],
                'pollers' => [
                    'active_count' => 1,
                    'stale_count' => 1,
                ],
            ],
            'admission' => [
                'workflow_tasks' => [
                    'status' => 'throttled',
                    'server_active_lease_count' => 2,
                    'server_max_active_leases_per_queue' => 2,
                    'server_remaining_active_lease_capacity' => 0,
                    'budget_source' => 'worker_registration.max_concurrent_workflow_tasks',
                    'server_budget_source' => 'server.admission.workflow_tasks.max_active_leases_per_queue',
                ],
                'activity_tasks' => [
                    'status' => 'accepting',
                    'server_active_lease_count' => 1,
                    'server_max_active_leases_per_queue' => 4,
                    'server_remaining_active_lease_capacity' => 3,
                    'budget_source' => 'worker_registration.max_concurrent_activity_tasks',
                    'server_budget_source' => 'server.admission.activity_tasks.max_active_leases_per_queue',
                ],
                'query_tasks' => [
                    'status' => 'full',
                    'approximate_pending_count' => 1,
                    'max_pending_per_queue' => 1,
                    'remaining_pending_capacity' => 0,
                    'lock_supported' => true,
                    'budget_source' => 'server.query_tasks.max_pending_per_queue',
                ],
            ],
            'current_leases' => [
                [
                    'task_id' => 'task-123',
                    'task_type' => 'workflow',
                    'workflow_id' => 'wf-123',
                    'lease_owner' => 'php-worker-1',
                    'lease_expires_at' => '2026-04-12T10:00:00Z',
                    'is_expired' => true,
                    'workflow_task_attempt' => 2,
                ],
            ],
            'pollers' => [
                [
                    'worker_id' => 'php-worker-1',
                    'runtime' => 'php',
                    'build_id' => 'build-a',
                    'last_heartbeat_at' => '2026-04-12T09:59:00Z',
                    'status' => 'active',
                    'is_stale' => false,
                ],
                [
                    'worker_id' => 'php-worker-2',
                    'runtime' => 'php',
                    'build_id' => 'build-b',
                    'last_heartbeat_at' => '2026-04-12T09:40:00Z',
                    'status' => 'stale',
                    'is_stale' => true,
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-queue' => 'external-workflows',
        ]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('Task Queue: external-workflows', $display);
        self::assertStringContainsString('Workflow Ready: 1', $display);
        self::assertStringContainsString('Workflow Expired Leases: 1', $display);
        self::assertStringContainsString('Pollers: active=1 stale=1', $display);
        self::assertStringContainsString('Admission:', $display);
        self::assertStringContainsString('Workflow Tasks: status=throttled active=2/2 remaining=0 source=server.admission.workflow_tasks.max_active_leases_per_queue', $display);
        self::assertStringContainsString('Activity Tasks: status=accepting active=1/4 remaining=3 source=server.admission.activity_tasks.max_active_leases_per_queue', $display);
        self::assertStringContainsString('Query Tasks: status=full pending=1/1 remaining=0 lock=yes source=server.query_tasks.max_pending_per_queue', $display);
        self::assertStringContainsString('Current Leases:', $display);
        self::assertStringContainsString('task-123', $display);
        self::assertStringContainsString('EXPIRED', $display);
        self::assertStringContainsString('php-worker-2', $display);
        self::assertStringContainsString('stale', $display);
    }
}

class FakeTaskQueueServerClient extends ServerClient
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
