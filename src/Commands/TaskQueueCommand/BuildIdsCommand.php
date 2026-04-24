<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\TaskQueueCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BuildIdsCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('task-queue:build-ids')
            ->setDescription('Show worker build-id rollout state for a task queue')
            ->setHelp(<<<'HELP'
Report the build-id rollout state for a task queue. For each build cohort
that has ever registered on the queue, the command reports active,
draining, and stale worker counts, rollout status, runtime and SDK-version
mix, first-seen timestamp, and most-recent heartbeat. Unversioned workers
are grouped under a null build_id row.

Build ids are the compatibility markers that pin workflow runs to a
compatibility family. A run stamped at start with marker <comment>M</comment>
is claimed only by workers whose supported set covers <comment>M</comment>
(or the workers-only <comment>*</comment> wildcard). Retry, continue-as-new,
and child runs inherit the parent run's marker, so a task on this queue
stays inside one family for its whole lifecycle. Use this command before
draining or removing an older build to confirm which builds can still
claim work on the queue without leaving pinned runs in the explicit
"no compatible worker is registered yet" state.

<comment>Examples:</comment>

  <info>dw task-queue:build-ids orders</info>
  <info>dw task-queue:build-ids orders --json | jq '.build_ids[].rollout_status'</info>
HELP)
            ->addArgument('task-queue', InputArgument::REQUIRED, 'Task queue name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskQueue = $input->getArgument('task-queue');
        $result = $this->client($input)->get("/task-queues/{$taskQueue}/build-ids");

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Task Queue: '.($result['task_queue'] ?? $taskQueue).'</info>');
        $staleAfter = $result['stale_after_seconds'] ?? null;
        if ($staleAfter !== null) {
            $output->writeln('Stale threshold: '.$staleAfter.'s');
        }
        $output->writeln('');

        $buildIds = $result['build_ids'] ?? [];

        if (empty($buildIds)) {
            $output->writeln('<comment>No workers have registered on this task queue.</comment>');

            return Command::SUCCESS;
        }

        $rows = array_map(function (array $entry): array {
            $runtimes = $entry['runtimes'] ?? [];
            $sdkVersions = $entry['sdk_versions'] ?? [];

            return [
                $this->formatBuildId($entry['build_id'] ?? null),
                $this->formatStatus($entry['rollout_status'] ?? null),
                (string) ($entry['active_worker_count'] ?? 0),
                (string) ($entry['draining_worker_count'] ?? 0),
                (string) ($entry['stale_worker_count'] ?? 0),
                (string) ($entry['total_worker_count'] ?? 0),
                $this->joinList($runtimes),
                $this->joinList($sdkVersions),
                (string) ($entry['last_heartbeat_at'] ?? '-'),
                (string) ($entry['first_seen_at'] ?? '-'),
            ];
        }, $buildIds);

        $this->renderTable(
            $output,
            ['Build ID', 'Rollout', 'Active', 'Draining', 'Stale', 'Total', 'Runtimes', 'SDK Versions', 'Last Heartbeat', 'First Seen'],
            $rows,
        );

        return Command::SUCCESS;
    }

    private function formatBuildId(mixed $buildId): string
    {
        if (! is_string($buildId) || $buildId === '') {
            return '(unversioned)';
        }

        return $buildId;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function joinList(array $values): string
    {
        $strings = array_values(array_filter($values, 'is_string'));

        return $strings === [] ? '-' : implode(', ', $strings);
    }
}
