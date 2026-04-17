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
            ->setHelp(<<<'HELP'
Invoke a side-effect-free query handler on a running workflow and
print the handler's result. Queries do not mutate state.

<comment>Examples:</comment>

  <info>dw workflow:query chk-42 status</info>
  <info>dw workflow:query chk-42 total --input='{"currency":"USD"}'</info>
HELP)
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addArgument('query-name', InputArgument::REQUIRED, 'Query name')
            ->addOption('input', 'i', InputOption::VALUE_OPTIONAL, 'Query input JSON')
            ->addOption('run-id', null, InputOption::VALUE_OPTIONAL, 'Target a specific run ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow-id');
        $queryName = $input->getArgument('query-name');
        $runId = $input->getOption('run-id');

        $body = [];
        $parsedInput = $this->parseJsonOption($input->getOption('input'), 'input');
        if ($parsedInput !== null) {
            $body['input'] = $parsedInput;
        }

        $path = $runId !== null
            ? "/workflows/{$workflowId}/runs/{$runId}/query/{$queryName}"
            : "/workflows/{$workflowId}/query/{$queryName}";

        $result = $this->client($input)->post($path, $body);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Query result</info>');
        if (isset($result['query_name'])) {
            $output->writeln('  Query: '.$result['query_name']);
        }
        $output->writeln(json_encode($result['result'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
