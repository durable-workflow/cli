<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\NamespaceCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('namespace:update')
            ->setDescription('Update a namespace')
            ->addArgument('name', InputArgument::REQUIRED, 'Namespace name')
            ->addOption('description', 'd', InputOption::VALUE_OPTIONAL, 'New description')
            ->addOption('retention', 'r', InputOption::VALUE_OPTIONAL, 'New retention period in days');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');

        $body = array_filter([
            'description' => $input->getOption('description'),
            'retention_days' => $input->getOption('retention') ? (int) $input->getOption('retention') : null,
        ], fn ($v) => $v !== null);

        $result = $this->client($input)->put("/namespaces/{$name}", $body);

        $output->writeln('<info>Namespace updated: '.$result['name'].'</info>');

        return Command::SUCCESS;
    }
}
