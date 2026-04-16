<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Application;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Every mutating (POST / PUT / DELETE) command must honour --json so
 * pipelines can consume the raw server response instead of the
 * human-readable summary. A stub client echoes a sentinel response and
 * the command should print it verbatim when --json is set.
 */
class MutatingCommandJsonTest extends TestCase
{
    /**
     * @dataProvider mutatingCommandCases
     *
     * @param  array<string, mixed>  $invocation
     */
    public function test_mutating_command_emits_json_when_requested(
        string $commandName,
        array $invocation,
    ): void {
        $application = new Application();
        $application->setAutoExit(false);

        $command = $application->find($commandName);
        $client = new StubServerClient();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        $tester->execute(array_merge($invocation, ['--json' => true]));

        $display = trim($tester->getDisplay());
        self::assertJson(
            $display,
            sprintf('Expected %s with --json to emit JSON output. Got: %s', $commandName, $display),
        );

        $decoded = json_decode($display, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, "{$commandName} JSON output must decode to an array");
        self::assertSame('stub', $decoded['sentinel'] ?? null, "{$commandName} did not return the stub sentinel");
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function mutatingCommandCases(): iterable
    {
        yield 'workflow:archive' => ['workflow:archive', ['workflow-id' => 'wf-1']];
        yield 'workflow:cancel' => ['workflow:cancel', ['workflow-id' => 'wf-1']];
        yield 'workflow:repair' => ['workflow:repair', ['workflow-id' => 'wf-1']];
        yield 'workflow:signal' => ['workflow:signal', ['workflow-id' => 'wf-1', 'signal-name' => 'approve']];
        yield 'workflow:terminate' => ['workflow:terminate', ['workflow-id' => 'wf-1']];
        yield 'workflow:query' => ['workflow:query', ['workflow-id' => 'wf-1', 'query-name' => 'status']];

        yield 'activity:complete' => [
            'activity:complete',
            ['task-id' => 'task-1', 'attempt-id' => 'att-1'],
        ];
        yield 'activity:fail' => [
            'activity:fail',
            ['task-id' => 'task-1', 'attempt-id' => 'att-1', '--message' => 'boom'],
        ];

        yield 'namespace:create' => ['namespace:create', ['name' => 'ns-1']];
        yield 'namespace:update' => ['namespace:update', ['name' => 'ns-1', '--retention' => '7']];

        yield 'worker:deregister' => ['worker:deregister', ['worker-id' => 'w-1']];

        yield 'search-attribute:create' => [
            'search-attribute:create',
            ['name' => 'Attr', 'type' => 'keyword'],
        ];
        yield 'search-attribute:delete' => ['search-attribute:delete', ['name' => 'Attr']];

        yield 'schedule:delete' => ['schedule:delete', ['schedule-id' => 's-1']];
        yield 'schedule:pause' => ['schedule:pause', ['schedule-id' => 's-1']];
        yield 'schedule:resume' => ['schedule:resume', ['schedule-id' => 's-1']];
        yield 'schedule:trigger' => ['schedule:trigger', ['schedule-id' => 's-1']];
        yield 'schedule:backfill' => [
            'schedule:backfill',
            [
                'schedule-id' => 's-1',
                '--start-time' => '2026-01-01T00:00:00Z',
                '--end-time' => '2026-01-02T00:00:00Z',
            ],
        ];
        yield 'schedule:create' => [
            'schedule:create',
            [
                '--schedule-id' => 's-1',
                '--workflow-type' => 'demo.Workflow',
                '--cron' => '0 * * * *',
            ],
        ];
        yield 'schedule:update' => [
            'schedule:update',
            [
                'schedule-id' => 's-1',
                '--cron' => '*/5 * * * *',
            ],
        ];

        yield 'workflow:start' => [
            'workflow:start',
            ['--type' => 'demo.Workflow'],
        ];
    }
}

/**
 * A minimal ServerClient replacement that returns a sentinel payload
 * regardless of method or path. Used to verify --json behaviour without
 * hitting a real server.
 */
class StubServerClient extends ServerClient
{
    public function __construct() {}

    public function get(string $path, array $query = []): array
    {
        return $this->payload();
    }

    public function post(string $path, array $body = []): array
    {
        return $this->payload();
    }

    public function put(string $path, array $body = []): array
    {
        return $this->payload();
    }

    public function delete(string $path): array
    {
        return $this->payload();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'sentinel' => 'stub',
            'workflow_id' => 'wf-1',
            'schedule_id' => 's-1',
            'signal_name' => 'approve',
            'update_name' => 'noop',
            'update_id' => 'upd-1',
            'activity_attempt_id' => 'att-1',
            'task_id' => 'task-1',
            'run_id' => 'run-1',
            'outcome' => 'triggered',
            'name' => 'stub-name',
            'type' => 'keyword',
            'worker_id' => 'w-1',
            'result' => ['stub' => true],
        ];
    }
}
