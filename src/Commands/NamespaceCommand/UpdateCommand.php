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
            ->setHelp(<<<'HELP'
Change a namespace's description or retention window. Only fields that
you pass are updated.

<comment>Examples:</comment>

  <info>dw namespace:update billing --retention=180</info>
  <info>dw namespace:update billing -d "prod invoicing (2026)"</info>
HELP)
            ->addArgument('name', InputArgument::REQUIRED, 'Namespace name')
            ->addOption('description', 'd', InputOption::VALUE_OPTIONAL, 'New description')
            ->addOption('retention', 'r', InputOption::VALUE_OPTIONAL, 'New retention period in days')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');

        $body = array_filter([
            'description' => $input->getOption('description'),
            'retention_days' => $input->getOption('retention') ? (int) $input->getOption('retention') : null,
        ], fn ($v) => $v !== null);

        $result = $this->client($input)->put("/namespaces/{$name}", $body);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Namespace updated: '.$result['name'].'</info>');

        return Command::SUCCESS;
    }
}
