<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class HistoryCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:history')
            ->setDescription('Show event history for a workflow run')
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addArgument('run-id', InputArgument::REQUIRED, 'Run ID')
            ->addOption('follow', 'f', InputOption::VALUE_NONE, 'Follow new events (long-poll)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow-id');
        $runId = $input->getArgument('run-id');
        $follow = $input->getOption('follow');

        $result = $this->client($input)->get("/workflows/{$workflowId}/runs/{$runId}/history", [
            'wait_new_event' => $follow,
        ]);

        if ($input->getOption('json')) {
            return $this->renderJson($output, $result);
        }

        $events = $result['events'] ?? [];

        if (empty($events)) {
            $output->writeln('<comment>No history events.</comment>');

            return Command::SUCCESS;
        }

        $rows = array_map(fn ($e) => [
            $e['sequence'] ?? '-',
            $e['event_type'] ?? '-',
            $e['timestamp'] ?? '-',
            json_encode($e['details'] ?? null, JSON_UNESCAPED_SLASHES),
        ], $events);

        $this->renderTable($output, ['#', 'Event Type', 'Time', 'Details'], $rows);

        return Command::SUCCESS;
    }
}
