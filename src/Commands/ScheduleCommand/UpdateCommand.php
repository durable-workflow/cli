<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ScheduleCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('schedule:update')
            ->setDescription('Update a schedule')
            ->addArgument('schedule-id', InputArgument::REQUIRED, 'Schedule ID')
            ->addOption('cron', 'c', InputOption::VALUE_OPTIONAL, 'Cron expression')
            ->addOption('interval', null, InputOption::VALUE_OPTIONAL, 'Interval as ISO 8601 duration (e.g. PT30M, PT1H)')
            ->addOption('workflow-type', 't', InputOption::VALUE_OPTIONAL, 'Workflow type')
            ->addOption('task-queue', null, InputOption::VALUE_OPTIONAL, 'Task queue')
            ->addOption('overlap-policy', null, InputOption::VALUE_OPTIONAL, 'Overlap policy')
            ->addOption('note', null, InputOption::VALUE_OPTIONAL, 'Note');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scheduleId = $input->getArgument('schedule-id');

        $body = [];

        $cron = $input->getOption('cron');
        $interval = $input->getOption('interval');

        if ($cron !== null || $interval !== null) {
            $body['spec'] = array_filter([
                'cron_expressions' => $cron !== null ? [$cron] : null,
                'intervals' => $interval !== null ? [['every' => $interval]] : null,
            ], fn ($v) => $v !== null);
        }

        $workflowType = $input->getOption('workflow-type');
        $taskQueue = $input->getOption('task-queue');

        if ($workflowType !== null || $taskQueue !== null) {
            $body['action'] = array_filter([
                'workflow_type' => $workflowType,
                'task_queue' => $taskQueue,
            ], fn ($v) => $v !== null);
        }

        if ($input->getOption('overlap-policy') !== null) {
            $body['overlap_policy'] = $input->getOption('overlap-policy');
        }

        if ($input->getOption('note') !== null) {
            $body['note'] = $input->getOption('note');
        }

        if ($body === []) {
            $output->writeln('<error>No update fields provided. Use --cron, --interval, --workflow-type, --task-queue, --overlap-policy, or --note.</error>');

            return Command::FAILURE;
        }

        $this->client($input)->put("/schedules/{$scheduleId}", $body);

        $output->writeln('<info>Schedule updated: '.$scheduleId.'</info>');

        return Command::SUCCESS;
    }
}
