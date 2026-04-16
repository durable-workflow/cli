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
            ->setHelp(<<<'HELP'
Change a schedule in place. Pass only the fields you want to update —
everything else is left alone. At least one field is required.

<comment>Examples:</comment>

  <info>dw schedule:update daily-report --cron="0 5 * * *"</info>
  <info>dw schedule:update daily-report --overlap-policy=skip --jitter=60</info>
  <info>dw schedule:update daily-report --max-runs=100</info>
HELP)
            ->addArgument('schedule-id', InputArgument::REQUIRED, 'Schedule ID')
            ->addOption('cron', 'c', InputOption::VALUE_OPTIONAL, 'Cron expression')
            ->addOption('interval', null, InputOption::VALUE_OPTIONAL, 'Interval as ISO 8601 duration (e.g. PT30M, PT1H)')
            ->addOption('workflow-type', 't', InputOption::VALUE_OPTIONAL, 'Workflow type')
            ->addOption('task-queue', null, InputOption::VALUE_OPTIONAL, 'Task queue')
            ->addOption('execution-timeout', null, InputOption::VALUE_REQUIRED, 'Workflow execution timeout in seconds')
            ->addOption('run-timeout', null, InputOption::VALUE_REQUIRED, 'Workflow run timeout in seconds')
            ->addOption('overlap-policy', null, InputOption::VALUE_OPTIONAL, 'Overlap policy')
            ->addOption('jitter', null, InputOption::VALUE_REQUIRED, 'Jitter in seconds (0-3600)')
            ->addOption('max-runs', null, InputOption::VALUE_REQUIRED, 'Maximum number of runs')
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
        $executionTimeout = $input->getOption('execution-timeout');
        $runTimeout = $input->getOption('run-timeout');

        if ($workflowType !== null || $taskQueue !== null || $executionTimeout !== null || $runTimeout !== null) {
            $body['action'] = array_filter([
                'workflow_type' => $workflowType,
                'task_queue' => $taskQueue,
                'execution_timeout_seconds' => $executionTimeout !== null ? (int) $executionTimeout : null,
                'run_timeout_seconds' => $runTimeout !== null ? (int) $runTimeout : null,
            ], fn ($v) => $v !== null);
        }

        if ($input->getOption('overlap-policy') !== null) {
            $body['overlap_policy'] = $input->getOption('overlap-policy');
        }

        if ($input->getOption('jitter') !== null) {
            $body['jitter_seconds'] = (int) $input->getOption('jitter');
        }

        if ($input->getOption('max-runs') !== null) {
            $body['max_runs'] = (int) $input->getOption('max-runs');
        }

        if ($input->getOption('note') !== null) {
            $body['note'] = $input->getOption('note');
        }

        if ($body === []) {
            $output->writeln('<error>No update fields provided. Use --cron, --interval, --workflow-type, --task-queue, --execution-timeout, --run-timeout, --overlap-policy, --jitter, --max-runs, or --note.</error>');

            return Command::FAILURE;
        }

        $this->client($input)->put("/schedules/{$scheduleId}", $body);

        $output->writeln('<info>Schedule updated: '.$scheduleId.'</info>');

        return Command::SUCCESS;
    }
}
