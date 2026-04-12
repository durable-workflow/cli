<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\TaskQueueCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('task-queue:list')
            ->setDescription('List task queues with active pollers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client($input)->get('/task-queues');

        $queues = $result['task_queues'] ?? [];

        if (empty($queues)) {
            $output->writeln('<comment>No task queues found.</comment>');

            return Command::SUCCESS;
        }

        $rows = array_map(fn ($q) => [
            $q['name'],
        ], $queues);

        $this->renderTable($output, ['Task Queue'], $rows);

        return Command::SUCCESS;
    }
}
