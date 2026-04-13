<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\SystemCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RepairPassCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('system:repair-pass')
            ->setDescription('Run one task repair sweep on the server')
            ->addOption('run-id', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Limit to specific run IDs')
            ->addOption('instance-id', null, InputOption::VALUE_REQUIRED, 'Limit to a specific workflow instance ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $body = [];

        $runIds = $input->getOption('run-id');
        if (is_array($runIds) && $runIds !== []) {
            $body['run_ids'] = $runIds;
        }

        $instanceId = $input->getOption('instance-id');
        if (is_string($instanceId) && $instanceId !== '') {
            $body['instance_id'] = $instanceId;
        }

        $result = $this->client($input)->post('/system/repair/pass', $body);

        if ($input->getOption('json')) {
            return $this->renderJson($output, $result);
        }

        if ($result['throttled'] ?? false) {
            $output->writeln('<comment>Repair pass skipped (watchdog throttle held)</comment>');

            return Command::SUCCESS;
        }

        $output->writeln('<info>Repair pass completed</info>');
        $output->writeln(sprintf(
            '  Candidates:   %d existing task, %d missing task',
            $result['selected_existing_task_candidates'] ?? 0,
            $result['selected_missing_task_candidates'] ?? 0,
        ));
        $output->writeln(sprintf(
            '  Repaired:     %d existing, %d missing, %d dispatched',
            $result['repaired_existing_tasks'] ?? 0,
            $result['repaired_missing_tasks'] ?? 0,
            $result['dispatched_tasks'] ?? 0,
        ));
        $output->writeln(sprintf(
            '  Contracts:    %d selected, %d backfilled, %d unavailable',
            $result['selected_command_contract_candidates'] ?? 0,
            $result['backfilled_command_contracts'] ?? 0,
            $result['command_contract_backfill_unavailable'] ?? 0,
        ));

        $hasFailures = false;

        foreach ($result['existing_task_failures'] ?? [] as $failure) {
            $output->writeln(sprintf(
                '<error>  Task %s: %s</error>',
                $failure['candidate_id'] ?? '?',
                $failure['message'] ?? 'unknown error',
            ));
            $hasFailures = true;
        }

        foreach ($result['missing_run_failures'] ?? [] as $failure) {
            $output->writeln(sprintf(
                '<error>  Run %s: %s</error>',
                $failure['run_id'] ?? '?',
                $failure['message'] ?? 'unknown error',
            ));
            $hasFailures = true;
        }

        foreach ($result['command_contract_failures'] ?? [] as $failure) {
            $output->writeln(sprintf(
                '<error>  Contract %s: %s</error>',
                $failure['run_id'] ?? '?',
                $failure['message'] ?? 'unknown error',
            ));
            $hasFailures = true;
        }

        return $hasFailures ? Command::FAILURE : Command::SUCCESS;
    }
}
