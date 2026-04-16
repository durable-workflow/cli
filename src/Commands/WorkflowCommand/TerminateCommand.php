<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TerminateCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:terminate')
            ->setDescription('Terminate a workflow immediately')
            ->setHelp(<<<'HELP'
Force-stop a workflow. Unlike <comment>workflow:cancel</comment>,
termination does not give the workflow a chance to clean up — use it
for runaway or unreachable workflows.

<comment>Examples:</comment>

  <info>dw workflow:terminate chk-42 --reason="runaway loop"</info>
  <info>dw workflow:terminate chk-42 --run-id=01HZ...</info>
HELP)
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addOption('reason', null, InputOption::VALUE_OPTIONAL, 'Termination reason')
            ->addOption('run-id', null, InputOption::VALUE_OPTIONAL, 'Target a specific run ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow-id');
        $runId = $input->getOption('run-id');

        $body = array_filter([
            'reason' => $input->getOption('reason'),
        ], fn ($v) => $v !== null);

        $path = $runId !== null
            ? "/workflows/{$workflowId}/runs/{$runId}/terminate"
            : "/workflows/{$workflowId}/terminate";

        $result = $this->client($input)->post($path, $body);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Workflow terminated</info>');
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
