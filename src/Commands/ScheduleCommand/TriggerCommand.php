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
            ->addArgument('schedule-id', InputArgument::REQUIRED, 'Schedule ID')
            ->addOption('overlap-policy', null, InputOption::VALUE_OPTIONAL, 'Override overlap policy');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scheduleId = $input->getArgument('schedule-id');

        $this->client($input)->post("/schedules/{$scheduleId}/trigger", array_filter([
            'overlap_policy' => $input->getOption('overlap-policy'),
        ], fn ($v) => $v !== null));

        $output->writeln('<info>Schedule triggered: '.$scheduleId.'</info>');

        return Command::SUCCESS;
    }
}
