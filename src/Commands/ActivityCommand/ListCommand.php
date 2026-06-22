<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ActivityCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\CompletionValues;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('activity:list')
            ->setAliases(['activities:list'])
            ->setDescription('List standalone activity executions')
            ->setHelp(<<<'HELP'
List standalone activities in the current namespace. Use
<comment>--output=json</comment> for the machine-readable operator view
of activity execution ids, current attempt state, and historical attempt
rows.

<comment>Examples:</comment>

  <info>dw activity:list</info>
  <info>dw activity:list --status=running --limit=100</info>
  <info>dw activity:list --output=json | jq '.activities[].attempts'</info>
  <info>dw activities:list --namespace=orders --output=jsonl</info>
HELP)
            ->addOption('status', null, InputOption::VALUE_OPTIONAL, 'Filter by status bucket (running, completed, failed)', null, CompletionValues::WORKFLOW_STATUSES)
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Page size', '50')
            ->addOption('next-page-token', null, InputOption::VALUE_OPTIONAL, 'Pagination token from a previous response')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->addNamespaceContext($input, $this->client($input)->get('/activities', array_filter([
            'status' => $input->getOption('status'),
            'page_size' => (int) $input->getOption('limit'),
            'next_page_token' => $input->getOption('next-page-token'),
        ], static fn (mixed $value): bool => $value !== null)), 'activities');

        if ($this->wantsJson($input)) {
            return $this->renderJsonList($output, $input, $result, 'activities');
        }

        $activities = $result['activities'] ?? [];
        $namespace = $this->namespaceContext($input, $result);

        if (! is_array($activities) || $activities === []) {
            $output->writeln(sprintf('<comment>No activities found in namespace %s.</comment>', $namespace));

            return Command::SUCCESS;
        }

        $output->writeln('Namespace: '.$namespace);

        $rows = array_map(fn (mixed $activity): array => is_array($activity) ? [
            $activity['activity_id'] ?? '-',
            $activity['activity_type'] ?? '-',
            $this->formatStatus($activity['activity_status'] ?? ($activity['status'] ?? null)),
            $this->formatStatus($activity['current_attempt_status'] ?? null),
            $activity['current_attempt_id'] ?? '-',
            $activity['task_queue'] ?? '-',
            $activity['started_at'] ?? '-',
            $activity['closed_at'] ?? '-',
        ] : ['-', '-', '-', '-', '-', '-', '-', '-'], $activities);

        $this->renderTable(
            $output,
            ['Activity ID', 'Type', 'Status', 'Attempt', 'Attempt ID', 'Task Queue', 'Started', 'Closed'],
            $rows,
        );

        return Command::SUCCESS;
    }
}
