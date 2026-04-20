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
            ->setHelp(<<<'HELP'
List every namespace the calling identity can see on the server.

<comment>Examples:</comment>

  <info>dw namespace:list</info>
  <info>dw namespace:list --output=json | jq '.namespaces[].name'</info>
  <info>dw namespace:list --output=jsonl | jq '.name'</info>
HELP)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON (alias for --output=json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client($input)->get('/namespaces');

        if ($this->wantsJson($input)) {
            return $this->renderJsonList($output, $input, $result, 'namespaces');
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
