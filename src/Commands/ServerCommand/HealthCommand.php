<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ServerCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class HealthCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('server:health')
            ->setDescription('Check the health of the Durable Workflow server')
            ->setHelp(<<<'HELP'
Check server health and exit with <comment>NETWORK (3)</comment> if the
server is unreachable.

<comment>Examples:</comment>

  <info>dw server:health</info>
  <info>dw server:health --server=http://localhost:8080</info>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client($input)->get('/health');
        $output->writeln('<info>Server is '.$result['status'].'</info>');
        $output->writeln('Timestamp: '.$result['timestamp']);

        foreach ($result['checks'] ?? [] as $check => $status) {
            $tag = $status === 'ok' ? 'info' : 'error';
            $output->writeln("  {$check}: <{$tag}>{$status}</{$tag}>");
        }

        return Command::SUCCESS;
    }
}
