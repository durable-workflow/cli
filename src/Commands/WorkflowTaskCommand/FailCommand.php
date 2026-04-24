<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowTaskCommand;

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
        $this->setName('workflow-task:fail')
            ->setDescription('Fail a leased workflow task')
            ->setHelp(<<<'HELP'
Report a worker-side failure for the named workflow-task attempt through
the worker protocol. Workflow tasks are replayed deterministically,
not retried against application logic, so this records that the worker
could not durably commit the decision — the engine replays the same
history into a fresh task. It is distinct from completing a task with
a workflow-level fail_workflow command, which writes WorkflowFailed
into the run.

<comment>Examples:</comment>

  <info>dw workflow-task:fail task-123 1 --lease-owner=cli-worker --message="replay mismatch"</info>
  <info>dw workflow-task:fail task-123 1 --message="bad command" --type=NonDeterminism --stack-trace=trace.txt --json</info>
HELP)
            ->addArgument('task-id', InputArgument::REQUIRED, 'Workflow task ID')
            ->addArgument('attempt', InputArgument::REQUIRED, 'Workflow task attempt number')
            ->addOption('lease-owner', null, InputOption::VALUE_REQUIRED, 'Lease owner identity', 'cli')
            ->addOption('message', 'm', InputOption::VALUE_REQUIRED, 'Failure message')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Failure type')
            ->addOption('stack-trace', null, InputOption::VALUE_OPTIONAL, 'Failure stack trace or diagnostic text')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskId = (string) $input->getArgument('task-id');
        $attempt = $this->positiveAttempt((string) $input->getArgument('attempt'));
        $message = $input->getOption('message');

        if (! is_string($message) || trim($message) === '') {
            throw new InvalidOptionException('--message is required.');
        }

        $failure = ['message' => $message];
        foreach (['type', 'stack_trace'] as $field) {
            $option = str_replace('_', '-', $field);
            $value = $input->getOption($option);
            if (is_string($value) && $value !== '') {
                $failure[$field] = $value;
            }
        }

        $result = $this->client($input)->post("/worker/workflow-tasks/{$taskId}/fail", [
            'lease_owner' => $input->getOption('lease-owner'),
            'workflow_task_attempt' => $attempt,
            'failure' => $failure,
        ]);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Workflow task failed</info>');
        $output->writeln('  Task ID: '.($result['task_id'] ?? $taskId));
        $output->writeln('  Attempt: '.($result['workflow_task_attempt'] ?? $attempt));
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
