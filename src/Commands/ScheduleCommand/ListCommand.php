<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ScheduleCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('schedule:list')
            ->setDescription('List all schedules')
            ->setHelp(<<<'HELP'
List every schedule in the current namespace with next/last fire
times.

<comment>Examples:</comment>

  <info>dw schedule:list</info>
  <info>dw schedule:list --json | jq '.schedules[] | select(.paused)'</info>
HELP)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client($input)->get('/schedules');

        if ($input->getOption('json')) {
            return $this->renderJson($output, $result);
        }

        $schedules = $result['schedules'] ?? [];

        if (empty($schedules)) {
            $output->writeln('<comment>No schedules found.</comment>');

            return Command::SUCCESS;
        }

        $rows = array_map(fn ($s) => [
            $s['schedule_id'] ?? '-',
            $s['workflow_type'] ?? '-',
            $s['paused'] ? 'paused' : 'active',
            $s['next_fire'] ?? '-',
            $s['last_fire'] ?? '-',
        ], $schedules);

        $this->renderTable($output, ['Schedule ID', 'Workflow Type', 'Status', 'Next Fire', 'Last Fire'], $rows);

        return Command::SUCCESS;
    }
}
