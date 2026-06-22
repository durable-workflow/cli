<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\ActivityCommand\DescribeCommand;
use DurableWorkflow\Cli\Commands\ActivityCommand\ListCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ActivityReadCommandTest extends TestCase
{
    public function test_list_command_exposes_attempt_rows_in_json_output(): void
    {
        $client = new ActivityReadFakeServerClient([
            '/activities' => [
                'activities' => [[
                    'activity_id' => 'activity-1',
                    'activity_execution_id' => 'execution-1',
                    'activity_type' => 'email.send',
                    'activity_status' => 'running',
                    'current_attempt_id' => 'attempt-1',
                    'current_attempt_status' => 'running',
                    'attempts' => [[
                        'activity_attempt_id' => 'attempt-1',
                        'activity_execution_id' => 'execution-1',
                        'attempt_number' => 1,
                        'status' => 'running',
                    ]],
                ]],
                'activity_count' => 1,
                'next_page_token' => null,
            ],
        ]);
        $command = new ListCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--namespace' => 'activities',
            '--status' => 'running',
            '--limit' => '100',
            '--output' => 'json',
        ]));

        self::assertSame('/activities', $client->lastGetPath);
        self::assertSame('running', $client->lastGetQuery['status'] ?? null);
        self::assertSame(100, $client->lastGetQuery['page_size'] ?? null);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('activities', $decoded['namespace'] ?? null);
        self::assertSame('activities', $decoded['activities'][0]['namespace'] ?? null);
        self::assertSame('execution-1', $decoded['activities'][0]['activity_execution_id'] ?? null);
        self::assertSame('attempt-1', $decoded['activities'][0]['attempts'][0]['activity_attempt_id'] ?? null);
        self::assertSame('running', $decoded['activities'][0]['attempts'][0]['status'] ?? null);
    }

    public function test_describe_command_preserves_activity_attempt_state_in_json_output(): void
    {
        $client = new ActivityReadFakeServerClient([
            '/activities/activity-1' => [
                'activity_id' => 'activity-1',
                'activity_execution_id' => 'execution-1',
                'activity_type' => 'email.send',
                'activity_status' => 'completed',
                'current_attempt_id' => 'attempt-1',
                'current_attempt_status' => 'completed',
                'attempts' => [[
                    'activity_attempt_id' => 'attempt-1',
                    'activity_execution_id' => 'execution-1',
                    'attempt_number' => 1,
                    'status' => 'completed',
                ]],
            ],
        ]);
        $command = new DescribeCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'activity-id' => 'activity-1',
            '--namespace' => 'activities',
            '--json' => true,
        ]));

        self::assertSame('/activities/activity-1', $client->lastGetPath);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('activities', $decoded['namespace'] ?? null);
        self::assertSame('execution-1', $decoded['activity_execution_id'] ?? null);
        self::assertSame('attempt-1', $decoded['current_attempt_id'] ?? null);
        self::assertSame('completed', $decoded['attempts'][0]['status'] ?? null);
    }

    public function test_human_describe_output_includes_current_attempt_state(): void
    {
        $client = new ActivityReadFakeServerClient([
            '/activities/activity-1' => [
                'namespace' => 'activities',
                'activity_id' => 'activity-1',
                'activity_execution_id' => 'execution-1',
                'activity_type' => 'email.send',
                'activity_status' => 'running',
                'current_attempt_id' => 'attempt-1',
                'current_attempt_status' => 'running',
                'attempt_count' => 1,
                'attempts' => [[
                    'activity_attempt_id' => 'attempt-1',
                    'attempt_number' => 1,
                    'status' => 'running',
                ]],
            ],
        ]);
        $command = new DescribeCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'activity-id' => 'activity-1',
        ]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('Activity Execution ID: execution-1', $display);
        self::assertStringContainsString('Current Attempt ID: attempt-1', $display);
        self::assertStringContainsString('Current Attempt Status: running', $display);
    }
}

final class ActivityReadFakeServerClient extends ServerClient
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $payloadsByPath;

    public string $lastGetPath = '';

    /**
     * @var array<string, mixed>
     */
    public array $lastGetQuery = [];

    /**
     * @param array<string, array<string, mixed>> $payloadsByPath
     */
    public function __construct(array $payloadsByPath)
    {
        $this->payloadsByPath = $payloadsByPath;
    }

    public function get(string $path, array $query = []): array
    {
        $this->lastGetPath = $path;
        $this->lastGetQuery = $query;

        return $this->payloadsByPath[$path] ?? [];
    }
}
