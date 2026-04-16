<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ScheduleCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PauseCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('schedule:pause')
            ->setDescription('Pause a schedule')
            ->setHelp(<<<'HELP'
Stop new fires without deleting the schedule. Existing runs continue
to completion.

<comment>Examples:</comment>

  <info>dw schedule:pause daily-report</info>
  <info>dw schedule:pause daily-report --note="paused during maintenance"</info>
HELP)
            ->addArgument('schedule-id', InputArgument::REQUIRED, 'Schedule ID')
            ->addOption('note', null, InputOption::VALUE_OPTIONAL, 'Note')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scheduleId = $input->getArgument('schedule-id');

        $result = $this->client($input)->post("/schedules/{$scheduleId}/pause", array_filter([
            'note' => $input->getOption('note'),
        ], fn ($v) => $v !== null));

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Schedule paused: '.$scheduleId.'</info>');

        return Command::SUCCESS;
    }
}
