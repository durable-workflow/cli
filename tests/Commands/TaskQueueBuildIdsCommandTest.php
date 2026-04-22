<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\TaskQueueCommand\BuildIdsCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TaskQueueBuildIdsCommandTest extends TestCase
{
    public function test_renders_build_id_rollout_table(): void
    {
        $command = new BuildIdsCommand();
        $command->setServerClient(new FakeTaskQueueBuildIdsClient([
            'namespace' => 'default',
            'task_queue' => 'ingest',
            'stale_after_seconds' => 60,
            'build_ids' => [
                [
                    'build_id' => 'build-2026.04.22-a1',
                    'rollout_status' => 'active',
                    'active_worker_count' => 2,
                    'draining_worker_count' => 0,
                    'stale_worker_count' => 0,
                    'total_worker_count' => 2,
                    'runtimes' => ['worker-runtime'],
                    'sdk_versions' => ['polyglot-sdk/2.0.0'],
                    'last_heartbeat_at' => '2026-04-22T09:30:00Z',
                    'first_seen_at' => '2026-04-22T08:00:00Z',
                ],
                [
                    'build_id' => null,
                    'rollout_status' => 'stale_only',
                    'active_worker_count' => 0,
                    'draining_worker_count' => 0,
                    'stale_worker_count' => 1,
                    'total_worker_count' => 1,
                    'runtimes' => ['worker-runtime'],
                    'sdk_versions' => [],
                    'last_heartbeat_at' => '2026-04-20T12:00:00Z',
                    'first_seen_at' => '2026-04-20T11:00:00Z',
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-queue' => 'ingest',
        ]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('Task Queue: ingest', $display);
        self::assertStringContainsString('Stale threshold: 60s', $display);
        self::assertStringContainsString('build-2026.04.22-a1', $display);
        self::assertStringContainsString('(unversioned)', $display);
        self::assertStringContainsString('active', $display);
        self::assertStringContainsString('stale_only', $display);
    }

    public function test_renders_empty_message_when_no_workers_have_registered(): void
    {
        $command = new BuildIdsCommand();
        $command->setServerClient(new FakeTaskQueueBuildIdsClient([
            'namespace' => 'default',
            'task_queue' => 'empty-queue',
            'stale_after_seconds' => 60,
            'build_ids' => [],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-queue' => 'empty-queue',
        ]));

        self::assertStringContainsString(
            'No workers have registered on this task queue.',
            $tester->getDisplay(),
        );
    }

    public function test_json_output_returns_full_server_payload_verbatim(): void
    {
        $payload = [
            'namespace' => 'default',
            'task_queue' => 'orders',
            'stale_after_seconds' => 90,
            'build_ids' => [
                [
                    'build_id' => 'build-alpha',
                    'rollout_status' => 'active_with_draining',
                    'active_worker_count' => 1,
                    'draining_worker_count' => 1,
                    'stale_worker_count' => 0,
                    'total_worker_count' => 2,
                    'runtimes' => ['worker-runtime'],
                    'sdk_versions' => ['polyglot-sdk/2.0.0'],
                    'last_heartbeat_at' => '2026-04-22T09:30:00Z',
                    'first_seen_at' => '2026-04-22T08:00:00Z',
                ],
            ],
        ];

        $command = new BuildIdsCommand();
        $command->setServerClient(new FakeTaskQueueBuildIdsClient($payload));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-queue' => 'orders',
            '--json' => true,
        ]));

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($payload, $decoded);
    }

    public function test_sends_get_request_to_task_queue_build_ids_path(): void
    {
        $client = new FakeTaskQueueBuildIdsClient([
            'namespace' => 'default',
            'task_queue' => 'orders',
            'stale_after_seconds' => 60,
            'build_ids' => [],
        ]);

        $command = new BuildIdsCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-queue' => 'orders',
            '--json' => true,
        ]));

        self::assertSame('GET', $client->lastMethod);
        self::assertSame('/task-queues/orders/build-ids', $client->lastPath);
    }
}

final class FakeTaskQueueBuildIdsClient extends ServerClient
{
    public ?string $lastMethod = null;

    public ?string $lastPath = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly array $payload)
    {
    }

    public function get(string $path, array $query = []): array
    {
        $this->lastMethod = 'GET';
        $this->lastPath = $path;

        return $this->payload;
    }
}
