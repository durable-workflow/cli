<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkerCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\InvalidOptionException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RegisterCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('worker:register')
            ->setDescription('Register a diagnostic worker with the server')
            ->setHelp(<<<'HELP'
Register a worker identity for direct worker-protocol diagnostics. Real SDK
workers register automatically; this command is useful when smoke-testing
task routing, leases, and workflow-task completion from the CLI.

<comment>Examples:</comment>

  <info>dw worker:register cli-worker --task-queue=orders --workflow-type=orders.Checkout</info>
  <info>dw worker:register py-worker --runtime=python --activity-type=email.send --json</info>
HELP)
            ->addArgument('worker-id', InputArgument::OPTIONAL, 'Worker ID; omitted lets the server assign one')
            ->addOption('task-queue', null, InputOption::VALUE_REQUIRED, 'Task queue to poll', 'default')
            ->addOption('runtime', null, InputOption::VALUE_REQUIRED, 'Runtime (php, python, typescript, go, java)', 'php')
            ->addOption('sdk-version', null, InputOption::VALUE_OPTIONAL, 'Advertised SDK version', 'dw-cli')
            ->addOption('build-id', null, InputOption::VALUE_OPTIONAL, 'Compatibility build ID')
            ->addOption('workflow-type', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Supported workflow type')
            ->addOption('activity-type', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Supported activity type')
            ->addOption('max-workflow-tasks', null, InputOption::VALUE_OPTIONAL, 'Maximum concurrent workflow tasks')
            ->addOption('max-activity-tasks', null, InputOption::VALUE_OPTIONAL, 'Maximum concurrent activity tasks')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $body = array_filter([
            'worker_id' => $input->getArgument('worker-id') ?: null,
            'task_queue' => $input->getOption('task-queue'),
            'runtime' => $input->getOption('runtime'),
            'sdk_version' => $input->getOption('sdk-version'),
            'build_id' => $input->getOption('build-id'),
            'supported_workflow_types' => $this->stringList($input->getOption('workflow-type')),
            'supported_activity_types' => $this->stringList($input->getOption('activity-type')),
            'max_concurrent_workflow_tasks' => $this->positiveIntOption($input, 'max-workflow-tasks'),
            'max_concurrent_activity_tasks' => $this->positiveIntOption($input, 'max-activity-tasks'),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        $result = $this->client($input)->post('/worker/register', $body);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Worker registered</info>');
        $output->writeln('  Worker ID: '.($result['worker_id'] ?? '-'));
        $output->writeln('  Task Queue: '.($body['task_queue'] ?? '-'));
        $output->writeln('  Runtime: '.($body['runtime'] ?? '-'));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== '',
        ));
    }

    private function positiveIntOption(InputInterface $input, string $option): ?int
    {
        $value = $input->getOption($option);

        if ($value === null || $value === '') {
            return null;
        }

        if (! ctype_digit((string) $value) || (int) $value < 1) {
            throw new InvalidOptionException(sprintf('--%s must be a positive integer.', $option));
        }

        return (int) $value;
    }
}
