<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands;

use DurableWorkflow\Cli\Support\InvalidOptionException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DebugCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->setName('debug')
            ->setDescription('Run one-shot diagnostics for a workflow')
            ->setHelp(<<<'HELP'
Run a one-shot workflow diagnostic that combines execution state, pending
workflow/activity work, task queue state, recent failures, and compatibility
metadata from the server.

<comment>Examples:</comment>

  <info>dw debug workflow order-123</info>
  <info>dw debug workflow order-123 --run-id=01HZ...</info>
  <info>dw debug workflow order-123 --output=json | jq '.findings'</info>
HELP)
            ->addArgument('target', InputArgument::REQUIRED, 'Diagnostic target: workflow')
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addOption('run-id', 'r', InputOption::VALUE_OPTIONAL, 'Specific run ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = (string) $input->getArgument('target');

        if ($target !== 'workflow') {
            throw new InvalidOptionException('dw debug currently supports only: workflow.');
        }

        $workflowId = (string) $input->getArgument('workflow-id');
        $runId = $this->optionString($input, 'run-id');
        $path = $runId === null
            ? sprintf('/workflows/%s/debug', rawurlencode($workflowId))
            : sprintf('/workflows/%s/runs/%s/debug', rawurlencode($workflowId), rawurlencode($runId));
        $result = $this->client($input)->get($path);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $this->renderHuman($input, $output, $result);

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderHuman(InputInterface $input, OutputInterface $output, array $result): void
    {
        $resolved = $this->resolvedConnection($input);
        $workflowId = (string) ($result['workflow_id'] ?? '-');
        $runId = (string) ($result['run_id'] ?? '-');
        $execution = is_array($result['execution'] ?? null) ? $result['execution'] : [];
        $taskQueue = is_array($result['task_queue'] ?? null) ? $result['task_queue'] : [];
        $queueStats = is_array($taskQueue['stats'] ?? null) ? $taskQueue['stats'] : [];

        $output->writeln('<info>Workflow Debug</info>');
        $output->writeln(sprintf(
            '  Connection: %s namespace=%s workflow=%s run=%s',
            $resolved->server,
            $resolved->namespace,
            $workflowId,
            $runId,
        ));
        $output->writeln(sprintf('  Status: %s (%s)', $execution['status'] ?? '-', $result['diagnostic_status'] ?? '-'));
        $output->writeln(sprintf('  Type: %s', $execution['workflow_type'] ?? '-'));
        $output->writeln(sprintf('  Task Queue: %s', $execution['task_queue'] ?? '-'));
        $output->writeln(sprintf('  Last Event: %s', $this->eventLabel($execution['last_event'] ?? null)));
        $output->writeln(sprintf('  Next Scheduled: %s', $this->scheduledLabel($execution['next_scheduled_event'] ?? null)));
        $output->writeln('');

        $findings = is_array($result['findings'] ?? null) ? $result['findings'] : [];
        if ($findings !== []) {
            $output->writeln('Findings:');
            foreach ($findings as $finding) {
                if (! is_array($finding)) {
                    continue;
                }

                $output->writeln(sprintf(
                    '  [%s] %s: %s',
                    strtoupper((string) ($finding['severity'] ?? 'info')),
                    $finding['code'] ?? '-',
                    $finding['message'] ?? '-',
                ));
            }
            $output->writeln('');
        }

        $output->writeln('Task Queue:');
        $output->writeln(sprintf('  Backlog: %s', $queueStats['approximate_backlog_count'] ?? 0));
        $output->writeln(sprintf('  Backlog Age: %s', $queueStats['approximate_backlog_age'] ?? '-'));
        $output->writeln(sprintf(
            '  Workflow Tasks: ready=%d leased=%d expired=%d',
            (int) $this->nested($queueStats, 'workflow_tasks.ready_count', 0),
            (int) $this->nested($queueStats, 'workflow_tasks.leased_count', 0),
            (int) $this->nested($queueStats, 'workflow_tasks.expired_lease_count', 0),
        ));
        $output->writeln(sprintf(
            '  Activity Tasks: ready=%d leased=%d expired=%d',
            (int) $this->nested($queueStats, 'activity_tasks.ready_count', 0),
            (int) $this->nested($queueStats, 'activity_tasks.leased_count', 0),
            (int) $this->nested($queueStats, 'activity_tasks.expired_lease_count', 0),
        ));
        $output->writeln(sprintf(
            '  Pollers: active=%d stale=%d',
            (int) $this->nested($queueStats, 'pollers.active_count', 0),
            (int) $this->nested($queueStats, 'pollers.stale_count', 0),
        ));
        $output->writeln('');

        $this->renderWorkflowTasks($output, $result['pending_workflow_tasks'] ?? []);
        $this->renderActivities($output, $result['pending_activities'] ?? []);
        $this->renderFailures($output, $result['recent_failures'] ?? []);
    }

    /**
     * @param  mixed  $tasks
     */
    private function renderWorkflowTasks(OutputInterface $output, mixed $tasks): void
    {
        if (! is_array($tasks) || $tasks === []) {
            $output->writeln('Pending Workflow Tasks: none');
            $output->writeln('');

            return;
        }

        $rows = array_map(static fn (array $task): array => [
            $task['task_id'] ?? '-',
            $task['status'] ?? '-',
            $task['lease_owner'] ?? '-',
            $task['lease_expires_at'] ?? '-',
            ($task['lease_expired'] ?? false) ? 'expired' : '-',
        ], array_filter($tasks, 'is_array'));

        $output->writeln('Pending Workflow Tasks:');
        $this->renderTable($output, ['Task ID', 'Status', 'Lease Owner', 'Lease Expires', 'State'], $rows);
        $output->writeln('');
    }

    /**
     * @param  mixed  $activities
     */
    private function renderActivities(OutputInterface $output, mixed $activities): void
    {
        if (! is_array($activities) || $activities === []) {
            $output->writeln('Pending Activities: none');
            $output->writeln('');

            return;
        }

        $rows = [];
        foreach ($activities as $activity) {
            if (! is_array($activity)) {
                continue;
            }

            $attempt = is_array($activity['current_attempt'] ?? null) ? $activity['current_attempt'] : [];
            $rows[] = [
                $activity['activity_execution_id'] ?? '-',
                $activity['activity_type'] ?? '-',
                $activity['status'] ?? '-',
                $attempt['lease_owner'] ?? '-',
                $attempt['lease_expires_at'] ?? '-',
            ];
        }

        $output->writeln('Pending Activities:');
        $this->renderTable($output, ['Activity ID', 'Type', 'Status', 'Lease Owner', 'Lease Expires'], $rows);
        $output->writeln('');
    }

    /**
     * @param  mixed  $failures
     */
    private function renderFailures(OutputInterface $output, mixed $failures): void
    {
        if (! is_array($failures) || $failures === []) {
            $output->writeln('Recent Failures: none');

            return;
        }

        $rows = array_map(static fn (array $failure): array => [
            $failure['exception_class'] ?? '-',
            $failure['message'] ?? '-',
            $failure['created_at'] ?? '-',
        ], array_filter($failures, 'is_array'));

        $output->writeln('Recent Failures:');
        $this->renderTable($output, ['Exception', 'Message', 'Created'], $rows);
    }

    private function eventLabel(mixed $event): string
    {
        if (! is_array($event)) {
            return '-';
        }

        return sprintf(
            '#%s %s at %s',
            $event['sequence'] ?? '-',
            $event['event_type'] ?? '-',
            $event['timestamp'] ?? '-',
        );
    }

    private function scheduledLabel(mixed $event): string
    {
        if (! is_array($event)) {
            return '-';
        }

        return sprintf(
            '%s %s at %s',
            $event['task_type'] ?? 'task',
            $event['task_status'] ?? '-',
            $event['available_at'] ?? $event['lease_expires_at'] ?? $event['wait_deadline_at'] ?? '-',
        );
    }

    private function optionString(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function nested(array $data, string $path, mixed $default = null): mixed
    {
        $value = $data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
