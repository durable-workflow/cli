<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\TaskQueueCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DescribeCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('task-queue:describe')
            ->setDescription('Show task queue details including pollers and backlog')
            ->setHelp(<<<'HELP'
Show backlog size, leased tasks, expired leases, and which workers are
currently polling the queue. Use this when troubleshooting stalled
workflows or runaway activity retries.

<comment>Examples:</comment>

  <info>dw task-queue:describe orders</info>
  <info>dw task-queue:describe orders --json | jq '.stats.approximate_backlog_count'</info>
HELP)
            ->addArgument('task-queue', InputArgument::REQUIRED, 'Task queue name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskQueue = $input->getArgument('task-queue');
        $result = $this->client($input)->get("/task-queues/{$taskQueue}");

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Task Queue: '.$result['name'].'</info>');
        $output->writeln('');

        $stats = $result['stats'] ?? [];
        $output->writeln('Stats:');
        $output->writeln('  Backlog: '.($stats['approximate_backlog_count'] ?? 0));
        $output->writeln('  Backlog Age: '.($stats['approximate_backlog_age'] ?? '-'));
        $output->writeln('  Workflow Ready: '.($stats['workflow_tasks']['ready_count'] ?? 0));
        $output->writeln('  Workflow Leased: '.($stats['workflow_tasks']['leased_count'] ?? 0));
        $output->writeln('  Workflow Expired Leases: '.($stats['workflow_tasks']['expired_lease_count'] ?? 0));
        $output->writeln('  Activity Ready: '.($stats['activity_tasks']['ready_count'] ?? 0));
        $output->writeln('  Activity Leased: '.($stats['activity_tasks']['leased_count'] ?? 0));
        $output->writeln('  Activity Expired Leases: '.($stats['activity_tasks']['expired_lease_count'] ?? 0));
        $output->writeln(sprintf(
            '  Pollers: active=%d stale=%d',
            (int) ($stats['pollers']['active_count'] ?? 0),
            (int) ($stats['pollers']['stale_count'] ?? 0),
        ));
        $output->writeln('');

        $admission = $result['admission'] ?? [];
        if (is_array($admission) && $admission !== []) {
            $output->writeln('Admission:');
            $this->renderAdmissionTable($output, $admission);
            $output->writeln('');
        }

        $leases = $result['current_leases'] ?? [];
        if (! empty($leases)) {
            $rows = array_map(fn (array $lease): array => [
                $lease['task_id'] ?? '-',
                $lease['task_type'] ?? '-',
                $lease['workflow_id'] ?? '-',
                $lease['lease_owner'] ?? '-',
                $lease['lease_expires_at'] ?? '-',
                $this->formatStatus(($lease['is_expired'] ?? false) ? 'EXPIRED' : 'active'),
            ], $leases);

            $output->writeln('Current Leases:');
            $this->renderTable(
                $output,
                ['Task ID', 'Type', 'Workflow ID', 'Lease Owner', 'Lease Expires', 'State'],
                $rows,
            );
            $output->writeln('');
        }

        $pollers = $result['pollers'] ?? [];
        if (empty($pollers)) {
            $output->writeln('<comment>No active pollers.</comment>');
        } else {
            $rows = array_map(fn ($p) => [
                $p['worker_id'] ?? '-',
                $p['runtime'] ?? '-',
                $p['build_id'] ?? '-',
                $p['last_heartbeat_at'] ?? '-',
                $this->formatStatus($p['status'] ?? null),
            ], $pollers);

            $output->writeln('Pollers:');
            $this->renderTable($output, ['Worker ID', 'Runtime', 'Build ID', 'Last Heartbeat', 'Status'], $rows);
        }

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $admission
     */
    private function renderAdmissionTable(OutputInterface $output, array $admission): void
    {
        $this->renderTable($output, ['Kind', 'Status', 'Capacity', 'Dispatch', 'Source'], [
            $this->admissionRow('Workflow Tasks', $admission['workflow_tasks'] ?? null, false),
            $this->admissionRow('Activity Tasks', $admission['activity_tasks'] ?? null, false),
            $this->admissionRow('Query Tasks', $admission['query_tasks'] ?? null, true),
        ]);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    private function admissionRow(string $label, mixed $admission, bool $queryTasks): array
    {
        if (! is_array($admission)) {
            return [$label, '-', '-', '-', '-'];
        }

        $capacity = [];
        $dispatch = [];
        $source = $admission['budget_source'] ?? null;

        if ($queryTasks) {
            $pending = $admission['approximate_pending_count'] ?? null;
            $limit = $admission['max_pending_per_queue'] ?? null;

            if ($pending !== null || $limit !== null) {
                $capacity[] = sprintf('pending %s/%s', $pending ?? '-', $limit ?? '-');
            }

            if (array_key_exists('remaining_pending_capacity', $admission)) {
                $capacity[] = 'remaining '.$admission['remaining_pending_capacity'];
            }

            if (array_key_exists('lock_supported', $admission)) {
                $capacity[] = 'lock '.($admission['lock_supported'] ? 'yes' : 'no');
            }
        } else {
            $active = $admission['server_active_lease_count'] ?? $admission['active_lease_count'] ?? $admission['leased_count'] ?? null;
            $limit = $admission['server_max_active_leases_per_queue'] ?? $admission['server_limit'] ?? $admission['configured_slot_count'] ?? null;

            if ($active !== null || $limit !== null) {
                $capacity[] = sprintf('active %s/%s', $active ?? '-', $limit ?? '-');
            }

            if (($admission['server_remaining_active_lease_capacity'] ?? null) !== null) {
                $capacity[] = 'remaining '.$admission['server_remaining_active_lease_capacity'];
            } elseif (($admission['remaining_server_capacity'] ?? null) !== null) {
                $capacity[] = 'remaining '.$admission['remaining_server_capacity'];
            } elseif (($admission['available_slot_count'] ?? null) !== null) {
                $capacity[] = 'remaining '.$admission['available_slot_count'];
            }

            $namespaceActive = $admission['server_namespace_active_lease_count'] ?? null;
            $namespaceLimit = $admission['server_max_active_leases_per_namespace'] ?? null;

            if ($namespaceActive !== null || $namespaceLimit !== null) {
                $capacity[] = sprintf('namespace active %s/%s', $namespaceActive ?? '-', $namespaceLimit ?? '-');
            }

            if (($admission['server_remaining_namespace_active_lease_capacity'] ?? null) !== null) {
                $capacity[] = 'namespace remaining '.$admission['server_remaining_namespace_active_lease_capacity'];
            }

            $dispatchCount = $admission['server_dispatch_count_this_minute'] ?? null;
            $dispatchLimit = $admission['server_max_dispatches_per_minute'] ?? null;

            if ($dispatchCount !== null || $dispatchLimit !== null) {
                $dispatch[] = sprintf('dispatch %s/%s/min', $dispatchCount ?? '-', $dispatchLimit ?? '-');
            }

            if (($admission['server_remaining_dispatch_capacity'] ?? null) !== null) {
                $dispatch[] = 'dispatch remaining '.$admission['server_remaining_dispatch_capacity'];
            }

            $namespaceDispatchCount = $admission['server_namespace_dispatch_count_this_minute'] ?? null;
            $namespaceDispatchLimit = $admission['server_max_dispatches_per_minute_per_namespace'] ?? null;

            if ($namespaceDispatchCount !== null || $namespaceDispatchLimit !== null) {
                $dispatch[] = sprintf('namespace dispatch %s/%s/min', $namespaceDispatchCount ?? '-', $namespaceDispatchLimit ?? '-');
            }

            if (($admission['server_remaining_namespace_dispatch_capacity'] ?? null) !== null) {
                $dispatch[] = 'namespace dispatch remaining '.$admission['server_remaining_namespace_dispatch_capacity'];
            }

            $budgetGroup = $admission['server_dispatch_budget_group'] ?? null;
            $budgetGroupDispatchCount = $admission['server_budget_group_dispatch_count_this_minute'] ?? null;
            $budgetGroupDispatchLimit = $admission['server_max_dispatches_per_minute_per_budget_group'] ?? null;

            if ($budgetGroupDispatchCount !== null || $budgetGroupDispatchLimit !== null) {
                $group = sprintf('group %s/%s/min', $budgetGroupDispatchCount ?? '-', $budgetGroupDispatchLimit ?? '-');
                if ($budgetGroup !== null && $budgetGroup !== '') {
                    $group = sprintf('group %s %s/%s/min', $budgetGroup, $budgetGroupDispatchCount ?? '-', $budgetGroupDispatchLimit ?? '-');
                }

                $dispatch[] = $group;
            } elseif ($budgetGroup !== null && $budgetGroup !== '') {
                $dispatch[] = 'group '.$budgetGroup;
            }

            if (($admission['server_remaining_budget_group_dispatch_capacity'] ?? null) !== null) {
                $dispatch[] = 'group remaining '.$admission['server_remaining_budget_group_dispatch_capacity'];
            }

            if (
                ($admission['server_max_active_leases_per_queue'] ?? $admission['server_limit'] ?? null) !== null
                || ($admission['server_max_active_leases_per_namespace'] ?? null) !== null
                || ($admission['server_max_dispatches_per_minute'] ?? null) !== null
                || ($admission['server_max_dispatches_per_minute_per_namespace'] ?? null) !== null
                || ($admission['server_max_dispatches_per_minute_per_budget_group'] ?? null) !== null
            ) {
                $source = $admission['server_budget_source'] ?? $source;
            }
        }

        return [
            $label,
            $this->formatStatus($admission['status'] ?? null),
            $capacity === [] ? '-' : implode('; ', $capacity),
            $dispatch === [] ? '-' : implode('; ', $dispatch),
            is_scalar($source) && (string) $source !== '' ? (string) $source : '-',
        ];
    }
}
