<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\QueryTaskCommand;

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
        $this->setName('query-task:fail')
            ->setDescription('Fail a leased routed workflow query task')
            ->setHelp(<<<'HELP'
Report a routed workflow query-task failure through the worker protocol. Use a
stable reason so callers and agents can distinguish unknown queries, decode
failures, and runtime errors.

<comment>Examples:</comment>

  <info>dw query-task:fail query-123 1 --lease-owner=cli-worker --message="unknown query"</info>
  <info>dw query-task:fail query-123 1 --message="decode failed" --reason=decode_failure --type=DecodeError --json</info>
HELP)
            ->addArgument('query-task-id', InputArgument::REQUIRED, 'Query task ID')
            ->addArgument('attempt', InputArgument::REQUIRED, 'Query task attempt number')
            ->addOption('lease-owner', null, InputOption::VALUE_REQUIRED, 'Lease owner identity', 'cli')
            ->addOption('message', 'm', InputOption::VALUE_REQUIRED, 'Failure message')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Machine-readable failure reason', 'query_rejected')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Failure type')
            ->addOption('stack-trace', null, InputOption::VALUE_OPTIONAL, 'Failure stack trace or diagnostic text')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queryTaskId = (string) $input->getArgument('query-task-id');
        $attempt = $this->positiveAttempt((string) $input->getArgument('attempt'));
        $message = $input->getOption('message');

        if (! is_string($message) || trim($message) === '') {
            throw new InvalidOptionException('--message is required.');
        }

        $failure = [
            'message' => $message,
            'reason' => $input->getOption('reason'),
        ];

        foreach (['type', 'stack_trace'] as $field) {
            $option = str_replace('_', '-', $field);
            $value = $input->getOption($option);
            if (is_string($value) && $value !== '') {
                $failure[$field] = $value;
            }
        }

        $result = $this->client($input)->post("/worker/query-tasks/{$queryTaskId}/fail", [
            'lease_owner' => $input->getOption('lease-owner'),
            'query_task_attempt' => $attempt,
            'failure' => $failure,
        ]);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Query task failed</info>');
        $output->writeln('  Task ID: '.($result['query_task_id'] ?? $queryTaskId));
        $output->writeln('  Attempt: '.($result['query_task_attempt'] ?? $attempt));
        $output->writeln('  Outcome: '.($result['outcome'] ?? '-'));

        return Command::SUCCESS;
    }

    private function positiveAttempt(string $value): int
    {
        if (! ctype_digit($value) || (int) $value < 1) {
            throw new InvalidOptionException('attempt must be a positive integer.');
        }

        return (int) $value;
    }
}
