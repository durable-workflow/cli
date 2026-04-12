<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowCommand\SignalCommand;
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
        $this->lastPostBody = $body;

        return $this->payload;
    }

    public function controlPlaneRequestContract(): ?ControlPlaneRequestContract
    {
        return $this->requestContract;
    }
}
