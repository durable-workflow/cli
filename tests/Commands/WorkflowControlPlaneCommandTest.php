<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowCommand\ArchiveCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\CancelCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\RepairCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\RedriveCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\SignalCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\TerminateCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\UpdateCommand;
use DurableWorkflow\Cli\Support\ControlPlaneRequestContract;
use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\ServerClient;
use DurableWorkflow\Cli\Support\ServerHttpException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class WorkflowControlPlaneCommandTest extends TestCase
{
    public function test_signal_command_uses_the_canonical_signal_name_field(): void
    {
        $command = new SignalCommand();
        $command->setServerClient(new FakeServerClient([
            'workflow_id' => 'wf-123',
            'signal_name' => 'advance',
            'outcome' => 'signal_received',
            'command_status' => 'accepted',
            'command_id' => 'cmd-1',
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
            'signal-name' => 'advance',
        ]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('Signal: advance', $display);
        self::assertStringContainsString('Command Status: accepted', $display);
    }

    public function test_update_command_sends_wait_for_and_renders_the_canonical_update_name_field(): void
    {
        $client = new FakeServerClient([
            'workflow_id' => 'wf-123',
            'update_name' => 'approve',
            'update_id' => 'upd-1',
            'outcome' => 'update_completed',
            'command_status' => 'accepted',
            'update_status' => 'completed',
            'wait_for' => 'completed',
        ], self::requestContract());

        $command = new UpdateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
            'update-name' => 'approve',
            '--wait' => 'completed',
        ]));

        self::assertSame([
            'wait_for' => 'completed',
        ], $client->lastPostBody);

        $display = $tester->getDisplay();

        self::assertStringContainsString('Update: approve', $display);
        self::assertStringContainsString('Wait For: completed', $display);
    }

    public function test_update_command_rejects_invalid_wait_values_from_the_server_contract(): void
    {
        $client = new FakeServerClient([
            'workflow_id' => 'wf-123',
            'update_name' => 'approve',
            'update_id' => 'upd-1',
            'outcome' => 'update_completed',
        ], self::requestContract());

        $command = new UpdateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::INVALID, $tester->execute([
            'workflow-id' => 'wf-123',
            'update-name' => 'approve',
            '--wait' => 'settled',
        ]));

        self::assertSame([], $client->lastPostBody);
        self::assertSame(0, $client->postCalls);
        self::assertStringContainsString(
            'Server contract expects --wait to be one of [accepted, completed]; got [settled].',
            $tester->getDisplay(),
        );
    }

    public function test_signal_command_uses_run_targeted_path_when_run_id_is_provided(): void
    {
        $client = new FakeServerClient([
            'workflow_id' => 'wf-123',
            'signal_name' => 'advance',
            'outcome' => 'signal_received',
            'command_status' => 'accepted',
        ]);

        $command = new SignalCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
            'signal-name' => 'advance',
            '--run-id' => 'run-456',
        ]));

        self::assertSame('/workflows/wf-123/runs/run-456/signal/advance', $client->lastPostPath);
    }

    public function test_signal_command_uses_instance_path_without_run_id(): void
    {
        $client = new FakeServerClient([
            'workflow_id' => 'wf-123',
            'signal_name' => 'advance',
            'outcome' => 'signal_received',
            'command_status' => 'accepted',
        ]);

        $command = new SignalCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
            'signal-name' => 'advance',
        ]));

        self::assertSame('/workflows/wf-123/signal/advance', $client->lastPostPath);
    }

    public function test_cancel_command_uses_run_targeted_path_when_run_id_is_provided(): void
    {
        $client = new FakeServerClient([
            'workflow_id' => 'wf-123',
            'outcome' => 'cancelled',
            'command_status' => 'accepted',
        ]);

        $command = new CancelCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
            '--run-id' => 'run-456',
        ]));

        self::assertSame('/workflows/wf-123/runs/run-456/cancel', $client->lastPostPath);
    }

    public function test_cancel_command_batch_cancels_matching_workflows_after_confirmation(): void
    {
        $client = new BatchCancelFakeServerClient([
            [
                'workflows' => [
                    ['workflow_id' => 'wf-1', 'run_id' => 'run-1'],
                    ['workflow_id' => 'wf-2', 'run_id' => 'run-2'],
                ],
                'next_page_token' => null,
            ],
        ]);

        $command = new CancelCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--all-matching' => 'customer-42',
            '--type' => 'orders.process',
            '--status' => 'running',
            '--limit' => '2',
            '--reason' => 'customer request',
        ]));

        self::assertSame('/workflows', $client->getCalls[0]['path']);
        self::assertSame([
            'query' => 'customer-42',
            'workflow_type' => 'orders.process',
            'status' => 'running',
            'page_size' => 2,
        ], $client->getCalls[0]['query']);

        self::assertSame(['/workflows/wf-1/runs/run-1/cancel', '/workflows/wf-2/runs/run-2/cancel'], array_column($client->postCalls, 'path'));
        self::assertSame(['reason' => 'customer request'], $client->postCalls[0]['body']);
        self::assertSame(['reason' => 'customer request'], $client->postCalls[1]['body']);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Cancel 2 workflows matching [customer-42]?', $display);
        self::assertStringContainsString('Cancelled 2 workflows.', $display);
        self::assertStringContainsString('Matched: 2', $display);
        self::assertStringContainsString('wf-1', $display);
        self::assertStringContainsString('wf-2', $display);
    }

    public function test_cancel_command_batch_json_requires_yes_to_avoid_prompting(): void
    {
        $client = new BatchCancelFakeServerClient([]);

        $command = new CancelCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::INVALID, $tester->execute([
            '--all-matching' => 'customer-42',
            '--json' => true,
        ]));

        self::assertSame([], $client->getCalls);
        self::assertStringContainsString('--yes is required when using --all-matching with --json.', $tester->getDisplay());
    }

    public function test_cancel_command_batch_json_renders_summary(): void
    {
        $client = new BatchCancelFakeServerClient([
            [
                'workflows' => [
                    ['workflow_id' => 'wf-1', 'run_id' => 'run-1'],
                ],
                'next_page_token' => null,
            ],
        ]);

        $command = new CancelCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--all-matching' => 'customer-42',
            '--yes' => true,
            '--json' => true,
        ]));

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('customer-42', $decoded['query']);
        self::assertSame('running', $decoded['status']);
        self::assertSame(1, $decoded['matched']);
        self::assertSame(1, $decoded['cancelled']);
        self::assertSame(0, $decoded['failed']);
        self::assertSame(['wf-1'], $decoded['workflow_ids']);
        self::assertSame('cancelled', $decoded['results'][0]['outcome']);
    }

    public function test_cancel_command_batch_paginates_until_limit(): void
    {
        $client = new BatchCancelFakeServerClient([
            [
                'workflows' => [
                    ['workflow_id' => 'wf-1', 'run_id' => 'run-1'],
                    ['workflow_id' => 'wf-2', 'run_id' => 'run-2'],
                ],
                'next_page_token' => 'page-2',
            ],
            [
                'workflows' => [
                    ['workflow_id' => 'wf-3', 'run_id' => 'run-3'],
                    ['workflow_id' => 'wf-4', 'run_id' => 'run-4'],
                ],
                'next_page_token' => null,
            ],
        ]);

        $command = new CancelCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--all-matching' => 'customer-42',
            '--yes' => true,
            '--limit' => '3',
        ]));

        self::assertCount(2, $client->getCalls);
        self::assertSame(3, $client->getCalls[0]['query']['page_size']);
        self::assertSame('page-2', $client->getCalls[1]['query']['next_page_token']);
        self::assertSame(1, $client->getCalls[1]['query']['page_size']);
        self::assertSame(['/workflows/wf-1/runs/run-1/cancel', '/workflows/wf-2/runs/run-2/cancel', '/workflows/wf-3/runs/run-3/cancel'], array_column($client->postCalls, 'path'));
    }

    public function test_cancel_command_batch_aborts_when_confirmation_is_declined(): void
    {
        $client = new BatchCancelFakeServerClient([
            [
                'workflows' => [
                    ['workflow_id' => 'wf-1', 'run_id' => 'run-1'],
                ],
                'next_page_token' => null,
            ],
        ]);

        $command = new CancelCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);
        $tester->setInputs(['no']);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--all-matching' => 'customer-42',
        ]));

        self::assertSame([], $client->postCalls);
        self::assertStringContainsString('Batch cancellation aborted.', $tester->getDisplay());
    }

    public function test_cancel_command_batch_targets_the_selected_run_not_the_current_run(): void
    {
        $client = new BatchCancelFakeServerClient([
            [
                'workflows' => [
                    ['workflow_id' => 'order-7', 'run_id' => 'run-a'],
                ],
                'next_page_token' => null,
            ],
        ]);

        $command = new CancelCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--all-matching' => 'customer-42',
            '--yes' => true,
            '--json' => true,
        ]));

        self::assertSame(['/workflows/order-7/runs/run-a/cancel'], array_column($client->postCalls, 'path'));

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([['workflow_id' => 'order-7', 'run_id' => 'run-a', 'namespace' => 'default']], $decoded['targets']);
        self::assertSame('run-a', $decoded['results'][0]['run_id']);
    }

    public function test_cancel_command_batch_reports_a_replaced_run_without_cancelling_its_successor(): void
    {
        $client = new BatchCancelFakeServerClient(
            [
                [
                    'workflows' => [
                        ['workflow_id' => 'order-7', 'run_id' => 'run-a'],
                        ['workflow_id' => 'order-8', 'run_id' => 'run-c'],
                    ],
                    'next_page_token' => null,
                ],
            ],
            [
                'run-a' => new ServerHttpException(
                    'Server error: Commands cannot target historical runs.',
                    409,
                    body: [
                        'workflow_id' => 'order-7',
                        'run_id' => 'run-a',
                        'reason' => 'historical_run_command_rejected',
                        'target_scope' => 'run',
                    ],
                ),
            ],
        );

        $command = new CancelCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--all-matching' => 'customer-42',
            '--yes' => true,
            '--json' => true,
        ]));

        // One request per selected run. The refused run is never retried
        // against the instance endpoint, which would reach its successor.
        self::assertSame(
            ['/workflows/order-7/runs/run-a/cancel', '/workflows/order-8/runs/run-c/cancel'],
            array_column($client->postCalls, 'path'),
        );

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $decoded['matched']);
        self::assertSame(1, $decoded['cancelled']);
        self::assertSame(1, $decoded['failed']);
        self::assertSame('order-7', $decoded['failures'][0]['workflow_id']);
        self::assertSame('run-a', $decoded['failures'][0]['run_id']);
        self::assertSame('default', $decoded['failures'][0]['namespace']);
        self::assertSame('historical_run_command_rejected', $decoded['failures'][0]['reason']);
        self::assertSame(ExitCode::INVALID, $decoded['failures'][0]['exit_code']);
        self::assertSame('run-c', $decoded['results'][0]['run_id']);
    }

    public function test_cancel_command_batch_does_not_cancel_a_workflow_without_a_listed_run_id(): void
    {
        $client = new BatchCancelFakeServerClient([
            [
                'workflows' => [
                    ['workflow_id' => 'order-7'],
                    ['workflow_id' => 'order-8', 'run_id' => ''],
                ],
                'next_page_token' => null,
            ],
        ]);

        $command = new CancelCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--all-matching' => 'customer-42',
            '--yes' => true,
            '--json' => true,
        ]));

        self::assertSame([], $client->postCalls);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $decoded['cancelled']);
        self::assertSame(['run_id_unavailable', 'run_id_unavailable'], array_column($decoded['failures'], 'reason'));
        self::assertSame([null, null], array_column($decoded['failures'], 'run_id'));
    }

    public function test_cancel_command_batch_keeps_the_first_selected_run_when_pages_shift(): void
    {
        // The visibility list is not a snapshot. A workflow that continued as
        // new between page requests can appear again with its successor run.
        $client = new BatchCancelFakeServerClient([
            [
                'workflows' => [
                    ['workflow_id' => 'order-7', 'run_id' => 'run-a'],
                ],
                'next_page_token' => 'page-2',
            ],
            [
                'workflows' => [
                    ['workflow_id' => 'order-7', 'run_id' => 'run-b'],
                    ['workflow_id' => 'order-8', 'run_id' => 'run-c'],
                    ['workflow_id' => 'order-9', 'run_id' => 'run-d'],
                ],
                'next_page_token' => null,
            ],
        ]);

        $command = new CancelCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--all-matching' => 'customer-42',
            '--yes' => true,
            '--limit' => '2',
            '--json' => true,
        ]));

        self::assertSame(
            ['/workflows/order-7/runs/run-a/cancel', '/workflows/order-8/runs/run-c/cancel'],
            array_column($client->postCalls, 'path'),
        );

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $decoded['matched']);
        self::assertSame(['order-7', 'order-8'], $decoded['workflow_ids']);
    }

    public function test_cancel_command_batch_stops_when_a_page_adds_no_new_workflows(): void
    {
        // A stuck cursor: every page repeats the same workflow with a fresh
        // token. Selection must stop instead of paging forever.
        $pages = [];
        for ($page = 1; $page <= 50; $page++) {
            $pages[] = [
                'workflows' => [['workflow_id' => 'order-7', 'run_id' => 'run-a']],
                'next_page_token' => 'page-'.$page,
            ];
        }
        $client = new BatchCancelFakeServerClient($pages);

        $command = new CancelCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--all-matching' => 'customer-42',
            '--yes' => true,
            '--limit' => '2',
            '--json' => true,
        ]));

        self::assertCount(2, $client->getCalls);
        self::assertSame([], $client->postCalls);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString('a page added no new workflows', $decoded['error']);
        self::assertSame(1, $decoded['exit_code']);
    }

    public function test_cancel_command_batch_stops_when_the_page_token_repeats(): void
    {
        $client = new BatchCancelFakeServerClient([
            [
                'workflows' => [['workflow_id' => 'order-7', 'run_id' => 'run-a']],
                'next_page_token' => 'stuck',
            ],
            [
                'workflows' => [['workflow_id' => 'order-8', 'run_id' => 'run-c']],
                'next_page_token' => 'stuck',
            ],
            [
                'workflows' => [['workflow_id' => 'order-9', 'run_id' => 'run-d']],
                'next_page_token' => null,
            ],
        ]);

        $command = new CancelCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--all-matching' => 'customer-42',
            '--yes' => true,
            '--limit' => '5',
        ]));

        self::assertCount(2, $client->getCalls);
        self::assertSame([], $client->postCalls);
        self::assertStringContainsString('the server returned the same page token twice', $tester->getDisplay());
    }

    public function test_cancel_command_batch_human_output_names_the_selected_runs(): void
    {
        $client = new BatchCancelFakeServerClient(
            [
                [
                    'workflows' => [
                        ['workflow_id' => 'order-7', 'run_id' => 'run-a'],
                        ['workflow_id' => 'order-8', 'run_id' => 'run-c'],
                    ],
                    'next_page_token' => null,
                ],
            ],
            [
                'run-c' => new ServerHttpException(
                    'Server error: Workflow run is not active.',
                    409,
                    body: ['reason' => 'run_not_active'],
                ),
            ],
        );

        $command = new CancelCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--all-matching' => 'customer-42',
            '--yes' => true,
        ]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('Cancelled 1 workflow.', $display);
        self::assertStringContainsString('- order-7 (run run-a)', $display);
        self::assertStringContainsString('order-8 (run run-c): Server error: Workflow run is not active. [run_not_active]', $display);
    }

    public function test_cancel_command_rejects_missing_or_conflicting_batch_targets(): void
    {
        $command = new CancelCommand();
        $command->setServerClient(new BatchCancelFakeServerClient([]));

        $tester = new CommandTester($command);

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('workflow-id is required unless --all-matching is used.', $tester->getDisplay());

        $tester = new CommandTester($command);

        self::assertSame(Command::INVALID, $tester->execute([
            'workflow-id' => 'wf-1',
            '--all-matching' => 'customer-42',
        ]));
        self::assertStringContainsString('Pass either workflow-id or --all-matching, not both.', $tester->getDisplay());
    }

    public function test_terminate_command_uses_run_targeted_path_when_run_id_is_provided(): void
    {
        $client = new FakeServerClient([
            'workflow_id' => 'wf-123',
            'outcome' => 'terminated',
            'command_status' => 'accepted',
        ]);

        $command = new TerminateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-123',
            '--run-id' => 'run-456',
        ]));

        self::assertSame('/workflows/wf-123/runs/run-456/terminate', $client->lastPostPath);
    }

    public function test_repair_command_sends_post_and_renders_outcome(): void
    {
        $client = new FakeServerClient([
            'workflow_id' => 'wf-repair-1',
            'outcome' => 'repair_requested',
            'command_status' => 'accepted',
            'command_id' => 'cmd-repair-1',
        ]);

        $command = new RepairCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-repair-1',
        ]));

        self::assertSame('/workflows/wf-repair-1/repair', $client->lastPostPath);

        $display = $tester->getDisplay();

        self::assertStringContainsString('Repair requested', $display);
        self::assertStringContainsString('Workflow ID: wf-repair-1', $display);
        self::assertStringContainsString('Outcome: repair_requested', $display);
        self::assertStringContainsString('Command Status: accepted', $display);
        self::assertStringContainsString('Command ID: cmd-repair-1', $display);
    }

    public function test_redrive_command_targets_a_failed_run_with_an_optional_request_id(): void
    {
        $client = new FakeServerClient([
            'workflow_id' => 'wf-redrive-1',
            'run_id' => 'run-new',
            'continued_from_run_id' => 'run-failed',
            'resume_step_sequence' => 2,
        ]);

        $command = new RedriveCommand();
        $command->setServerClient($client);
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-redrive-1',
            'run-id' => 'run-failed',
            '--request-id' => 'incident-42',
        ]));

        self::assertSame('/workflows/wf-redrive-1/runs/run-failed/redrive', $client->lastPostPath);
        self::assertSame(['request_id' => 'incident-42'], $client->lastPostBody);
        self::assertStringContainsString('Source Run ID: run-failed', $tester->getDisplay());
        self::assertStringContainsString('New Run ID: run-new', $tester->getDisplay());
        self::assertStringContainsString('Resume Step: 2', $tester->getDisplay());
    }

    public function test_redrive_command_omits_request_id_when_not_provided(): void
    {
        $client = new FakeServerClient(['workflow_id' => 'wf-1', 'run_id' => 'run-new']);
        $command = new RedriveCommand();
        $command->setServerClient($client);

        self::assertSame(Command::SUCCESS, (new CommandTester($command))->execute([
            'workflow-id' => 'wf-1',
            'run-id' => 'run-failed',
        ]));
        self::assertSame([], $client->lastPostBody);
    }

    public function test_archive_command_sends_reason_and_renders_outcome(): void
    {
        $client = new FakeServerClient([
            'workflow_id' => 'wf-archive-1',
            'outcome' => 'archived',
            'command_status' => 'accepted',
            'command_id' => 'cmd-archive-1',
        ]);

        $command = new ArchiveCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-archive-1',
            '--reason' => 'retention policy cleanup',
        ]));

        self::assertSame('/workflows/wf-archive-1/archive', $client->lastPostPath);
        self::assertSame(['reason' => 'retention policy cleanup'], $client->lastPostBody);

        $display = $tester->getDisplay();

        self::assertStringContainsString('Archive requested', $display);
        self::assertStringContainsString('Workflow ID: wf-archive-1', $display);
        self::assertStringContainsString('Outcome: archived', $display);
    }

    public function test_archive_command_omits_reason_when_not_provided(): void
    {
        $client = new FakeServerClient([
            'workflow_id' => 'wf-archive-2',
            'outcome' => 'archived',
            'command_status' => 'accepted',
        ]);

        $command = new ArchiveCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => 'wf-archive-2',
        ]));

        self::assertSame([], $client->lastPostBody);
    }

    private static function requestContract(): ControlPlaneRequestContract
    {
        return new ControlPlaneRequestContract([
            'update' => [
                'fields' => [
                    'wait_for' => [
                        'canonical_values' => ['accepted', 'completed'],
                    ],
                ],
            ],
        ]);
    }
}

class FakeServerClient extends ServerClient
{
    /**
     * @var array<string, mixed>
     */
    public array $lastPostBody = [];

    public string $lastPostPath = '';

    public int $postCalls = 0;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly array $payload,
        private readonly ?ControlPlaneRequestContract $requestContract = null,
    ) {
    }

    public function post(string $path, array $body = []): array
    {
        $this->postCalls++;
        $this->lastPostPath = $path;
        $this->lastPostBody = $body;

        return $this->payload;
    }

    public function controlPlaneRequestContract(): ?ControlPlaneRequestContract
    {
        return $this->requestContract;
    }
}

class BatchCancelFakeServerClient extends ServerClient
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $pages;

    /**
     * @var list<array{path: string, query: array<string, mixed>}>
     */
    public array $getCalls = [];

    /**
     * @var list<array{path: string, body: array<string, mixed>}>
     */
    public array $postCalls = [];

    /**
     * @param list<array<string, mixed>> $pages
     * @param array<string, \Throwable> $refusals keyed by run ID
     */
    public function __construct(array $pages, private readonly array $refusals = [])
    {
        $this->pages = $pages;
    }

    public function get(string $path, array $query = []): array
    {
        $this->getCalls[] = ['path' => $path, 'query' => $query];

        return array_shift($this->pages) ?? [
            'workflows' => [],
            'next_page_token' => null,
        ];
    }

    public function post(string $path, array $body = []): array
    {
        $this->postCalls[] = ['path' => $path, 'body' => $body];

        if (preg_match('#^/workflows/([^/]+)/runs/([^/]+)/cancel$#', $path, $matches) !== 1) {
            throw new \LogicException("Batch cancellation must target a selected run, got [{$path}].");
        }

        [, $workflowId, $runId] = $matches;

        if (isset($this->refusals[$runId])) {
            throw $this->refusals[$runId];
        }

        return [
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'outcome' => 'cancelled',
            'command_status' => 'accepted',
        ];
    }

    public function controlPlaneRequestContract(): ?ControlPlaneRequestContract
    {
        return new ControlPlaneRequestContract([
            'list' => [
                'fields' => [
                    'status' => [
                        'canonical_values' => ['running', 'completed', 'failed'],
                    ],
                ],
            ],
        ]);
    }
}
