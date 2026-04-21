<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListRunsCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:list-runs')
            ->setDescription('List all runs for a workflow execution')
            ->setHelp(<<<'HELP'
List every run (including retries and continue-as-new successors) for a
workflow ID, oldest first.

<comment>Examples:</comment>

  <info>dw workflow:list-runs chk-42</info>
  <info>dw workflow:list-runs chk-42 --json</info>
HELP)
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow-id');

        $result = $this->client($input)->get("/workflows/{$workflowId}/runs");

        if ($this->wantsJson($input)) {
            return $this->renderJsonList($output, $input, $result, 'runs');
        }

        $runs = $result['runs'] ?? [];

        if (empty($runs)) {
            $output->writeln('<comment>No runs found.</comment>');

            return Command::SUCCESS;
        }

        $output->writeln('<info>Workflow: '.$workflowId.'</info>');
        $output->writeln('Run Count: '.($result['run_count'] ?? count($runs)));
        $output->writeln('');

        $rows = array_map(fn ($r) => [
            $r['run_id'] ?? '-',
            $r['run_number'] ?? '-',
            $r['workflow_type'] ?? '-',
            $this->formatStatus($r['status'] ?? null),
            $r['task_queue'] ?? '-',
            $r['started_at'] ?? '-',
            $r['closed_at'] ?? '-',
        ], $runs);

        $this->renderTable($output, ['Run ID', '#', 'Type', 'Status', 'Task Queue', 'Started', 'Closed'], $rows);

        return Command::SUCCESS;
    }
}
