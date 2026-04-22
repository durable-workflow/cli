<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ActivityCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\InvalidOptionException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FailCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('activity:fail')
            ->setDescription('Fail an activity task externally')
            ->setHelp(<<<'HELP'
Fail an activity from outside the worker process. <comment>--non-retryable</comment>
tells the server to skip the workflow's retry policy and surface the
failure to the workflow immediately.

<comment>Examples:</comment>

  <info>dw activity:fail act-123 att-456 -m "upstream returned 500"</info>
  <info>dw activity:fail act-123 att-456 -m "bad input" -t ValidationError --non-retryable</info>
HELP)
            ->addArgument('task-id', InputArgument::REQUIRED, 'Activity task ID')
            ->addArgument('attempt-id', InputArgument::REQUIRED, 'Leased activity attempt ID')
            ->addOption('lease-owner', null, InputOption::VALUE_OPTIONAL, 'Lease owner identity', 'cli')
            ->addOption('message', 'm', InputOption::VALUE_REQUIRED, 'Failure message')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Failure type')
            ->addOption(
                'failure-kind',
                null,
                InputOption::VALUE_REQUIRED,
                'Machine failure kind: application, timeout, cancellation, malformed_output, handler_crash, decode_failure, unsupported_payload',
                null,
                [
                    'application',
                    'timeout',
                    'cancellation',
                    'malformed_output',
                    'handler_crash',
                    'decode_failure',
                    'unsupported_payload',
                ],
            )
            ->addOption('retryable', null, InputOption::VALUE_NONE, 'Mark as retryable for machines that read positive retryability')
            ->addOption('non-retryable', null, InputOption::VALUE_NONE, 'Mark as non-retryable')
            ->addOption(
                'timeout-type',
                null,
                InputOption::VALUE_REQUIRED,
                'Timeout classification: schedule_to_start, start_to_close, schedule_to_close, heartbeat, deadline_exceeded',
                null,
                ['schedule_to_start', 'start_to_close', 'schedule_to_close', 'heartbeat', 'deadline_exceeded'],
            )
            ->addOption('cancelled', null, InputOption::VALUE_NONE, 'Classify the failure as cancellation-related')
            ->addOption('malformed-output', null, InputOption::VALUE_NONE, 'Classify the failure as malformed handler output')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskId = $input->getArgument('task-id');
        $attemptId = $input->getArgument('attempt-id');
        $retryable = (bool) $input->getOption('retryable');
        $nonRetryable = (bool) $input->getOption('non-retryable');

        if ($retryable && $nonRetryable) {
            throw new InvalidOptionException('--retryable and --non-retryable cannot both be set.');
        }

        $failure = [
            'message' => $input->getOption('message'),
            'type' => $input->getOption('type'),
            'non_retryable' => $nonRetryable,
        ];
        $optionalFailureFields = [
            'kind' => $input->getOption('failure-kind'),
            'retryable' => $retryable ? true : null,
            'timeout_type' => $input->getOption('timeout-type'),
            'cancelled' => $input->getOption('cancelled') ? true : null,
            'malformed_output' => $input->getOption('malformed-output') ? true : null,
        ];

        foreach ($optionalFailureFields as $field => $value) {
            if ($value !== null) {
                $failure[$field] = $value;
            }
        }

        $body = [
            'activity_attempt_id' => $attemptId,
            'lease_owner' => $input->getOption('lease-owner'),
            'failure' => $failure,
        ];

        $result = $this->client($input)->post("/worker/activity-tasks/{$taskId}/fail", $body);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Activity failed</info>');
        $output->writeln('  Task ID: '.$result['task_id']);
        $output->writeln('  Attempt ID: '.$result['activity_attempt_id']);

        return Command::SUCCESS;
    }
}
