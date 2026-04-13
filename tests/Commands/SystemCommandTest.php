<?php

declare(strict_types=1);

namespace Tests\Commands;

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
