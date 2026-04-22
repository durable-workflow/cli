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

class HistoryCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow-task:history')
            ->setDescription('Fetch the next history page for a leased workflow task')
            ->setHelp(<<<'HELP'
Fetch additional workflow history for a leased workflow task when the initial
poll response includes a next_history_page_token. This is a worker-protocol
diagnostic command for SDK parity and smoke testing.

<comment>Examples:</comment>

  <info>dw workflow-task:history task-123 page-2 --lease-owner=cli-worker --attempt=1</info>
  <info>dw workflow-task:history task-123 page-2 --lease-owner=cli-worker --attempt=1 --json</info>
HELP)
            ->addArgument('task-id', InputArgument::REQUIRED, 'Workflow task ID')
            ->addArgument('page-token', InputArgument::REQUIRED, 'History page token from workflow-task:poll')
            ->addOption('lease-owner', null, InputOption::VALUE_REQUIRED, 'Lease owner identity', 'cli')
            ->addOption('attempt', null, InputOption::VALUE_REQUIRED, 'Workflow task attempt number')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskId = (string) $input->getArgument('task-id');
        $attempt = $this->positiveAttempt((string) $input->getOption('attempt'));

        $result = $this->client($input)->post("/worker/workflow-tasks/{$taskId}/history", [
            'next_history_page_token' => $input->getArgument('page-token'),
            'lease_owner' => $input->getOption('lease-owner'),
            'workflow_task_attempt' => $attempt,
        ]);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $events = is_array($result['history_events'] ?? null) ? $result['history_events'] : [];
        $output->writeln('<info>Workflow task history page fetched</info>');
        $output->writeln('  Task ID: '.$taskId);
        $output->writeln('  Attempt: '.$attempt);
        $output->writeln('  Events: '.count($events));

        if (($result['next_history_page_token'] ?? null) !== null) {
            $output->writeln('  Next History Page Token: '.$result['next_history_page_token']);
        }

        return Command::SUCCESS;
    }

    private function positiveAttempt(string $value): int
    {
        if (! ctype_digit($value) || (int) $value < 1) {
            throw new InvalidOptionException('--attempt must be a positive integer.');
        }

        return (int) $value;
    }
}
