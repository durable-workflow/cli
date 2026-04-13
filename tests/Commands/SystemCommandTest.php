<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\SystemCommand\ActivityTimeoutPassCommand;
use DurableWorkflow\Cli\Commands\SystemCommand\ActivityTimeoutStatusCommand;
use DurableWorkflow\Cli\Commands\SystemCommand\RepairPassCommand;
use DurableWorkflow\Cli\Commands\SystemCommand\RepairStatusCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SystemCommandTest extends TestCase
{
    public function test_repair_status_command_renders_policy_and_candidates(): void
    {
        $command = new RepairStatusCommand();
        $command->setServerClient(new SystemFakeClient([
            'policy' => [
                'redispatch_after_seconds' => 120,
                'loop_throttle_seconds' => 10,
                'scan_limit' => 100,
                'scan_strategy' => 'oldest_first',
                'failure_backoff_max_seconds' => 300,
            ],
            'candidates' => [
                'total_candidates' => 5,
                'existing_task_candidates' => 3,
                'missing_task_candidates' => 2,
                'scan_pressure' => 'low',
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('120', $display);
        self::assertStringContainsString('oldest_first', $display);
        self::assertStringContainsString('5', $display);
    }

    public function test_repair_status_command_renders_json_output(): void
    {
        $payload = [
            'policy' => [
                'redispatch_after_seconds' => 120,
                'scan_limit' => 100,
            ],
            'candidates' => [
                'total_candidates' => 5,
            ],
        ];

        $command = new RepairStatusCommand();
        $command->setServerClient(new SystemFakeClient($payload));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--json' => true,
        ]));

        $decoded = json_decode($tester->getDisplay(), true);

        self::assertIsArray($decoded);
        self::assertSame(120, $decoded['policy']['redispatch_after_seconds']);
    }

    public function test_repair_pass_command_renders_repair_results(): void
    {
        $command = new RepairPassCommand();
        $command->setServerClient(new SystemFakeClient([
            'selected_existing_task_candidates' => 3,
            'selected_missing_task_candidates' => 2,
            'repaired_existing_tasks' => 2,
            'repaired_missing_tasks' => 1,
            'dispatched_tasks' => 3,
            'selected_command_contract_candidates' => 1,
            'backfilled_command_contracts' => 0,
            'command_contract_backfill_unavailable' => 0,
            'existing_task_failures' => [],
            'missing_run_failures' => [],
            'command_contract_failures' => [],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('3 existing task', $display);
        self::assertStringContainsString('2 existing', $display);
    }

    public function test_repair_pass_command_sends_run_ids_and_instance_id(): void
    {
        $client = new SystemFakeClient([
            'selected_existing_task_candidates' => 0,
            'selected_missing_task_candidates' => 0,
            'repaired_existing_tasks' => 0,
            'repaired_missing_tasks' => 0,
            'dispatched_tasks' => 0,
            'selected_command_contract_candidates' => 0,
            'backfilled_command_contracts' => 0,
            'command_contract_backfill_unavailable' => 0,
            'existing_task_failures' => [],
            'missing_run_failures' => [],
            'command_contract_failures' => [],
        ]);

        $command = new RepairPassCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--run-id' => ['run-1', 'run-2'],
            '--instance-id' => 'wf-123',
        ]));

        self::assertSame('/system/repair/pass', $client->lastPostPath);
        self::assertContains('run-1', $client->lastPostBody['run_ids'] ?? []);
        self::assertContains('run-2', $client->lastPostBody['run_ids'] ?? []);
        self::assertSame('wf-123', $client->lastPostBody['instance_id'] ?? null);
    }

    public function test_repair_pass_command_renders_json_output(): void
    {
        $payload = [
            'selected_existing_task_candidates' => 1,
            'selected_missing_task_candidates' => 0,
            'repaired_existing_tasks' => 1,
            'repaired_missing_tasks' => 0,
            'dispatched_tasks' => 1,
            'selected_command_contract_candidates' => 0,
            'backfilled_command_contracts' => 0,
            'command_contract_backfill_unavailable' => 0,
            'existing_task_failures' => [],
            'missing_run_failures' => [],
            'command_contract_failures' => [],
        ];

        $command = new RepairPassCommand();
        $command->setServerClient(new SystemFakeClient($payload));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--json' => true,
        ]));

        $decoded = json_decode($tester->getDisplay(), true);

        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['repaired_existing_tasks']);
    }

    public function test_repair_pass_command_returns_failure_when_errors_exist(): void
    {
        $command = new RepairPassCommand();
        $command->setServerClient(new SystemFakeClient([
            'selected_existing_task_candidates' => 1,
            'selected_missing_task_candidates' => 0,
            'repaired_existing_tasks' => 0,
            'repaired_missing_tasks' => 0,
            'dispatched_tasks' => 0,
            'selected_command_contract_candidates' => 0,
            'backfilled_command_contracts' => 0,
            'command_contract_backfill_unavailable' => 0,
            'existing_task_failures' => [
                ['candidate_id' => 'task-1', 'message' => 'Dispatch failed'],
            ],
            'missing_run_failures' => [],
            'command_contract_failures' => [],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([]));

        self::assertStringContainsString('Dispatch failed', $tester->getDisplay());
    }
    // ── Activity Timeout Status ──────────────────────────────────────

    public function test_activity_timeout_status_renders_diagnostics(): void
    {
        $command = new ActivityTimeoutStatusCommand();
        $command->setServerClient(new SystemFakeClient([
            'expired_count' => 3,
            'expired_execution_ids' => ['exec-1', 'exec-2', 'exec-3'],
            'scan_limit' => 100,
            'scan_pressure' => false,
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('3', $display);
        self::assertStringContainsString('exec-1', $display);
        self::assertStringContainsString('exec-2', $display);
        self::assertStringContainsString('100', $display);
    }

    public function test_activity_timeout_status_renders_json_output(): void
    {
        $payload = [
            'expired_count' => 0,
            'expired_execution_ids' => [],
            'scan_limit' => 100,
            'scan_pressure' => false,
        ];

        $command = new ActivityTimeoutStatusCommand();
        $command->setServerClient(new SystemFakeClient($payload));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));

        $decoded = json_decode($tester->getDisplay(), true);

        self::assertIsArray($decoded);
        self::assertSame(0, $decoded['expired_count']);
    }

    // ── Activity Timeout Pass ──────────────────────────────────────

    public function test_activity_timeout_pass_renders_results(): void
    {
        $command = new ActivityTimeoutPassCommand();
        $command->setServerClient(new SystemFakeClient([
            'processed' => 2,
            'enforced' => 1,
            'skipped' => 1,
            'failed' => 0,
            'results' => [
                ['execution_id' => 'exec-1', 'outcome' => 'enforced', 'has_retry' => true],
                ['execution_id' => 'exec-2', 'outcome' => 'skipped', 'reason' => 'no_deadline_expired'],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('Processed:  2', $display);
        self::assertStringContainsString('Enforced:   1', $display);
        self::assertStringContainsString('Skipped:    1', $display);
    }

    public function test_activity_timeout_pass_sends_execution_ids(): void
    {
        $client = new SystemFakeClient([
            'processed' => 1,
            'enforced' => 0,
            'skipped' => 1,
            'failed' => 0,
            'results' => [],
        ]);

        $command = new ActivityTimeoutPassCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--execution-id' => ['exec-1', 'exec-2'],
            '--limit' => '50',
        ]));

        self::assertSame('/system/activity-timeouts/pass', $client->lastPostPath);
        self::assertContains('exec-1', $client->lastPostBody['execution_ids'] ?? []);
        self::assertContains('exec-2', $client->lastPostBody['execution_ids'] ?? []);
        self::assertSame(50, $client->lastPostBody['limit'] ?? null);
    }

    public function test_activity_timeout_pass_returns_failure_on_errors(): void
    {
        $command = new ActivityTimeoutPassCommand();
        $command->setServerClient(new SystemFakeClient([
            'processed' => 1,
            'enforced' => 0,
            'skipped' => 0,
            'failed' => 1,
            'results' => [
                ['execution_id' => 'exec-1', 'outcome' => 'error', 'reason' => 'Something went wrong'],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([]));

        self::assertStringContainsString('Something went wrong', $tester->getDisplay());
    }

    public function test_activity_timeout_pass_renders_json_output(): void
    {
        $payload = [
            'processed' => 1,
            'enforced' => 1,
            'skipped' => 0,
            'failed' => 0,
            'results' => [
                ['execution_id' => 'exec-1', 'outcome' => 'enforced', 'has_retry' => false],
            ],
        ];

        $command = new ActivityTimeoutPassCommand();
        $command->setServerClient(new SystemFakeClient($payload));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));

        $decoded = json_decode($tester->getDisplay(), true);

        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['enforced']);
    }
}

class SystemFakeClient extends ServerClient
{
    /** @var array<string, mixed> */
    public array $lastPostBody = [];

    public string $lastPostPath = '';

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly array $payload,
    ) {}

    public function get(string $path, array $query = []): array
    {
        return $this->payload;
    }

    public function post(string $path, array $body = []): array
    {
        $this->lastPostPath = $path;
        $this->lastPostBody = $body;

        return $this->payload;
    }
}
