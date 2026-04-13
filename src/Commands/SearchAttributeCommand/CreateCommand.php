<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\SearchAttributeCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CreateCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('search-attribute:create')
            ->setDescription('Register a custom search attribute')
            ->addArgument('name', InputArgument::REQUIRED, 'Attribute name (e.g. OrderStatus)')
            ->addArgument('type', InputArgument::REQUIRED, 'Attribute type (keyword, text, int, double, bool, datetime, keyword_list)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client($input)->post('/search-attributes', [
            'name' => $input->getArgument('name'),
            'type' => $input->getArgument('type'),
        ]);

        $output->writeln(sprintf(
            '<info>Search attribute created: %s (%s)</info>',
            $result['name'],
            $result['type'],
        ));

        return Command::SUCCESS;
    }
}
