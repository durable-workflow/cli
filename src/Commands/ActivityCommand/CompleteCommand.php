<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ActivityCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CompleteCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('activity:complete')
            ->setDescription('Complete an activity task externally')
            ->setHelp(<<<'HELP'
Complete an activity from outside the worker process. You must supply
the task's leased attempt ID so the server can validate ownership.

<comment>Example:</comment>

  <info>dw activity:complete act-123 att-456 --result='{"ok":true}'</info>
HELP)
            ->addArgument('task-id', InputArgument::REQUIRED, 'Activity task ID')
            ->addArgument('attempt-id', InputArgument::REQUIRED, 'Leased activity attempt ID')
            ->addOption('lease-owner', null, InputOption::VALUE_OPTIONAL, 'Lease owner identity', 'cli')
            ->addOption('result', 'r', InputOption::VALUE_OPTIONAL, 'Result JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskId = $input->getArgument('task-id');
        $attemptId = $input->getArgument('attempt-id');

        $body = [
            'activity_attempt_id' => $attemptId,
            'lease_owner' => $input->getOption('lease-owner'),
        ];
        if ($input->getOption('result')) {
            $body['result'] = json_decode($input->getOption('result'), true);
        }

        $result = $this->client($input)->post("/worker/activity-tasks/{$taskId}/complete", $body);

        $output->writeln('<info>Activity completed</info>');
        $output->writeln('  Task ID: '.$result['task_id']);
        $output->writeln('  Attempt ID: '.$result['activity_attempt_id']);

        return Command::SUCCESS;
    }
}
