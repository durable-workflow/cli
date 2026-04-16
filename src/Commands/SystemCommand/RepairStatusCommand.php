<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\SystemCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RepairStatusCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('system:repair-status')
            ->setDescription('Show task repair candidate diagnostics and policy')
            ->setHelp(<<<'HELP'
Show how many stuck tasks the repair loop would pick up on its next
pass, broken out by scope, plus the policy that governs scanning.

<comment>Examples:</comment>

  <info>dw system:repair-status</info>
  <info>dw system:repair-status --json | jq '.candidates.total_candidates'</info>
HELP)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client($input)->get('/system/repair');

        if ($input->getOption('json')) {
            return $this->renderJson($output, $result);
        }

        $policy = $result['policy'] ?? [];
        $candidates = $result['candidates'] ?? [];

        $output->writeln('<info>Task Repair Policy</info>');
        $output->writeln(sprintf('  Redispatch after:     %ds', $policy['redispatch_after_seconds'] ?? 0));
        $output->writeln(sprintf('  Loop throttle:        %ds', $policy['loop_throttle_seconds'] ?? 0));
        $output->writeln(sprintf('  Scan limit:           %d', $policy['scan_limit'] ?? 0));
        $output->writeln(sprintf('  Scan strategy:        %s', $policy['scan_strategy'] ?? 'unknown'));
        $output->writeln(sprintf('  Backoff max:          %ds', $policy['failure_backoff_max_seconds'] ?? 0));
        $output->writeln('');

        $total = $candidates['total_candidates'] ?? 0;
        $tag = $total > 0 ? 'comment' : 'info';
        $output->writeln(sprintf('<%s>Repair Candidates: %d</%s>', $tag, $total, $tag));
        $output->writeln(sprintf('  Existing task:        %d', $candidates['existing_task_candidates'] ?? 0));
        $output->writeln(sprintf('  Missing task:         %d', $candidates['missing_task_candidates'] ?? 0));
        $output->writeln(sprintf('  Scan pressure:        %s', ($candidates['scan_pressure'] ?? false) ? 'yes' : 'no'));

        if (($candidates['oldest_task_candidate_created_at'] ?? null) !== null) {
            $output->writeln(sprintf('  Oldest task candidate: %s', $candidates['oldest_task_candidate_created_at']));
        }
        if (($candidates['oldest_missing_run_started_at'] ?? null) !== null) {
            $output->writeln(sprintf('  Oldest missing run:    %s', $candidates['oldest_missing_run_started_at']));
        }

        $scopes = $candidates['scopes'] ?? [];
        if ($scopes !== []) {
            $output->writeln('');
            $output->writeln('<info>Scopes</info>');
            $this->renderTable($output, ['Scope', 'Existing', 'Missing', 'Total', 'Selected', 'Scan Limited'], array_map(
                static fn (array $scope): array => [
                    $scope['scope_key'] ?? '',
                    $scope['existing_task_candidates'] ?? 0,
                    $scope['missing_task_candidates'] ?? 0,
                    $scope['total_candidates'] ?? 0,
                    $scope['selected_total_candidates'] ?? 0,
                    ($scope['scan_limited_by_global_policy'] ?? false) ? 'yes' : 'no',
                ],
                $scopes,
            ));
        }

        return Command::SUCCESS;
    }
}
