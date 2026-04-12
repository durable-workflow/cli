<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class QueryCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:query')
            ->setDescription('Query a workflow\'s state')
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addArgument('query-name', InputArgument::REQUIRED, 'Query name')
            ->addOption('input', 'i', InputOption::VALUE_OPTIONAL, 'Query input JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow-id');
        $queryName = $input->getArgument('query-name');

        $body = [];
        if ($input->getOption('input')) {
            $body['input'] = json_decode($input->getOption('input'), true);
        }

        $result = $this->client($input)->post("/workflows/{$workflowId}/query/{$queryName}", $body);

        $output->writeln('<info>Query result</info>');
        if (isset($result['query_name'])) {
            $output->writeln('  Query: '.$result['query_name']);
        }
        $output->writeln(json_encode($result['result'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
