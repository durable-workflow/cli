<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowTaskCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\InvalidOptionException;
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
        $this->setName('workflow-task:poll')
            ->setDescription('Poll and lease one workflow task')
            ->setHelp(<<<'HELP'
Poll the worker protocol directly for one workflow task. This is intended
for diagnostics and smoke tests; production workers should use an SDK.

<comment>Examples:</comment>

  <info>dw workflow-task:poll cli-worker --task-queue=orders</info>
  <info>dw workflow-task:poll cli-worker --task-queue=orders --history-page-size=100 --json</info>
HELP)
            ->addArgument('worker-id', InputArgument::REQUIRED, 'Registered worker ID')
            ->addOption('task-queue', null, InputOption::VALUE_REQUIRED, 'Task queue to poll', 'default')
            ->addOption('build-id', null, InputOption::VALUE_OPTIONAL, 'Compatibility build ID')
            ->addOption('poll-request-id', null, InputOption::VALUE_OPTIONAL, 'Idempotency key for retrying a poll')
            ->addOption('history-page-size', null, InputOption::VALUE_OPTIONAL, 'Maximum history events in the first page')
            ->addOption('accept-history-encoding', null, InputOption::VALUE_OPTIONAL, 'Accepted compressed history encoding')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $body = array_filter([
            'worker_id' => $input->getArgument('worker-id'),
            'task_queue' => $input->getOption('task-queue'),
            'build_id' => $input->getOption('build-id'),
            'poll_request_id' => $input->getOption('poll-request-id'),
            'history_page_size' => $this->positiveIntOption($input, 'history-page-size'),
            'accept_history_encoding' => $input->getOption('accept-history-encoding'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $result = $this->client($input)->post('/worker/workflow-tasks/poll', $body);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $task = $result['task'] ?? null;

        if (! is_array($task)) {
            $output->writeln('<comment>No workflow task available.</comment>');

            return Command::SUCCESS;
        }

        $output->writeln('<info>Workflow task leased</info>');
        $output->writeln('  Task ID: '.($task['task_id'] ?? '-'));
        $output->writeln('  Workflow ID: '.($task['workflow_id'] ?? '-'));
        $output->writeln('  Run ID: '.($task['run_id'] ?? '-'));
        $output->writeln('  Attempt: '.($task['workflow_task_attempt'] ?? '-'));
        $output->writeln('  Lease Owner: '.($task['lease_owner'] ?? '-'));
        $output->writeln('  History Events: '.count(is_array($task['history_events'] ?? null) ? $task['history_events'] : []));

        if (($task['next_history_page_token'] ?? null) !== null) {
            $output->writeln('  Next History Page Token: '.$task['next_history_page_token']);
        }

        return Command::SUCCESS;
    }

    private function positiveIntOption(InputInterface $input, string $option): ?int
    {
        $value = $input->getOption($option);

        if ($value === null || $value === '') {
            return null;
        }

        if (! ctype_digit((string) $value) || (int) $value < 1) {
            throw new InvalidOptionException(sprintf('--%s must be a positive integer.', $option));
        }

        return (int) $value;
    }
}
