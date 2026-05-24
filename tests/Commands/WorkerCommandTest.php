<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkerCommand\DeregisterCommand;
use DurableWorkflow\Cli\Commands\WorkerCommand\DescribeCommand;
use DurableWorkflow\Cli\Commands\WorkerCommand\ListCommand;
use DurableWorkflow\Cli\Commands\WorkerCommand\RegisterCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class WorkerCommandTest extends TestCase
{
    public function test_register_command_sends_worker_capabilities(): void
    {
        $client = new WorkerFakeClient([
            'worker_id' => 'worker-a',
            'registered' => true,
        ]);

        $command = new RegisterCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'worker-id' => 'worker-a',
            '--task-queue' => 'queue-alpha',
            '--runtime' => 'python',
            '--sdk-version' => '1.0.0',
            '--build-id' => 'build-42',
            '--workflow-type' => ['orders.Process', 'orders.Refund'],
            '--activity-type' => ['email.send'],
            '--max-workflow-tasks' => '5',
            '--max-activity-tasks' => '7',
        ]));

        self::assertSame('/worker/register', $client->lastPostPath);
        self::assertSame('worker-a', $client->lastPostBody['worker_id']);
        self::assertSame('queue-alpha', $client->lastPostBody['task_queue']);
        self::assertSame('python', $client->lastPostBody['runtime']);
        self::assertSame('1.0.0', $client->lastPostBody['sdk_version']);
        self::assertSame('build-42', $client->lastPostBody['build_id']);
        self::assertSame(['orders.Process', 'orders.Refund'], $client->lastPostBody['supported_workflow_types']);
        self::assertSame(['email.send'], $client->lastPostBody['supported_activity_types']);
        self::assertSame(5, $client->lastPostBody['max_concurrent_workflow_tasks']);
        self::assertSame(7, $client->lastPostBody['max_concurrent_activity_tasks']);
        self::assertStringContainsString('worker-a', $tester->getDisplay());
    }

    public function test_register_command_json_output(): void
    {
        $payload = [
            'worker_id' => 'worker-a',
            'registered' => true,
        ];

        $command = new RegisterCommand();
        $command->setServerClient(new WorkerFakeClient($payload));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'worker-id' => 'worker-a',
            '--json' => true,
        ]));

        self::assertSame($payload, json_decode($tester->getDisplay(), true));
    }

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

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--namespace' => 'tenant-a',
        ]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('worker-a', $display);
        self::assertStringContainsString('queue-alpha', $display);
        self::assertStringContainsString('php', $display);
        self::assertStringContainsString('build-1', $display);
        self::assertStringContainsString('active', $display);
        self::assertStringContainsString('worker-b', $display);
        self::assertStringContainsString('python', $display);
        self::assertStringContainsString('stale', $display);
        self::assertStringContainsString('Namespace: tenant-a', $display);
    }

    public function test_list_command_colors_statuses_only_when_output_is_decorated(): void
    {
        $payload = [
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
        ];

        $plainCommand = new ListCommand();
        $plainCommand->setServerClient(new WorkerFakeClient($payload));
        $plainTester = new CommandTester($plainCommand);

        self::assertSame(Command::SUCCESS, $plainTester->execute([]));
        self::assertStringContainsString('active', $plainTester->getDisplay());
        self::assertStringNotContainsString("\033[", $plainTester->getDisplay());

        $decoratedCommand = new ListCommand();
        $decoratedCommand->setServerClient(new WorkerFakeClient($payload));
        $decoratedTester = new CommandTester($decoratedCommand);

        self::assertSame(Command::SUCCESS, $decoratedTester->execute([], ['decorated' => true]));
        self::assertStringContainsString('active', $decoratedTester->getDisplay());
        self::assertStringContainsString('stale', $decoratedTester->getDisplay());
        self::assertStringContainsString("\033[", $decoratedTester->getDisplay());
    }

    public function test_list_command_shows_message_when_no_workers_exist(): void
    {
        $command = new ListCommand();
        $command->setServerClient(new WorkerFakeClient([
            'workers' => [],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--namespace' => 'tenant-a',
        ]));

        self::assertStringContainsString('No workers found in namespace tenant-a', $tester->getDisplay());
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

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--namespace' => 'tenant-a',
            '--json' => true,
        ]));

        $output = json_decode($tester->getDisplay(), true);

        self::assertIsArray($output);
        self::assertSame('tenant-a', $output['namespace'] ?? null);
        self::assertArrayHasKey('workers', $output);
        self::assertSame('tenant-a', $output['workers'][0]['namespace'] ?? null);
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

    public function test_describe_command_colors_status_only_when_output_is_decorated(): void
    {
        $payload = [
            'worker_id' => 'worker-a',
            'namespace' => 'default',
            'task_queue' => 'queue-alpha',
            'runtime' => 'php',
            'sdk_version' => '1.2.3',
            'build_id' => 'build-42',
            'status' => 'stale',
            'last_heartbeat_at' => '2026-04-13T12:00:00Z',
        ];

        $plainCommand = new DescribeCommand();
        $plainCommand->setServerClient(new WorkerFakeClient($payload));
        $plainTester = new CommandTester($plainCommand);

        self::assertSame(Command::SUCCESS, $plainTester->execute(['worker-id' => 'worker-a']));
        self::assertStringContainsString('Status: stale', $plainTester->getDisplay());
        self::assertStringNotContainsString("\033[", $plainTester->getDisplay());

        $decoratedCommand = new DescribeCommand();
        $decoratedCommand->setServerClient(new WorkerFakeClient($payload));
        $decoratedTester = new CommandTester($decoratedCommand);

        self::assertSame(Command::SUCCESS, $decoratedTester->execute(['worker-id' => 'worker-a'], ['decorated' => true]));
        $display = $decoratedTester->getDisplay();
        self::assertStringContainsString('stale', $display);
        self::assertStringContainsString("\033[", $display);
    }

    public function test_list_command_renders_task_slot_summary(): void
    {
        $command = new ListCommand();
        $command->setServerClient(new WorkerFakeClient([
            'workers' => [
                [
                    'worker_id' => 'worker-slots',
                    'task_queue' => 'queue-alpha',
                    'runtime' => 'python',
                    'build_id' => null,
                    'status' => 'active',
                    'last_heartbeat_at' => '2026-05-09T12:00:00Z',
                    'max_concurrent_workflow_tasks' => 8,
                    'max_concurrent_activity_tasks' => 4,
                    'task_slots' => [
                        'workflow_available' => 5,
                        'activity_available' => 1,
                        'workflow_capacity' => 8,
                        'activity_capacity' => 4,
                    ],
                ],
            ],
        ]));

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('wf 5/8', $display);
        self::assertStringContainsString('act 1/4', $display);
    }

    public function test_describe_command_renders_task_slots_and_process_metrics(): void
    {
        $command = new DescribeCommand();
        $command->setServerClient(new WorkerFakeClient([
            'worker_id' => 'worker-slots',
            'namespace' => 'default',
            'task_queue' => 'queue-alpha',
            'runtime' => 'python',
            'status' => 'active',
            'max_concurrent_workflow_tasks' => 8,
            'max_concurrent_activity_tasks' => 4,
            'max_concurrent_worker_sessions' => 2,
            'task_slots' => [
                'workflow_available' => 5,
                'activity_available' => 1,
                'session_available' => 2,
                'workflow_capacity' => 8,
                'activity_capacity' => 4,
                'session_capacity' => 2,
            ],
            'process_metrics' => [
                'cpu_percent' => 7.5,
                'memory_bytes' => 134217728,
                'process_uptime_seconds' => 750,
                'process_id' => 99,
                'host' => 'py-worker-01',
            ],
            'heartbeat_interval_seconds' => 30,
            'last_heartbeat_at' => '2026-05-09T12:00:00Z',
        ]));

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute(['worker-id' => 'worker-slots']));

        $display = $tester->getDisplay();
        self::assertStringContainsString('Task Slots', $display);
        self::assertStringContainsString('Workflow: 5 / 8', $display);
        self::assertStringContainsString('Activity: 1 / 4', $display);
        self::assertStringContainsString('Sessions: 2 / 2', $display);
        self::assertStringContainsString('Process Metrics', $display);
        self::assertStringContainsString('CPU: 7.5%', $display);
        self::assertStringContainsString('Memory: 128', $display);
        self::assertStringContainsString('Host: py-worker-01', $display);
        self::assertStringContainsString('Heartbeat Interval: 30s', $display);
    }

    public function test_describe_command_json_output(): void
    {
        $payload = [
            'worker_id' => 'worker-a',
            'task_queue' => 'queue-alpha',
            'runtime' => 'php',
            'status' => 'active',
        ];

        $command = new DescribeCommand();
        $command->setServerClient(new WorkerFakeClient($payload));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'worker-id' => 'worker-a',
            '--namespace' => 'tenant-a',
            '--json' => true,
        ]));

        $output = json_decode($tester->getDisplay(), true);

        self::assertIsArray($output);
        self::assertSame('worker-a', $output['worker_id']);
        self::assertSame('tenant-a', $output['namespace'] ?? null);
    }

    public function test_worker_describe_schema_pins_heartbeat_surface_keys(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/worker.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $properties = $schema['properties'];

        self::assertContains('namespace', $schema['required']);
        self::assertSame(['integer', 'null'], $properties['max_concurrent_worker_sessions']['type']);
        self::assertSame(['integer', 'null'], $properties['heartbeat_interval_seconds']['type']);

        $taskSlots = $properties['task_slots']['properties'];
        self::assertSame(['object', 'null'], $properties['task_slots']['type']);
        self::assertSame(['integer', 'null'], $taskSlots['workflow_available']['type']);
        self::assertSame(['integer', 'null'], $taskSlots['workflow_capacity']['type']);
        self::assertSame(['integer', 'null'], $taskSlots['activity_available']['type']);
        self::assertSame(['integer', 'null'], $taskSlots['activity_capacity']['type']);
        self::assertSame(['integer', 'null'], $taskSlots['session_available']['type']);
        self::assertSame(['integer', 'null'], $taskSlots['session_capacity']['type']);

        $processMetrics = $properties['process_metrics']['properties'];
        self::assertSame(['object', 'null'], $properties['process_metrics']['type']);
        self::assertSame(['number', 'null'], $processMetrics['cpu_percent']['type']);
        self::assertSame(['integer', 'null'], $processMetrics['memory_bytes']['type']);
        self::assertSame(['integer', 'null'], $processMetrics['process_uptime_seconds']['type']);
        self::assertSame(['integer', 'null'], $processMetrics['process_id']['type']);
        self::assertSame(['string', 'null'], $processMetrics['host']['type']);
    }

    public function test_worker_list_schema_pins_heartbeat_surface_keys(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/worker-list.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $worker = $schema['properties']['workers']['items']['properties'];

        self::assertContains('namespace', $schema['required']);
        self::assertContains('namespace', $schema['properties']['workers']['items']['required']);
        self::assertSame(['integer', 'null'], $worker['max_concurrent_workflow_tasks']['type']);
        self::assertSame(['integer', 'null'], $worker['max_concurrent_activity_tasks']['type']);
        self::assertSame(['object', 'null'], $worker['task_slots']['type']);

        $taskSlots = $worker['task_slots']['properties'];
        self::assertSame(['integer', 'null'], $taskSlots['workflow_available']['type']);
        self::assertSame(['integer', 'null'], $taskSlots['workflow_capacity']['type']);
        self::assertSame(['integer', 'null'], $taskSlots['activity_available']['type']);
        self::assertSame(['integer', 'null'], $taskSlots['activity_capacity']['type']);
        self::assertSame(['integer', 'null'], $taskSlots['session_available']['type']);
        self::assertSame(['integer', 'null'], $taskSlots['session_capacity']['type']);
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
    /** @var array<string, mixed> */
    public array $lastPostBody = [];

    public string $lastPostPath = '';

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

    public function post(string $path, array $body = []): array
    {
        $this->lastPostPath = $path;
        $this->lastPostBody = $body;

        return $this->payload;
    }

    public function delete(string $path): array
    {
        $this->lastDeletePath = $path;

        return $this->payload;
    }
}
