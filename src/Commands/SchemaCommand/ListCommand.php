<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\SchemaCommand;

use DurableWorkflow\Cli\Support\ConfigSchemaRegistry;
use DurableWorkflow\Cli\Support\OutputSchemaRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('schema:list')
            ->setDescription('List published JSON schemas')
            ->setHelp(<<<'HELP'
List the JSON Schema files bundled with this CLI for machine-readable
command output and stable configuration contracts.

<comment>Examples:</comment>

  <info>dw schema:list</info>
  <info>dw schema:show workflow:list > workflow-list.schema.json</info>
  <info>dw schema:show external-executor-config > dw-executor.schema.json</info>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $table->setHeaders(['Type', 'Name', 'Output', 'Schema']);

        $rows = array_map(
            static fn (array $entry): array => [
                'output',
                $entry['command'],
                $entry['output'] ?? '-',
                $entry['schema'] ?? '-',
            ],
            OutputSchemaRegistry::entries(),
        );

        foreach (ConfigSchemaRegistry::entries() as $entry) {
            $rows[] = [
                'config',
                $entry['name'],
                '-',
                $entry['schema'] ?? '-',
            ];
        }

        usort($rows, static fn (array $left, array $right): int => strcmp(
            implode("\0", array_map('strval', $left)),
            implode("\0", array_map('strval', $right)),
        ));

        $table->setRows($rows);
        $table->render();

        return Command::SUCCESS;
    }
}
