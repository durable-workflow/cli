<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\SchemaCommand;

use DurableWorkflow\Cli\Support\ConfigSchemaRegistry;
use DurableWorkflow\Cli\Support\OutputSchemaRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ManifestCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('schema:manifest')
            ->setDescription('Print the bundled JSON schema manifest')
            ->setHelp(<<<'HELP'
Print the manifest that maps each published machine-readable CLI command
output and configuration contract to its JSON Schema. Output-schema entries
include a public resolver URL and SHA-256 digest for checkout-free consumers.

<comment>Examples:</comment>

  <info>dw schema:manifest</info>
  <info>dw schema:manifest | jq '.commands["workflow:list"].resolver_url'</info>
  <info>dw schema:manifest | jq '.config_schemas["external-executor-config"].schema'</info>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manifest = OutputSchemaRegistry::manifest();
        $manifest['config_schemas'] = ConfigSchemaRegistry::manifest()['schemas'] ?? [];

        $output->writeln(json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));

        return Command::SUCCESS;
    }
}
