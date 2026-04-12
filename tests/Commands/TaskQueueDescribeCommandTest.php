<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\TaskQueueCommand\DescribeCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TaskQueueDescribeCommandTest extends TestCase
{
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
