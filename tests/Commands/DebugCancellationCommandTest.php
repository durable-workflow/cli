<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\DebugCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DebugCancellationCommandTest extends TestCase
{
    public function test_human_output_explains_the_root_child_stops_and_cleanup_recovery_together(): void
    {
        $display = $this->display($this->payload());
        foreach ([
            'Root request: root-request', 'Original request: 2026-10-02T00:00:00Z deadline=2026-10-02T00:00:30Z',
            'parent-run [selected]', 'phase=cleaning_up', 'phase=cancelled', 'budget=original root',
            'Request: child-request parent=root-request', 'Delivery: event=13 sequence=1 span=2 kind=parallel',
            'Cleanup: completed', 'Activity php-work (php.local.work, local): callback=reported_stopped',
            'Activity rust-work (rust.remote.work, remote): callback=reported_stopped',
            'Cleanup recovery: activity=php-cleanup event=17',
            'task-1 attempt=1 owner=old-worker -> task-2 attempt=2 owner=replacement callback=unknown',
            'Child child-run: policy=wait_cancellation_completed outcome=requested',
            'Relation: parent-run -> child-run kind=child_workflow state=resolved', 'Evidence: complete',
        ] as $expected) {
            self::assertStringContainsString($expected, $display);
        }
        self::assertStringNotContainsString('SIGKILL', $display, 'Grant recovery does not prove the cause of worker loss.');
    }

    public function test_json_preserves_the_entire_response_including_unrecognized_evidence(): void
    {
        $payload = $this->payload();
        $payload['cancellation_cascade']['future_evidence'] = ['retained' => true];
        $payload['cancellation_cascade']['schema'] = 'durable-workflow.cancellation-cascade/v2';
        $display = $this->display($payload, true);
        self::assertSame($payload, json_decode(trim($display), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_fences_and_partial_history_do_not_imply_callback_exit_or_completed_cleanup(): void
    {
        $payload = $this->payload();
        $view = &$payload['cancellation_cascade'];
        $view['inspection_complete'] = false;
        $view['truncated'] = true;
        $view['runs'][0]['activity_stops'][0]['callback_state'] = 'unknown';
        $view['runs'][0]['activity_stops'][0]['stop_history_event_id'] = null;
        $view['runs'][0]['activity_stops'][0]['evidence_source'] = null;
        $view['runs'][1]['cleanup'] = null;
        $view['findings'] = [['code' => 'history_limit', 'run_id' => 'parent-run', 'message' => 'Open the run history for additional evidence.']];
        $display = $this->display($payload);
        self::assertStringContainsString('Evidence: partial (inspection limits reached)', $display);
        self::assertStringContainsString('callback=unknown fence=8 stop=- source=-', $display);
        self::assertStringContainsString('Cleanup: - finished=- terminal=WorkflowCancelled', $display);
        self::assertStringContainsString('Finding [history_limit] run=parent-run: Open the run history', $display);
    }

    public function test_independent_child_roots_keep_their_own_deadlines(): void
    {
        $payload = $this->payload();
        $child = &$payload['cancellation_cascade']['runs'][1];
        $child['same_root_budget'] = false;
        $child['request']['root_request_id'] = 'independent-request';
        $child['request']['cleanup_deadline_at'] = '2026-10-02T00:00:45Z';
        self::assertStringContainsString('budget=independent root deadline=2026-10-02T00:00:45Z', $this->display($payload));
    }

    public function test_missing_root_and_conflicting_budget_are_reported_as_unverified(): void
    {
        $payload = $this->payload();
        $payload['cancellation_cascade']['runs'][1]['same_root_budget'] = false;
        self::assertStringContainsString('budget=conflict or unverified', $this->display($payload));
        $payload['cancellation_cascade']['root'] = null;
        self::assertStringContainsString('Root request: -', $this->display($payload));
        self::assertStringContainsString('budget=unverified', $this->display($payload));
    }

    public function test_old_runtime_no_request_and_future_schema_are_distinguishable(): void
    {
        self::assertStringContainsString('Unavailable with this Server runtime.', $this->display(['cancellation_cascade_supported' => false]));
        self::assertStringContainsString('No cooperative cancellation request for this run.', $this->display(['cancellation_cascade_supported' => true, 'cancellation_cascade' => null]));
        self::assertStringContainsString('Unrecognized cascade schema.', $this->display(['cancellation_cascade' => ['schema' => 'future']]));
        self::assertStringNotContainsString('Cancellation Cascade:', $this->display([]));
    }

    public function test_caller_text_cannot_inject_console_markup_or_terminal_controls(): void
    {
        $payload = $this->payload();
        $payload['cancellation_cascade']['root']['reason'] = "<error>literal reason</error>\x1b]52;c;payload\x07\r\nforged line";
        $payload['cancellation_cascade']['runs'][0]['workflow_type'] = '<info>caller type</info>';
        $display = $this->display($payload);
        self::assertStringContainsString('<error>literal reason</error>', $display);
        self::assertStringContainsString('<info>caller type</info>', $display);
        self::assertStringNotContainsString("\x1b", $display);
        self::assertStringNotContainsString("\x07", $display);
        self::assertStringNotContainsString("\nforged line", $display);
    }

    private function display(array $payload, bool $json = false): string
    {
        $client = new class($payload) extends ServerClient
        {
            public function __construct(private readonly array $payload) {}

            public function get(string $path, array $query = []): array
            {
                return $this->payload;
            }
        };
        $command = new DebugCommand();
        $command->setServerClient($client);
        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([
            'target' => 'workflow', 'workflow-id' => 'parent-workflow', '--run-id' => 'parent-run',
            '--output' => $json ? 'json' : 'table',
        ]));

        return $tester->getDisplay();
    }

    /** A diagnostic display fixture, not a runtime qualification result. */
    private function payload(): array
    {
        $root = [
            'root_request_id' => 'root-request', 'request_id' => 'root-request',
            'root_workflow_instance_id' => 'parent-workflow', 'root_workflow_run_id' => 'parent-run',
            'parent_request_id' => null, 'reason' => 'Maintenance', 'source' => 'operator', 'requester' => ['id' => 'test-operator'],
            'requested_at' => '2026-10-02T00:00:00Z', 'cleanup_deadline_at' => '2026-10-02T00:00:30Z',
        ];
        $stop = [
            'activity_execution_id' => 'php-work', 'activity_attempt_id' => 'php-attempt', 'activity_type' => 'php.local.work',
            'execution_mode' => 'local', 'fence_history_event_id' => 8, 'callback_state' => 'reported_stopped',
            'stop_history_event_id' => 9, 'evidence_source' => 'joined_callback',
            'acknowledged_at' => '2026-10-02T00:00:01Z', 'received_after_deadline' => false,
        ];
        $parent = [
            'run_id' => 'parent-run', 'workflow_id' => 'parent-workflow', 'workflow_type' => 'php.parent',
            'projected_status' => 'waiting', 'lifecycle' => 'cleaning_up', 'same_root_budget' => true, 'request' => $root,
            'delivery' => ['history_event_id' => 13, 'sequence' => 1, 'sequence_span' => 2, 'call_kind' => 'parallel'],
            'cleanup' => null, 'terminal_event_type' => null, 'terminal_history_event_id' => null,
            'activity_stops' => [$stop],
            'cleanup_recovery' => [[
                'history_event_id' => 17, 'activity_execution_id' => 'php-cleanup', 'recorded_at' => '2026-10-02T00:00:12Z',
                'attempt' => ['original_workflow_task_id' => 'task-1', 'original_workflow_task_attempt' => 1,
                    'original_lease_owner' => 'old-worker', 'workflow_task_id' => 'task-2', 'workflow_task_attempt' => 2,
                    'lease_owner' => 'replacement', 'callback_stop_state' => 'unknown'],
            ]],
            'child_propagation' => [['child_run_id' => 'child-run', 'policy' => 'wait_cancellation_completed',
                'request_outcome' => 'requested', 'history_event_id' => 10]],
        ];
        $child = array_replace($parent, [
            'run_id' => 'child-run', 'workflow_id' => 'child-workflow', 'workflow_type' => 'python.child',
            'projected_status' => 'cancelled', 'lifecycle' => 'cancelled',
            'request' => array_replace($root, ['request_id' => 'child-request', 'parent_request_id' => 'root-request']),
            'cleanup' => ['outcome' => 'completed', 'finished_at' => '2026-10-02T00:00:03Z'],
            'terminal_event_type' => 'WorkflowCancelled', 'terminal_history_event_id' => 11,
            'activity_stops' => [array_replace($stop, ['activity_execution_id' => 'rust-work', 'activity_type' => 'rust.remote.work', 'execution_mode' => 'remote'])],
            'cleanup_recovery' => [], 'child_propagation' => [],
        ]);

        return [
            'workflow_id' => 'parent-workflow', 'run_id' => 'parent-run', 'cancellation_cascade_supported' => true,
            'cancellation_cascade' => ['schema' => 'durable-workflow.cancellation-cascade/v1',
                'selected_run_id' => 'parent-run', 'root' => $root, 'runs' => [$parent, $child],
                'edges' => [['parent_run_id' => 'parent-run', 'child_run_id' => 'child-run', 'kind' => 'child_workflow', 'reference_state' => 'resolved']],
                'inspection_complete' => true, 'truncated' => false, 'findings' => []],
        ];
    }
}
