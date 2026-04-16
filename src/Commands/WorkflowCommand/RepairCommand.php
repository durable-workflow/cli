<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RepairCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:repair')
            ->setDescription('Request repair of a workflow run')
            ->setHelp(<<<'HELP'
Ask the server to re-enqueue the workflow's next task if it has
stalled (stuck behind a lost worker, expired lease, etc.). This is
safe to call on a healthy workflow — the server will no-op.

<comment>Example:</comment>

  <info>dw workflow:repair chk-42</info>
HELP)
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow-id');

        $result = $this->client($input)->post("/workflows/{$workflowId}/repair");

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
