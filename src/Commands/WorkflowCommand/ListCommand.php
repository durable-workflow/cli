<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
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
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Filter by workflow type')
            ->addOption('status', null, InputOption::VALUE_OPTIONAL, 'Filter by status bucket (running, completed, failed)')
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

        if ($input->getOption('json')) {
            return $this->renderJson($output, $result);
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
