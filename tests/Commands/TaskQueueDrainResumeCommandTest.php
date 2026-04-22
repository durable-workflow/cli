<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\TaskQueueCommand\DrainCommand;
use DurableWorkflow\Cli\Commands\TaskQueueCommand\ResumeCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;

final class TaskQueueDrainResumeCommandTest extends TestCase
{
    public function test_drain_posts_build_id_to_server_and_renders_confirmation(): void
    {
        $client = new FakeDrainResumeClient([
            'namespace' => 'default',
            'task_queue' => 'orders',
            'build_id' => 'build-2026.04.21-z9',
            'drain_intent' => 'draining',
            'drained_at' => '2026-04-22T09:45:00Z',
        ]);

        $command = new DrainCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-queue' => 'orders',
            '--build-id' => 'build-2026.04.21-z9',
        ]));

        self::assertSame('POST', $client->lastMethod);
        self::assertSame('/task-queues/orders/build-ids/drain', $client->lastPath);
        self::assertSame(['build_id' => 'build-2026.04.21-z9'], $client->lastBody);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Drained build_id build-2026.04.21-z9 on task queue orders.', $display);
        self::assertStringContainsString('Drained at: 2026-04-22T09:45:00Z', $display);
    }

    public function test_drain_targets_unversioned_cohort_with_null_build_id_body(): void
    {
        $client = new FakeDrainResumeClient([
            'namespace' => 'default',
            'task_queue' => 'orders',
            'build_id' => null,
            'drain_intent' => 'draining',
            'drained_at' => '2026-04-22T09:45:00Z',
        ]);

        $command = new DrainCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-queue' => 'orders',
            '--unversioned' => true,
        ]));

        self::assertSame(['build_id' => null], $client->lastBody);
        self::assertStringContainsString('(unversioned)', $tester->getDisplay());
    }

    public function test_drain_requires_build_id_or_unversioned(): void
    {
        $command = new DrainCommand();
        $command->setServerClient(new FakeDrainResumeClient([]));
        $tester = new CommandTester($command);

        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('--build-id <value> or --unversioned');

        $tester->execute(['task-queue' => 'orders']);
    }

    public function test_drain_rejects_build_id_combined_with_unversioned(): void
    {
        $command = new DrainCommand();
        $command->setServerClient(new FakeDrainResumeClient([]));
        $tester = new CommandTester($command);

        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('--unversioned cannot be combined with --build-id');

        $tester->execute([
            'task-queue' => 'orders',
            '--build-id' => 'build-1',
            '--unversioned' => true,
        ]);
    }

    public function test_drain_json_output_returns_full_server_payload(): void
    {
        $payload = [
            'namespace' => 'default',
            'task_queue' => 'orders',
            'build_id' => 'build-2026.04.21-z9',
            'drain_intent' => 'draining',
            'drained_at' => '2026-04-22T09:45:00Z',
        ];
        $client = new FakeDrainResumeClient($payload);

        $command = new DrainCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-queue' => 'orders',
            '--build-id' => 'build-2026.04.21-z9',
            '--json' => true,
        ]));

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($payload, $decoded);
    }

    public function test_resume_posts_build_id_to_resume_endpoint(): void
    {
        $client = new FakeDrainResumeClient([
            'namespace' => 'default',
            'task_queue' => 'orders',
            'build_id' => 'build-2026.04.21-z9',
            'drain_intent' => 'active',
            'drained_at' => null,
        ]);

        $command = new ResumeCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-queue' => 'orders',
            '--build-id' => 'build-2026.04.21-z9',
        ]));

        self::assertSame('POST', $client->lastMethod);
        self::assertSame('/task-queues/orders/build-ids/resume', $client->lastPath);
        self::assertSame(['build_id' => 'build-2026.04.21-z9'], $client->lastBody);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Resumed build_id build-2026.04.21-z9 on task queue orders.', $display);
    }

    public function test_resume_targets_unversioned_cohort_with_null_build_id(): void
    {
        $client = new FakeDrainResumeClient([
            'namespace' => 'default',
            'task_queue' => 'orders',
            'build_id' => null,
            'drain_intent' => 'active',
            'drained_at' => null,
        ]);

        $command = new ResumeCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-queue' => 'orders',
            '--unversioned' => true,
        ]));

        self::assertSame(['build_id' => null], $client->lastBody);
        self::assertStringContainsString('(unversioned)', $tester->getDisplay());
    }

    public function test_resume_requires_build_id_or_unversioned(): void
    {
        $command = new ResumeCommand();
        $command->setServerClient(new FakeDrainResumeClient([]));
        $tester = new CommandTester($command);

        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('--build-id <value> or --unversioned');

        $tester->execute(['task-queue' => 'orders']);
    }

    public function test_resume_json_output_returns_full_server_payload(): void
    {
        $payload = [
            'namespace' => 'default',
            'task_queue' => 'orders',
            'build_id' => 'build-2026.04.21-z9',
            'drain_intent' => 'active',
            'drained_at' => null,
        ];
        $client = new FakeDrainResumeClient($payload);

        $command = new ResumeCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-queue' => 'orders',
            '--build-id' => 'build-2026.04.21-z9',
            '--json' => true,
        ]));

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($payload, $decoded);
    }
}

final class FakeDrainResumeClient extends ServerClient
{
    public ?string $lastMethod = null;

    public ?string $lastPath = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $lastBody = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly array $payload)
    {
    }

    public function post(string $path, array $body = []): array
    {
        $this->lastMethod = 'POST';
        $this->lastPath = $path;
        $this->lastBody = $body;

        return $this->payload;
    }
}
