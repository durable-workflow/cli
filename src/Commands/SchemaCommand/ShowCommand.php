<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\SchemaCommand;

use DurableWorkflow\Cli\Support\OutputSchemaRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShowCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('schema:show')
            ->setDescription('Print the JSON output schema for a command')
            ->setHelp(<<<'HELP'
Print the bundled JSON Schema for a command's machine-readable output.
Use this in CI to validate <comment>dw ... --json</comment> responses
before handing them to downstream automation.

<comment>Examples:</comment>

  <info>dw schema:show workflow:list</info>
  <info>dw schema:show schedule:trigger > schedule-trigger.schema.json</info>
HELP)
            ->addArgument('command-name', InputArgument::REQUIRED, 'Command name, such as workflow:list');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $command = (string) $input->getArgument('command-name');

        try {
            $schema = OutputSchemaRegistry::schema($command);
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return Command::INVALID;
        }

        $output->writeln(json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
