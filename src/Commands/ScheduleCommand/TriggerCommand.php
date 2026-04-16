<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ScheduleCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TriggerCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('schedule:trigger')
            ->setDescription('Trigger a schedule execution immediately')
            ->setHelp(<<<'HELP'
Fire a schedule once, right now. The schedule's configured overlap
policy applies unless you override it with <comment>--overlap-policy</comment>.

<comment>Examples:</comment>

  <info>dw schedule:trigger daily-report</info>
  <info>dw schedule:trigger daily-report --overlap-policy=allow</info>
HELP)
            ->addArgument('schedule-id', InputArgument::REQUIRED, 'Schedule ID')
            ->addOption('overlap-policy', null, InputOption::VALUE_OPTIONAL, 'Override overlap policy');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scheduleId = $input->getArgument('schedule-id');

        $result = $this->client($input)->post("/schedules/{$scheduleId}/trigger", array_filter([
            'overlap_policy' => $input->getOption('overlap-policy'),
        ], fn ($v) => $v !== null));

        $outcome = $result['outcome'] ?? 'unknown';

        match ($outcome) {
            'triggered' => $output->writeln(sprintf(
                '<info>Schedule triggered:</info> %s → %s',
                $scheduleId,
                $result['workflow_id'] ?? '(unknown)',
            )),
            'buffered' => $output->writeln(sprintf(
                '<comment>Schedule buffered:</comment> %s (buffer depth: %d)',
                $scheduleId,
                $result['buffer_depth'] ?? 0,
            )),
            'buffer_full' => $output->writeln(sprintf(
                '<comment>Buffer full:</comment> %s — %s',
                $scheduleId,
                $result['reason'] ?? 'previous workflow still running',
            )),
            'skipped' => $output->writeln(sprintf(
                '<comment>Skipped:</comment> %s — %s',
                $scheduleId,
                $result['reason'] ?? 'schedule exhausted',
            )),
            'trigger_failed' => $output->writeln(sprintf(
                '<error>Trigger failed:</error> %s — %s',
                $scheduleId,
                $result['reason'] ?? 'unknown error',
            )),
            default => $output->writeln(sprintf(
                '<info>Schedule trigger outcome:</info> %s (%s)',
                $scheduleId,
                $outcome,
            )),
        };

        return match ($outcome) {
            'triggered', 'buffered', 'buffer_full', 'skipped' => Command::SUCCESS,
            default => Command::FAILURE,
        };
    }
}
