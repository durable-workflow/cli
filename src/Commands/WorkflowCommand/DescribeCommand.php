<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\DetectsTerminalStatus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DescribeCommand extends BaseCommand
{
    use DetectsTerminalStatus;

    private const FOLLOW_POLL_INTERVAL_SECONDS = 2;

    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:describe')
            ->setDescription('Show detailed information about a workflow execution')
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addOption('run-id', 'r', InputOption::VALUE_OPTIONAL, 'Specific run ID')
            ->addOption('follow', 'f', InputOption::VALUE_NONE, 'Poll until the workflow reaches a terminal state')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow-id');
        $runId = $input->getOption('run-id');
        $follow = $input->getOption('follow');

        $result = $this->fetchWorkflow($input, $workflowId, $runId);

        if ($follow && ! $this->isTerminal($result)) {
            return $this->followUntilTerminal($input, $output, $workflowId, $runId, $result);
        }

        return $this->renderResult($input, $output, $result);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function followUntilTerminal(
        InputInterface $input,
        OutputInterface $output,
        string $workflowId,
        ?string $runId,
        array $result,
    ): int {
        $lastStatus = $result['status'] ?? null;
        $output->writeln('<comment>Following workflow (status: '.($lastStatus ?? 'unknown').')...</comment>');

        while (! $this->isTerminal($result)) {
            sleep(self::FOLLOW_POLL_INTERVAL_SECONDS);

            $result = $this->fetchWorkflow($input, $workflowId, $runId);
            $currentStatus = $result['status'] ?? null;

            if ($currentStatus !== $lastStatus) {
                $output->writeln('  Status changed: '.($lastStatus ?? '-').' -> '.($currentStatus ?? '-'));
                $lastStatus = $currentStatus;
            }
        }

        $output->writeln('');

        return $this->renderResult($input, $output, $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchWorkflow(InputInterface $input, string $workflowId, ?string $runId): array
    {
        return $runId
            ? $this->client($input)->get("/workflows/{$workflowId}/runs/{$runId}")
            : $this->client($input)->get("/workflows/{$workflowId}");
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderResult(InputInterface $input, OutputInterface $output, array $result): int
    {
        if ($input->getOption('json')) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Workflow Execution</info>');
        $output->writeln('  Workflow ID: '.($result['workflow_id'] ?? '-'));
        $output->writeln('  Run ID: '.($result['run_id'] ?? '-'));
        $output->writeln('  Type: '.($result['workflow_type'] ?? '-'));
        $output->writeln('  Namespace: '.($result['namespace'] ?? '-'));
        $output->writeln('  Business Key: '.($result['business_key'] ?? '-'));
        $output->writeln('  Status: '.($result['status'] ?? '-'));
        $output->writeln('  Status Bucket: '.($result['status_bucket'] ?? '-'));
        $output->writeln('  Run Number: '.($result['run_number'] ?? '-'));
        $output->writeln('  Run Count: '.($result['run_count'] ?? '-'));
        $output->writeln('  Current Run: '.(($result['is_current_run'] ?? false) ? 'yes' : 'no'));
        $output->writeln('  Task Queue: '.($result['task_queue'] ?? '-'));
        $output->writeln('  Compatibility: '.($result['compatibility'] ?? '-'));
        $output->writeln('  Started: '.($result['started_at'] ?? '-'));
        $output->writeln('  Closed: '.($result['closed_at'] ?? '-'));
        $output->writeln('  Last Progress: '.($result['last_progress_at'] ?? '-'));
        $output->writeln('  Closed Reason: '.($result['closed_reason'] ?? '-'));
        $output->writeln('  Wait Kind: '.($result['wait_kind'] ?? '-'));
        $output->writeln('  Wait Reason: '.($result['wait_reason'] ?? '-'));

        if (isset($result['input'])) {
            $output->writeln('  Input: '.json_encode($result['input'], JSON_UNESCAPED_SLASHES));
        }
        if (isset($result['output'])) {
            $output->writeln('  Output: '.json_encode($result['output'], JSON_UNESCAPED_SLASHES));
        }
        if (isset($result['memo'])) {
            $output->writeln('  Memo: '.json_encode($result['memo'], JSON_UNESCAPED_SLASHES));
        }
        if (isset($result['search_attributes'])) {
            $output->writeln('  Search Attributes: '.json_encode($result['search_attributes'], JSON_UNESCAPED_SLASHES));
        }
        if (is_array($result['actions'] ?? null)) {
            $actions = array_keys(array_filter(
                $result['actions'],
                static fn (mixed $enabled): bool => $enabled === true,
            ));
            $output->writeln('  Actions: '.($actions === [] ? '-' : implode(', ', $actions)));
        }

        return Command::SUCCESS;
    }
}
