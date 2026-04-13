<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowCommand\HistoryCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\QueryCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class WorkflowHistoryQueryTest extends TestCase
{
    public function test_history_command_renders_events_in_a_table(): void
    {
        $command = new HistoryCommand();
        $command->setServerClient(new HistoryQueryFakeClient([
            'workflow_id' => 'wf-123',
            'run_id' => 'run-1',
            'events' => [
                [
                    'sequence' => 1,
                    'event_type' => 'workflow_started',
                    'timestamp' => '2026-04-13T00:00:00Z',
                    'details' => ['type' => 'orders.process'],
                ],
                [
                    'sequence' => 2,
                    'event_type' => 'activity_scheduled',
                    'timestamp' => '2026-04-13T00:00:01Z',
                    'details' => ['activity_type' => 'send_email'],
                ],
            ],
            'next_page_token' => null,
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
            'run-id' => 'run-1',
        ]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('workflow_started', $display);
        self::assertStringContainsString('activity_scheduled', $display);
        self::assertStringContainsString('send_email', $display);
    }

    public function test_history_command_renders_json_output(): void
    {
        $command = new HistoryCommand();
        $command->setServerClient(new HistoryQueryFakeClient([
            'workflow_id' => 'wf-123',
            'run_id' => 'run-1',
            'events' => [
                [
                    'sequence' => 1,
                    'event_type' => 'workflow_started',
                    'timestamp' => '2026-04-13T00:00:00Z',
                    'details' => [],
                ],
            ],
            'next_page_token' => null,
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
            'run-id' => 'run-1',
            '--json' => true,
        ]));

        $decoded = json_decode($tester->getDisplay(), true);

        self::assertIsArray($decoded);
        self::assertSame('wf-123', $decoded['workflow_id']);
        self::assertCount(1, $decoded['events']);
        self::assertSame('workflow_started', $decoded['events'][0]['event_type']);
    }

    public function test_history_command_paginates_across_pages(): void
    {
        $client = new HistoryQueryPaginatingClient([
            [
                'events' => [
                    ['sequence' => 1, 'event_type' => 'workflow_started', 'timestamp' => '2026-04-13T00:00:00Z', 'details' => []],
                ],
                'next_page_token' => 'page-2',
            ],
            [
                'events' => [
                    ['sequence' => 2, 'event_type' => 'activity_scheduled', 'timestamp' => '2026-04-13T00:00:01Z', 'details' => []],
                ],
                'next_page_token' => null,
            ],
        ]);

        $command = new HistoryCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
            'run-id' => 'run-1',
        ]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('workflow_started', $display);
        self::assertStringContainsString('activity_scheduled', $display);
        self::assertSame(2, $client->getCalls);
    }

    public function test_history_command_sends_follow_flag_as_wait_new_event(): void
    {
        $client = new HistoryQueryFakeClient([
            'workflow_id' => 'wf-123',
            'run_id' => 'run-1',
            'events' => [],
            'next_page_token' => null,
        ]);

        $command = new HistoryCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
            'run-id' => 'run-1',
            '--follow' => true,
        ]));

        self::assertTrue($client->lastGetQuery['wait_new_event'] ?? false);
    }

    public function test_query_command_sends_post_and_renders_result(): void
    {
        $client = new HistoryQueryFakeClient([
            'workflow_id' => 'wf-123',
            'query_name' => 'getStatus',
            'result' => ['progress' => 75, 'step' => 'processing'],
        ]);

        $command = new QueryCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
            'query-name' => 'getStatus',
        ]));

        self::assertSame('/workflows/wf-123/query/getStatus', $client->lastPostPath);

        $display = $tester->getDisplay();

        self::assertStringContainsString('getStatus', $display);
        self::assertStringContainsString('progress', $display);
    }

    public function test_query_command_sends_input_when_provided(): void
    {
        $client = new HistoryQueryFakeClient([
            'workflow_id' => 'wf-123',
            'query_name' => 'getStatus',
            'result' => 'ok',
        ]);

        $command = new QueryCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
            'query-name' => 'getStatus',
            '--input' => '{"verbose": true}',
        ]));

        self::assertSame(['verbose' => true], $client->lastPostBody['input'] ?? null);
    }

    public function test_query_command_uses_run_targeted_path_when_run_id_is_provided(): void
    {
        $client = new HistoryQueryFakeClient([
            'workflow_id' => 'wf-123',
            'query_name' => 'getStatus',
            'result' => null,
        ]);

        $command = new QueryCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
            'query-name' => 'getStatus',
            '--run-id' => 'run-456',
        ]));

        self::assertSame('/workflows/wf-123/runs/run-456/query/getStatus', $client->lastPostPath);
    }
}

class HistoryQueryFakeClient extends ServerClient
{
    /** @var array<string, mixed> */
    public array $lastPostBody = [];

    public string $lastPostPath = '';

    /** @var array<string, mixed> */
    public array $lastGetQuery = [];

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly array $payload,
    ) {}

    public function get(string $path, array $query = []): array
    {
        $this->lastGetQuery = $query;

        return $this->payload;
    }

    public function post(string $path, array $body = []): array
    {
        $this->lastPostPath = $path;
        $this->lastPostBody = $body;

        return $this->payload;
    }
}

class HistoryQueryPaginatingClient extends ServerClient
{
    public int $getCalls = 0;

    /** @param list<array<string, mixed>> $pages */
    public function __construct(
        private readonly array $pages,
    ) {}

    public function get(string $path, array $query = []): array
    {
        $page = $this->pages[$this->getCalls] ?? end($this->pages);
        $this->getCalls++;

        return $page;
    }
}
