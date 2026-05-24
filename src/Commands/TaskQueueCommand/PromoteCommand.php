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

class PromoteCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('task-queue:promote')
            ->setDescription('Promote a build_id cohort for new workflow starts')
            ->setHelp(<<<'HELP'
Promote a build_id cohort so new workflow starts on the task queue pin
to that worker build. Existing workflow runs keep their stamped
compatibility marker and continue routing only to compatible workers.

Pass <info>--unversioned</info> to promote the legacy unversioned cohort
(workers registered without a build_id). Otherwise a build_id value is
required.

<comment>Examples:</comment>

  <info>dw task-queue:promote orders --build-id build-2026.04.21-z9</info>
  <info>dw task-queue:promote orders --unversioned</info>
HELP)
            ->addArgument('task-queue', InputArgument::REQUIRED, 'Task queue name')
            ->addOption('build-id', null, InputOption::VALUE_REQUIRED, 'Build ID to promote for new workflow starts')
            ->addOption('unversioned', null, InputOption::VALUE_NONE, 'Target the unversioned cohort (workers without a build_id)');
        $this->addJsonOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskQueue = (string) $input->getArgument('task-queue');
        $buildId = $this->resolveTargetBuildId($input);

        $result = $this->addNamespaceContext(
            $input,
            $this->client($input)->post(
                "/task-queues/{$taskQueue}/build-ids/promote",
                ['build_id' => $buildId],
            ),
        );

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $rendered = $this->formatBuildId($result['build_id'] ?? $buildId);
        $promotedAt = $result['promoted_at'] ?? null;

        $output->writeln(sprintf(
            '<info>Promoted build_id %s on task queue %s.</info>',
            $rendered,
            $result['task_queue'] ?? $taskQueue,
        ));
        $this->writeNamespaceLine($output, $result);

        if (is_string($promotedAt) && $promotedAt !== '') {
            $output->writeln('Promoted at: '.$promotedAt);
        }

        $output->writeln('');
        $output->writeln('Fresh workflow starts now pin to this build_id. Existing runs keep their current compatibility marker and continue routing to compatible workers.');

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
