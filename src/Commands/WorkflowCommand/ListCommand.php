<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\CompletionValues;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:list')
            ->setDescription('List workflow executions')
            ->setHelp(<<<'HELP'
List workflows in the current namespace. Filters combine: type,
status bucket, and free-form visibility query can all be applied at
once. <comment>--output=json</comment> (or <comment>--json</comment>)
emits a single JSON document; <comment>--output=jsonl</comment> streams
one JSON object per line for scripting over large result sets.

<comment>Examples:</comment>

  # Last 20 workflows in the namespace
  <info>dw workflow:list</info>

  # Just the running ones, up to 100
  <info>dw workflow:list --status=running --limit=100</info>

  # Pipe into jq (single JSON document)
  <info>dw workflow:list --output=json | jq '.workflows[].workflow_id'</info>

  # Stream one workflow per line (NDJSON)
  <info>dw workflow:list --output=jsonl | while read -r wf; do echo "$wf"; done</info>

  # Visibility query (depends on search attributes in use)
  <info>dw workflow:list --query='CustomerId="42" and Status="running"'</info>
HELP)
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Filter by workflow type')
            ->addOption('status', null, InputOption::VALUE_OPTIONAL, 'Filter by status bucket (running, completed, failed)', null, CompletionValues::WORKFLOW_STATUSES)
            ->addOption('query', null, InputOption::VALUE_OPTIONAL, 'Visibility query')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Page size', '20')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $input->getOption('status');
        if ($status !== null && ! $this->validateControlPlaneOption(
            client: $this->client($input),
            output: $output,
            operation: 'list',
            field: 'status',
            value: $status,
            optionName: '--status',
        )) {
            return Command::INVALID;
        }

        $result = $this->client($input)->get('/workflows', array_filter([
            'workflow_type' => $input->getOption('type'),
            'status' => $input->getOption('status'),
            'query' => $input->getOption('query'),
            'page_size' => (int) $input->getOption('limit'),
        ], fn ($v) => $v !== null));

        if ($this->wantsJson($input)) {
            return $this->renderJsonList($output, $input, $result, 'workflows');
        }

        $workflows = $result['workflows'] ?? [];

        if (empty($workflows)) {
            $output->writeln('<comment>No workflows found.</comment>');

            return Command::SUCCESS;
        }

        $rows = array_map(fn ($w) => [
            $w['workflow_id'] ?? '-',
            $w['workflow_type'] ?? '-',
            $w['business_key'] ?? '-',
            $w['status'] ?? '-',
            $w['started_at'] ?? '-',
            $w['closed_at'] ?? '-',
        ], $workflows);

        $this->renderTable($output, ['Workflow ID', 'Type', 'Business Key', 'Status', 'Started', 'Closed'], $rows);

        return Command::SUCCESS;
    }
}
