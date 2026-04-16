<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\SearchAttributeCommand;

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
        $this->setName('search-attribute:list')
            ->setDescription('List search attribute definitions')
            ->setHelp(<<<'HELP'
List every search attribute — system-defined attributes shipped by the
server and any custom attributes registered for this namespace.

<comment>Examples:</comment>

  <info>dw search-attribute:list</info>
  <info>dw search-attribute:list --json | jq '.custom_attributes'</info>
HELP)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client($input)->get('/search-attributes');

        if ($input->getOption('json')) {
            return $this->renderJson($output, $result);
        }

        $rows = [];

        foreach ($result['system_attributes'] ?? [] as $name => $type) {
            $rows[] = [$name, $type, 'system'];
        }

        foreach ($result['custom_attributes'] ?? [] as $name => $type) {
            $rows[] = [$name, $type, 'custom'];
        }

        if (empty($rows)) {
            $output->writeln('<comment>No search attributes found.</comment>');

            return Command::SUCCESS;
        }

        $this->renderTable($output, ['Name', 'Type', 'Source'], $rows);

        return Command::SUCCESS;
    }
}
