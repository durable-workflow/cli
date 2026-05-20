<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\NamespaceCommand;

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
        $this->setName('namespace:delete')
            ->setDescription('Delete a namespace and its runtime state')
            ->setHelp(<<<'HELP'
Delete a namespace through the server lifecycle surface. Workflows,
schedules, search attributes, worker registrations, and other runtime
records owned by the namespace are cleaned up before the namespace is
removed.

<comment>Examples:</comment>

  <info>dw namespace:delete billing</info>
  <info>dw namespace:delete billing --json</info>
HELP)
            ->addArgument('name', InputArgument::REQUIRED, 'Namespace name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $result = $this->client($input)->delete("/namespaces/{$name}");

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Namespace deleted: '.($result['name'] ?? $name).'</info>');

        return Command::SUCCESS;
    }
}
