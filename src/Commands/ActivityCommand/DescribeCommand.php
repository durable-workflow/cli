<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ActivityCommand;

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
        $this->setName('activity:describe')
            ->setAliases(['activities:describe'])
            ->setDescription('Show detailed information about a standalone activity execution')
            ->setHelp(<<<'HELP'
Describe a standalone activity by activity ID. The JSON form preserves
the server activity detail payload, including activity_execution_id,
current_attempt, and historical attempts.

<comment>Examples:</comment>

  <info>dw activity:describe email-activity-42</info>
  <info>dw activity:describe email-activity-42 --output=json | jq '.attempts'</info>
  <info>dw activities:describe email-activity-42 --namespace=orders --json</info>
HELP)
            ->addArgument('activity-id', InputArgument::REQUIRED, 'Activity ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $activityId = (string) $input->getArgument('activity-id');
        $result = $this->addNamespaceContext(
            $input,
            $this->client($input)->get('/activities/'.rawurlencode($activityId)),
        );

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Activity Execution</info>');
        $this->writeNamespaceLine($output, $result);
        $output->writeln('  Activity ID: '.($result['activity_id'] ?? $activityId));
        $output->writeln('  Activity Execution ID: '.($result['activity_execution_id'] ?? '-'));
        $output->writeln('  Type: '.($result['activity_type'] ?? '-'));
        $output->writeln('  Class: '.($result['activity_class'] ?? '-'));
        $output->writeln('  Status: '.$this->formatStatus($result['activity_status'] ?? ($result['status'] ?? null)));
        $output->writeln('  Attempt Count: '.($result['attempt_count'] ?? '-'));
        $output->writeln('  Current Attempt ID: '.($result['current_attempt_id'] ?? '-'));
        $output->writeln('  Current Attempt Status: '.$this->formatStatus($result['current_attempt_status'] ?? null));
        $output->writeln('  Workflow Run ID: '.($result['workflow_run_id'] ?? '-'));
        $output->writeln('  Task Queue: '.($result['task_queue'] ?? '-'));
        $output->writeln('  Started: '.($result['started_at'] ?? '-'));
        $output->writeln('  Closed: '.($result['closed_at'] ?? '-'));
        $output->writeln('  Closed Reason: '.($result['closed_reason'] ?? '-'));

        $attempts = is_array($result['attempts'] ?? null) ? $result['attempts'] : [];
        if ($attempts !== []) {
            $rows = array_map(static fn (mixed $attempt): array => is_array($attempt) ? [
                $attempt['activity_attempt_id'] ?? ($attempt['id'] ?? '-'),
                $attempt['attempt_number'] ?? '-',
                $attempt['status'] ?? '-',
                $attempt['lease_owner'] ?? '-',
                $attempt['started_at'] ?? '-',
                $attempt['closed_at'] ?? '-',
            ] : ['-', '-', '-', '-', '-', '-'], $attempts);

            $output->writeln('');
            $output->writeln('Attempts:');
            $this->renderTable($output, ['Attempt ID', 'Number', 'Status', 'Lease Owner', 'Started', 'Closed'], $rows);
        }

        return Command::SUCCESS;
    }
}
