<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowTaskCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\InvalidOptionException;
use DurableWorkflow\Cli\Support\JsonPayloadEnvelope;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CompleteCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow-task:complete')
            ->setDescription('Complete a leased workflow task')
            ->setHelp(<<<'HELP'
Complete a leased workflow task through the worker protocol. By default the
command emits a single <comment>complete_workflow</comment> command; pass
<comment>--command</comment> for lower-level SDK command payloads.

<comment>Examples:</comment>

  <info>dw workflow-task:complete task-123 1 --lease-owner=cli-worker --complete-result='{"ok":true}'</info>
  <info>dw workflow-task:complete task-123 1 --command='{"type":"fail_workflow","message":"boom"}' --json</info>
HELP)
            ->addArgument('task-id', InputArgument::REQUIRED, 'Workflow task ID')
            ->addArgument('attempt', InputArgument::REQUIRED, 'Workflow task attempt number')
            ->addOption('lease-owner', null, InputOption::VALUE_REQUIRED, 'Lease owner identity', 'cli')
            ->addOption('complete-result', null, InputOption::VALUE_OPTIONAL, 'JSON result for a complete_workflow command')
            ->addOption('command', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Raw workflow task command JSON')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskId = (string) $input->getArgument('task-id');
        $attempt = $this->positiveAttempt((string) $input->getArgument('attempt'));
        $commands = $this->commands($input);

        $result = $this->client($input)->post("/worker/workflow-tasks/{$taskId}/complete", [
            'lease_owner' => $input->getOption('lease-owner'),
            'workflow_task_attempt' => $attempt,
            'commands' => $commands,
        ]);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Workflow task completed</info>');
        $output->writeln('  Task ID: '.($result['task_id'] ?? $taskId));
        $output->writeln('  Attempt: '.($result['workflow_task_attempt'] ?? $attempt));
        $output->writeln('  Outcome: '.($result['outcome'] ?? '-'));
        $output->writeln('  Run Status: '.($result['run_status'] ?? '-'));

        return Command::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function commands(InputInterface $input): array
    {
        $rawCommands = $input->getOption('command');
        $completeResult = $input->getOption('complete-result');

        if (is_array($rawCommands) && $rawCommands !== []) {
            if ($completeResult !== null && $completeResult !== '') {
                throw new InvalidOptionException('Use either --command or --complete-result, not both.');
            }

            return array_map(function (string $rawCommand): array {
                $decoded = $this->parseJsonOption($rawCommand, 'command');

                if (! is_array($decoded)) {
                    throw new InvalidOptionException('--command must decode to a JSON object.');
                }

                /** @var array<string, mixed> $decoded */
                return $decoded;
            }, $rawCommands);
        }

        $command = ['type' => 'complete_workflow'];

        if ($completeResult !== null && $completeResult !== '') {
            $command['result'] = JsonPayloadEnvelope::fromValue(
                $this->parseJsonOption($completeResult, 'complete-result'),
            );
        }

        return [$command];
    }

    private function positiveAttempt(string $value): int
    {
        if (! ctype_digit($value) || (int) $value < 1) {
            throw new InvalidOptionException('attempt must be a positive integer.');
        }

        return (int) $value;
    }
}
