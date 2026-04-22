<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\TaskQueueCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DrainCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('task-queue:drain')
            ->setDescription('Mark a build_id cohort as draining for a task queue')
            ->setHelp(<<<'HELP'
Mark the workers registered under the given build_id as draining so
operators can cut traffic off a build before deleting its workers. The
call is idempotent: repeated drains do not shift the recorded
drained_at timestamp. Drain intent persists on the server, so later
workers that register or heartbeat under the same build_id also land
as draining.

Pass <info>--unversioned</info> to drain the legacy unversioned cohort
(workers registered without a build_id). Otherwise a build_id value is
required.

Use <info>dw task-queue:resume</info> to reverse a drain.

<comment>Examples:</comment>

  <info>dw task-queue:drain orders --build-id build-2026.04.21-z9</info>
  <info>dw task-queue:drain orders --unversioned</info>
HELP)
            ->addArgument('task-queue', InputArgument::REQUIRED, 'Task queue name')
            ->addOption('build-id', null, InputOption::VALUE_REQUIRED, 'Build ID to drain')
            ->addOption('unversioned', null, InputOption::VALUE_NONE, 'Target the unversioned cohort (workers without a build_id)');
        $this->addJsonOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskQueue = (string) $input->getArgument('task-queue');
        $buildId = $this->resolveTargetBuildId($input);

        $result = $this->client($input)->post(
            "/task-queues/{$taskQueue}/build-ids/drain",
            ['build_id' => $buildId],
        );

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $renderedBuildId = $this->formatBuildId($result['build_id'] ?? $buildId);
        $drainedAt = $result['drained_at'] ?? null;

        $output->writeln(sprintf(
            '<info>Drained build_id %s on task queue %s.</info>',
            $renderedBuildId,
            $result['task_queue'] ?? $taskQueue,
        ));

        if (is_string($drainedAt) && $drainedAt !== '') {
            $output->writeln('Drained at: '.$drainedAt);
        }

        $output->writeln('');
        $output->writeln('Workers registered under this build_id will keep running their in-flight work but stop claiming new tasks. Use <info>dw task-queue:resume</info> to undo this.');

        return Command::SUCCESS;
    }

    private function resolveTargetBuildId(InputInterface $input): ?string
    {
        $unversioned = (bool) $input->getOption('unversioned');
        $buildIdOption = $input->getOption('build-id');

        if ($unversioned && is_string($buildIdOption) && $buildIdOption !== '') {
            throw new InvalidOptionException('--unversioned cannot be combined with --build-id. Choose one.');
        }

        if ($unversioned) {
            return null;
        }

        if (! is_string($buildIdOption) || $buildIdOption === '') {
            throw new InvalidOptionException('One of --build-id <value> or --unversioned is required.');
        }

        return $buildIdOption;
    }

    private function formatBuildId(mixed $buildId): string
    {
        if (! is_string($buildId) || $buildId === '') {
            return '(unversioned)';
        }

        return $buildId;
    }
}
