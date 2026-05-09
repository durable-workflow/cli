<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkerCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DescribeCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('worker:describe')
            ->setDescription('Show details of a registered worker')
            ->setHelp(<<<'HELP'
Show the worker's runtime, SDK version, build ID, heartbeat cadence,
and which workflow/activity types it is currently handling.

<comment>Examples:</comment>

  <info>dw worker:describe py-worker-abc123</info>
  <info>dw worker:describe py-worker-abc123 --json | jq '.supported_activity_types'</info>
HELP)
            ->addArgument('worker-id', InputArgument::REQUIRED, 'Worker ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workerId = $input->getArgument('worker-id');
        $result = $this->client($input)->get("/workers/{$workerId}");

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Worker: '.$result['worker_id'].'</info>');
        $output->writeln('  Namespace: '.($result['namespace'] ?? '-'));
        $output->writeln('  Task Queue: '.($result['task_queue'] ?? '-'));
        $output->writeln('  Runtime: '.($result['runtime'] ?? '-'));
        $output->writeln('  SDK Version: '.($result['sdk_version'] ?? '-'));
        $output->writeln('  Build ID: '.($result['build_id'] ?? '-'));
        $output->writeln('  Status: '.$this->formatStatus($result['status'] ?? null));
        $output->writeln('  Max Concurrent Workflow Tasks: '.($result['max_concurrent_workflow_tasks'] ?? '-'));
        $output->writeln('  Max Concurrent Activity Tasks: '.($result['max_concurrent_activity_tasks'] ?? '-'));
        $output->writeln('  Max Concurrent Worker Sessions: '.($result['max_concurrent_worker_sessions'] ?? '-'));
        $this->writeTaskSlots($output, $result);
        $this->writeProcessMetrics($output, $result);
        $output->writeln('  Heartbeat Interval: '.$this->formatHeartbeatCadence($result));
        $output->writeln('  Last Heartbeat: '.($result['last_heartbeat_at'] ?? '-'));
        $output->writeln('  Registered: '.($result['registered_at'] ?? '-'));
        $output->writeln('  Updated: '.($result['updated_at'] ?? '-'));

        $workflowTypes = $result['supported_workflow_types'] ?? [];
        $activityTypes = $result['supported_activity_types'] ?? [];

        if (! empty($workflowTypes)) {
            $output->writeln('  Workflow Types:');
            foreach ($workflowTypes as $type) {
                $output->writeln('    - '.$type);
            }
        }

        if (! empty($activityTypes)) {
            $output->writeln('  Activity Types:');
            foreach ($activityTypes as $type) {
                $output->writeln('    - '.$type);
            }
        }

        return Command::SUCCESS;
    }

    private function writeTaskSlots(OutputInterface $output, array $result): void
    {
        $slots = is_array($result['task_slots'] ?? null) ? $result['task_slots'] : null;

        if ($slots === null) {
            return;
        }

        $output->writeln('  Task Slots (available / capacity):');
        $output->writeln('    Workflow: '.$this->slotLine(
            $slots['workflow_available'] ?? null,
            $slots['workflow_capacity'] ?? $result['max_concurrent_workflow_tasks'] ?? null,
        ));
        $output->writeln('    Activity: '.$this->slotLine(
            $slots['activity_available'] ?? null,
            $slots['activity_capacity'] ?? $result['max_concurrent_activity_tasks'] ?? null,
        ));
        $output->writeln('    Sessions: '.$this->slotLine(
            $slots['session_available'] ?? null,
            $slots['session_capacity'] ?? $result['max_concurrent_worker_sessions'] ?? null,
        ));
    }

    private function writeProcessMetrics(OutputInterface $output, array $result): void
    {
        $metrics = is_array($result['process_metrics'] ?? null) ? $result['process_metrics'] : null;

        if ($metrics === null || $metrics === []) {
            return;
        }

        $output->writeln('  Process Metrics:');

        if (isset($metrics['cpu_percent'])) {
            $output->writeln(sprintf('    CPU: %s%%', $this->renderNumber($metrics['cpu_percent'])));
        }
        if (isset($metrics['memory_bytes'])) {
            $output->writeln('    Memory: '.$this->humanBytes((int) $metrics['memory_bytes']));
        }
        if (isset($metrics['process_uptime_seconds'])) {
            $output->writeln('    Uptime: '.$this->humanDuration((int) $metrics['process_uptime_seconds']));
        }
        if (isset($metrics['process_id'])) {
            $output->writeln('    PID: '.(int) $metrics['process_id']);
        }
        if (! empty($metrics['host'])) {
            $output->writeln('    Host: '.$metrics['host']);
        }
    }

    private function slotLine(mixed $available, mixed $capacity): string
    {
        $availableLabel = is_int($available) ? (string) $available : '-';
        $capacityLabel = is_int($capacity) && $capacity > 0 ? (string) $capacity : '-';

        if ($availableLabel === '-' && $capacityLabel === '-') {
            return '-';
        }

        return $availableLabel.' / '.$capacityLabel;
    }

    private function formatHeartbeatCadence(array $result): string
    {
        $interval = $result['heartbeat_interval_seconds'] ?? null;
        if (! is_int($interval) || $interval <= 0) {
            return '-';
        }

        return $interval.'s';
    }

    private function renderNumber(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.2f', $value), '0'), '.') ?: '0';
        }

        return '-';
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 ** 2) {
            return sprintf('%.1f KiB', $bytes / 1024);
        }
        if ($bytes < 1024 ** 3) {
            return sprintf('%.1f MiB', $bytes / (1024 ** 2));
        }

        return sprintf('%.2f GiB', $bytes / (1024 ** 3));
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }
        if ($seconds < 3600) {
            return sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60);
        }
        if ($seconds < 86400) {
            return sprintf(
                '%dh%02dm',
                intdiv($seconds, 3600),
                intdiv($seconds % 3600, 60),
            );
        }

        return sprintf('%dd%02dh', intdiv($seconds, 86400), intdiv($seconds % 86400, 3600));
    }
}
