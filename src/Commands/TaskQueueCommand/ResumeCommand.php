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

class ResumeCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('task-queue:resume')
            ->setDescription('Clear drain intent from a build_id cohort on a task queue')
            ->setHelp(<<<'HELP'
Reverse a prior drain so the build_id cohort can accept new work again.
This is the rollback path: stamp the cohort as active, clear its
drained_at timestamp, and flip any draining worker rows back to active
so the observed state matches the operator intent immediately. The
call is idempotent.

Pass <info>--unversioned</info> to resume the legacy unversioned cohort
(workers registered without a build_id). Otherwise a build_id value is
required.

<comment>Examples:</comment>

  <info>dw task-queue:resume orders --build-id build-2026.04.21-z9</info>
  <info>dw task-queue:resume orders --unversioned</info>
HELP)
            ->addArgument('task-queue', InputArgument::REQUIRED, 'Task queue name')
            ->addOption('build-id', null, InputOption::VALUE_REQUIRED, 'Build ID to resume')
            ->addOption('unversioned', null, InputOption::VALUE_NONE, 'Target the unversioned cohort (workers without a build_id)');
        $this->addJsonOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskQueue = (string) $input->getArgument('task-queue');
        $buildId = $this->resolveTargetBuildId($input);

        $result = $this->client($input)->post(
            "/task-queues/{$taskQueue}/build-ids/resume",
            ['build_id' => $buildId],
        );

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $rendered = $this->formatBuildId($result['build_id'] ?? $buildId);

        $output->writeln(sprintf(
            '<info>Resumed build_id %s on task queue %s.</info>',
            $rendered,
            $result['task_queue'] ?? $taskQueue,
        ));
        $output->writeln('');
        $output->writeln('The cohort is marked active again. Workers that were flagged as draining have been flipped back to active and will resume claiming new tasks.');

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
