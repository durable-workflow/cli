<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ScheduleCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\CompletionValues;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackfillCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('schedule:backfill')
            ->setDescription('Backfill missed schedule executions')
            ->setHelp(<<<'HELP'
Fire every scheduled time that falls between <comment>--start-time</comment>
and <comment>--end-time</comment>. Useful after a downtime to replay
missed runs.

<comment>Example:</comment>

  <info>dw schedule:backfill daily-report \\</info>
  <info>    --start-time=2026-04-01T00:00:00Z \\</info>
  <info>    --end-time=2026-04-07T00:00:00Z \\</info>
  <info>    --overlap-policy=skip</info>
HELP)
            ->addArgument('schedule-id', InputArgument::REQUIRED, 'Schedule ID')
            ->addOption('start-time', null, InputOption::VALUE_REQUIRED, 'Start time (ISO 8601)')
            ->addOption('end-time', null, InputOption::VALUE_REQUIRED, 'End time (ISO 8601)')
            ->addOption('overlap-policy', null, InputOption::VALUE_OPTIONAL, 'Override overlap policy', null, CompletionValues::SCHEDULE_OVERLAP_POLICIES)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scheduleId = $input->getArgument('schedule-id');

        $result = $this->client($input)->post("/schedules/{$scheduleId}/backfill", array_filter([
            'start_time' => $input->getOption('start-time'),
            'end_time' => $input->getOption('end-time'),
            'overlap_policy' => $input->getOption('overlap-policy'),
        ], fn ($v) => $v !== null));

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Backfill started for: '.$scheduleId.'</info>');

        return Command::SUCCESS;
    }
}
