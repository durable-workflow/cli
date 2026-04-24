<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RepairCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:repair')
            ->setDescription('Request repair of a workflow run')
            ->setHelp(<<<'HELP'
Ask the server to re-enqueue the workflow's next task if it has stalled
behind a lost worker or expired lease. Repair is engine-level recovery:
it routes to the same decision set, does not duplicate history, and the
exactly-once-at-commit guarantee still holds for the typed history rows
the decision batch writes. Safe to call on a healthy workflow — the
server will no-op.

<comment>Examples:</comment>

  <info>dw workflow:repair chk-42</info>
  <info>dw workflow:repair chk-42 --json</info>
HELP)
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow-id');

        $result = $this->client($input)->post("/workflows/{$workflowId}/repair");

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Repair requested</info>');
        $output->writeln('  Workflow ID: '.$result['workflow_id']);
        $output->writeln('  Outcome: '.$result['outcome']);
        if (isset($result['command_status'])) {
            $output->writeln('  Command Status: '.$result['command_status']);
        }
        if (isset($result['command_id'])) {
            $output->writeln('  Command ID: '.$result['command_id']);
        }

        return Command::SUCCESS;
    }
}
