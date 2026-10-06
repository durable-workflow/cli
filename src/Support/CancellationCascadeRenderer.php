<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/** Render recorded evidence without inferring callback exit or renewing a deadline. */
final class CancellationCascadeRenderer
{
    /** @param array<string, mixed> $diagnostics */
    public function render(OutputInterface $output, array $diagnostics): void
    {
        if (! array_key_exists('cancellation_cascade_supported', $diagnostics)
            && ! array_key_exists('cancellation_cascade', $diagnostics)) {
            return;
        }
        $output->writeln('Cancellation Cascade:');
        $view = $diagnostics['cancellation_cascade'] ?? null;
        if (! is_array($view)) {
            $output->writeln(($diagnostics['cancellation_cascade_supported'] ?? false) === true
                ? '  No cooperative cancellation request for this run.'
                : '  Unavailable with this Server runtime.');
            $output->writeln('');

            return;
        }
        if (($view['schema'] ?? null) !== 'durable-workflow.cancellation-cascade/v1') {
            $output->writeln('  Unrecognized cascade schema. Use --output=json to inspect the response.');
            $output->writeln('');

            return;
        }

        $root = $this->object($view['root'] ?? null);
        $requester = $this->object($root['requester'] ?? null);
        $this->line($output, '  Root request: %s', $root['root_request_id'] ?? null);
        $this->line($output, '  Root workflow: %s run=%s', $root['root_workflow_instance_id'] ?? null, $root['root_workflow_run_id'] ?? null);
        $this->line($output, '  Original request: %s deadline=%s', $root['requested_at'] ?? null, $root['cleanup_deadline_at'] ?? null);
        $this->line($output, '  Reason: %s source=%s requester=%s', $root['reason'] ?? null, $root['source'] ?? null, $requester['label'] ?? $requester['id'] ?? null);
        $this->line($output, '  Evidence: %s%s',
            ($view['inspection_complete'] ?? false) === true ? 'complete' : 'partial',
            ($view['truncated'] ?? false) === true ? ' (inspection limits reached)' : '');

        foreach ($this->objects($view['runs'] ?? null) as $run) {
            $request = $this->object($run['request'] ?? null);
            $delivery = $this->object($run['delivery'] ?? null);
            $cleanup = $this->object($run['cleanup'] ?? null);
            $this->line($output, '  Run %s%s: %s workflow=%s phase=%s status=%s',
                $run['run_id'] ?? null, ($run['run_id'] ?? null) === ($view['selected_run_id'] ?? null) ? ' [selected]' : '',
                $run['workflow_type'] ?? null, $run['workflow_id'] ?? null, $run['lifecycle'] ?? null, $run['projected_status'] ?? null);
            $this->line($output, '    Request: %s parent=%s budget=%s deadline=%s',
                $request['request_id'] ?? null, $request['parent_request_id'] ?? null, $this->budget($run, $root), $request['cleanup_deadline_at'] ?? null);
            $this->line($output, '    Delivery: event=%s sequence=%s span=%s kind=%s',
                $delivery['history_event_id'] ?? null, $delivery['sequence'] ?? null, $delivery['sequence_span'] ?? null, $delivery['call_kind'] ?? null);
            $this->line($output, '    Cleanup: %s finished=%s terminal=%s event=%s',
                $cleanup['outcome'] ?? null, $cleanup['finished_at'] ?? null, $run['terminal_event_type'] ?? null, $run['terminal_history_event_id'] ?? null);
            foreach ($this->objects($run['activity_stops'] ?? null) as $stop) {
                $this->line($output, '    Activity %s (%s, %s): callback=%s fence=%s stop=%s source=%s',
                    $stop['activity_execution_id'] ?? null, $stop['activity_type'] ?? null, $stop['execution_mode'] ?? null,
                    $stop['callback_state'] ?? null, $stop['fence_history_event_id'] ?? null, $stop['stop_history_event_id'] ?? null, $stop['evidence_source'] ?? null);
                $this->line($output, '      Attempt: %s acknowledged=%s after_deadline=%s',
                    $stop['activity_attempt_id'] ?? null, $stop['acknowledged_at'] ?? null, $stop['received_after_deadline'] ?? null);
            }
            foreach ($this->objects($run['cleanup_recovery'] ?? null) as $recovery) {
                $attempt = $this->object($recovery['attempt'] ?? null);
                $this->line($output, '    Cleanup recovery: activity=%s event=%s at=%s',
                    $recovery['activity_execution_id'] ?? null, $recovery['history_event_id'] ?? null, $recovery['recorded_at'] ?? null);
                $this->line($output, '      %s attempt=%s owner=%s -> %s attempt=%s owner=%s callback=%s',
                    $attempt['original_workflow_task_id'] ?? null, $attempt['original_workflow_task_attempt'] ?? null,
                    $attempt['original_lease_owner'] ?? null, $attempt['workflow_task_id'] ?? null,
                    $attempt['workflow_task_attempt'] ?? null, $attempt['lease_owner'] ?? null, $attempt['callback_stop_state'] ?? null);
            }
            foreach ($this->objects($run['child_propagation'] ?? null) as $propagation) {
                $this->line($output, '    Child %s: policy=%s outcome=%s rejection=%s event=%s terminal=%s',
                    $propagation['child_run_id'] ?? null, $propagation['policy'] ?? null, $propagation['request_outcome'] ?? null,
                    $propagation['rejection_reason'] ?? null, $propagation['history_event_id'] ?? null, $propagation['child_terminal_history_event_id'] ?? null);
            }
        }
        foreach ($this->objects($view['edges'] ?? null) as $edge) {
            $this->line($output, '  Relation: %s -> %s kind=%s state=%s',
                $edge['parent_run_id'] ?? null, $edge['child_run_id'] ?? null, $edge['kind'] ?? null, $edge['reference_state'] ?? null);
        }
        foreach ($this->objects($view['findings'] ?? null) as $finding) {
            $this->line($output, '  Finding [%s] run=%s: %s', $finding['code'] ?? null, $finding['run_id'] ?? null, $finding['message'] ?? null);
        }
        $output->writeln('');
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $root */
    private function budget(array $run, array $root): string
    {
        $request = $this->object($run['request'] ?? null);
        if ($request === [] || $root === []) {
            return 'unverified';
        }
        if (($run['same_root_budget'] ?? false) === true) {
            return 'original root';
        }

        return ($request['root_request_id'] ?? null) !== ($root['root_request_id'] ?? null)
            ? 'independent root' : 'conflict or unverified';
    }

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array<string, mixed>> */
    private function objects(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private function line(OutputInterface $output, string $format, mixed ...$values): void
    {
        $output->writeln(sprintf($format, ...array_map($this->text(...), $values)));
    }

    private function text(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '-';
        }
        $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        return OutputFormatter::escape(preg_replace('/[\x00-\x1F\x7F\x{80}-\x{9F}]/u', ' ', $text) ?? '-');
    }
}
