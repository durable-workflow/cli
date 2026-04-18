<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\SchemaCommand;

use DurableWorkflow\Cli\Support\OutputSchemaRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ManifestCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('schema:manifest')
            ->setDescription('Print the bundled JSON output schema manifest')
            ->setHelp(<<<'HELP'
Print the manifest that maps each machine-readable CLI command output
to the bundled JSON Schema file that describes it.

<comment>Examples:</comment>

  <info>dw schema:manifest</info>
  <info>dw schema:manifest | jq '.commands["workflow:list"].schema'</info>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(json_encode(
            OutputSchemaRegistry::manifest(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));

        return Command::SUCCESS;
    }
}
