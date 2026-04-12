<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ScheduleCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ResumeCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('schedule:resume')
            ->setDescription('Resume a paused schedule')
            ->addArgument('schedule-id', InputArgument::REQUIRED, 'Schedule ID')
            ->addOption('note', null, InputOption::VALUE_OPTIONAL, 'Note');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scheduleId = $input->getArgument('schedule-id');

        $this->client($input)->post("/schedules/{$scheduleId}/resume", array_filter([
            'note' => $input->getOption('note'),
        ], fn ($v) => $v !== null));

        $output->writeln('<info>Schedule resumed: '.$scheduleId.'</info>');

        return Command::SUCCESS;
    }
}
