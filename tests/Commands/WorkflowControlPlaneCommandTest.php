<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowCommand\ArchiveCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\CancelCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\RepairCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\SignalCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\TerminateCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\UpdateCommand;
use DurableWorkflow\Cli\Support\ControlPlaneRequestContract;
use DurableWorkflow\Cli\Support\ServerClient;
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
