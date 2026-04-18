<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkerCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\CompletionValues;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('worker:list')
            ->setDescription('List registered workers')
            ->setHelp(<<<'HELP'
List workers registered in the current namespace. Filter by task queue
or by fleet status (<comment>active</comment>, <comment>stale</comment>,
<comment>draining</comment>).

<comment>Examples:</comment>

  <info>dw worker:list</info>
  <info>dw worker:list --task-queue=orders</info>
  <info>dw worker:list --status=stale --json | jq '.workers[].worker_id'</info>
HELP)
            ->addOption('task-queue', null, InputOption::VALUE_OPTIONAL, 'Filter by task queue')
            ->addOption('status', null, InputOption::VALUE_OPTIONAL, 'Filter by status (active, stale, draining)', null, CompletionValues::WORKER_STATUSES)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $query = [];

        if ($input->getOption('task-queue') !== null) {
            $query['task_queue'] = $input->getOption('task-queue');
        }

        if ($input->getOption('status') !== null) {
            $query['status'] = $input->getOption('status');
        }

        $result = $this->client($input)->get('/workers', $query);

        $workers = $result['workers'] ?? [];

        if ($input->getOption('json')) {
            return $this->renderJson($output, $result);
        }

        if (empty($workers)) {
            $output->writeln('<comment>No workers found.</comment>');

            return Command::SUCCESS;
        }

        $rows = array_map(fn (array $worker) => [
            $worker['worker_id'],
            $worker['task_queue'] ?? '-',
            $worker['runtime'] ?? '-',
            $worker['build_id'] ?? '-',
            $worker['status'] ?? '-',
            $worker['last_heartbeat_at'] ?? '-',
        ], $workers);

        $this->renderTable($output, ['Worker ID', 'Task Queue', 'Runtime', 'Build ID', 'Status', 'Last Heartbeat'], $rows);

        return Command::SUCCESS;
    }
}
