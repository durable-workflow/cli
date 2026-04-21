<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\TaskQueueCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('task-queue:list')
            ->setDescription('List task queues with active pollers')
            ->setHelp(<<<'HELP'
List every task queue known to the namespace.

<comment>Examples:</comment>

  <info>dw task-queue:list</info>
  <info>dw task-queue:list --output=json | jq '.task_queues[].name'</info>
  <info>dw task-queue:list --output=jsonl | jq '.name'</info>
HELP)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON (alias for --output=json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client($input)->get('/task-queues');

        if ($this->wantsJson($input)) {
            return $this->renderJsonList($output, $input, $result, 'task_queues');
        }

        $queues = $result['task_queues'] ?? [];

        if (empty($queues)) {
            $output->writeln('<comment>No task queues found.</comment>');

            return Command::SUCCESS;
        }

        $rows = array_map(fn ($q) => [
            $q['name'],
            $this->formatStatus($this->admissionStatus($q, 'workflow_tasks')),
            $this->formatStatus($this->admissionStatus($q, 'activity_tasks')),
            $this->formatStatus($this->admissionStatus($q, 'query_tasks')),
        ], $queues);

        $this->renderTable($output, ['Task Queue', 'Workflow Admission', 'Activity Admission', 'Query Admission'], $rows);

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $queue
     */
    private function admissionStatus(array $queue, string $kind): string
    {
        $admission = $queue['admission'] ?? null;

        if (! is_array($admission) || ! is_array($admission[$kind] ?? null)) {
            return '-';
        }

        $status = (string) ($admission[$kind]['status'] ?? '-');

        if ($kind === 'query_tasks') {
            $remaining = $admission[$kind]['remaining_pending_capacity'] ?? null;

            return $remaining === null ? $status : sprintf('%s (%s left)', $status, $remaining);
        }

        $details = [];

        $remaining = $admission[$kind]['server_remaining_active_lease_capacity']
            ?? $admission[$kind]['remaining_server_capacity']
            ?? null;
        if ($remaining === null) {
            $remaining = $admission[$kind]['available_slot_count'] ?? null;
        }
        if ($remaining !== null) {
            $details[] = sprintf('%s leases left', $remaining);
        }

        $namespaceRemaining = $admission[$kind]['server_remaining_namespace_active_lease_capacity'] ?? null;
        if ($namespaceRemaining !== null) {
            $details[] = sprintf('%s namespace leases left', $namespaceRemaining);
        }

        $dispatchRemaining = $admission[$kind]['server_remaining_dispatch_capacity'] ?? null;
        if ($dispatchRemaining !== null) {
            $details[] = sprintf('%s/min left', $dispatchRemaining);
        }

        $namespaceDispatchRemaining = $admission[$kind]['server_remaining_namespace_dispatch_capacity'] ?? null;
        if ($namespaceDispatchRemaining !== null) {
            $details[] = sprintf('%s namespace/min left', $namespaceDispatchRemaining);
        }

        return $details === [] ? $status : sprintf('%s (%s)', $status, implode(', ', $details));
    }
}
