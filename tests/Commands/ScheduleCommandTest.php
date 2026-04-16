<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\ScheduleCommand\BackfillCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand\CreateCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand\DeleteCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand\DescribeCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand\ListCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand\PauseCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand\ResumeCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand\TriggerCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand\UpdateCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ScheduleCommandTest extends TestCase
{
    public function test_list_command_renders_schedules_in_a_table(): void
    {
        $command = new ListCommand();
        $command->setServerClient(new ScheduleFakeClient([
            'schedules' => [
                [
                    'schedule_id' => 'daily-report',
                    'workflow_type' => 'reports.daily',
                    'paused' => false,
                    'next_fire' => '2026-04-14T00:00:00Z',
                    'last_fire' => '2026-04-13T00:00:00Z',
                ],
                [
                    'schedule_id' => 'weekly-cleanup',
                    'workflow_type' => 'maintenance.cleanup',
                    'paused' => true,
                    'next_fire' => null,
                    'last_fire' => '2026-04-06T00:00:00Z',
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('daily-report', $display);
        self::assertStringContainsString('reports.daily', $display);
        self::assertStringContainsString('weekly-cleanup', $display);
        self::assertStringContainsString('paused', $display);
    }

    public function test_list_command_renders_json_output(): void
    {
        $command = new ListCommand();
        $command->setServerClient(new ScheduleFakeClient([
            'schedules' => [
                [
                    'schedule_id' => 'daily-report',
                    'workflow_type' => 'reports.daily',
                    'paused' => false,
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--json' => true,
        ]));

        $decoded = json_decode($tester->getDisplay(), true);

        self::assertIsArray($decoded);
        self::assertSame('daily-report', $decoded['schedules'][0]['schedule_id']);
    }

    public function test_create_command_sends_cron_schedule(): void
    {
        $client = new ScheduleFakeClient([
            'schedule_id' => 'daily-report',
        ]);

        $command = new CreateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--schedule-id' => 'daily-report',
            '--workflow-type' => 'reports.daily',
            '--cron' => '0 0 * * *',
            '--task-queue' => 'reports',
            '--timezone' => 'America/New_York',
            '--overlap-policy' => 'skip',
        ]));

        self::assertSame('/schedules', $client->lastPostPath);
        self::assertSame('daily-report', $client->lastPostBody['schedule_id']);
        self::assertSame(['cron_expressions' => ['0 0 * * *'], 'timezone' => 'America/New_York'], $client->lastPostBody['spec']);
        self::assertSame('reports.daily', $client->lastPostBody['action']['workflow_type']);
        self::assertSame('reports', $client->lastPostBody['action']['task_queue']);
        self::assertSame('skip', $client->lastPostBody['overlap_policy']);
        self::assertStringContainsString('daily-report', $tester->getDisplay());
    }

    public function test_create_command_sends_interval_schedule(): void
    {
        $client = new ScheduleFakeClient([
            'schedule_id' => 'heartbeat-check',
        ]);

        $command = new CreateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--workflow-type' => 'health.check',
            '--interval' => 'PT30M',
        ]));

        self::assertSame(['intervals' => [['every' => 'PT30M']], 'timezone' => 'UTC'], $client->lastPostBody['spec']);
        self::assertSame('health.check', $client->lastPostBody['action']['workflow_type']);
    }

    public function test_create_command_fails_without_cron_or_interval(): void
    {
        $client = new ScheduleFakeClient([]);

        $command = new CreateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--workflow-type' => 'reports.daily',
        ]));

        self::assertSame(0, $client->postCalls);
    }

    public function test_create_command_sends_paused_flag(): void
    {
        $client = new ScheduleFakeClient([
            'schedule_id' => 'paused-schedule',
        ]);

        $command = new CreateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--workflow-type' => 'reports.daily',
            '--cron' => '0 0 * * *',
            '--paused' => true,
        ]));

        self::assertTrue($client->lastPostBody['paused']);
    }

    public function test_describe_command_renders_schedule_details(): void
    {
        $command = new DescribeCommand();
        $command->setServerClient(new ScheduleFakeClient([
            'schedule_id' => 'daily-report',
            'spec' => ['cron_expressions' => ['0 0 * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'reports.daily', 'task_queue' => 'reports'],
            'overlap_policy' => 'skip',
            'paused' => false,
            'next_fire_at' => '2026-04-14T00:00:00Z',
            'fires_count' => 42,
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
        ]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('daily-report', $display);
        self::assertStringContainsString('skip', $display);
    }

    public function test_describe_command_renders_json_output(): void
    {
        $command = new DescribeCommand();
        $command->setServerClient(new ScheduleFakeClient([
            'schedule_id' => 'daily-report',
            'spec' => ['cron_expressions' => ['0 0 * * *']],
            'action' => ['workflow_type' => 'reports.daily'],
            'overlap_policy' => 'skip',
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
            '--json' => true,
        ]));

        $display = $tester->getDisplay();
        $decoded = json_decode($display, true);

        self::assertIsArray($decoded);
        self::assertSame('daily-report', $decoded['schedule_id']);
    }

    public function test_delete_command_sends_delete_request(): void
    {
        $client = new ScheduleFakeClient([]);

        $command = new DeleteCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
        ]));

        self::assertSame('/schedules/daily-report', $client->lastDeletePath);
        self::assertStringContainsString('daily-report', $tester->getDisplay());
    }

    public function test_pause_command_sends_post_with_optional_note(): void
    {
        $client = new ScheduleFakeClient([]);

        $command = new PauseCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
            '--note' => 'Pausing for maintenance',
        ]));

        self::assertSame('/schedules/daily-report/pause', $client->lastPostPath);
        self::assertStringContainsString('daily-report', $tester->getDisplay());
    }

    public function test_resume_command_sends_post_with_optional_note(): void
    {
        $client = new ScheduleFakeClient([]);

        $command = new ResumeCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
            '--note' => 'Maintenance complete',
        ]));

        self::assertSame('/schedules/daily-report/resume', $client->lastPostPath);
        self::assertStringContainsString('daily-report', $tester->getDisplay());
    }

    public function test_trigger_command_renders_triggered_outcome(): void
    {
        $command = new TriggerCommand();
        $command->setServerClient(new ScheduleFakeClient([
            'outcome' => 'triggered',
            'workflow_id' => 'wf-triggered-123',
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
        ]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('daily-report', $display);
        self::assertStringContainsString('wf-triggered-123', $display);
    }

    public function test_trigger_command_renders_buffered_outcome(): void
    {
        $command = new TriggerCommand();
        $command->setServerClient(new ScheduleFakeClient([
            'outcome' => 'buffered',
            'buffer_depth' => 3,
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
        ]));

        self::assertStringContainsString('buffered', $tester->getDisplay());
    }

    public function test_trigger_command_sends_overlap_policy_override(): void
    {
        $client = new ScheduleFakeClient([
            'outcome' => 'triggered',
            'workflow_id' => 'wf-1',
        ]);

        $command = new TriggerCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
            '--overlap-policy' => 'allow_all',
        ]));

        self::assertSame('allow_all', $client->lastPostBody['overlap_policy'] ?? null);
    }

    public function test_backfill_command_sends_time_range(): void
    {
        $client = new ScheduleFakeClient([]);

        $command = new BackfillCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
            '--start-time' => '2026-04-01T00:00:00Z',
            '--end-time' => '2026-04-10T00:00:00Z',
        ]));

        self::assertSame('/schedules/daily-report/backfill', $client->lastPostPath);
        self::assertSame('2026-04-01T00:00:00Z', $client->lastPostBody['start_time']);
        self::assertSame('2026-04-10T00:00:00Z', $client->lastPostBody['end_time']);
        self::assertStringContainsString('daily-report', $tester->getDisplay());
    }

    public function test_update_command_sends_cron_spec(): void
    {
        $client = new ScheduleFakeClient([
            'schedule_id' => 'daily-report',
            'outcome' => 'updated',
        ]);

        $command = new UpdateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
            '--cron' => '0 6 * * *',
        ]));

        self::assertSame('/schedules/daily-report', $client->lastPutPath);
        self::assertSame(['cron_expressions' => ['0 6 * * *']], $client->lastPutBody['spec']);
        self::assertStringContainsString('daily-report', $tester->getDisplay());
    }

    public function test_update_command_sends_overlap_policy_and_note(): void
    {
        $client = new ScheduleFakeClient([
            'schedule_id' => 'daily-report',
            'outcome' => 'updated',
        ]);

        $command = new UpdateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
            '--overlap-policy' => 'allow_all',
            '--note' => 'Relaxing overlap',
        ]));

        self::assertSame('allow_all', $client->lastPutBody['overlap_policy']);
        self::assertSame('Relaxing overlap', $client->lastPutBody['note']);
        self::assertArrayNotHasKey('spec', $client->lastPutBody);
        self::assertArrayNotHasKey('action', $client->lastPutBody);
    }

    public function test_update_command_sends_action_fields(): void
    {
        $client = new ScheduleFakeClient([
            'schedule_id' => 'daily-report',
            'outcome' => 'updated',
        ]);

        $command = new UpdateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
            '--workflow-type' => 'reports.weekly',
            '--task-queue' => 'reports-v2',
        ]));

        self::assertSame('reports.weekly', $client->lastPutBody['action']['workflow_type']);
        self::assertSame('reports-v2', $client->lastPutBody['action']['task_queue']);
    }

    public function test_create_command_sends_timeout_options(): void
    {
        $client = new ScheduleFakeClient([
            'schedule_id' => 'timeout-schedule',
        ]);

        $command = new CreateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--workflow-type' => 'reports.daily',
            '--cron' => '0 0 * * *',
            '--execution-timeout' => '300',
            '--run-timeout' => '120',
        ]));

        self::assertSame(300, $client->lastPostBody['action']['execution_timeout_seconds']);
        self::assertSame(120, $client->lastPostBody['action']['run_timeout_seconds']);
    }

    public function test_create_command_omits_timeouts_when_not_provided(): void
    {
        $client = new ScheduleFakeClient([
            'schedule_id' => 'no-timeout-schedule',
        ]);

        $command = new CreateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--workflow-type' => 'reports.daily',
            '--cron' => '0 0 * * *',
        ]));

        self::assertArrayNotHasKey('execution_timeout_seconds', $client->lastPostBody['action']);
        self::assertArrayNotHasKey('run_timeout_seconds', $client->lastPostBody['action']);
    }

    public function test_update_command_sends_timeout_options(): void
    {
        $client = new ScheduleFakeClient([
            'schedule_id' => 'daily-report',
            'outcome' => 'updated',
        ]);

        $command = new UpdateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
            '--execution-timeout' => '600',
            '--run-timeout' => '180',
        ]));

        self::assertSame(600, $client->lastPutBody['action']['execution_timeout_seconds']);
        self::assertSame(180, $client->lastPutBody['action']['run_timeout_seconds']);
    }

    public function test_update_command_fails_without_any_fields(): void
    {
        $client = new ScheduleFakeClient([]);

        $command = new UpdateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([
            'schedule-id' => 'daily-report',
        ]));

        self::assertNull($client->lastPutPath);
    }
}

class ScheduleFakeClient extends ServerClient
{
    /** @var array<string, mixed> */
    public array $lastPostBody = [];

    public string $lastPostPath = '';

    public string $lastDeletePath = '';

    /** @var array<string, mixed> */
    public array $lastPutBody = [];

    public ?string $lastPutPath = null;

    public int $postCalls = 0;

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
        $this->postCalls++;
        $this->lastPostPath = $path;
        $this->lastPostBody = $body;

        return $this->payload;
    }

    public function put(string $path, array $body = []): array
    {
        $this->lastPutPath = $path;
        $this->lastPutBody = $body;

        return $this->payload;
    }

    public function delete(string $path): array
    {
        $this->lastDeletePath = $path;

        return $this->payload;
    }
}
