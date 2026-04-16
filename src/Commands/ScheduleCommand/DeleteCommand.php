<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ScheduleCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('schedule:delete')
            ->setDescription('Delete a schedule')
            ->setHelp(<<<'HELP'
Delete a schedule. In-flight runs it has already spawned keep running
— only future fires are cancelled.

<comment>Example:</comment>

  <info>dw schedule:delete daily-report</info>
HELP)
            ->addArgument('schedule-id', InputArgument::REQUIRED, 'Schedule ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scheduleId = $input->getArgument('schedule-id');
        $this->client($input)->delete("/schedules/{$scheduleId}");

        $output->writeln('<info>Schedule deleted: '.$scheduleId.'</info>');

        return Command::SUCCESS;
    }
}
