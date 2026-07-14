<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ScheduleCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\CompletionValues;
use DurableWorkflow\Cli\Support\InvalidOptionException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('schedule:list')
            ->setDescription('List schedules with visibility filters and cursor paging')
            ->setHelp(<<<'HELP'
List one server-filtered schedule page in the current namespace. Status,
workflow type, and visibility-query filters combine with AND semantics.
Continuation tokens are opaque; pass a returned token back unchanged with
the same namespace and filters. When <comment>--namespace</comment> is
omitted, the command queries the resolved default namespace only.

<comment>Examples:</comment>

  <info>dw schedules list</info>
  <info>dw schedules list --namespace=orders</info>
  <info>dw schedules list --status=paused --type=orders.rollup</info>
  <info>dw schedules list --query='Region = "eu" AND Priority = 2' --limit=25</info>
  <info>dw schedules list --next-page-token='opaque-token' --output=json</info>
  <info>dw schedules list --output=jsonl | jq 'select(.paused)'</info>
HELP)
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Filter by workflow type')
            ->addOption(
                'status',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter by status (active, paused)',
                null,
                CompletionValues::SCHEDULE_STATUSES,
            )
            ->addOption('query', null, InputOption::VALUE_REQUIRED, 'Schedule visibility query')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Page size (1-200; server default 50)')
            ->addOption(
                'next-page-token',
                null,
                InputOption::VALUE_REQUIRED,
                'Opaque pagination token from a previous response',
            )
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON (alias for --output=json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->nonBlankOption($input, 'status');
        $workflowType = $this->nonBlankOption($input, 'type');
        $query = $this->nonBlankOption($input, 'query');
        $pageSize = $this->parsePageSize($input);
        $nextPageToken = $this->nonBlankOption($input, 'next-page-token');

        $result = $this->addNamespaceContext($input, $this->client($input)->get('/schedules', array_filter([
            'status' => $status,
            'workflow_type' => $workflowType,
            'query' => $query,
            'page_size' => $pageSize,
            'next_page_token' => $nextPageToken,
        ], static fn (mixed $value): bool => $value !== null)), 'schedules');

        if ($this->wantsJson($input)) {
            return $this->renderJsonList($output, $input, $result, 'schedules');
        }

        $schedules = $result['schedules'] ?? [];
        $namespace = $this->namespaceContext($input, $result);

        if (empty($schedules)) {
            $output->writeln(sprintf('<comment>No schedules found in namespace %s.</comment>', $namespace));

            return Command::SUCCESS;
        }

        $output->writeln('Namespace: '.$namespace);

        $rows = array_map(fn ($s) => [
            $s['schedule_id'] ?? '-',
            $s['workflow_type'] ?? '-',
            $this->formatStatus($s['status'] ?? (($s['paused'] ?? false) ? 'paused' : 'active')),
            $s['next_fire'] ?? '-',
            $s['last_fire'] ?? '-',
        ], $schedules);

        $this->renderTable($output, ['Schedule ID', 'Workflow Type', 'Status', 'Next Fire', 'Last Fire'], $rows);

        if (is_string($result['next_page_token'] ?? null) && $result['next_page_token'] !== '') {
            $output->writeln('Next page token: '.$result['next_page_token']);
        }

        return Command::SUCCESS;
    }

    private function nonBlankOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidOptionException(sprintf('--%s must not be blank.', $name));
        }

        return $value;
    }

    private function parsePageSize(InputInterface $input): ?int
    {
        $value = $input->getOption('limit');

        if ($value === null) {
            return null;
        }

        if (
            (! is_string($value) && ! is_int($value))
            || (is_string($value) && ! preg_match('/^\d+$/', $value))
        ) {
            throw new InvalidOptionException('--limit must be an integer between 1 and 200.');
        }

        $pageSize = (int) $value;

        if ($pageSize < 1 || $pageSize > 200) {
            throw new InvalidOptionException('--limit must be an integer between 1 and 200.');
        }

        return $pageSize;
    }
}
