<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SignalCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:signal')
            ->setDescription('Send a signal to a running workflow')
            ->setHelp(<<<'HELP'
Deliver an asynchronous signal to a running workflow. The signal is
durably recorded in history even if the workflow is currently idle or
waiting.

<comment>Examples:</comment>

  <info>dw workflow:signal chk-42 approve</info>
  <info>dw workflow:signal chk-42 payment_received -i '{"amount":99.95}'</info>
HELP)
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addArgument('signal-name', InputArgument::REQUIRED, 'Signal name')
            ->addOption('input', 'i', InputOption::VALUE_OPTIONAL, 'Signal input JSON')
            ->addOption('run-id', null, InputOption::VALUE_OPTIONAL, 'Target a specific run ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow-id');
        $signalName = $input->getArgument('signal-name');
        $runId = $input->getOption('run-id');

        $body = [];
        if ($input->getOption('input')) {
            $body['input'] = json_decode($input->getOption('input'), true);
        }

        $path = $runId !== null
            ? "/workflows/{$workflowId}/runs/{$runId}/signal/{$signalName}"
            : "/workflows/{$workflowId}/signal/{$signalName}";

        $result = $this->client($input)->post($path, $body);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Signal sent</info>');
        $output->writeln('  Workflow ID: '.$result['workflow_id']);
        $output->writeln('  Signal: '.$result['signal_name']);
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
