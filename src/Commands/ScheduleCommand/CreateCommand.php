<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ScheduleCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
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
            ->addOption('schedule-id', null, InputOption::VALUE_OPTIONAL, 'Schedule ID')
            ->addOption('workflow-type', 't', InputOption::VALUE_REQUIRED, 'Workflow type to execute')
            ->addOption('cron', 'c', InputOption::VALUE_REQUIRED, 'Cron expression')
            ->addOption('task-queue', null, InputOption::VALUE_OPTIONAL, 'Task queue')
            ->addOption('input', 'i', InputOption::VALUE_OPTIONAL, 'Workflow input JSON')
            ->addOption('timezone', null, InputOption::VALUE_OPTIONAL, 'Timezone', 'UTC')
            ->addOption('overlap-policy', null, InputOption::VALUE_OPTIONAL, 'Overlap policy', 'skip')
            ->addOption('paused', null, InputOption::VALUE_NONE, 'Create in paused state')
            ->addOption('note', null, InputOption::VALUE_OPTIONAL, 'Note');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $body = [
            'schedule_id' => $input->getOption('schedule-id'),
            'spec' => [
                'cron_expressions' => [$input->getOption('cron')],
                'timezone' => $input->getOption('timezone'),
            ],
            'action' => array_filter([
                'workflow_type' => $input->getOption('workflow-type'),
                'task_queue' => $input->getOption('task-queue'),
                'input' => $input->getOption('input') ? json_decode($input->getOption('input'), true) : null,
            ], fn ($v) => $v !== null),
            'overlap_policy' => $input->getOption('overlap-policy'),
            'paused' => $input->getOption('paused'),
            'note' => $input->getOption('note'),
        ];

        $result = $this->client($input)->post('/schedules', $body);

        $output->writeln('<info>Schedule created: '.$result['schedule_id'].'</info>');

        return Command::SUCCESS;
    }
}
