<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\SchemaCommand;

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
            ->setDescription('List published JSON output schemas')
            ->setHelp(<<<'HELP'
List the JSON Schema files bundled with this CLI for server response
documents printed by commands such as <comment>--json</comment> and
<comment>workflow:history-export</comment>.

<comment>Examples:</comment>

  <info>dw schema:list</info>
  <info>dw schema:show workflow:list > workflow-list.schema.json</info>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $table->setHeaders(['Command', 'Output', 'Schema']);
        $table->setRows(array_map(
            static fn (array $entry): array => [
                $entry['command'],
                $entry['output'] ?? '-',
                $entry['schema'] ?? '-',
            ],
            OutputSchemaRegistry::entries(),
        ));
        $table->render();

        return Command::SUCCESS;
    }
}
