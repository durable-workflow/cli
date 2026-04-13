<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\SearchAttributeCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('search-attribute:delete')
            ->setDescription('Remove a custom search attribute')
            ->addArgument('name', InputArgument::REQUIRED, 'Attribute name to remove');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');

        $this->client($input)->delete('/search-attributes/'.$name);

        $output->writeln(sprintf('<info>Search attribute deleted: %s</info>', $name));

        return Command::SUCCESS;
    }
}
