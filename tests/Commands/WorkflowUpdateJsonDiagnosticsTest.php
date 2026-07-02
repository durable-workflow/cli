<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowCommand\UpdateCommand;
use DurableWorkflow\Cli\Support\ControlPlaneRequestContract;
use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\ServerClient;
use DurableWorkflow\Cli\Support\ServerHttpException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkflowUpdateJsonDiagnosticsTest extends TestCase
{
    public function test_accepted_update_json_surfaces_request_state_and_history_references(): void
    {
        $client = new WorkflowUpdateDiagnosticsClient([
            'workflow_id' => 'wf-accepted',
            'run_id' => 'run-accepted',
            'update_name' => 'approve',
            'update_id' => 'upd-accepted',
            'outcome' => 'update_accepted',
            'command_status' => 'accepted',
            'update_status' => 'accepted',
            'wait_for' => 'accepted',
            'workflow_sequence' => 11,
            'command_sequence' => 7,
            'accepted_at' => '2026-07-02T12:00:00Z',
        ]);

        $tester = $this->executeUpdate($client, [
            'workflow-id' => 'wf-accepted',
            'update-name' => 'approve',
            '--wait' => 'accepted',
            '--request-id' => 'accepted-request-1',
            '--input' => '[{"approved":true}]',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([
            'wait_for' => 'accepted',
            'request_id' => 'accepted-request-1',
            'input' => [
                ['approved' => true],
            ],
        ], $client->lastPostBody);

        $decoded = $this->json($tester);

        self::assertSame('wf-accepted', $decoded['workflow_id']);
        self::assertSame('run-accepted', $decoded['run_id']);
        self::assertSame('approve', $decoded['update_name']);
        self::assertSame('upd-accepted', $decoded['update_id']);
        self::assertSame('accepted-request-1', $decoded['request_id']);
        self::assertSame('update_accepted', $decoded['outcome']);
        self::assertSame('accepted', $decoded['command_status']);
        self::assertSame('accepted', $decoded['update_status']);
        self::assertSame('accepted-request-1', $decoded['request']['request_id']);
        self::assertSame([['approved' => true]], $decoded['request']['input']);
        self::assertSame(11, $decoded['history_references']['workflow_sequence']);
        self::assertSame(7, $decoded['history_references']['command_sequence']);
        self::assertSame('2026-07-02T12:00:00Z', $decoded['history_references']['accepted_at']);
    }

    public function test_completed_update_json_surfaces_result_payload_and_history_references(): void
    {
        $client = new WorkflowUpdateDiagnosticsClient([
            'workflow_id' => 'wf-completed',
            'run_id' => 'run-completed',
            'update_name' => 'approve',
            'update_id' => 'upd-completed',
            'request_id' => 'completed-request-1',
            'outcome' => 'update_completed',
            'command_status' => 'accepted',
            'update_status' => 'completed',
            'wait_for' => 'completed',
            'result' => ['approved' => true, 'source' => 'cli'],
            'result_envelope' => [
                'codec' => 'json',
                'blob' => 'eyJhcHByb3ZlZCI6dHJ1ZX0=',
            ],
            'workflow_sequence' => 12,
            'command_sequence' => 8,
            'applied_at' => '2026-07-02T12:01:00Z',
            'closed_at' => '2026-07-02T12:01:01Z',
        ]);

        $tester = $this->executeUpdate($client, [
            'workflow-id' => 'wf-completed',
            'update-name' => 'approve',
            '--wait' => 'completed',
            '--request-id' => 'completed-request-1',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $decoded = $this->json($tester);

        self::assertSame('completed-request-1', $decoded['request_id']);
        self::assertSame('update_completed', $decoded['outcome']);
        self::assertSame('completed', $decoded['update_status']);
        self::assertSame(['approved' => true, 'source' => 'cli'], $decoded['result']);
        self::assertSame('json', $decoded['result_envelope']['codec']);
        self::assertSame('eyJhcHByb3ZlZCI6dHJ1ZX0=', $decoded['result_envelope']['blob']);
        self::assertSame(12, $decoded['history_references']['workflow_sequence']);
        self::assertSame('2026-07-02T12:01:00Z', $decoded['history_references']['applied_at']);
        self::assertSame('completed', $decoded['request']['wait_for']);
    }

    public function test_failed_update_json_error_promotes_update_diagnostics(): void
    {
        $body = [
            'workflow_id' => 'wf-failed',
            'run_id' => 'run-failed',
            'update_name' => 'approve',
            'update_id' => 'upd-failed',
            'request_id' => 'failed-request-1',
            'outcome' => 'update_failed',
            'reason' => 'update_handler_failed',
            'command_status' => 'accepted',
            'update_status' => 'failed',
            'failure_id' => 'failure-1',
            'failure_message' => 'Handler rejected approval.',
            'result' => null,
            'result_envelope' => null,
            'workflow_sequence' => 13,
            'command_sequence' => 9,
            'closed_at' => '2026-07-02T12:02:00Z',
            'message' => 'Workflow update failed.',
        ];

        $client = new WorkflowUpdateDiagnosticsClient($body, exception: new ServerHttpException(
            'Server error: Workflow update failed.',
            422,
            body: $body,
        ));

        $tester = $this->executeUpdate($client, [
            'workflow-id' => 'wf-failed',
            'update-name' => 'approve',
            '--wait' => 'completed',
            '--request-id' => 'failed-request-1',
            '--json' => true,
        ]);

        self::assertSame(ExitCode::INVALID, $tester->getStatusCode());

        $decoded = $this->json($tester);

        self::assertSame(422, $decoded['status_code']);
        self::assertSame('wf-failed', $decoded['workflow_id']);
        self::assertSame('approve', $decoded['update_name']);
        self::assertSame('upd-failed', $decoded['update_id']);
        self::assertSame('failed-request-1', $decoded['request_id']);
        self::assertSame('update_failed', $decoded['outcome']);
        self::assertSame('update_handler_failed', $decoded['reason']);
        self::assertSame('failed', $decoded['update_status']);
        self::assertSame('failure-1', $decoded['failure_id']);
        self::assertSame('Handler rejected approval.', $decoded['failure_message']);
        self::assertSame(13, $decoded['history_references']['workflow_sequence']);
        self::assertSame($body, $decoded['server_response']);
    }

    public function test_refused_update_json_error_includes_request_id_and_command_context(): void
    {
        $body = [
            'message' => 'Workflow not found.',
            'reason' => 'instance_not_found',
        ];

        $client = new WorkflowUpdateDiagnosticsClient($body, exception: new ServerHttpException(
            'Server error: Workflow not found.',
            404,
            body: $body,
        ));

        $tester = $this->executeUpdate($client, [
            'workflow-id' => 'wf-missing',
            'update-name' => 'approve',
            '--request-id' => 'refused-request-1',
            '--json' => true,
        ]);

        self::assertSame(ExitCode::NOT_FOUND, $tester->getStatusCode());

        $decoded = $this->json($tester);

        self::assertSame(404, $decoded['status_code']);
        self::assertSame('wf-missing', $decoded['workflow_id']);
        self::assertSame('approve', $decoded['update_name']);
        self::assertSame('refused-request-1', $decoded['request_id']);
        self::assertSame('instance_not_found', $decoded['reason']);
        self::assertSame('Workflow not found.', $decoded['message']);
        self::assertSame($body, $decoded['server_response']);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function executeUpdate(WorkflowUpdateDiagnosticsClient $client, array $arguments): CommandTester
    {
        $command = new UpdateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);
        $tester->execute($arguments);

        return $tester;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(CommandTester $tester): array
    {
        $decoded = json_decode(trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

final class WorkflowUpdateDiagnosticsClient extends ServerClient
{
    /**
     * @var array<string, mixed>
     */
    public array $lastPostBody = [];

    public string $lastPostPath = '';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly array $payload,
        private readonly ?ServerHttpException $exception = null,
    ) {
    }

    public function post(string $path, array $body = []): array
    {
        $this->lastPostPath = $path;
        $this->lastPostBody = $body;

        if ($this->exception instanceof ServerHttpException) {
            throw $this->exception;
        }

        return $this->payload;
    }

    public function controlPlaneRequestContract(): ?ControlPlaneRequestContract
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
