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

    public function test_fail_command_sends_structured_failure_classification(): void
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
            '--message' => 'Deadline exceeded',
            '--type' => 'TimeoutError',
            '--failure-kind' => 'timeout',
            '--retryable' => true,
            '--timeout-type' => 'start_to_close',
            '--cancelled' => true,
            '--malformed-output' => true,
        ]));

        self::assertSame('timeout', $client->lastPostBody['failure']['kind']);
        self::assertTrue($client->lastPostBody['failure']['retryable']);
        self::assertFalse($client->lastPostBody['failure']['non_retryable']);
        self::assertSame('start_to_close', $client->lastPostBody['failure']['timeout_type']);
        self::assertTrue($client->lastPostBody['failure']['cancelled']);
        self::assertTrue($client->lastPostBody['failure']['malformed_output']);
    }

    public function test_fail_command_rejects_conflicting_retryability_flags(): void
    {
        $client = new ActivityFakeClient([
            'task_id' => 'task-1',
            'activity_attempt_id' => 'attempt-1',
        ]);

        $command = new FailCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(2, $tester->execute([
            'task-id' => 'task-1',
            'attempt-id' => 'attempt-1',
            '--message' => 'Invalid retryability',
            '--retryable' => true,
            '--non-retryable' => true,
        ]));

        self::assertStringContainsString('--retryable and --non-retryable cannot both be set', $tester->getDisplay());
        self::assertSame('', $client->lastPostPath);
    }

    public function test_activity_result_schema_pins_machine_failure_classifications(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/activity-task-result.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(['completed', 'failed', 'ignored', null], $schema['properties']['outcome']['enum']);
        self::assertSame(
            [
                'application',
                'timeout',
                'cancellation',
                'malformed_output',
                'handler_crash',
                'decode_failure',
                'unsupported_payload',
                null,
            ],
            $schema['properties']['failure']['properties']['kind']['enum'],
        );
        self::assertSame(
            ['schedule_to_start', 'start_to_close', 'schedule_to_close', 'heartbeat', 'deadline_exceeded', null],
            $schema['properties']['failure']['properties']['timeout_type']['enum'],
        );
        self::assertArrayHasKey('retryable', $schema['properties']['failure']['properties']);
        self::assertArrayHasKey('non_retryable', $schema['properties']['failure']['properties']);
    }

    public function test_failure_classification_fixture_matches_cli_request_contract(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__.'/../fixtures/external-task/failure-classification-fixture.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('durable-workflow.polyglot.external-task-failure-fixture', $fixture['schema']);

        $cases = array_column($fixture['cases'], 'failure', 'name');
        self::assertSame(
            [
                'message' => 'Deadline exceeded while calling the inventory API.',
                'type' => 'TimeoutError',
                'kind' => 'timeout',
                'retryable' => true,
                'non_retryable' => false,
                'timeout_type' => 'start_to_close',
                'cancelled' => false,
                'malformed_output' => false,
            ],
            $cases['retryable_timeout'],
        );
        self::assertTrue($cases['malformed_handler_output']['malformed_output']);
        self::assertTrue($cases['permanent_validation_error']['non_retryable']);
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
