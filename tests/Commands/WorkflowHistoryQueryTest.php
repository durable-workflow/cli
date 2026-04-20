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
                    'payload' => ['type' => 'orders.process'],
                ],
                [
                    'sequence' => 2,
                    'event_type' => 'activity_scheduled',
                    'timestamp' => '2026-04-13T00:00:01Z',
                    'payload' => ['activity_type' => 'send_email'],
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
                    'payload' => [],
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
                    ['sequence' => 1, 'event_type' => 'workflow_started', 'timestamp' => '2026-04-13T00:00:00Z', 'payload' => []],
                ],
                'next_page_token' => 'page-2',
            ],
            [
                'events' => [
                    ['sequence' => 2, 'event_type' => 'activity_scheduled', 'timestamp' => '2026-04-13T00:00:01Z', 'payload' => []],
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

    public function test_history_command_jsonl_streams_events_across_pages(): void
    {
        $client = new HistoryQueryPaginatingClient([
            [
                'events' => [
                    ['sequence' => 1, 'event_type' => 'workflow_started', 'timestamp' => '2026-04-13T00:00:00Z', 'payload' => []],
                    ['sequence' => 2, 'event_type' => 'activity_scheduled', 'timestamp' => '2026-04-13T00:00:01Z', 'payload' => []],
                ],
                'next_page_token' => 'page-2',
            ],
            [
                'events' => [
                    ['sequence' => 3, 'event_type' => 'activity_completed', 'timestamp' => '2026-04-13T00:00:02Z', 'payload' => []],
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
            '--output' => 'jsonl',
        ]));

        $lines = array_values(array_filter(
            explode("\n", $tester->getDisplay()),
            static fn (string $line): bool => $line !== '',
        ));

        self::assertCount(3, $lines, 'jsonl must emit one line per event across every page');
        self::assertSame(2, $client->getCalls, 'all history pages must be fetched');

        $events = array_map(
            static fn (string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        );

        self::assertSame([1, 2, 3], array_column($events, 'sequence'));
        self::assertSame(
            ['workflow_started', 'activity_scheduled', 'activity_completed'],
            array_column($events, 'event_type'),
        );

        foreach ($lines as $i => $line) {
            self::assertStringNotContainsString("\n", rtrim($line, "\n"), "line {$i} must not embed newlines");
        }
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
