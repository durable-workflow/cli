<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\SystemCommand\ActivityTimeoutPassCommand;
use DurableWorkflow\Cli\Commands\SystemCommand\ActivityTimeoutStatusCommand;
use DurableWorkflow\Cli\Commands\SystemCommand\OperatorMetricsCommand;
use DurableWorkflow\Cli\Commands\SystemCommand\RepairPassCommand;
use DurableWorkflow\Cli\Commands\SystemCommand\RepairStatusCommand;
use DurableWorkflow\Cli\Commands\SystemCommand\RetentionPassCommand;
use DurableWorkflow\Cli\Commands\SystemCommand\RetentionStatusCommand;
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

    // ── Retention Status ────────────────────────────────────────────

    public function test_retention_status_renders_diagnostics(): void
    {
        $command = new RetentionStatusCommand();
        $command->setServerClient(new SystemFakeClient([
            'namespace' => 'default',
            'retention_days' => 30,
            'cutoff' => '2026-03-15T00:00:00+00:00',
            'expired_run_count' => 5,
            'expired_run_ids' => ['run-1', 'run-2', 'run-3', 'run-4', 'run-5'],
            'scan_limit' => 100,
            'scan_pressure' => false,
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('default', $display);
        self::assertStringContainsString('30', $display);
        self::assertStringContainsString('5', $display);
        self::assertStringContainsString('run-1', $display);
    }

    public function test_retention_status_renders_json_output(): void
    {
        $payload = [
            'namespace' => 'default',
            'retention_days' => 30,
            'cutoff' => '2026-03-15T00:00:00+00:00',
            'expired_run_count' => 0,
            'expired_run_ids' => [],
            'scan_limit' => 100,
            'scan_pressure' => false,
        ];

        $command = new RetentionStatusCommand();
        $command->setServerClient(new SystemFakeClient($payload));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));

        $decoded = json_decode($tester->getDisplay(), true);

        self::assertIsArray($decoded);
        self::assertSame(30, $decoded['retention_days']);
        self::assertSame(0, $decoded['expired_run_count']);
    }

    // ── Retention Pass ──────────────────────────────────────────────

    public function test_retention_pass_renders_results(): void
    {
        $command = new RetentionPassCommand();
        $command->setServerClient(new SystemFakeClient([
            'processed' => 3,
            'pruned' => 2,
            'skipped' => 1,
            'failed' => 0,
            'results' => [
                ['run_id' => 'run-1', 'outcome' => 'pruned', 'history_events_deleted' => 50, 'tasks_deleted' => 3],
                ['run_id' => 'run-2', 'outcome' => 'pruned', 'history_events_deleted' => 20, 'tasks_deleted' => 1],
                ['run_id' => 'run-3', 'outcome' => 'skipped', 'reason' => 'run_not_terminal'],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('Processed:  3', $display);
        self::assertStringContainsString('Pruned:     2', $display);
        self::assertStringContainsString('Skipped:    1', $display);
    }

    public function test_retention_pass_sends_run_ids_and_limit(): void
    {
        $client = new SystemFakeClient([
            'processed' => 2,
            'pruned' => 0,
            'skipped' => 2,
            'failed' => 0,
            'results' => [],
        ]);

        $command = new RetentionPassCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--run-id' => ['run-1', 'run-2'],
            '--limit' => '50',
        ]));

        self::assertSame('/system/retention/pass', $client->lastPostPath);
        self::assertContains('run-1', $client->lastPostBody['run_ids'] ?? []);
        self::assertContains('run-2', $client->lastPostBody['run_ids'] ?? []);
        self::assertSame(50, $client->lastPostBody['limit'] ?? null);
    }

    public function test_retention_pass_returns_failure_on_errors(): void
    {
        $command = new RetentionPassCommand();
        $command->setServerClient(new SystemFakeClient([
            'processed' => 1,
            'pruned' => 0,
            'skipped' => 0,
            'failed' => 1,
            'results' => [
                ['run_id' => 'run-1', 'outcome' => 'error', 'reason' => 'Database connection lost'],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([]));

        self::assertStringContainsString('Database connection lost', $tester->getDisplay());
    }

    public function test_retention_pass_renders_json_output(): void
    {
        $payload = [
            'processed' => 1,
            'pruned' => 1,
            'skipped' => 0,
            'failed' => 0,
            'results' => [
                ['run_id' => 'run-1', 'outcome' => 'pruned', 'history_events_deleted' => 100, 'tasks_deleted' => 5],
            ],
        ];

        $command = new RetentionPassCommand();
        $command->setServerClient(new SystemFakeClient($payload));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));

        $decoded = json_decode($tester->getDisplay(), true);

        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['pruned']);
    }

    public function test_operator_metrics_command_renders_rollout_safety_signals(): void
    {
        $command = new OperatorMetricsCommand();
        $client = new SystemFakeClient(self::operatorMetricsPayload());
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertSame('/system/operator-metrics', $client->lastGetPath);

        self::assertStringContainsString('Operator metrics for namespace orders-prod', $display);
        self::assertStringContainsString('generated 2026-04-24T11:30:00Z', $display);

        self::assertStringContainsString('Runs', $display);
        self::assertStringContainsString('Repair needed:        4', $display);
        self::assertStringContainsString('Oldest repair-needed age: 375000 ms', $display);
        self::assertStringContainsString('Oldest repair-needed at:  2026-04-24T11:23:45Z', $display);
        self::assertStringContainsString('Claim failed:         2', $display);
        self::assertStringContainsString('Compatibility blocked: 1', $display);
        self::assertStringContainsString('Waiting (durable resume): 6', $display);
        self::assertStringContainsString('Oldest wait age:      285000 ms', $display);
        self::assertStringContainsString('Oldest wait started at: 2026-04-24T11:25:15Z', $display);

        self::assertStringContainsString('Queue depth:          9 ready (7 due), 3 delayed, 5 leased', $display);
        self::assertStringContainsString(
            'Unhealthy (duplicate-risk roll-up): 11 (dispatch failed 2, claim failed 3, dispatch overdue 4, lease expired 2)',
            $display,
        );
        self::assertStringContainsString('Oldest unhealthy age:     135000 ms', $display);
        self::assertStringContainsString('Oldest unhealthy at:      2026-04-24T11:27:45Z', $display);
        self::assertStringContainsString('Oldest lease-expired age: 95000 ms', $display);
        self::assertStringContainsString('Oldest lease expired at:  2026-04-24T11:28:25Z', $display);
        self::assertStringContainsString('Oldest ready-due age:     15000 ms', $display);
        self::assertStringContainsString('Oldest ready-due at:      2026-04-24T11:29:45Z', $display);
        self::assertStringContainsString('Oldest dispatch-overdue age: 55000 ms', $display);
        self::assertStringContainsString('Oldest dispatch-overdue since: 2026-04-24T11:29:05Z', $display);
        self::assertStringContainsString('Oldest claim-failed age:  65000 ms', $display);
        self::assertStringContainsString('Oldest claim failed at:   2026-04-24T11:28:55Z', $display);
        self::assertStringContainsString('Oldest dispatch-failed age: 135000 ms', $display);
        self::assertStringContainsString('Oldest dispatch failed at: 2026-04-24T11:27:45Z', $display);

        self::assertStringContainsString('Runnable tasks:       7', $display);
        self::assertStringContainsString('Delayed tasks:        3', $display);
        self::assertStringContainsString('Leased tasks:         5', $display);
        self::assertStringContainsString('Unhealthy tasks:      11', $display);
        self::assertStringContainsString('Compatibility blocked runs: 1', $display);
        self::assertStringContainsString('Oldest compatibility-blocked age: 185000 ms', $display);
        self::assertStringContainsString('Oldest compatibility-blocked at:  2026-04-24T11:26:55Z', $display);

        self::assertStringContainsString('Missing-task candidates: 3 (2 selected this pass)', $display);
        self::assertStringContainsString('Oldest missing-task age: 125000 ms', $display);
        self::assertStringContainsString('Oldest missing run at:   2026-04-24T11:27:55Z', $display);

        self::assertStringContainsString('Projection lag', $display);
        self::assertStringContainsString('Run-summary missing age: 330000 ms', $display);
        self::assertStringContainsString('Oldest run-summary missing run at: 2026-04-24T11:24:30Z', $display);

        self::assertStringContainsString('Required compatibility: build-2026.04.24', $display);
        self::assertStringContainsString('Active workers:         2 (2 queue scopes, 1 supporting required)', $display);
        self::assertStringNotContainsString(
            'No active worker supports the required compatibility marker',
            $display,
        );
        self::assertStringContainsString('worker-a', $display);
        self::assertStringContainsString('worker-b', $display);
        self::assertStringContainsString('build-2026.04.24', $display);

        self::assertStringContainsString('Supported:            yes', $display);
        self::assertStringContainsString('Severity:             ok', $display);
        self::assertStringContainsString('Database:              mysql/mysql', $display);
        self::assertStringContainsString('Cache:                 redis/redis', $display);

        self::assertStringContainsString('Matching-role (this node)', $display);
        self::assertStringContainsString('Queue wake enabled:   yes', $display);
        self::assertStringContainsString('Shape:                in_worker', $display);
        self::assertStringContainsString('Task dispatch mode:   queue', $display);
        self::assertStringContainsString('Partition primitives: connection, queue, compatibility, namespace', $display);
        self::assertStringContainsString('Backpressure model:  lease_ownership', $display);

        self::assertStringContainsString('Active 4, paused 1, missed 1, oldest overdue 5000 ms', $display);
        self::assertStringContainsString('Lifetime fires: 128 (3 failures)', $display);

        self::assertStringContainsString('Activities', $display);
        self::assertStringContainsString('Open 12 (pending 8, running 4), retrying 3', $display);
        self::assertStringContainsString('Oldest retrying age:  270000 ms', $display);
        self::assertStringContainsString('Oldest retrying started at: 2026-04-24T11:25:30Z', $display);
        self::assertStringContainsString('Timeout overdue:      2', $display);
        self::assertStringContainsString('Oldest timeout-overdue age: 345000 ms', $display);
        self::assertStringContainsString('Oldest timeout-overdue at:  2026-04-24T11:24:15Z', $display);
        self::assertStringContainsString('Failed attempts:      7 (max attempts on a single execution: 5)', $display);

        self::assertStringContainsString('Redispatch after:     120s', $display);
        self::assertStringContainsString('Loop throttle:        10s', $display);
        self::assertStringContainsString('Scan limit:           100', $display);
        self::assertStringContainsString('Backoff cap:          300s', $display);
    }

    public function test_operator_metrics_command_renders_json_output(): void
    {
        $command = new OperatorMetricsCommand();
        $command->setServerClient(new SystemFakeClient(self::operatorMetricsPayload()));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame('orders-prod', $decoded['namespace']);
        self::assertSame('build-2026.04.24', $decoded['operator_metrics']['workers']['required_compatibility']);
        self::assertSame(11, $decoded['operator_metrics']['tasks']['unhealthy']);
        self::assertSame(
            ['redispatch_after_seconds', 'loop_throttle_seconds', 'scan_limit', 'failure_backoff_max_seconds'],
            array_keys($decoded['operator_metrics']['repair_policy']),
        );
    }

    public function test_operator_metrics_command_warns_when_no_worker_supports_required_marker(): void
    {
        $payload = self::operatorMetricsPayload();
        $payload['operator_metrics']['workers']['active_workers'] = 1;
        $payload['operator_metrics']['workers']['active_workers_supporting_required'] = 0;

        $command = new OperatorMetricsCommand();
        $command->setServerClient(new SystemFakeClient($payload));

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertStringContainsString(
            'No active worker supports the required compatibility marker',
            $tester->getDisplay(),
        );
    }

    public function test_operator_metrics_schema_pins_compatibility_blocked_age_keys(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/operator-metrics.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $backlog = $schema['properties']['operator_metrics']['properties']['backlog']['properties'];

        self::assertSame(['string', 'null'], $backlog['oldest_compatibility_blocked_started_at']['type']);
        self::assertSame(['integer', 'null'], $backlog['max_compatibility_blocked_age_ms']['type']);
    }

    public function test_operator_metrics_schema_pins_stuck_lease_age_keys(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/operator-metrics.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $tasks = $schema['properties']['operator_metrics']['properties']['tasks']['properties'];

        self::assertSame(['string', 'null'], $tasks['oldest_lease_expired_at']['type']);
        self::assertSame(['integer', 'null'], $tasks['max_lease_expired_age_ms']['type']);
    }

    public function test_operator_metrics_schema_pins_ready_due_age_keys(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/operator-metrics.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $tasks = $schema['properties']['operator_metrics']['properties']['tasks']['properties'];

        self::assertSame(['string', 'null'], $tasks['oldest_ready_due_at']['type']);
        self::assertSame(['integer', 'null'], $tasks['max_ready_due_age_ms']['type']);
    }

    public function test_operator_metrics_schema_pins_dispatch_overdue_age_keys(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/operator-metrics.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $tasks = $schema['properties']['operator_metrics']['properties']['tasks']['properties'];

        self::assertSame(['string', 'null'], $tasks['oldest_dispatch_overdue_since']['type']);
        self::assertSame(['integer', 'null'], $tasks['max_dispatch_overdue_age_ms']['type']);
    }

    public function test_operator_metrics_schema_pins_claim_failed_age_keys(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/operator-metrics.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $tasks = $schema['properties']['operator_metrics']['properties']['tasks']['properties'];

        self::assertSame(['string', 'null'], $tasks['oldest_claim_failed_at']['type']);
        self::assertSame(['integer', 'null'], $tasks['max_claim_failed_age_ms']['type']);
    }

    public function test_operator_metrics_schema_pins_dispatch_failed_age_keys(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/operator-metrics.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $tasks = $schema['properties']['operator_metrics']['properties']['tasks']['properties'];

        self::assertSame(['string', 'null'], $tasks['oldest_dispatch_failed_at']['type']);
        self::assertSame(['integer', 'null'], $tasks['max_dispatch_failed_age_ms']['type']);
    }

    public function test_operator_metrics_schema_pins_unhealthy_age_keys(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/operator-metrics.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $tasks = $schema['properties']['operator_metrics']['properties']['tasks']['properties'];

        self::assertSame(['string', 'null'], $tasks['oldest_unhealthy_at']['type']);
        self::assertSame(['integer', 'null'], $tasks['max_unhealthy_age_ms']['type']);
    }

    public function test_operator_metrics_command_omits_unhealthy_age_when_snapshot_predates_contract(): void
    {
        $payload = self::operatorMetricsPayload();
        unset(
            $payload['operator_metrics']['tasks']['oldest_unhealthy_at'],
            $payload['operator_metrics']['tasks']['max_unhealthy_age_ms'],
        );

        $command = new OperatorMetricsCommand();
        $command->setServerClient(new SystemFakeClient($payload));

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringNotContainsString('Oldest unhealthy age:', $display);
        self::assertStringNotContainsString('Oldest unhealthy at:', $display);
        self::assertStringContainsString(
            'Unhealthy (duplicate-risk roll-up): 11',
            $display,
        );
    }

    public function test_operator_metrics_schema_pins_run_wait_age_keys(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/operator-metrics.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $runs = $schema['properties']['operator_metrics']['properties']['runs']['properties'];

        self::assertSame(['integer', 'null'], $runs['waiting']['type']);
        self::assertSame(['string', 'null'], $runs['oldest_wait_started_at']['type']);
        self::assertSame(['integer', 'null'], $runs['max_wait_age_ms']['type']);
    }

    public function test_operator_metrics_schema_pins_runs_repair_needed_age_keys(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/operator-metrics.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $runs = $schema['properties']['operator_metrics']['properties']['runs']['properties'];

        self::assertSame(['integer', 'null'], $runs['repair_needed']['type']);
        self::assertSame(['string', 'null'], $runs['oldest_repair_needed_at']['type']);
        self::assertSame(['integer', 'null'], $runs['max_repair_needed_age_ms']['type']);
    }

    public function test_operator_metrics_command_omits_runs_repair_needed_age_when_snapshot_predates_contract(): void
    {
        $payload = self::operatorMetricsPayload();
        unset(
            $payload['operator_metrics']['runs']['oldest_repair_needed_at'],
            $payload['operator_metrics']['runs']['max_repair_needed_age_ms'],
        );

        $command = new OperatorMetricsCommand();
        $command->setServerClient(new SystemFakeClient($payload));

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringNotContainsString('Oldest repair-needed age', $display);
        self::assertStringNotContainsString('Oldest repair-needed at', $display);
        self::assertStringContainsString('Repair needed:        4', $display);
    }

    public function test_operator_metrics_schema_pins_matching_role_keys(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/operator-metrics.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $matchingRole = $schema['properties']['operator_metrics']['properties']['matching_role']['properties'];

        self::assertSame(['boolean', 'null'], $matchingRole['queue_wake_enabled']['type']);
        self::assertSame(['string', 'null'], $matchingRole['shape']['type']);
        self::assertSame(['string', 'null'], $matchingRole['task_dispatch_mode']['type']);
        self::assertSame(['array', 'null'], $matchingRole['partition_primitives']['type']);
        self::assertSame('string', $matchingRole['partition_primitives']['items']['type']);
        self::assertSame(['string', 'null'], $matchingRole['backpressure_model']['type']);
    }

    public function test_operator_metrics_schema_pins_activities_retrying_age_keys(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/operator-metrics.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $activities = $schema['properties']['operator_metrics']['properties']['activities']['properties'];

        self::assertSame(['integer', 'null'], $activities['open']['type']);
        self::assertSame(['integer', 'null'], $activities['pending']['type']);
        self::assertSame(['integer', 'null'], $activities['running']['type']);
        self::assertSame(['integer', 'null'], $activities['retrying']['type']);
        self::assertSame(['string', 'null'], $activities['oldest_retrying_started_at']['type']);
        self::assertSame(['integer', 'null'], $activities['max_retrying_age_ms']['type']);
        self::assertSame(['integer', 'null'], $activities['failed_attempts']['type']);
        self::assertSame(['integer', 'null'], $activities['max_attempt_count']['type']);
    }

    public function test_operator_metrics_command_omits_claim_failed_age_when_snapshot_predates_contract(): void
    {
        $payload = self::operatorMetricsPayload();
        unset(
            $payload['operator_metrics']['tasks']['oldest_claim_failed_at'],
            $payload['operator_metrics']['tasks']['max_claim_failed_age_ms'],
        );

        $command = new OperatorMetricsCommand();
        $command->setServerClient(new SystemFakeClient($payload));

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringNotContainsString('Oldest claim-failed age', $display);
        self::assertStringNotContainsString('Oldest claim failed at', $display);
        self::assertStringContainsString('claim failed 3', $display);
    }

    public function test_operator_metrics_command_omits_dispatch_failed_age_when_snapshot_predates_contract(): void
    {
        $payload = self::operatorMetricsPayload();
        unset(
            $payload['operator_metrics']['tasks']['oldest_dispatch_failed_at'],
            $payload['operator_metrics']['tasks']['max_dispatch_failed_age_ms'],
        );

        $command = new OperatorMetricsCommand();
        $command->setServerClient(new SystemFakeClient($payload));

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringNotContainsString('Oldest dispatch-failed age', $display);
        self::assertStringNotContainsString('Oldest dispatch failed at', $display);
        self::assertStringContainsString('dispatch failed 2', $display);
    }

    public function test_operator_metrics_schema_pins_backend_severity_key(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/operator-metrics.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $backend = $schema['properties']['operator_metrics']['properties']['backend']['properties'];

        self::assertSame(['boolean', 'null'], $backend['supported']['type']);
        self::assertSame(['string', 'null'], $backend['severity']['type']);
    }

    public function test_operator_metrics_command_renders_backend_severity_rollup(): void
    {
        $payload = self::operatorMetricsPayload();
        $payload['operator_metrics']['backend'] = [
            'supported' => false,
            'severity' => 'error',
            'database' => ['connection' => 'mysql', 'driver' => 'mysql'],
            'queue' => ['connection' => 'sync', 'driver' => 'sync'],
            'cache' => ['store' => 'array', 'driver' => 'array'],
            'issues' => [
                [
                    'component' => 'queue',
                    'code' => 'queue_sync_unsupported',
                    'severity' => 'error',
                    'summary' => 'Workflow v2 cannot run on the sync queue connection.',
                ],
            ],
        ];

        $command = new OperatorMetricsCommand();
        $command->setServerClient(new SystemFakeClient($payload));

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('Supported:            no', $display);
        self::assertStringContainsString('Severity:             error', $display);
        self::assertStringContainsString('[error] Workflow v2 cannot run on the sync queue connection.', $display);
    }

    public function test_operator_metrics_command_omits_backend_severity_when_snapshot_predates_contract(): void
    {
        $payload = self::operatorMetricsPayload();
        unset($payload['operator_metrics']['backend']['severity']);

        $command = new OperatorMetricsCommand();
        $command->setServerClient(new SystemFakeClient($payload));

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringNotContainsString('Severity:', $display);
        self::assertStringContainsString('Supported:            yes', $display);
    }

    public function test_operator_metrics_schema_pins_run_summary_missing_age_keys(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/operator-metrics.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $runSummaries = $schema['properties']['operator_metrics']['properties']
            ['projections']['properties']['run_summaries']['properties'];

        self::assertSame(['string', 'null'], $runSummaries['oldest_missing_run_started_at']['type']);
        self::assertSame(['integer', 'null'], $runSummaries['max_missing_run_age_ms']['type']);
    }

    public function test_operator_metrics_command_omits_run_summary_missing_age_when_snapshot_predates_contract(): void
    {
        $payload = self::operatorMetricsPayload();
        unset($payload['operator_metrics']['projections']);

        $command = new OperatorMetricsCommand();
        $command->setServerClient(new SystemFakeClient($payload));

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringNotContainsString('Projection lag', $display);
        self::assertStringNotContainsString('Run-summary missing age', $display);
        self::assertStringNotContainsString('Oldest run-summary missing run at', $display);
        self::assertStringContainsString('Oldest missing-task age: 125000 ms', $display);
    }

    public function test_operator_metrics_command_omits_activities_block_when_payload_lacks_it(): void
    {
        $payload = self::operatorMetricsPayload();
        unset($payload['operator_metrics']['activities']);

        $command = new OperatorMetricsCommand();
        $command->setServerClient(new SystemFakeClient($payload));

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringNotContainsString('Activities', $display);
        self::assertStringNotContainsString('Oldest retrying age', $display);
    }

    public function test_operator_metrics_schema_pins_activities_timeout_overdue_keys(): void
    {
        $schema = json_decode(
            (string) file_get_contents(__DIR__.'/../../schemas/output/operator-metrics.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $activities = $schema['properties']['operator_metrics']['properties']['activities']['properties'];

        self::assertSame(['integer', 'null'], $activities['timeout_overdue']['type']);
        self::assertSame(['string', 'null'], $activities['oldest_timeout_overdue_at']['type']);
        self::assertSame(['integer', 'null'], $activities['max_timeout_overdue_age_ms']['type']);
    }

    public function test_operator_metrics_command_omits_activities_timeout_overdue_when_snapshot_predates_contract(): void
    {
        $payload = self::operatorMetricsPayload();
        unset(
            $payload['operator_metrics']['activities']['timeout_overdue'],
            $payload['operator_metrics']['activities']['oldest_timeout_overdue_at'],
            $payload['operator_metrics']['activities']['max_timeout_overdue_age_ms'],
        );

        $command = new OperatorMetricsCommand();
        $command->setServerClient(new SystemFakeClient($payload));

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringNotContainsString('Timeout overdue:', $display);
        self::assertStringNotContainsString('Oldest timeout-overdue age', $display);
        self::assertStringNotContainsString('Oldest timeout-overdue at', $display);
        self::assertStringContainsString('Open 12 (pending 8, running 4), retrying 3', $display);
        self::assertStringContainsString('Failed attempts:      7', $display);
    }

    public function test_operator_metrics_command_renders_dedicated_matching_role_shape(): void
    {
        $payload = self::operatorMetricsPayload();
        $payload['operator_metrics']['matching_role'] = [
            'queue_wake_enabled' => false,
            'shape' => 'dedicated',
            'task_dispatch_mode' => 'poll',
            'partition_primitives' => ['connection', 'queue', 'compatibility', 'namespace'],
            'backpressure_model' => 'lease_ownership',
        ];

        $command = new OperatorMetricsCommand();
        $command->setServerClient(new SystemFakeClient($payload));

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('Queue wake enabled:   no', $display);
        self::assertStringContainsString('Shape:                dedicated', $display);
        self::assertStringContainsString('Task dispatch mode:   poll', $display);
        self::assertStringContainsString('Partition primitives: connection, queue, compatibility, namespace', $display);
        self::assertStringContainsString('Backpressure model:  lease_ownership', $display);
    }

    public function test_operator_metrics_command_tolerates_minimal_payload(): void
    {
        $command = new OperatorMetricsCommand();
        $command->setServerClient(new SystemFakeClient([]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('Operator metrics', $display);
        self::assertStringContainsString('Repair needed:        0', $display);
        self::assertStringContainsString('Required compatibility: (unset)', $display);
    }

    /**
     * @return array<string, mixed>
     */
    private static function operatorMetricsPayload(): array
    {
        return [
            'namespace' => 'orders-prod',
            'operator_metrics' => [
                'generated_at' => '2026-04-24T11:30:00Z',
                'runs' => [
                    'repair_needed' => 4,
                    'oldest_repair_needed_at' => '2026-04-24T11:23:45Z',
                    'max_repair_needed_age_ms' => 375000,
                    'claim_failed' => 2,
                    'compatibility_blocked' => 1,
                    'waiting' => 6,
                    'oldest_wait_started_at' => '2026-04-24T11:25:15Z',
                    'max_wait_age_ms' => 285000,
                ],
                'tasks' => [
                    'ready' => 9,
                    'ready_due' => 7,
                    'delayed' => 3,
                    'leased' => 5,
                    'dispatch_failed' => 2,
                    'claim_failed' => 3,
                    'dispatch_overdue' => 4,
                    'lease_expired' => 2,
                    'oldest_lease_expired_at' => '2026-04-24T11:28:25Z',
                    'max_lease_expired_age_ms' => 95000,
                    'oldest_ready_due_at' => '2026-04-24T11:29:45Z',
                    'max_ready_due_age_ms' => 15000,
                    'oldest_dispatch_overdue_since' => '2026-04-24T11:29:05Z',
                    'max_dispatch_overdue_age_ms' => 55000,
                    'oldest_claim_failed_at' => '2026-04-24T11:28:55Z',
                    'max_claim_failed_age_ms' => 65000,
                    'oldest_dispatch_failed_at' => '2026-04-24T11:27:45Z',
                    'max_dispatch_failed_age_ms' => 135000,
                    'unhealthy' => 11,
                    'oldest_unhealthy_at' => '2026-04-24T11:27:45Z',
                    'max_unhealthy_age_ms' => 135000,
                ],
                'backlog' => [
                    'runnable_tasks' => 7,
                    'delayed_tasks' => 3,
                    'leased_tasks' => 5,
                    'unhealthy_tasks' => 11,
                    'repair_needed_runs' => 4,
                    'claim_failed_runs' => 2,
                    'compatibility_blocked_runs' => 1,
                    'oldest_compatibility_blocked_started_at' => '2026-04-24T11:26:55Z',
                    'max_compatibility_blocked_age_ms' => 185000,
                ],
                'repair' => [
                    'missing_task_candidates' => 3,
                    'selected_missing_task_candidates' => 2,
                    'oldest_missing_run_started_at' => '2026-04-24T11:27:55Z',
                    'max_missing_run_age_ms' => 125000,
                ],
                'workers' => [
                    'required_compatibility' => 'build-2026.04.24',
                    'active_workers' => 2,
                    'active_worker_scopes' => 2,
                    'active_workers_supporting_required' => 1,
                    'fleet' => [
                        [
                            'worker_id' => 'worker-a',
                            'connection' => 'mysql',
                            'queue' => 'default',
                            'supported' => ['build-2026.04.24'],
                            'supports_required' => true,
                            'recorded_at' => '2026-04-24T11:29:00Z',
                        ],
                        [
                            'worker_id' => 'worker-b',
                            'connection' => 'mysql',
                            'queue' => 'priority',
                            'supported' => ['build-2026.04.23'],
                            'supports_required' => false,
                            'recorded_at' => '2026-04-24T11:29:30Z',
                        ],
                    ],
                ],
                'backend' => [
                    'supported' => true,
                    'severity' => 'ok',
                    'database' => ['connection' => 'mysql', 'driver' => 'mysql'],
                    'queue' => ['connection' => 'redis', 'driver' => 'redis'],
                    'cache' => ['store' => 'redis', 'driver' => 'redis'],
                    'issues' => [],
                ],
                'schedules' => [
                    'active' => 4,
                    'paused' => 1,
                    'missed' => 1,
                    'oldest_overdue_at' => '2026-04-24T11:29:55Z',
                    'max_overdue_ms' => 5000,
                    'fires_total' => 128,
                    'failures_total' => 3,
                ],
                'activities' => [
                    'open' => 12,
                    'pending' => 8,
                    'running' => 4,
                    'retrying' => 3,
                    'oldest_retrying_started_at' => '2026-04-24T11:25:30Z',
                    'max_retrying_age_ms' => 270000,
                    'timeout_overdue' => 2,
                    'oldest_timeout_overdue_at' => '2026-04-24T11:24:15Z',
                    'max_timeout_overdue_age_ms' => 345000,
                    'failed_attempts' => 7,
                    'max_attempt_count' => 5,
                ],
                'repair_policy' => [
                    'redispatch_after_seconds' => 120,
                    'loop_throttle_seconds' => 10,
                    'scan_limit' => 100,
                    'failure_backoff_max_seconds' => 300,
                ],
                'matching_role' => [
                    'queue_wake_enabled' => true,
                    'shape' => 'in_worker',
                    'task_dispatch_mode' => 'queue',
                    'partition_primitives' => ['connection', 'queue', 'compatibility', 'namespace'],
                    'backpressure_model' => 'lease_ownership',
                ],
                'projections' => [
                    'run_summaries' => [
                        'oldest_missing_run_started_at' => '2026-04-24T11:24:30Z',
                        'max_missing_run_age_ms' => 330000,
                    ],
                ],
            ],
        ];
    }
}

class SystemFakeClient extends ServerClient
{
    /** @var array<string, mixed> */
    public array $lastPostBody = [];

    public string $lastPostPath = '';

    public string $lastGetPath = '';

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly array $payload,
    ) {}

    public function get(string $path, array $query = []): array
    {
        $this->lastGetPath = $path;

        return $this->payload;
    }

    public function post(string $path, array $body = []): array
    {
        $this->lastPostPath = $path;
        $this->lastPostBody = $body;

        return $this->payload;
    }
}
