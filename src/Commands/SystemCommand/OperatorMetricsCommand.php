<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\SystemCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OperatorMetricsCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('system:operator-metrics')
            ->setDescription('Show the rollout-safety coordination health snapshot')
            ->setHelp(<<<'HELP'
Render the v2 operator-metrics snapshot that the Phase 6 rollout-safety
contract freezes as the operator-visible view of coordination health.

Each section is pulled from `OperatorMetrics::snapshot()` through the
server's `/system/operator-metrics` route and grouped by the concern it
surfaces: run-level stuck/blocked counts, task-phase queue depth and the
duplicate-risk roll-up, backlog roll-ups including compatibility blocks,
the stuck-run detectors from the repair candidate scan, the worker
compatibility fleet with per-worker markers, the BackendCapabilities
admission check, scheduler-role health, and the repair-policy tuning
knobs. The `fleet` table only lists heartbeats older workers would have
hidden behind logs; every entry carries the build id markers it
advertises so mixed-build state is visible without log archaeology.

<comment>Examples:</comment>

  <info>dw system:operator-metrics</info>
  <info>dw system:operator-metrics --json | jq '.operator_metrics.workers'</info>

The contract guarantees the frozen key inventory under
`operator_metrics.{runs,tasks,backlog,repair,workers,backend,schedules,activities,repair_policy,matching_role}`;
consumers MAY add derived keys but MUST NOT rename these. The
`matching_role` block is per-process and reflects only the node
serving the request; read one snapshot per node to see the full
deployment shape.
HELP)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client($input)->get('/system/operator-metrics');

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $namespace = is_string($result['namespace'] ?? null) ? $result['namespace'] : null;
        $metrics = is_array($result['operator_metrics'] ?? null) ? $result['operator_metrics'] : [];

        $output->writeln(sprintf(
            '<info>Operator metrics</info>%s%s',
            $namespace !== null ? ' for namespace '.$namespace : '',
            is_string($metrics['generated_at'] ?? null) ? ' (generated '.$metrics['generated_at'].')' : '',
        ));
        $output->writeln('');

        $this->renderRuns($output, $this->sectionArray($metrics, 'runs'));
        $this->renderTasks($output, $this->sectionArray($metrics, 'tasks'));
        $this->renderBacklog($output, $this->sectionArray($metrics, 'backlog'));
        $this->renderRepair($output, $this->sectionArray($metrics, 'repair'));
        $this->renderWorkers($output, $this->sectionArray($metrics, 'workers'));
        $this->renderBackend($output, $this->sectionArray($metrics, 'backend'));
        $this->renderMatchingRole($output, $this->sectionArray($metrics, 'matching_role'));
        $this->renderSchedules($output, $this->sectionArray($metrics, 'schedules'));
        $this->renderActivities($output, $this->sectionArray($metrics, 'activities'));
        $this->renderRepairPolicy($output, $this->sectionArray($metrics, 'repair_policy'));

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function sectionArray(array $metrics, string $key): array
    {
        $section = $metrics[$key] ?? null;

        return is_array($section) ? $section : [];
    }

    /**
     * @param  array<string, mixed>  $runs
     */
    private function renderRuns(OutputInterface $output, array $runs): void
    {
        $output->writeln('<info>Runs</info>');
        $output->writeln(sprintf('  Repair needed:        %d', (int) ($runs['repair_needed'] ?? 0)));
        $output->writeln(sprintf('  Claim failed:         %d', (int) ($runs['claim_failed'] ?? 0)));
        $output->writeln(sprintf('  Compatibility blocked: %d', (int) ($runs['compatibility_blocked'] ?? 0)));
        if (array_key_exists('waiting', $runs)) {
            $output->writeln(sprintf('  Waiting (durable resume): %d', (int) ($runs['waiting'] ?? 0)));
        }
        if (array_key_exists('max_wait_age_ms', $runs)) {
            $output->writeln(sprintf(
                '  Oldest wait age:      %d ms',
                (int) ($runs['max_wait_age_ms'] ?? 0),
            ));
        }
        if (is_string($runs['oldest_wait_started_at'] ?? null)) {
            $output->writeln(sprintf('  Oldest wait started at: %s', $runs['oldest_wait_started_at']));
        }
        $output->writeln('');
    }

    /**
     * @param  array<string, mixed>  $tasks
     */
    private function renderTasks(OutputInterface $output, array $tasks): void
    {
        $output->writeln('<info>Tasks</info>');
        $output->writeln(sprintf(
            '  Queue depth:          %d ready (%d due), %d delayed, %d leased',
            (int) ($tasks['ready'] ?? 0),
            (int) ($tasks['ready_due'] ?? 0),
            (int) ($tasks['delayed'] ?? 0),
            (int) ($tasks['leased'] ?? 0),
        ));
        $output->writeln(sprintf(
            '  Unhealthy (duplicate-risk roll-up): %d (dispatch failed %d, claim failed %d, dispatch overdue %d, lease expired %d)',
            (int) ($tasks['unhealthy'] ?? 0),
            (int) ($tasks['dispatch_failed'] ?? 0),
            (int) ($tasks['claim_failed'] ?? 0),
            (int) ($tasks['dispatch_overdue'] ?? 0),
            (int) ($tasks['lease_expired'] ?? 0),
        ));
        if (array_key_exists('max_lease_expired_age_ms', $tasks)) {
            $output->writeln(sprintf(
                '  Oldest lease-expired age: %d ms',
                (int) ($tasks['max_lease_expired_age_ms'] ?? 0),
            ));
        }
        if (is_string($tasks['oldest_lease_expired_at'] ?? null)) {
            $output->writeln(sprintf('  Oldest lease expired at:  %s', $tasks['oldest_lease_expired_at']));
        }
        if (array_key_exists('max_ready_due_age_ms', $tasks)) {
            $output->writeln(sprintf(
                '  Oldest ready-due age:     %d ms',
                (int) ($tasks['max_ready_due_age_ms'] ?? 0),
            ));
        }
        if (is_string($tasks['oldest_ready_due_at'] ?? null)) {
            $output->writeln(sprintf('  Oldest ready-due at:      %s', $tasks['oldest_ready_due_at']));
        }
        if (array_key_exists('max_dispatch_overdue_age_ms', $tasks)) {
            $output->writeln(sprintf(
                '  Oldest dispatch-overdue age: %d ms',
                (int) ($tasks['max_dispatch_overdue_age_ms'] ?? 0),
            ));
        }
        if (is_string($tasks['oldest_dispatch_overdue_since'] ?? null)) {
            $output->writeln(sprintf('  Oldest dispatch-overdue since: %s', $tasks['oldest_dispatch_overdue_since']));
        }
        $output->writeln('');
    }

    /**
     * @param  array<string, mixed>  $backlog
     */
    private function renderBacklog(OutputInterface $output, array $backlog): void
    {
        $output->writeln('<info>Backlog</info>');
        $output->writeln(sprintf('  Runnable tasks:       %d', (int) ($backlog['runnable_tasks'] ?? 0)));
        $output->writeln(sprintf('  Delayed tasks:        %d', (int) ($backlog['delayed_tasks'] ?? 0)));
        $output->writeln(sprintf('  Leased tasks:         %d', (int) ($backlog['leased_tasks'] ?? 0)));
        $output->writeln(sprintf('  Unhealthy tasks:      %d', (int) ($backlog['unhealthy_tasks'] ?? 0)));
        $output->writeln(sprintf('  Repair needed runs:   %d', (int) ($backlog['repair_needed_runs'] ?? 0)));
        $output->writeln(sprintf('  Claim failed runs:    %d', (int) ($backlog['claim_failed_runs'] ?? 0)));
        $output->writeln(sprintf('  Compatibility blocked runs: %d', (int) ($backlog['compatibility_blocked_runs'] ?? 0)));
        $output->writeln(sprintf('  Oldest compatibility-blocked age: %d ms', (int) ($backlog['max_compatibility_blocked_age_ms'] ?? 0)));
        if (is_string($backlog['oldest_compatibility_blocked_started_at'] ?? null)) {
            $output->writeln(sprintf('  Oldest compatibility-blocked at:  %s', $backlog['oldest_compatibility_blocked_started_at']));
        }
        $output->writeln('');
    }

    /**
     * @param  array<string, mixed>  $repair
     */
    private function renderRepair(OutputInterface $output, array $repair): void
    {
        $output->writeln('<info>Stuck-run detectors</info>');
        $output->writeln(sprintf(
            '  Missing-task candidates: %d (%d selected this pass)',
            (int) ($repair['missing_task_candidates'] ?? 0),
            (int) ($repair['selected_missing_task_candidates'] ?? 0),
        ));
        $output->writeln(sprintf('  Oldest missing-task age: %d ms', (int) ($repair['max_missing_run_age_ms'] ?? 0)));
        if (is_string($repair['oldest_missing_run_started_at'] ?? null)) {
            $output->writeln(sprintf('  Oldest missing run at:   %s', $repair['oldest_missing_run_started_at']));
        }
        $output->writeln('');
    }

    /**
     * @param  array<string, mixed>  $workers
     */
    private function renderWorkers(OutputInterface $output, array $workers): void
    {
        $required = is_scalar($workers['required_compatibility'] ?? null) && (string) $workers['required_compatibility'] !== ''
            ? (string) $workers['required_compatibility']
            : '(unset)';
        $active = (int) ($workers['active_workers'] ?? 0);
        $scopes = (int) ($workers['active_worker_scopes'] ?? 0);
        $supporting = (int) ($workers['active_workers_supporting_required'] ?? 0);

        $output->writeln('<info>Worker compatibility fleet</info>');
        $output->writeln(sprintf('  Required compatibility: %s', $required));
        $output->writeln(sprintf(
            '  Active workers:         %d (%d queue scopes, %d supporting required)',
            $active,
            $scopes,
            $supporting,
        ));

        if ($active > 0 && $supporting === 0) {
            $output->writeln('  <comment>No active worker supports the required compatibility marker — new ready tasks will remain claim-blocked.</comment>');
        }

        $fleet = is_array($workers['fleet'] ?? null) ? $workers['fleet'] : [];
        $fleetRows = [];
        foreach ($fleet as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $supported = is_array($entry['supported'] ?? null)
                ? implode(',', array_filter($entry['supported'], 'is_string'))
                : '';
            $fleetRows[] = [
                (string) ($entry['worker_id'] ?? ''),
                (string) ($entry['connection'] ?? '-'),
                (string) ($entry['queue'] ?? '-'),
                $supported === '' ? '-' : $supported,
                ($entry['supports_required'] ?? false) === true ? 'yes' : 'no',
                is_string($entry['recorded_at'] ?? null) ? $entry['recorded_at'] : '-',
            ];
        }

        if ($fleetRows !== []) {
            $output->writeln('');
            $this->renderTable(
                $output,
                ['Worker', 'Connection', 'Queue', 'Supported', 'Supports required', 'Recorded'],
                $fleetRows,
            );
        }

        $output->writeln('');
    }

    /**
     * @param  array<string, mixed>  $backend
     */
    private function renderBackend(OutputInterface $output, array $backend): void
    {
        $supported = ($backend['supported'] ?? false) === true;
        $output->writeln('<info>Backend capabilities (admission roll-up)</info>');
        $output->writeln(sprintf('  Supported:            %s', $supported ? 'yes' : 'no'));

        foreach (['database', 'queue', 'cache'] as $component) {
            $detail = is_array($backend[$component] ?? null) ? $backend[$component] : [];
            $label = match ($component) {
                'cache' => (string) ($detail['store'] ?? 'unknown').'/'.(string) ($detail['driver'] ?? 'unknown'),
                default => (string) ($detail['connection'] ?? 'unknown').'/'.(string) ($detail['driver'] ?? 'unknown'),
            };
            $output->writeln(sprintf('  %-22s %s', ucfirst($component).':', $label));
        }

        $issues = is_array($backend['issues'] ?? null) ? $backend['issues'] : [];
        if ($issues !== []) {
            $output->writeln('  <comment>Issues:</comment>');
            foreach ($issues as $issue) {
                if (! is_array($issue)) {
                    continue;
                }
                $summary = (string) ($issue['summary'] ?? $issue['code'] ?? $issue['component'] ?? 'capability issue');
                $severity = (string) ($issue['severity'] ?? 'warning');
                $output->writeln(sprintf('    [%s] %s', $severity, $summary));
            }
        }
        $output->writeln('');
    }

    /**
     * @param  array<string, mixed>  $matchingRole
     */
    private function renderMatchingRole(OutputInterface $output, array $matchingRole): void
    {
        if ($matchingRole === []) {
            return;
        }

        $output->writeln('<info>Matching-role (this node)</info>');

        if (array_key_exists('queue_wake_enabled', $matchingRole)) {
            $output->writeln(sprintf(
                '  Queue wake enabled:   %s',
                ((bool) $matchingRole['queue_wake_enabled']) ? 'yes' : 'no',
            ));
        }

        if (is_string($matchingRole['shape'] ?? null) && $matchingRole['shape'] !== '') {
            $output->writeln(sprintf('  Shape:                %s', $matchingRole['shape']));
        }

        if (is_string($matchingRole['task_dispatch_mode'] ?? null) && $matchingRole['task_dispatch_mode'] !== '') {
            $output->writeln(sprintf('  Task dispatch mode:   %s', $matchingRole['task_dispatch_mode']));
        }

        $output->writeln('');
    }

    /**
     * @param  array<string, mixed>  $schedules
     */
    private function renderSchedules(OutputInterface $output, array $schedules): void
    {
        if ($schedules === []) {
            return;
        }

        $output->writeln('<info>Schedules</info>');
        $output->writeln(sprintf(
            '  Active %d, paused %d, missed %d, oldest overdue %d ms',
            (int) ($schedules['active'] ?? 0),
            (int) ($schedules['paused'] ?? 0),
            (int) ($schedules['missed'] ?? 0),
            (int) ($schedules['max_overdue_ms'] ?? 0),
        ));
        if (is_string($schedules['oldest_overdue_at'] ?? null)) {
            $output->writeln(sprintf('  Oldest overdue at:    %s', $schedules['oldest_overdue_at']));
        }
        $output->writeln(sprintf(
            '  Lifetime fires: %d (%d failures)',
            (int) ($schedules['fires_total'] ?? 0),
            (int) ($schedules['failures_total'] ?? 0),
        ));
        $output->writeln('');
    }

    /**
     * @param  array<string, mixed>  $activities
     */
    private function renderActivities(OutputInterface $output, array $activities): void
    {
        if ($activities === []) {
            return;
        }

        $output->writeln('<info>Activities</info>');
        $output->writeln(sprintf(
            '  Open %d (pending %d, running %d), retrying %d',
            (int) ($activities['open'] ?? 0),
            (int) ($activities['pending'] ?? 0),
            (int) ($activities['running'] ?? 0),
            (int) ($activities['retrying'] ?? 0),
        ));
        if (array_key_exists('max_retrying_age_ms', $activities)) {
            $output->writeln(sprintf(
                '  Oldest retrying age:  %d ms',
                (int) ($activities['max_retrying_age_ms'] ?? 0),
            ));
        }
        if (is_string($activities['oldest_retrying_started_at'] ?? null)) {
            $output->writeln(sprintf('  Oldest retrying started at: %s', $activities['oldest_retrying_started_at']));
        }
        $output->writeln(sprintf(
            '  Failed attempts:      %d (max attempts on a single execution: %d)',
            (int) ($activities['failed_attempts'] ?? 0),
            (int) ($activities['max_attempt_count'] ?? 0),
        ));
        $output->writeln('');
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function renderRepairPolicy(OutputInterface $output, array $policy): void
    {
        $output->writeln('<info>Repair policy</info>');
        $output->writeln(sprintf('  Redispatch after:     %ds', (int) ($policy['redispatch_after_seconds'] ?? 0)));
        $output->writeln(sprintf('  Loop throttle:        %ds', (int) ($policy['loop_throttle_seconds'] ?? 0)));
        $output->writeln(sprintf('  Scan limit:           %d', (int) ($policy['scan_limit'] ?? 0)));
        $output->writeln(sprintf('  Backoff cap:          %ds', (int) ($policy['failure_backoff_max_seconds'] ?? 0)));
    }
}
