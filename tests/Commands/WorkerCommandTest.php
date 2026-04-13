<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkerCommand\DeregisterCommand;
use DurableWorkflow\Cli\Commands\WorkerCommand\DescribeCommand;
use DurableWorkflow\Cli\Commands\WorkerCommand\ListCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class WorkerCommandTest extends TestCase
{
    public function test_list_command_renders_workers_in_a_table(): void
    {
        $command = new ListCommand();
        $command->setServerClient(new WorkerFakeClient([
            'workers' => [
                [
                    'worker_id' => 'worker-a',
                    'task_queue' => 'queue-alpha',
                    'runtime' => 'php',
                    'build_id' => 'build-1',
                    'status' => 'active',
                    'last_heartbeat_at' => '2026-04-13T12:00:00Z',
                ],
                [
                    'worker_id' => 'worker-b',
                    'task_queue' => 'queue-beta',
                    'runtime' => 'python',
                    'build_id' => null,
                    'status' => 'stale',
                    'last_heartbeat_at' => '2026-04-13T11:00:00Z',
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('worker-a', $display);
        self::assertStringContainsString('queue-alpha', $display);
        self::assertStringContainsString('php', $display);
        self::assertStringContainsString('build-1', $display);
        self::assertStringContainsString('active', $display);
        self::assertStringContainsString('worker-b', $display);
        self::assertStringContainsString('python', $display);
        self::assertStringContainsString('stale', $display);
    }

    public function test_list_command_shows_message_when_no_workers_exist(): void
    {
        $command = new ListCommand();
        $command->setServerClient(new WorkerFakeClient([
            'workers' => [],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertStringContainsString('No workers found', $tester->getDisplay());
    }

    public function test_list_command_sends_task_queue_filter(): void
    {
        $client = new WorkerFakeClient([
            'workers' => [
                [
                    'worker_id' => 'worker-a',
                    'task_queue' => 'queue-alpha',
                    'runtime' => 'php',
                    'build_id' => null,
                    'status' => 'active',
                    'last_heartbeat_at' => '2026-04-13T12:00:00Z',
                ],
            ],
        ]);

        $command = new ListCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--task-queue' => 'queue-alpha',
        ]));

        self::assertSame('/workers', $client->lastGetPath);
        self::assertSame(['task_queue' => 'queue-alpha'], $client->lastGetQuery);
    }

    public function test_list_command_sends_status_filter(): void
    {
        $client = new WorkerFakeClient([
            'workers' => [],
        ]);

        $command = new ListCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        $tester->execute(['--status' => 'stale']);

        self::assertArrayHasKey('status', $client->lastGetQuery);
        self::assertSame('stale', $client->lastGetQuery['status']);
    }

    public function test_list_command_json_output(): void
    {
        $payload = [
            'workers' => [
                [
                    'worker_id' => 'worker-a',
                    'task_queue' => 'queue-alpha',
                    'runtime' => 'php',
                    'status' => 'active',
                ],
            ],
        ];

        $command = new ListCommand();
        $command->setServerClient(new WorkerFakeClient($payload));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));

        $output = json_decode($tester->getDisplay(), true);

        self::assertIsArray($output);
        self::assertArrayHasKey('workers', $output);
        self::assertSame('worker-a', $output['workers'][0]['worker_id']);
    }

    public function test_describe_command_renders_worker_details(): void
    {
        $command = new DescribeCommand();
        $command->setServerClient(new WorkerFakeClient([
            'worker_id' => 'worker-a',
            'namespace' => 'default',
            'task_queue' => 'queue-alpha',
            'runtime' => 'php',
            'sdk_version' => '1.2.3',
            'build_id' => 'build-42',
            'status' => 'active',
            'max_concurrent_workflow_tasks' => 100,
            'max_concurrent_activity_tasks' => 50,
            'supported_workflow_types' => ['order.process', 'payment.capture'],
            'supported_activity_types' => ['email.send'],
            'last_heartbeat_at' => '2026-04-13T12:00:00Z',
            'registered_at' => '2026-04-13T10:00:00Z',
            'updated_at' => '2026-04-13T12:00:00Z',
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'worker-id' => 'worker-a',
        ]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('worker-a', $display);
        self::assertStringContainsString('queue-alpha', $display);
        self::assertStringContainsString('php', $display);
        self::assertStringContainsString('1.2.3', $display);
        self::assertStringContainsString('build-42', $display);
        self::assertStringContainsString('active', $display);
        self::assertStringContainsString('order.process', $display);
        self::assertStringContainsString('payment.capture', $display);
        self::assertStringContainsString('email.send', $display);
    }

    public function test_describe_command_json_output(): void
    {
        $payload = [
            'worker_id' => 'worker-a',
            'namespace' => 'default',
            'task_queue' => 'queue-alpha',
            'runtime' => 'php',
            'status' => 'active',
        ];

        $command = new DescribeCommand();
        $command->setServerClient(new WorkerFakeClient($payload));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'worker-id' => 'worker-a',
            '--json' => true,
        ]));

        $output = json_decode($tester->getDisplay(), true);

        self::assertIsArray($output);
        self::assertSame('worker-a', $output['worker_id']);
    }

    public function test_deregister_command_sends_delete_request(): void
    {
        $client = new WorkerFakeClient([
            'worker_id' => 'worker-a',
            'outcome' => 'deregistered',
        ]);

        $command = new DeregisterCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'worker-id' => 'worker-a',
        ]));

        self::assertSame('/workers/worker-a', $client->lastDeletePath);
        self::assertStringContainsString('worker-a', $tester->getDisplay());
        self::assertStringContainsString('deregistered', $tester->getDisplay());
    }
}

class WorkerFakeClient extends ServerClient
{
    public string $lastGetPath = '';

    /** @var array<string, string> */
    public array $lastGetQuery = [];

    public string $lastDeletePath = '';

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly array $payload,
    ) {}

    public function get(string $path, array $query = []): array
    {
        $this->lastGetPath = $path;
        $this->lastGetQuery = $query;

        return $this->payload;
    }

    public function delete(string $path): array
    {
        $this->lastDeletePath = $path;

        return $this->payload;
    }
}
