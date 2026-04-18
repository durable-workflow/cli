<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowTaskCommand\CompleteCommand;
use DurableWorkflow\Cli\Commands\WorkflowTaskCommand\PollCommand;
use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class WorkflowTaskCommandTest extends TestCase
{
    public function test_poll_command_sends_worker_task_queue_and_history_options(): void
    {
        $client = new WorkflowTaskFakeClient([
            'task' => [
                'task_id' => 'task-1',
                'workflow_id' => 'wf-1',
                'run_id' => 'run-1',
                'workflow_task_attempt' => 2,
                'lease_owner' => 'worker-1',
                'history_events' => [
                    ['event_type' => 'WorkflowStarted'],
                ],
            ],
        ]);

        $command = new PollCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'worker-id' => 'worker-1',
            '--task-queue' => 'orders',
            '--build-id' => 'build-1',
            '--poll-request-id' => 'poll-1',
            '--history-page-size' => '50',
            '--accept-history-encoding' => 'gzip',
        ]));

        self::assertSame('/worker/workflow-tasks/poll', $client->lastPostPath);
        self::assertSame('worker-1', $client->lastPostBody['worker_id']);
        self::assertSame('orders', $client->lastPostBody['task_queue']);
        self::assertSame('build-1', $client->lastPostBody['build_id']);
        self::assertSame('poll-1', $client->lastPostBody['poll_request_id']);
        self::assertSame(50, $client->lastPostBody['history_page_size']);
        self::assertSame('gzip', $client->lastPostBody['accept_history_encoding']);
        self::assertStringContainsString('task-1', $tester->getDisplay());
    }

    public function test_poll_command_json_output(): void
    {
        $payload = ['task' => null];

        $command = new PollCommand();
        $command->setServerClient(new WorkflowTaskFakeClient($payload));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'worker-id' => 'worker-1',
            '--json' => true,
        ]));

        self::assertSame($payload, json_decode($tester->getDisplay(), true));
    }

    public function test_complete_command_wraps_complete_result_in_json_codec_envelope(): void
    {
        $client = new WorkflowTaskFakeClient([
            'task_id' => 'task-1',
            'workflow_task_attempt' => 1,
            'outcome' => 'completed',
            'run_status' => 'completed',
        ]);

        $command = new CompleteCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-id' => 'task-1',
            'attempt' => '1',
            '--lease-owner' => 'worker-1',
            '--complete-result' => '{"ok":true}',
        ]));

        self::assertSame('/worker/workflow-tasks/task-1/complete', $client->lastPostPath);
        self::assertSame('worker-1', $client->lastPostBody['lease_owner']);
        self::assertSame(1, $client->lastPostBody['workflow_task_attempt']);
        self::assertSame('complete_workflow', $client->lastPostBody['commands'][0]['type']);
        self::assertSame('json', $client->lastPostBody['commands'][0]['result']['codec']);
        self::assertSame('{"ok":true}', $client->lastPostBody['commands'][0]['result']['blob']);
        self::assertStringContainsString('completed', $tester->getDisplay());
    }

    public function test_complete_command_accepts_raw_command_json(): void
    {
        $client = new WorkflowTaskFakeClient([
            'task_id' => 'task-1',
            'workflow_task_attempt' => 1,
            'outcome' => 'completed',
        ]);

        $command = new CompleteCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'task-id' => 'task-1',
            'attempt' => '1',
            '--command' => ['{"type":"fail_workflow","message":"boom"}'],
        ]));

        self::assertSame([
            ['type' => 'fail_workflow', 'message' => 'boom'],
        ], $client->lastPostBody['commands']);
    }

    public function test_complete_command_rejects_ambiguous_command_options(): void
    {
        $command = new CompleteCommand();
        $command->setServerClient(new WorkflowTaskFakeClient([]));

        $tester = new CommandTester($command);

        self::assertSame(ExitCode::INVALID, $tester->execute([
            'task-id' => 'task-1',
            'attempt' => '1',
            '--complete-result' => '{"ok":true}',
            '--command' => ['{"type":"complete_workflow"}'],
        ]));

        self::assertStringContainsString('Use either --command or --complete-result', $tester->getDisplay());
    }
}

class WorkflowTaskFakeClient extends ServerClient
{
    /** @var array<string, mixed> */
    public array $lastPostBody = [];

    public string $lastPostPath = '';

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly array $payload,
    ) {}

    public function post(string $path, array $body = []): array
    {
        $this->lastPostPath = $path;
        $this->lastPostBody = $body;

        return $this->payload;
    }
}
