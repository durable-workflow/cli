<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkerCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeregisterCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('worker:deregister')
            ->setDescription('Deregister a worker from the server')
            ->setHelp(<<<'HELP'
Remove a stale or retired worker entry from the fleet roster. Active
workers re-register automatically on their next heartbeat.

<comment>Examples:</comment>

  <info>dw worker:deregister py-worker-abc123</info>
  <info>dw worker:deregister py-worker-abc123 --json</info>
HELP)
            ->addArgument('worker-id', InputArgument::REQUIRED, 'Worker ID to deregister')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workerId = $input->getArgument('worker-id');
        $result = $this->client($input)->delete("/workers/{$workerId}");

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $outcome = $result['outcome'] ?? 'unknown';

        $output->writeln(sprintf(
            '<info>Worker %s: %s</info>',
            $result['worker_id'] ?? $workerId,
            $outcome,
        ));

        return Command::SUCCESS;
    }
}
