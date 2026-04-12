<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\NamespaceCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DescribeCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('namespace:describe')
            ->setDescription('Show details of a namespace')
            ->addArgument('name', InputArgument::REQUIRED, 'Namespace name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $result = $this->client($input)->get("/namespaces/{$name}");

        $output->writeln('<info>Namespace: '.$result['name'].'</info>');
        $output->writeln('  Description: '.($result['description'] ?? '-'));
        $output->writeln('  Retention: '.($result['retention_days'] ?? '-').' days');
        $output->writeln('  Status: '.($result['status'] ?? '-'));
        $output->writeln('  Created: '.($result['created_at'] ?? '-'));
        $output->writeln('  Updated: '.($result['updated_at'] ?? '-'));

        return Command::SUCCESS;
    }
}
