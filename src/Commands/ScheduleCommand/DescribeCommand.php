<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ScheduleCommand;

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
        $this->setName('schedule:describe')
            ->setDescription('Show details of a schedule')
            ->setHelp(<<<'HELP'
Show a schedule's spec, action, state, overlap policy, buffered
actions, and skip history.

<comment>Examples:</comment>

  <info>dw schedule:describe daily-report</info>
  <info>dw schedule:describe daily-report --json | jq '.state'</info>
HELP)
            ->addArgument('schedule-id', InputArgument::REQUIRED, 'Schedule ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scheduleId = $input->getArgument('schedule-id');
        $result = $this->client($input)->get("/schedules/{$scheduleId}");

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Schedule: '.$scheduleId.'</info>');
        $output->writeln('  Spec: '.json_encode($result['spec'] ?? null, JSON_UNESCAPED_SLASHES));
        $output->writeln('  Action: '.json_encode($result['action'] ?? null, JSON_UNESCAPED_SLASHES));
        $output->writeln('  State: '.json_encode($result['state'] ?? null, JSON_UNESCAPED_SLASHES));
        $output->writeln('  Overlap Policy: '.($result['overlap_policy'] ?? 'skip'));

        $jitter = $result['jitter_seconds'] ?? 0;
        if ($jitter > 0) {
            $output->writeln('  Jitter: '.$jitter.'s');
        }

        $maxRuns = $result['max_runs'] ?? null;
        $remaining = $result['remaining_actions'] ?? null;
        if ($maxRuns !== null) {
            $output->writeln(sprintf('  Max Runs: %d (%s remaining)', $maxRuns, $remaining ?? '?'));
        }

        $info = $result['info'] ?? [];
        $bufferedActions = $info['buffered_actions'] ?? [];

        if (!empty($bufferedActions)) {
            $output->writeln(sprintf('  Buffered Actions: %d pending', count($bufferedActions)));
        }

        $skipCount = $info['skipped_trigger_count'] ?? 0;
        if ($skipCount > 0) {
            $output->writeln(sprintf('  Skipped: %d (last: %s)', $skipCount, $info['last_skip_reason'] ?? '-'));
        }

        return Command::SUCCESS;
    }
}
