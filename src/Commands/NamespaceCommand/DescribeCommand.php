<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\NamespaceCommand;

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
        $this->setName('namespace:describe')
            ->setDescription('Show details of a namespace')
            ->setHelp(<<<'HELP'
Show configuration for a namespace — retention period, status, and
audit timestamps.

<comment>Examples:</comment>

  <info>dw namespace:describe billing</info>
  <info>dw namespace:describe billing --json | jq '.retention_days'</info>
HELP)
            ->addArgument('name', InputArgument::REQUIRED, 'Namespace name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $result = $this->client($input)->get("/namespaces/{$name}");

        if ($input->getOption('json')) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Namespace: '.$result['name'].'</info>');
        $output->writeln('  Description: '.($result['description'] ?? '-'));
        $output->writeln('  Retention: '.($result['retention_days'] ?? '-').' days');
        $output->writeln('  Status: '.($result['status'] ?? '-'));
        $output->writeln('  Created: '.($result['created_at'] ?? '-'));
        $output->writeln('  Updated: '.($result['updated_at'] ?? '-'));

        return Command::SUCCESS;
    }
}
