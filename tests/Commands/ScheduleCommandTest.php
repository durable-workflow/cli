<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\ScheduleCommand\BackfillCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand\CreateCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand\DeleteCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand\DescribeCommand;
use DurableWorkflow\Cli\Commands\ScheduleCommand\HistoryCommand;
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

    public function test_create_command_reads_input_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dw-cli-input-');
        self::assertIsString($path);
        file_put_contents($path, '["daily"]');

        try {
            $client = new ScheduleFakeClient([
                'schedule_id' => 'daily-report',
            ]);

            $command = new CreateCommand();
            $command->setServerClient($client);

            $tester = new CommandTester($command);

            self::assertSame(Command::SUCCESS, $tester->execute([
                '--workflow-type' => 'reports.daily',
                '--cron' => '0 0 * * *',
                '--input-file' => $path,
            ]));

            self::assertSame(['daily'], $client->lastPostBody['action']['input']);
        } finally {
            @unlink($path);
        }
    }

    public function test_create_command_fails_without_cron_or_interval(): void
    {
        $client = new ScheduleFakeClient([]);

        $command = new CreateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        // Local validation — missing cron/interval is a usage error (exit 2)
        // rather than a generic failure, so scripts can distinguish invalid
        // input from server-side failure.
        self::assertSame(Command::INVALID, $tester->execute([
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

    public function test_trigger_command_returns_failure_for_trigger_failed_outcome(): void
    {
        $client = new ScheduleFakeClient([
            'outcome' => 'trigger_failed',
            'reason' => 'backend unavailable',
        ]);

        $command = new TriggerCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([
            'schedule-id' => 'daily-report',
        ]));
    }

    public function test_trigger_command_returns_failure_on_unknown_outcome(): void
    {
        $client = new ScheduleFakeClient([
            'outcome' => 'something-we-did-not-anticipate',
        ]);

        $command = new TriggerCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([
            'schedule-id' => 'daily-report',
        ]));
    }

    public function test_trigger_command_json_mode_respects_outcome_exit_code(): void
    {
        // Regression: --json used to unconditionally exit 0 because
        // renderJson() always returned SUCCESS. The JSON and human-readable
        // paths must agree on the exit code so CI scripts can parse stdout
        // AND key on $?.
        $client = new ScheduleFakeClient([
            'outcome' => 'trigger_failed',
            'reason' => 'backend unavailable',
        ]);

        $command = new TriggerCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([
            'schedule-id' => 'daily-report',
            '--json' => true,
        ]));

        // The raw server response is still rendered to stdout for scripting.
        self::assertStringContainsString('trigger_failed', $tester->getDisplay());
    }

    public function test_create_command_rejects_malformed_input_json(): void
    {
        $client = new ScheduleFakeClient([]);

        $command = new CreateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        // Malformed JSON on --input is a local validation error (exit 2),
        // not a silent null decode that reaches the server.
        self::assertSame(Command::INVALID, $tester->execute([
            '--schedule-id' => 'whatever',
            '--workflow-type' => 'reports.daily',
            '--cron' => '0 6 * * *',
            '--input' => '{not-json',
        ]));

        self::assertSame(0, $client->postCalls);
        self::assertStringContainsString('--input must be valid JSON', $tester->getDisplay());
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

    public function test_update_command_sends_input_as_action_field(): void
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
            '--input' => '{"tenant":"acme"}',
        ]));

        self::assertSame(['tenant' => 'acme'], $client->lastPutBody['action']['input']);
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

        // Local validation — schedule:update with no --cron/--interval/etc
        // is a usage error (exit 2), not a generic failure.
        self::assertSame(Command::INVALID, $tester->execute([
            'schedule-id' => 'daily-report',
        ]));

        self::assertNull($client->lastPutPath);
    }

    public function test_history_command_renders_events_in_a_table(): void
    {
        $client = new ScheduleFakeClient([
            'schedule_id' => 'daily-report',
            'namespace' => 'default',
            'events' => [
                [
                    'id' => 'evt-1',
                    'sequence' => 1,
                    'event_type' => 'ScheduleCreated',
                    'recorded_at' => '2026-04-01T00:00:00+00:00',
                    'workflow_instance_id' => null,
                    'workflow_run_id' => null,
                    'payload' => [],
                ],
                [
                    'id' => 'evt-2',
                    'sequence' => 2,
                    'event_type' => 'ScheduleTriggered',
                    'recorded_at' => '2026-04-02T00:00:00+00:00',
                    'workflow_instance_id' => 'wf-abc',
                    'workflow_run_id' => 'run-abc',
                    'payload' => ['outcome' => 'triggered'],
                ],
            ],
            'has_more' => false,
            'next_cursor' => null,
        ]);

        $command = new HistoryCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
        ]));

        self::assertSame('/schedules/daily-report/history', $client->lastGetPath);
        self::assertSame([], $client->lastGetQuery);

        $display = $tester->getDisplay();
        self::assertStringContainsString('ScheduleCreated', $display);
        self::assertStringContainsString('ScheduleTriggered', $display);
        self::assertStringContainsString('wf=wf-abc', $display);
        self::assertStringContainsString('run=run-abc', $display);
    }

    public function test_history_command_forwards_limit_and_after_sequence(): void
    {
        $client = new ScheduleFakeClient([
            'schedule_id' => 'daily-report',
            'namespace' => 'default',
            'events' => [],
            'has_more' => false,
            'next_cursor' => null,
        ]);

        $command = new HistoryCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
            '--limit' => '50',
            '--after-sequence' => '7',
        ]));

        self::assertSame('/schedules/daily-report/history', $client->lastGetPath);
        self::assertSame(['limit' => '50', 'after_sequence' => '7'], $client->lastGetQuery);
    }

    public function test_history_command_surfaces_has_more_hint(): void
    {
        $client = new ScheduleFakeClient([
            'schedule_id' => 'daily-report',
            'namespace' => 'default',
            'events' => [
                [
                    'sequence' => 1,
                    'event_type' => 'SchedulePaused',
                    'recorded_at' => '2026-04-01T00:00:00+00:00',
                    'payload' => [],
                ],
            ],
            'has_more' => true,
            'next_cursor' => 1,
        ]);

        $command = new HistoryCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
        ]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('More events available', $display);
        self::assertStringContainsString('--after-sequence=1', $display);
    }

    public function test_history_command_fetches_all_pages_with_all_flag(): void
    {
        $client = new ScheduleFakeClient([]);
        $client->queueGetResponses([
            [
                'schedule_id' => 'daily-report',
                'namespace' => 'default',
                'events' => [
                    ['sequence' => 1, 'event_type' => 'ScheduleCreated', 'recorded_at' => '2026-04-01T00:00:00+00:00', 'payload' => []],
                    ['sequence' => 2, 'event_type' => 'SchedulePaused', 'recorded_at' => '2026-04-02T00:00:00+00:00', 'payload' => []],
                ],
                'has_more' => true,
                'next_cursor' => 2,
            ],
            [
                'schedule_id' => 'daily-report',
                'namespace' => 'default',
                'events' => [
                    ['sequence' => 3, 'event_type' => 'ScheduleResumed', 'recorded_at' => '2026-04-03T00:00:00+00:00', 'payload' => []],
                ],
                'has_more' => false,
                'next_cursor' => null,
            ],
        ]);

        $command = new HistoryCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
            '--all' => true,
            '--json' => true,
        ]));

        self::assertCount(2, $client->getCalls);
        self::assertSame(['after_sequence' => '2'], $client->getCalls[1]['query']);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertCount(3, $decoded['events']);
        self::assertSame(false, $decoded['has_more']);
        self::assertSame([1, 2, 3], array_column($decoded['events'], 'sequence'));
    }

    public function test_history_command_emits_jsonl_one_event_per_line(): void
    {
        $client = new ScheduleFakeClient([
            'schedule_id' => 'daily-report',
            'namespace' => 'default',
            'events' => [
                ['sequence' => 1, 'event_type' => 'ScheduleCreated', 'recorded_at' => '2026-04-01T00:00:00+00:00', 'payload' => []],
                ['sequence' => 2, 'event_type' => 'ScheduleDeleted', 'recorded_at' => '2026-04-02T00:00:00+00:00', 'payload' => []],
            ],
            'has_more' => false,
            'next_cursor' => null,
        ]);

        $command = new HistoryCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
            '--output' => 'jsonl',
        ]));

        $lines = array_values(array_filter(
            preg_split('/\r?\n/', trim($tester->getDisplay())),
            static fn (string $l): bool => $l !== '',
        ));

        self::assertCount(2, $lines);

        $first = json_decode($lines[0], true);
        $second = json_decode($lines[1], true);

        self::assertSame('ScheduleCreated', $first['event_type']);
        self::assertSame('ScheduleDeleted', $second['event_type']);
    }

    public function test_history_command_rejects_invalid_limit(): void
    {
        $command = new HistoryCommand();
        $command->setServerClient(new ScheduleFakeClient([]));

        $tester = new CommandTester($command);

        self::assertSame(Command::INVALID, $tester->execute([
            'schedule-id' => 'daily-report',
            '--limit' => '0',
        ]));

        self::assertSame(Command::INVALID, $tester->execute([
            'schedule-id' => 'daily-report',
            '--limit' => '9999',
        ]));

        self::assertSame(Command::INVALID, $tester->execute([
            'schedule-id' => 'daily-report',
            '--limit' => 'not-a-number',
        ]));
    }

    public function test_history_command_rejects_invalid_after_sequence(): void
    {
        $command = new HistoryCommand();
        $command->setServerClient(new ScheduleFakeClient([]));

        $tester = new CommandTester($command);

        self::assertSame(Command::INVALID, $tester->execute([
            'schedule-id' => 'daily-report',
            '--after-sequence' => '-3',
        ]));

        self::assertSame(Command::INVALID, $tester->execute([
            'schedule-id' => 'daily-report',
            '--after-sequence' => 'not-a-number',
        ]));
    }

    public function test_history_command_notes_empty_audit_stream(): void
    {
        $client = new ScheduleFakeClient([
            'schedule_id' => 'daily-report',
            'namespace' => 'default',
            'events' => [],
            'has_more' => false,
            'next_cursor' => null,
        ]);

        $command = new HistoryCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'schedule-id' => 'daily-report',
        ]));

        self::assertStringContainsString('No audit events recorded', $tester->getDisplay());
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

    public ?string $lastGetPath = null;

    /** @var array<string, mixed> */
    public array $lastGetQuery = [];

    /** @var list<array<string, array<string, mixed>>> */
    public array $getCalls = [];

    /** @var list<array<string, mixed>> */
    private array $getResponseQueue;

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly array $payload,
    ) {
        $this->getResponseQueue = [];
    }

    /**
     * @param  list<array<string, mixed>>  $responses
     */
    public function queueGetResponses(array $responses): void
    {
        $this->getResponseQueue = array_values($responses);
    }

    public function get(string $path, array $query = []): array
    {
        $this->lastGetPath = $path;
        $this->lastGetQuery = $query;
        $this->getCalls[] = ['path' => $path, 'query' => $query];

        if ($this->getResponseQueue !== []) {
            return array_shift($this->getResponseQueue);
        }

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
