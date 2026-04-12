<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ScheduleCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DescribeCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('schedule:describe')
            ->setDescription('Show details of a schedule')
            ->addArgument('schedule-id', InputArgument::REQUIRED, 'Schedule ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scheduleId = $input->getArgument('schedule-id');
        $result = $this->client($input)->get("/schedules/{$scheduleId}");

        if ($input->getOption('json')) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Schedule: '.$scheduleId.'</info>');
        $output->writeln('  Spec: '.json_encode($result['spec'] ?? null, JSON_UNESCAPED_SLASHES));
        $output->writeln('  Action: '.json_encode($result['action'] ?? null, JSON_UNESCAPED_SLASHES));
        $output->writeln('  State: '.json_encode($result['state'] ?? null, JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
