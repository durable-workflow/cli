<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\CompletionValues;
use DurableWorkflow\Cli\Support\DetectsTerminalStatus;
use DurableWorkflow\Cli\Support\ServerClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StartCommand extends BaseCommand
{
    use DetectsTerminalStatus;

    private const WAIT_POLL_INTERVAL_SECONDS = 2;

    private const WAIT_DIAGNOSTIC_INTERVAL_SECONDS = 30;

    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:start')
            ->setDescription('Start a new workflow execution')
            ->setHelp(<<<'HELP'
Start a workflow by type. The server accepts JSON input, memo, and
search attributes. With <comment>--wait</comment> the command blocks until
the workflow reaches a terminal state and exits with
<comment>SUCCESS (0)</comment> on completion or <comment>FAILURE (1)</comment> on
failure / cancellation / termination.

Start commands are deduped by <comment>workflow_command_id</comment>. If the
caller retries after a network error it must send the same command id so
the engine recognises the retry. The <comment>--duplicate-policy</comment>
option controls what happens when a second start targets an existing
<comment>workflow_instance_id</comment> (e.g. <comment>reject_duplicate</comment>
returns a duplicate outcome, <comment>return_existing_active</comment>
returns the existing active run's identity).

The new run is pinned to the starter process's compatibility marker
(<comment>DW_V2_CURRENT_COMPATIBILITY</comment>) exactly once at start. Retry
runs, continue-as-new runs, and child workflows inherit that marker so the
run stays inside one compatibility family for its whole lifecycle. Only
workers whose <comment>DW_V2_SUPPORTED_COMPATIBILITIES</comment> set includes
the run's marker (or the workers-only <comment>*</comment> wildcard) will
claim the run's tasks; any other worker rejects the claim at claim time
with <comment>compatibility_blocked</comment> or
<comment>compatibility_unsupported</comment> and the task stays on the queue
for a compatible worker to pick up.

<comment>Examples:</comment>

  # Start with no input
  <info>dw workflow:start --type=orders.Checkout</info>

  # Start with JSON input and a custom workflow id
  <info>dw workflow:start -t orders.Checkout -w chk-42 -i '{"order_id":42}'</info>

  # Start, then wait for terminal state
  <info>dw workflow:start -t orders.Checkout -i '{"order_id":42}' --wait</info>

  # Start with search attributes for visibility filters
  <info>dw workflow:start -t orders.Checkout --search-attr env=prod --search-attr tier=gold</info>
HELP)
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Workflow type')
            ->addOption('workflow-id', 'w', InputOption::VALUE_OPTIONAL, 'Workflow ID (auto-generated if omitted)')
            ->addOption('business-key', null, InputOption::VALUE_OPTIONAL, 'Business key')
            ->addOption('task-queue', null, InputOption::VALUE_OPTIONAL, 'Task queue', 'default')
            ->addOption('duplicate-policy', null, InputOption::VALUE_OPTIONAL, 'Duplicate-start policy keyed on workflow_command_id (e.g. reject_duplicate, return_existing_active; discover canonical values with server:info)', null, CompletionValues::WORKFLOW_DUPLICATE_POLICIES)
            ->addOption('memo', null, InputOption::VALUE_OPTIONAL, 'Memo JSON')
            ->addOption('search-attr', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Search attributes (key=value)')
            ->addOption('execution-timeout', null, InputOption::VALUE_REQUIRED, 'Execution timeout in seconds (across all runs)')
            ->addOption('run-timeout', null, InputOption::VALUE_REQUIRED, 'Run timeout in seconds (single run)')
            ->addOption('priority', null, InputOption::VALUE_REQUIRED, 'Dispatch priority (0..9; lower runs first when workers on a shared queue are saturated)')
            ->addOption('fairness-key', null, InputOption::VALUE_REQUIRED, 'Workload-class identifier (1..64 URL-safe chars) used to rebalance dispatch across classes under contention')
            ->addOption('fairness-weight', null, InputOption::VALUE_REQUIRED, 'Relative weight (1..1000) for the fairness class — higher weights claim a proportionally larger share of dispatch slots')
            ->addOption('wait', null, InputOption::VALUE_NONE, 'Wait for the workflow to reach a terminal state')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
        $this->addInputOptions('Workflow input payload');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $this->client($input);
        $duplicatePolicy = $this->optionalString($input->getOption('duplicate-policy'));

        if (! $this->validateControlPlaneOption(
            client: $client,
            output: $output,
            operation: 'start',
            field: 'duplicate_policy',
            value: $duplicatePolicy,
            optionName: '--duplicate-policy',
        )) {
            return Command::INVALID;
        }

        $executionTimeout = $input->getOption('execution-timeout');
        $runTimeout = $input->getOption('run-timeout');
        $priority = $input->getOption('priority');
        $fairnessKey = $this->optionalString($input->getOption('fairness-key'));
        $fairnessWeight = $input->getOption('fairness-weight');

        $body = array_filter([
            'workflow_type' => $input->getOption('type'),
            'workflow_id' => $input->getOption('workflow-id'),
            'business_key' => $input->getOption('business-key'),
            'task_queue' => $input->getOption('task-queue'),
            'duplicate_policy' => $duplicatePolicy,
            'input' => $this->parseInputArgumentsOption($input),
            'memo' => $this->parseJsonOption($input->getOption('memo'), 'memo'),
            'execution_timeout_seconds' => $executionTimeout !== null ? (int) $executionTimeout : null,
            'run_timeout_seconds' => $runTimeout !== null ? (int) $runTimeout : null,
            'priority' => $priority !== null ? (int) $priority : null,
            'fairness_key' => $fairnessKey,
            'fairness_weight' => $fairnessWeight !== null ? (int) $fairnessWeight : null,
        ], fn ($v) => $v !== null);

        $searchAttrs = $input->getOption('search-attr');
        if ($searchAttrs) {
            $attrs = [];
            foreach ($searchAttrs as $attr) {
                [$key, $value] = explode('=', $attr, 2);
                $attrs[$key] = $value;
            }
            $body['search_attributes'] = $attrs;
        }

        $result = $client->post('/workflows', $body);
        $wait = (bool) $input->getOption('wait');
        $wantsJson = $this->wantsJson($input);

        // --wait defers the final emit until the workflow reaches a terminal
        // state, so automation callers using --json receive the terminal
        // describe (and the matching success/failure exit code) instead of
        // the transient start response.
        if ($wait) {
            return $this->waitAndEmit($client, $output, $result, $wantsJson);
        }

        if ($wantsJson) {
            return $this->renderJson($output, $result);
        }

        $this->emitStartBanner($output, $result);

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $startResult
     */
    private function waitAndEmit(
        ServerClient $client,
        OutputInterface $output,
        array $startResult,
        bool $wantsJson,
    ): int {
        if (! $wantsJson) {
            $this->emitStartBanner($output, $startResult);
            $output->writeln('');
            $output->writeln('<comment>Waiting for workflow to complete...</comment>');
        }

        $describe = $this->pollUntilTerminal(
            $client,
            $output,
            (string) $startResult['workflow_id'],
            $wantsJson,
        );

        $exit = ($describe['status_bucket'] ?? null) === 'completed'
            ? Command::SUCCESS
            : Command::FAILURE;

        if ($wantsJson) {
            $output->writeln(json_encode($describe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $output->writeln('');
        $output->writeln('<info>Workflow reached terminal state</info>');
        $output->writeln('  Status: '.$this->formatStatus($describe['status'] ?? null));
        $output->writeln('  Closed Reason: '.($describe['closed_reason'] ?? '-'));
        $output->writeln('  Closed At: '.($describe['closed_at'] ?? '-'));

        if (isset($describe['output'])) {
            $output->writeln('  Output: '.json_encode($describe['output'], JSON_UNESCAPED_SLASHES));
        }

        return $exit;
    }

    /**
     * @return array<string, mixed>
     */
    private function pollUntilTerminal(
        ServerClient $client,
        OutputInterface $output,
        string $workflowId,
        bool $wantsJson,
    ): array {
        $lastDiagnosticAt = null;

        while (true) {
            $describe = $client->get("/workflows/{$workflowId}");

            if ($this->isTerminal($describe)) {
                return $describe;
            }

            if ($wantsJson) {
                $now = time();

                if (
                    $lastDiagnosticAt === null
                    || ($now - $lastDiagnosticAt) >= self::WAIT_DIAGNOSTIC_INTERVAL_SECONDS
                ) {
                    $this->emitWaitDiagnostic($client, $output, $workflowId, $describe);
                    $lastDiagnosticAt = $now;
                }
            } else {
                $output->write('.');
            }

            $this->sleepBetweenWaitPolls();
        }
    }

    protected function sleepBetweenWaitPolls(): void
    {
        sleep(self::WAIT_POLL_INTERVAL_SECONDS);
    }

    /**
     * @param  array<string, mixed>  $describe
     */
    private function emitWaitDiagnostic(
        ServerClient $client,
        OutputInterface $output,
        string $workflowId,
        array $describe,
    ): void {
        $target = $this->diagnosticErrorOutput($output);

        if ($target === null) {
            return;
        }

        $debug = null;
        $debugError = null;

        try {
            $debug = $client->get(sprintf('/workflows/%s/debug', rawurlencode($workflowId)));
        } catch (\Throwable $exception) {
            $debugError = $exception->getMessage();
        }

        $target->writeln(json_encode(
            $this->waitDiagnostic($workflowId, $describe, $debug, $debugError),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function diagnosticErrorOutput(OutputInterface $output): ?OutputInterface
    {
        if ($output instanceof ConsoleOutputInterface) {
            return $output->getErrorOutput();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $describe
     * @param  array<string, mixed>|null  $debug
     * @return array<string, mixed>
     */
    private function waitDiagnostic(
        string $workflowId,
        array $describe,
        ?array $debug,
        ?string $debugError,
    ): array {
        $execution = is_array($debug['execution'] ?? null) ? $debug['execution'] : [];

        return $this->compactDiagnostic([
            'event' => 'workflow_wait_diagnostic',
            'workflow_id' => $workflowId,
            'run_id' => $debug['run_id'] ?? $describe['run_id'] ?? null,
            'status' => $execution['status'] ?? $describe['status'] ?? null,
            'status_bucket' => $describe['status_bucket'] ?? $execution['status_bucket'] ?? null,
            'wait_kind' => $describe['wait_kind'] ?? $execution['wait_kind'] ?? null,
            'wait_reason' => $describe['wait_reason'] ?? $execution['wait_reason'] ?? null,
            'diagnostic_status' => $debug['diagnostic_status'] ?? null,
            'findings' => $this->waitDiagnosticFindings($debug),
            'pending_workflow_tasks' => $this->waitDiagnosticWorkflowTasks($debug),
            'pending_activities' => $this->waitDiagnosticActivities($debug),
            'activity_task_queues' => $this->waitDiagnosticActivityTaskQueues($debug),
            'recent_failures' => $this->waitDiagnosticRecentFailures($debug),
            'diagnostic_error' => $debugError,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $debug
     * @return list<array<string, mixed>>
     */
    private function waitDiagnosticFindings(?array $debug): array
    {
        return $this->mapDiagnosticList(
            $debug['findings'] ?? null,
            fn (array $finding): array => $this->compactDiagnostic([
                'severity' => $finding['severity'] ?? null,
                'code' => $finding['code'] ?? null,
                'message' => $finding['message'] ?? null,
                'task_queue' => $finding['task_queue'] ?? null,
                'activity_type' => $finding['activity_type'] ?? null,
                'activity_execution_id' => $finding['activity_execution_id'] ?? null,
                'active_workers' => $finding['active_workers'] ?? null,
            ]),
        );
    }

    /**
     * @param  array<string, mixed>|null  $debug
     * @return list<array<string, mixed>>
     */
    private function waitDiagnosticWorkflowTasks(?array $debug): array
    {
        return $this->mapDiagnosticList(
            $debug['pending_workflow_tasks'] ?? null,
            fn (array $task): array => $this->compactDiagnostic([
                'task_id' => $task['task_id'] ?? null,
                'task_type' => $task['task_type'] ?? null,
                'status' => $task['status'] ?? null,
                'transport_state' => $task['transport_state'] ?? null,
                'summary' => $task['summary'] ?? null,
                'queue' => $task['queue'] ?? null,
                'lease_owner' => $task['lease_owner'] ?? null,
                'lease_expired' => $task['lease_expired'] ?? null,
                'compatibility_supported' => $task['compatibility_supported'] ?? null,
                'compatibility_reason' => $task['compatibility_reason'] ?? null,
            ]),
        );
    }

    /**
     * @param  array<string, mixed>|null  $debug
     * @return list<array<string, mixed>>
     */
    private function waitDiagnosticActivities(?array $debug): array
    {
        return $this->mapDiagnosticList(
            $debug['pending_activities'] ?? null,
            fn (array $activity): array => $this->compactDiagnostic([
                'activity_execution_id' => $activity['activity_execution_id'] ?? null,
                'activity_type' => $activity['activity_type'] ?? null,
                'status' => $activity['status'] ?? null,
                'queue' => $activity['queue'] ?? null,
                'attempt_count' => $activity['attempt_count'] ?? null,
                'current_attempt' => $this->diagnosticAttempt($activity['current_attempt'] ?? null),
            ]),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function diagnosticAttempt(mixed $attempt): ?array
    {
        if (! is_array($attempt)) {
            return null;
        }

        return $this->compactDiagnostic([
            'activity_attempt_id' => $attempt['activity_attempt_id'] ?? null,
            'attempt_number' => $attempt['attempt_number'] ?? null,
            'status' => $attempt['status'] ?? null,
            'lease_owner' => $attempt['lease_owner'] ?? null,
            'lease_expires_at' => $attempt['lease_expires_at'] ?? null,
            'lease_expired' => $attempt['lease_expired'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $debug
     * @return array<string, mixed>
     */
    private function waitDiagnosticActivityTaskQueues(?array $debug): array
    {
        $queues = $debug['activity_task_queues'] ?? null;

        if (! is_array($queues)) {
            return [];
        }

        $diagnostics = [];

        foreach ($queues as $queueName => $queue) {
            if (! is_array($queue)) {
                continue;
            }

            $pollerStats = is_array($queue['stats']['pollers'] ?? null)
                ? $queue['stats']['pollers']
                : [];

            $diagnostics[(string) $queueName] = $this->compactDiagnostic([
                'active_pollers' => $pollerStats['active_count'] ?? null,
                'stale_pollers' => $pollerStats['stale_count'] ?? null,
                'pollers' => $this->mapDiagnosticList(
                    $queue['pollers'] ?? null,
                    fn (array $poller): array => $this->compactDiagnostic([
                        'worker_id' => $poller['worker_id'] ?? null,
                        'runtime' => $poller['runtime'] ?? null,
                        'sdk_version' => $poller['sdk_version'] ?? null,
                        'build_id' => $poller['build_id'] ?? null,
                        'status' => $poller['status'] ?? null,
                        'is_stale' => $poller['is_stale'] ?? null,
                        'supported_activity_types' => $poller['supported_activity_types'] ?? null,
                    ]),
                ),
            ]);
        }

        return $diagnostics;
    }

    /**
     * @param  array<string, mixed>|null  $debug
     * @return list<array<string, mixed>>
     */
    private function waitDiagnosticRecentFailures(?array $debug): array
    {
        return $this->mapDiagnosticList(
            $debug['recent_failures'] ?? null,
            fn (array $failure): array => $this->compactDiagnostic([
                'failure_id' => $failure['failure_id'] ?? null,
                'source_kind' => $failure['source_kind'] ?? null,
                'source_id' => $failure['source_id'] ?? null,
                'failure_category' => $failure['failure_category'] ?? null,
                'exception_class' => $failure['exception_class'] ?? null,
                'message' => $failure['message'] ?? null,
            ]),
            3,
        );
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mapper
     * @return list<array<string, mixed>>
     */
    private function mapDiagnosticList(mixed $items, callable $mapper, int $limit = 5): array
    {
        if (! is_array($items)) {
            return [];
        }

        $mapped = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $mapped[] = $mapper($item);

            if (count($mapped) >= $limit) {
                break;
            }
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function compactDiagnostic(array $values): array
    {
        return array_filter(
            $values,
            static fn (mixed $value): bool => $value !== null && $value !== [],
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function emitStartBanner(OutputInterface $output, array $result): void
    {
        $output->writeln('<info>Workflow started</info>');
        $output->writeln('  Workflow ID: '.$result['workflow_id']);
        $output->writeln('  Run ID: '.$result['run_id']);
        if (isset($result['business_key'])) {
            $output->writeln('  Business Key: '.$result['business_key']);
        }
        if (isset($result['payload_codec'])) {
            $output->writeln('  Payload Codec: '.$result['payload_codec']);
        }
        $output->writeln('  Outcome: '.$this->formatStatus($result['outcome']));
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return $value;
    }
}
