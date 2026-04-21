<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\ActivityCommand\CompleteCommand;
use DurableWorkflow\Cli\Commands\ActivityCommand\FailCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ActivityCommandTest extends TestCase
{
    public function test_complete_command_sends_task_id_and_attempt_id(): void
    {
        $client = new ActivityFakeClient([
            'task_id' => 'task-1',
            'activity_attempt_id' => 'attempt-1',
        ]);

        $command = new CompleteCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-id' => 'task-1',
            'attempt-id' => 'attempt-1',
        ]));

        self::assertSame('/worker/activity-tasks/task-1/complete', $client->lastPostPath);
        self::assertSame('attempt-1', $client->lastPostBody['activity_attempt_id']);
        self::assertSame('cli', $client->lastPostBody['lease_owner']);
        self::assertStringContainsString('task-1', $tester->getDisplay());
    }

    public function test_complete_command_sends_result_when_provided(): void
    {
        $client = new ActivityFakeClient([
            'task_id' => 'task-1',
            'activity_attempt_id' => 'attempt-1',
        ]);

        $command = new CompleteCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-id' => 'task-1',
            'attempt-id' => 'attempt-1',
            '--input' => '{"total": 42}',
        ]));

        self::assertSame(['total' => 42], $client->lastPostBody['result']);
    }

    public function test_complete_command_accepts_raw_input_result(): void
    {
        $client = new ActivityFakeClient([
            'task_id' => 'task-1',
            'activity_attempt_id' => 'attempt-1',
        ]);

        $command = new CompleteCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-id' => 'task-1',
            'attempt-id' => 'attempt-1',
            '--input' => 'plain result',
            '--input-encoding' => 'raw',
        ]));

        self::assertSame('plain result', $client->lastPostBody['result']);
    }

    public function test_complete_command_accepts_custom_lease_owner(): void
    {
        $client = new ActivityFakeClient([
            'task_id' => 'task-1',
            'activity_attempt_id' => 'attempt-1',
        ]);

        $command = new CompleteCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-id' => 'task-1',
            'attempt-id' => 'attempt-1',
            '--lease-owner' => 'worker-5',
        ]));

        self::assertSame('worker-5', $client->lastPostBody['lease_owner']);
    }

    public function test_fail_command_sends_failure_details(): void
    {
        $client = new ActivityFakeClient([
            'task_id' => 'task-1',
            'activity_attempt_id' => 'attempt-1',
        ]);

        $command = new FailCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-id' => 'task-1',
            'attempt-id' => 'attempt-1',
            '--message' => 'Connection timeout',
            '--type' => 'TimeoutError',
        ]));

        self::assertSame('/worker/activity-tasks/task-1/fail', $client->lastPostPath);
        self::assertSame('attempt-1', $client->lastPostBody['activity_attempt_id']);
        self::assertSame('Connection timeout', $client->lastPostBody['failure']['message']);
        self::assertSame('TimeoutError', $client->lastPostBody['failure']['type']);
        self::assertStringContainsString('task-1', $tester->getDisplay());
    }

    public function test_fail_command_sends_non_retryable_flag(): void
    {
        $client = new ActivityFakeClient([
            'task_id' => 'task-1',
            'activity_attempt_id' => 'attempt-1',
        ]);

        $command = new FailCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-id' => 'task-1',
            'attempt-id' => 'attempt-1',
            '--message' => 'Invalid input',
            '--non-retryable' => true,
        ]));

        self::assertTrue($client->lastPostBody['failure']['non_retryable']);
    }
}

class ActivityFakeClient extends ServerClient
{
    /** @var array<string, mixed> */
    public array $lastPostBody = [];

    public string $lastPostPath = '';

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly array $payload,
    ) {}

    public function post(string $path, array $body = []): array
    {
        $this->lastPostPath = $path;
        $this->lastPostBody = $body;

        return $this->payload;
    }
}
