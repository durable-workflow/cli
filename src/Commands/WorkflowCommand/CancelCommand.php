<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\CompletionValues;
use DurableWorkflow\Cli\Support\InvalidOptionException;
use DurableWorkflow\Cli\Support\ServerClient;
use DurableWorkflow\Cli\Support\ServerException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class CancelCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:cancel')
            ->setDescription('Request cancellation of a workflow')
            ->setHelp(<<<'HELP'
Request cooperative cancellation. The workflow receives a cancellation
signal and has a chance to run its cancellation handlers.

<comment>Examples:</comment>

  <info>dw workflow:cancel chk-42</info>
  <info>dw workflow:cancel chk-42 --reason="user cancelled in UI"</info>
  <info>dw workflow:cancel chk-42 --run-id=01HZ...</info>
  <info>dw workflow:cancel --all-matching='customer-42' --yes</info>
HELP)
            ->addArgument('workflow-id', InputArgument::OPTIONAL, 'Workflow ID')
            ->addOption('reason', null, InputOption::VALUE_OPTIONAL, 'Cancellation reason')
            ->addOption('run-id', null, InputOption::VALUE_OPTIONAL, 'Target a specific run ID')
            ->addOption('all-matching', null, InputOption::VALUE_REQUIRED, 'Cancel every workflow matching a visibility query')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Batch filter by workflow type')
            ->addOption('status', null, InputOption::VALUE_OPTIONAL, 'Batch filter by status bucket', 'running', CompletionValues::WORKFLOW_STATUSES)
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximum matching workflows to cancel', '100')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt for batch cancellation')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow-id');
        $allMatching = $input->getOption('all-matching');

        if ($allMatching !== null) {
            if ($workflowId !== null && $workflowId !== '') {
                throw new InvalidOptionException('Pass either workflow-id or --all-matching, not both.');
            }

            return $this->executeBatchCancel($input, $output, (string) $allMatching);
        }

        if ($workflowId === null || $workflowId === '') {
            throw new InvalidOptionException('workflow-id is required unless --all-matching is used.');
        }

        $runId = $input->getOption('run-id');

        $body = array_filter([
            'reason' => $input->getOption('reason'),
        ], fn ($v) => $v !== null);

        $path = $runId !== null
            ? "/workflows/{$workflowId}/runs/{$runId}/cancel"
            : "/workflows/{$workflowId}/cancel";

        $result = $this->client($input)->post($path, $body);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Cancellation requested</info>');
        $output->writeln('  Workflow ID: '.$result['workflow_id']);
        $output->writeln('  Outcome: '.$result['outcome']);
        if (isset($result['command_status'])) {
            $output->writeln('  Command Status: '.$result['command_status']);
        }
        if (isset($result['command_id'])) {
            $output->writeln('  Command ID: '.$result['command_id']);
        }

        return Command::SUCCESS;
    }

    private function executeBatchCancel(InputInterface $input, OutputInterface $output, string $query): int
    {
        $query = trim($query);
        if ($query === '') {
            throw new InvalidOptionException('--all-matching must be a non-empty visibility query.');
        }

        if ($input->getOption('run-id') !== null) {
            throw new InvalidOptionException('--run-id cannot be combined with --all-matching.');
        }

        $wantsJson = $this->wantsJson($input);
        $skipConfirmation = (bool) $input->getOption('yes');
        if ($wantsJson && ! $skipConfirmation) {
            throw new InvalidOptionException('--yes is required when using --all-matching with --json.');
        }

        $client = $this->client($input);
        $status = $this->optionalString($input->getOption('status'));
        if (! $this->validateControlPlaneOption(
            client: $client,
            output: $output,
            operation: 'list',
            field: 'status',
            value: $status,
            optionName: '--status',
        )) {
            return Command::INVALID;
        }

        $workflowType = $this->optionalString($input->getOption('type'));
        $limit = $this->parsePositiveInt($input->getOption('limit'), '--limit');
        $matches = $this->matchingWorkflows($client, $query, $workflowType, $status, $limit);

        if ($matches === []) {
            $summary = $this->batchSummary($query, $workflowType, $status, [], [], []);

            if ($wantsJson) {
                return $this->renderBatchJson($output, $summary, Command::SUCCESS);
            }

            $output->writeln('<comment>No workflows matched.</comment>');

            return Command::SUCCESS;
        }

        if (! $skipConfirmation && ! $this->confirmBatchCancel($input, $output, count($matches), $query)) {
            $output->writeln('<comment>Batch cancellation aborted.</comment>');

            return Command::SUCCESS;
        }

        $body = array_filter([
            'reason' => $input->getOption('reason'),
        ], fn ($v) => $v !== null);

        $results = [];
        $failures = [];

        foreach ($matches as $workflow) {
            $workflowId = $workflow['workflow_id'];

            try {
                $results[] = $client->post("/workflows/{$workflowId}/cancel", $body);
            } catch (ServerException $e) {
                $failures[] = [
                    'workflow_id' => $workflowId,
                    'message' => $e->getMessage(),
                    'exit_code' => $e->exitCode(),
                ];
            }
        }

        $exitCode = $failures === [] ? Command::SUCCESS : Command::FAILURE;
        $summary = $this->batchSummary($query, $workflowType, $status, $matches, $results, $failures);

        if ($wantsJson) {
            return $this->renderBatchJson($output, $summary, $exitCode);
        }

        $output->writeln(sprintf('<info>Cancellation requested for %d workflow%s.</info>', count($results), count($results) === 1 ? '' : 's'));
        $output->writeln(sprintf('  Matched: %d', count($matches)));
        $output->writeln(sprintf('  Failed: %d', count($failures)));

        foreach ($matches as $workflow) {
            $output->writeln(sprintf('  - %s', $workflow['workflow_id']));
        }

        foreach ($failures as $failure) {
            $output->writeln(sprintf(
                '<error>  %s: %s</error>',
                $failure['workflow_id'],
                $failure['message'],
            ));
        }

        return $exitCode;
    }

    /**
     * @return list<array{workflow_id: string}>
     */
    private function matchingWorkflows(
        ServerClient $client,
        string $query,
        ?string $workflowType,
        ?string $status,
        int $limit,
    ): array {
        $matches = [];
        $nextPageToken = null;

        do {
            $remaining = $limit - count($matches);
            $params = array_filter([
                'query' => $query,
                'workflow_type' => $workflowType,
                'status' => $status,
                'page_size' => min(200, $remaining),
                'next_page_token' => $nextPageToken,
            ], fn ($value) => $value !== null);

            $page = $client->get('/workflows', $params);

            foreach (($page['workflows'] ?? []) as $workflow) {
                if (! is_array($workflow) || ! isset($workflow['workflow_id']) || ! is_string($workflow['workflow_id'])) {
                    continue;
                }

                $matches[] = ['workflow_id' => $workflow['workflow_id']];

                if (count($matches) >= $limit) {
                    break;
                }
            }

            $nextPageToken = $page['next_page_token'] ?? null;
            if (! is_string($nextPageToken) || $nextPageToken === '') {
                $nextPageToken = null;
            }
        } while ($nextPageToken !== null && count($matches) < $limit);

        return $matches;
    }

    private function confirmBatchCancel(InputInterface $input, OutputInterface $output, int $count, string $query): bool
    {
        $helperSet = $this->getHelperSet();
        $helper = $helperSet !== null && $helperSet->has('question')
            ? $helperSet->get('question')
            : new QuestionHelper();
        $question = new ConfirmationQuestion(sprintf(
            'Cancel %d workflow%s matching [%s]? [y/N] ',
            $count,
            $count === 1 ? '' : 's',
            $query,
        ), false);

        return (bool) $helper->ask($input, $output, $question);
    }

    /**
     * @param list<array{workflow_id: string}> $matches
     * @param list<array<string, mixed>> $results
     * @param list<array<string, mixed>> $failures
     * @return array<string, mixed>
     */
    private function batchSummary(
        string $query,
        ?string $workflowType,
        ?string $status,
        array $matches,
        array $results,
        array $failures,
    ): array {
        return [
            'query' => $query,
            'workflow_type' => $workflowType,
            'status' => $status,
            'matched' => count($matches),
            'cancelled' => count($results),
            'failed' => count($failures),
            'workflow_ids' => array_map(static fn (array $workflow): string => $workflow['workflow_id'], $matches),
            'results' => $results,
            'failures' => $failures,
        ];
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function renderBatchJson(OutputInterface $output, array $summary, int $exitCode): int
    {
        $output->writeln(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $exitCode;
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function parsePositiveInt(mixed $value, string $optionName): int
    {
        if (! is_scalar($value) || ! ctype_digit((string) $value) || (int) $value < 1) {
            throw new InvalidOptionException(sprintf('%s must be a positive integer.', $optionName));
        }

        return (int) $value;
    }
}
