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
            $this->renderAdmissionLine($output, 'Workflow Tasks', $admission['workflow_tasks'] ?? null, false);
            $this->renderAdmissionLine($output, 'Activity Tasks', $admission['activity_tasks'] ?? null, false);
            $this->renderAdmissionLine($output, 'Query Tasks', $admission['query_tasks'] ?? null, true);
            $output->writeln('');
        }

        $leases = $result['current_leases'] ?? [];
        if (! empty($leases)) {
            $rows = array_map(static fn (array $lease): array => [
                $lease['task_id'] ?? '-',
                $lease['task_type'] ?? '-',
                $lease['workflow_id'] ?? '-',
                $lease['lease_owner'] ?? '-',
                $lease['lease_expires_at'] ?? '-',
                ($lease['is_expired'] ?? false) ? 'EXPIRED' : 'active',
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
                $p['status'] ?? '-',
            ], $pollers);

            $output->writeln('Pollers:');
            $this->renderTable($output, ['Worker ID', 'Runtime', 'Build ID', 'Last Heartbeat', 'Status'], $rows);
        }

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, mixed>|null  $admission
     */
    private function renderAdmissionLine(OutputInterface $output, string $label, mixed $admission, bool $queryTasks): void
    {
        if (! is_array($admission)) {
            $output->writeln(sprintf('  %s: -', $label));

            return;
        }

        $parts = [
            'status='.($admission['status'] ?? '-'),
        ];

        if ($queryTasks) {
            $pending = $admission['approximate_pending_count'] ?? null;
            $limit = $admission['max_pending_per_queue'] ?? null;

            if ($pending !== null || $limit !== null) {
                $parts[] = sprintf('pending=%s/%s', $pending ?? '-', $limit ?? '-');
            }

            if (array_key_exists('remaining_pending_capacity', $admission)) {
                $parts[] = 'remaining='.$admission['remaining_pending_capacity'];
            }

            if (array_key_exists('lock_supported', $admission)) {
                $parts[] = 'lock='.($admission['lock_supported'] ? 'yes' : 'no');
            }
        } else {
            $active = $admission['server_active_lease_count'] ?? $admission['active_lease_count'] ?? $admission['leased_count'] ?? null;
            $limit = $admission['server_max_active_leases_per_queue'] ?? $admission['server_limit'] ?? $admission['configured_slot_count'] ?? null;

            if ($active !== null || $limit !== null) {
                $parts[] = sprintf('active=%s/%s', $active ?? '-', $limit ?? '-');
            }

            if (array_key_exists('server_remaining_active_lease_capacity', $admission)) {
                $parts[] = 'remaining='.$admission['server_remaining_active_lease_capacity'];
            } elseif (array_key_exists('remaining_server_capacity', $admission)) {
                $parts[] = 'remaining='.$admission['remaining_server_capacity'];
            } elseif (array_key_exists('available_slot_count', $admission)) {
                $parts[] = 'remaining='.$admission['available_slot_count'];
            }

            $dispatchCount = $admission['server_dispatch_count_this_minute'] ?? null;
            $dispatchLimit = $admission['server_max_dispatches_per_minute'] ?? null;

            if ($dispatchCount !== null || $dispatchLimit !== null) {
                $parts[] = sprintf('dispatches=%s/%s/min', $dispatchCount ?? '-', $dispatchLimit ?? '-');
            }

            if (array_key_exists('server_remaining_dispatch_capacity', $admission)) {
                $parts[] = 'dispatch_remaining='.$admission['server_remaining_dispatch_capacity'];
            }
        }

        $source = $admission['budget_source'] ?? null;
        if (! $queryTasks && ($admission['server_max_active_leases_per_queue'] ?? $admission['server_limit'] ?? null) !== null) {
            $source = $admission['server_budget_source'] ?? $source;
        }

        if ($source !== null) {
            $parts[] = 'source='.$source;
        }

        $output->writeln(sprintf('  %s: %s', $label, implode(' ', $parts)));
    }
}
