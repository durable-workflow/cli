<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\NamespaceCommand;

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
        $this->setName('namespace:list')
            ->setDescription('List all namespaces')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client($input)->get('/namespaces');

        if ($input->getOption('json')) {
            return $this->renderJson($output, $result);
        }

        $namespaces = $result['namespaces'] ?? [];

        if (empty($namespaces)) {
            $output->writeln('<comment>No namespaces found.</comment>');

            return Command::SUCCESS;
        }

        $rows = array_map(fn ($ns) => [
            $ns['name'],
            $ns['description'] ?? '-',
            $ns['retention_days'] ?? '-',
            $ns['status'] ?? '-',
        ], $namespaces);

        $this->renderTable($output, ['Name', 'Description', 'Retention (days)', 'Status'], $rows);

        return Command::SUCCESS;
    }
}
