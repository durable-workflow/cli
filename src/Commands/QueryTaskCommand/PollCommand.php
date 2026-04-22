<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\QueryTaskCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PollCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('query-task:poll')
            ->setDescription('Poll and lease one routed workflow query task')
            ->setHelp(<<<'HELP'
Poll the worker protocol directly for one routed workflow query task. This is
intended for diagnostics and CLI/SDK parity checks; production workers should
use an SDK.

<comment>Examples:</comment>

  <info>dw query-task:poll cli-worker --task-queue=orders</info>
  <info>dw query-task:poll cli-worker --task-queue=orders --json</info>
HELP)
            ->addArgument('worker-id', InputArgument::REQUIRED, 'Registered worker ID')
            ->addOption('task-queue', null, InputOption::VALUE_REQUIRED, 'Task queue to poll', 'default')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client($input)->post('/worker/query-tasks/poll', [
            'worker_id' => $input->getArgument('worker-id'),
            'task_queue' => $input->getOption('task-queue'),
        ]);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $task = $result['task'] ?? null;

        if (! is_array($task)) {
            $output->writeln('<comment>No query task available.</comment>');

            return Command::SUCCESS;
        }

        $output->writeln('<info>Query task leased</info>');
        $output->writeln('  Task ID: '.($task['query_task_id'] ?? '-'));
        $output->writeln('  Workflow ID: '.($task['workflow_id'] ?? '-'));
        $output->writeln('  Run ID: '.($task['run_id'] ?? '-'));
        $output->writeln('  Query: '.($task['query_name'] ?? '-'));
        $output->writeln('  Attempt: '.($task['query_task_attempt'] ?? '-'));

        return Command::SUCCESS;
    }
}
