<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WatchCommand;
use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class WatchCommandTest extends TestCase
{
    public function test_watch_workflow_prints_changed_fields_and_exits_success_on_completed_terminal(): void
    {
        $client = new WatchFakeClient([
            [
                'workflow_id' => 'wf-123',
                'run_id' => 'run-1',
                'status' => 'running',
                'status_bucket' => 'running',
                'is_terminal' => false,
                'last_progress_at' => '2026-04-18T00:00:00Z',
            ],
            [
                'workflow_id' => 'wf-123',
                'run_id' => 'run-1',
                'status' => 'completed',
                'status_bucket' => 'completed',
                'is_terminal' => true,
                'closed_at' => '2026-04-18T00:00:05Z',
                'closed_reason' => 'completed',
                'last_progress_at' => '2026-04-18T00:00:05Z',
                'output' => ['ok' => true],
            ],
        ]);

        $command = new WatchCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'resource' => 'workflow',
            'id' => 'wf-123',
            '--interval' => '0',
        ]));

        self::assertSame(['/workflows/wf-123', '/workflows/wf-123'], $client->getPaths);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Watching workflow wf-123 (status: running)', $display);
        self::assertStringContainsString('Change #2', $display);
        self::assertStringContainsString('status: running -> completed', $display);
        self::assertStringContainsString('status_bucket: running -> completed', $display);
        self::assertStringContainsString('is_terminal: false -> true', $display);
        self::assertStringContainsString('output: - -> {"ok":true}', $display);
        self::assertStringContainsString('Terminal state reached: completed', $display);
    }

    public function test_watch_workflow_uses_run_endpoint_when_run_id_is_provided(): void
    {
        $client = new WatchFakeClient([
            [
                'workflow_id' => 'wf-123',
                'run_id' => 'run-123',
                'status' => 'completed',
                'status_bucket' => 'completed',
                'is_terminal' => true,
            ],
        ]);

        $command = new WatchCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'resource' => 'workflow',
            'id' => 'wf-123',
            '--run-id' => 'run-123',
        ]));

        self::assertSame(['/workflows/wf-123/runs/run-123'], $client->getPaths);
        self::assertStringContainsString('Watching workflow wf-123 run run-123', $tester->getDisplay());
    }

    public function test_watch_returns_timeout_when_max_polls_is_reached_before_terminal(): void
    {
        $client = new WatchFakeClient([
            [
                'workflow_id' => 'wf-123',
                'status' => 'running',
                'status_bucket' => 'running',
                'is_terminal' => false,
            ],
        ]);

        $command = new WatchCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(ExitCode::TIMEOUT, $tester->execute([
            'resource' => 'workflow',
            'id' => 'wf-123',
            '--interval' => '0',
            '--max-polls' => '1',
        ]));

        self::assertSame(['/workflows/wf-123'], $client->getPaths);
        self::assertStringContainsString('Watch stopped after 1 poll before terminal state.', $tester->getDisplay());
    }

    public function test_watch_returns_failure_for_failed_terminal_state(): void
    {
        $client = new WatchFakeClient([
            [
                'workflow_id' => 'wf-123',
                'status' => 'failed',
                'status_bucket' => 'failed',
                'is_terminal' => true,
                'closed_reason' => 'activity_failed',
            ],
        ]);

        $command = new WatchCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([
            'resource' => 'workflow',
            'id' => 'wf-123',
        ]));

        self::assertStringContainsString('Terminal state reached: failed', $tester->getDisplay());
    }

    public function test_watch_rejects_invalid_resource_and_poll_options(): void
    {
        $command = new WatchCommand();
        $command->setServerClient(new WatchFakeClient([]));

        $tester = new CommandTester($command);

        self::assertSame(Command::INVALID, $tester->execute([
            'resource' => 'schedule',
            'id' => 'daily-report',
        ]));

        self::assertStringContainsString('Unsupported watch resource [schedule]', $tester->getDisplay());

        $tester = new CommandTester($command);

        self::assertSame(Command::INVALID, $tester->execute([
            'resource' => 'workflow',
            'id' => 'wf-123',
            '--interval' => 'fast',
        ]));

        self::assertStringContainsString('--interval must be a non-negative integer.', $tester->getDisplay());
    }
}

class WatchFakeClient extends ServerClient
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $payloads;

    /**
     * @var list<string>
     */
    public array $getPaths = [];

    /**
     * @param list<array<string, mixed>> $payloads
     */
    public function __construct(array $payloads)
    {
        $this->payloads = $payloads;
    }

    public function get(string $path, array $query = []): array
    {
        $this->getPaths[] = $path;

        if ($this->payloads === []) {
            return [];
        }

        if (count($this->payloads) === 1) {
            return $this->payloads[0];
        }

        return array_shift($this->payloads);
    }
}
