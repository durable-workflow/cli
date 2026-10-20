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

Every signal carries a durable command key (<comment>request_id</comment>).
The CLI generates one per invocation unless <comment>--request-id</comment>
is given, and prints the key it used. After a lost response, retry the same
command with that key: the server returns the original receipt instead of
recording a second signal, and refuses the key with HTTP 409
<comment>request_id_conflict</comment> if the retry carries a different
signal name, arguments or run target.

<comment>Examples:</comment>

  <info>dw workflow:signal chk-42 approve</info>
  <info>dw workflow:signal chk-42 payment_received -i '{"amount":99.95}'</info>
  <info>dw workflow:signal chk-42 approve --request-id=approve-chk-42-2026-10-20 --json</info>
HELP)
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addArgument('signal-name', InputArgument::REQUIRED, 'Signal name')
            ->addOption('run-id', null, InputOption::VALUE_OPTIONAL, 'Target a specific run ID')
            ->addOption('request-id', null, InputOption::VALUE_OPTIONAL, 'Durable command key reused on retry (1-255 bytes); generated when omitted')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the command response as JSON');
        $this->addInputOptions('Signal input payload');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow-id');
        $signalName = $input->getArgument('signal-name');
        $runId = $input->getOption('run-id');
        $requestId = $this->commandRequestId($input, 'signal');

        $body = [
            'request_id' => $requestId,
        ];
        $parsedInput = $this->parseInputArgumentsOption($input);
        if ($parsedInput !== null) {
            $body['input'] = $parsedInput;
        }

        $path = $runId !== null
            ? "/workflows/{$workflowId}/runs/{$runId}/signal/{$signalName}"
            : "/workflows/{$workflowId}/signal/{$signalName}";

        $result = $this->addNamespaceContext($input, $this->client($input)->post($path, $body));

        // Servers that predate keyed signals do not echo the key; the operator
        // still needs it to retry after a lost response.
        if (! is_string($result['request_id'] ?? null) || $result['request_id'] === '') {
            $result['request_id'] = $requestId;
        }

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Signal sent</info>');
        $output->writeln('  Workflow ID: '.$result['workflow_id']);
        $this->writeNamespaceLine($output, $result);
        $output->writeln('  Signal: '.$result['signal_name']);
        $output->writeln('  Outcome: '.$result['outcome']);
        if (isset($result['command_status'])) {
            $output->writeln('  Command Status: '.$result['command_status']);
        }
        if (isset($result['command_id'])) {
            $output->writeln('  Command ID: '.$result['command_id']);
        }
        $this->writeCommandReceiptLines($output, $result);

        return Command::SUCCESS;
    }
}
