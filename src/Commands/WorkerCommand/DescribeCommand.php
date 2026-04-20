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
        $output->writeln('  Status: '.($result['status'] ?? '-'));
        $output->writeln('  Max Concurrent Workflow Tasks: '.($result['max_concurrent_workflow_tasks'] ?? '-'));
        $output->writeln('  Max Concurrent Activity Tasks: '.($result['max_concurrent_activity_tasks'] ?? '-'));
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
}
