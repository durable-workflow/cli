<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\SearchAttributeCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('search-attribute:delete')
            ->setDescription('Remove a custom search attribute')
            ->setHelp(<<<'HELP'
Remove a custom search attribute. Existing workflows that indexed the
attribute retain their stored values, but new workflows can no longer
filter on it.

<comment>Examples:</comment>

  <info>dw search-attribute:delete OrderStatus</info>
  <info>dw search-attribute:delete OrderStatus --json</info>
HELP)
            ->addArgument('name', InputArgument::REQUIRED, 'Attribute name to remove')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');

        $result = $this->client($input)->delete('/search-attributes/'.$name);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln(sprintf('<info>Search attribute deleted: %s</info>', $name));

        return Command::SUCCESS;
    }
}
