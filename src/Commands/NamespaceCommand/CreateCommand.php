<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\NamespaceCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CreateCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('namespace:create')
            ->setDescription('Create a new namespace')
            ->setHelp(<<<'HELP'
Create a logical namespace for workflow executions. Namespaces
partition the visibility surface — workflows in different namespaces
never appear in each other's lists.

<comment>Examples:</comment>

  <info>dw namespace:create billing</info>
  <info>dw namespace:create billing --description="prod invoicing" --retention=90</info>
HELP)
            ->addArgument('name', InputArgument::REQUIRED, 'Namespace name')
            ->addOption('description', 'd', InputOption::VALUE_OPTIONAL, 'Description')
            ->addOption('retention', 'r', InputOption::VALUE_OPTIONAL, 'Retention period in days', '30')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client($input)->post('/namespaces', [
            'name' => $input->getArgument('name'),
            'description' => $input->getOption('description'),
            'retention_days' => (int) $input->getOption('retention'),
        ]);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Namespace created: '.$result['name'].'</info>');

        return Command::SUCCESS;
    }
}
