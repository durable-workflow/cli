<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\SchemaCommand;

use DurableWorkflow\Cli\Support\ConfigSchemaRegistry;
use DurableWorkflow\Cli\Support\OutputSchemaRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ShowCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('schema:show')
            ->setDescription('Print a published JSON schema')
            ->setHelp(<<<'HELP'
Print a bundled JSON Schema for a command's machine-readable output or
for a stable configuration contract. Use this in CI to validate
<comment>dw ... --output=json</comment> or
<comment>dw ... --output=jsonl</comment> records and checked-in config files.

<comment>Examples:</comment>

  <info>dw schema:show workflow:list</info>
  <info>dw schema:show workflow:list --output=jsonl</info>
  <info>dw schema:show schedule:trigger > schedule-trigger.schema.json</info>
  <info>dw schema:show external-executor-config > dw-executor.schema.json</info>
HELP)
            ->addArgument('schema-name', InputArgument::REQUIRED, 'Command name or config schema name, such as workflow:list')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Command output schema mode (json or jsonl)', 'json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('schema-name');
        $outputMode = (string) $input->getOption('output');

        try {
            $schema = OutputSchemaRegistry::hasCommand($name)
                ? OutputSchemaRegistry::schema($name, $outputMode)
                : ConfigSchemaRegistry::schema($name);
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return Command::INVALID;
        }

        $output->writeln(json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
