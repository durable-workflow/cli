<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ScheduleCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\CompletionValues;
use DurableWorkflow\Cli\Support\InvalidOptionException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CreateCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('schedule:create')
            ->setDescription('Create a new schedule')
            ->setHelp(<<<'HELP'
Create a schedule that periodically starts a workflow. Provide either
a cron expression or an ISO 8601 interval (<comment>PT1H</comment>,
<comment>PT30M</comment>, …). Overlap policy controls what happens when
the next fire time arrives while the previous run is still executing.

<comment>Examples:</comment>

  # Cron-based daily schedule
  <info>dw schedule:create --schedule-id=daily-report --workflow-type=reports.Daily --cron="0 6 * * *"</info>

  # Interval every 15 minutes
  <info>dw schedule:create --schedule-id=poller --workflow-type=sync.Poll --interval=PT15M</info>

  # Create paused with a one-time cap
  <info>dw schedule:create --schedule-id=one-shot --workflow-type=migrate.Once --cron="0 0 1 * *" --max-runs=1 --paused</info>
HELP)
            ->addOption('schedule-id', null, InputOption::VALUE_OPTIONAL, 'Schedule ID')
            ->addOption('workflow-type', 't', InputOption::VALUE_REQUIRED, 'Workflow type to execute')
            ->addOption('cron', 'c', InputOption::VALUE_OPTIONAL, 'Cron expression')
            ->addOption('interval', null, InputOption::VALUE_OPTIONAL, 'Interval as ISO 8601 duration (e.g. PT30M, PT1H)')
            ->addOption('task-queue', null, InputOption::VALUE_OPTIONAL, 'Task queue')
            ->addOption('timezone', null, InputOption::VALUE_OPTIONAL, 'Timezone', 'UTC')
            ->addOption('execution-timeout', null, InputOption::VALUE_REQUIRED, 'Workflow execution timeout in seconds')
            ->addOption('run-timeout', null, InputOption::VALUE_REQUIRED, 'Workflow run timeout in seconds')
            ->addOption('overlap-policy', null, InputOption::VALUE_OPTIONAL, 'Overlap policy', 'skip', CompletionValues::SCHEDULE_OVERLAP_POLICIES)
            ->addOption('jitter', null, InputOption::VALUE_REQUIRED, 'Jitter in seconds (0-3600)')
            ->addOption('max-runs', null, InputOption::VALUE_REQUIRED, 'Maximum number of runs before auto-delete')
            ->addOption('paused', null, InputOption::VALUE_NONE, 'Create in paused state')
            ->addOption('note', null, InputOption::VALUE_OPTIONAL, 'Note')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
        $this->addInputOptions('Scheduled workflow input payload');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cron = $input->getOption('cron');
        $interval = $input->getOption('interval');

        if ($cron === null && $interval === null) {
            throw new InvalidOptionException('Either --cron or --interval is required.');
        }

        $spec = array_filter([
            'cron_expressions' => $cron !== null ? [$cron] : null,
            'intervals' => $interval !== null ? [['every' => $interval]] : null,
            'timezone' => $input->getOption('timezone'),
        ], fn ($v) => $v !== null);

        $body = [
            'schedule_id' => $input->getOption('schedule-id'),
            'spec' => $spec,
            'action' => array_filter([
                'workflow_type' => $input->getOption('workflow-type'),
                'task_queue' => $input->getOption('task-queue'),
                'input' => $this->parseInputArgumentsOption($input),
                'execution_timeout_seconds' => $input->getOption('execution-timeout') !== null ? (int) $input->getOption('execution-timeout') : null,
                'run_timeout_seconds' => $input->getOption('run-timeout') !== null ? (int) $input->getOption('run-timeout') : null,
            ], fn ($v) => $v !== null),
            'overlap_policy' => $input->getOption('overlap-policy'),
            'jitter_seconds' => $input->getOption('jitter') !== null ? (int) $input->getOption('jitter') : null,
            'max_runs' => $input->getOption('max-runs') !== null ? (int) $input->getOption('max-runs') : null,
            'paused' => $input->getOption('paused'),
            'note' => $input->getOption('note'),
        ];

        $result = $this->client($input)->post('/schedules', $body);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Schedule created: '.$result['schedule_id'].'</info>');

        return Command::SUCCESS;
    }
}
