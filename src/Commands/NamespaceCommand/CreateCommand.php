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
            ->addArgument('name', InputArgument::REQUIRED, 'Namespace name')
            ->addOption('description', 'd', InputOption::VALUE_OPTIONAL, 'Description')
            ->addOption('retention', 'r', InputOption::VALUE_OPTIONAL, 'Retention period in days', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client($input)->post('/namespaces', [
            'name' => $input->getArgument('name'),
            'description' => $input->getOption('description'),
            'retention_days' => (int) $input->getOption('retention'),
        ]);

        $output->writeln('<info>Namespace created: '.$result['name'].'</info>');

        return Command::SUCCESS;
    }
}
