<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ActivityCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FailCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('activity:fail')
            ->setDescription('Fail an activity task externally')
            ->setHelp(<<<'HELP'
Fail an activity from outside the worker process. <comment>--non-retryable</comment>
tells the server to skip the workflow's retry policy and surface the
failure to the workflow immediately.

<comment>Examples:</comment>

  <info>dw activity:fail act-123 att-456 -m "upstream returned 500"</info>
  <info>dw activity:fail act-123 att-456 -m "bad input" -t ValidationError --non-retryable</info>
HELP)
            ->addArgument('task-id', InputArgument::REQUIRED, 'Activity task ID')
            ->addArgument('attempt-id', InputArgument::REQUIRED, 'Leased activity attempt ID')
            ->addOption('lease-owner', null, InputOption::VALUE_OPTIONAL, 'Lease owner identity', 'cli')
            ->addOption('message', 'm', InputOption::VALUE_REQUIRED, 'Failure message')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Failure type')
            ->addOption('non-retryable', null, InputOption::VALUE_NONE, 'Mark as non-retryable');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskId = $input->getArgument('task-id');
        $attemptId = $input->getArgument('attempt-id');

        $body = [
            'activity_attempt_id' => $attemptId,
            'lease_owner' => $input->getOption('lease-owner'),
            'failure' => [
                'message' => $input->getOption('message'),
                'type' => $input->getOption('type'),
                'non_retryable' => $input->getOption('non-retryable'),
            ],
        ];

        $result = $this->client($input)->post("/worker/activity-tasks/{$taskId}/fail", $body);

        $output->writeln('<info>Activity failed</info>');
        $output->writeln('  Task ID: '.$result['task_id']);
        $output->writeln('  Attempt ID: '.$result['activity_attempt_id']);

        return Command::SUCCESS;
    }
}
