<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\CompletionValues;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:update')
            ->setDescription('Send an update to a workflow')
            ->setHelp(<<<'HELP'
Send a synchronous update to a workflow and wait for the outcome.
<comment>--wait=accepted</comment> returns when the workflow has
validated the update; <comment>--wait=completed</comment> returns when
the handler has finished and a result is available.

Every update carries a durable command key (<comment>request_id</comment>).
The CLI generates one per invocation unless <comment>--request-id</comment>
is given, and prints the key it used. After a lost response, retry the same
command with that key: the server returns the original receipt (same update
id, status and result) instead of recording a second update, and refuses the
key with HTTP 409 <comment>request_id_conflict</comment> if the retry carries
a different update name, arguments or run target.

<comment>Examples:</comment>

  <info>dw workflow:update chk-42 increase_quota -i '{"by":10}'</info>
  <info>dw workflow:update chk-42 increase_quota -i '{"by":10}' --wait=completed</info>
  <info>dw workflow:update chk-42 increase_quota -i '{"by":10}' --request-id=quota-chk-42-2026-10-20 --json</info>
HELP)
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addArgument('update-name', InputArgument::REQUIRED, 'Update name')
            ->addOption('wait', null, InputOption::VALUE_OPTIONAL, 'Wait policy (accepted, completed)', 'accepted', CompletionValues::UPDATE_WAIT_POLICIES)
            ->addOption('run-id', null, InputOption::VALUE_OPTIONAL, 'Target a specific run ID')
            ->addOption('request-id', null, InputOption::VALUE_OPTIONAL, 'Durable command key reused on retry (1-255 bytes); generated when omitted')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the command response as JSON');
        $this->addInputOptions('Update input payload');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow-id');
        $updateName = $input->getArgument('update-name');
        $runId = $input->getOption('run-id');
        $client = $this->client($input);
        $waitFor = $this->optionalString($input->getOption('wait')) ?? 'accepted';

        if (! $this->validateControlPlaneOption(
            client: $client,
            output: $output,
            operation: 'update',
            field: 'wait_for',
            value: $waitFor,
            optionName: '--wait',
        )) {
            return Command::INVALID;
        }

        $body = [
            'wait_for' => $waitFor,
            'request_id' => $this->commandRequestId($input, 'update'),
        ];

        $parsedInput = $this->parseInputArgumentsOption($input);
        if ($parsedInput !== null) {
            $body['input'] = $parsedInput;
        }

        $path = $runId !== null
            ? "/workflows/{$workflowId}/runs/{$runId}/update/{$updateName}"
            : "/workflows/{$workflowId}/update/{$updateName}";

        $result = $this->addNamespaceContext($input, $client->post($path, $body));
        $result = $this->withWorkflowUpdateDiagnostics(
            payload: $result,
            workflowId: (string) $workflowId,
            updateName: (string) $updateName,
            runId: is_string($runId) && $runId !== '' ? $runId : null,
            request: $body,
        );

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Update sent</info>');
        $output->writeln('  Workflow ID: '.$result['workflow_id']);
        $this->writeNamespaceLine($output, $result);
        $output->writeln('  Update: '.$result['update_name']);
        $output->writeln('  Update ID: '.$result['update_id']);
        $output->writeln('  Outcome: '.$result['outcome']);
        if (isset($result['command_status'])) {
            $output->writeln('  Command Status: '.$result['command_status']);
        }
        if (isset($result['update_status'])) {
            $output->writeln('  Update Status: '.$result['update_status']);
        }
        if (isset($result['wait_for'])) {
            $output->writeln('  Wait For: '.$result['wait_for']);
        }
        $this->writeCommandReceiptLines($output, $result);

        return Command::SUCCESS;
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === ''
            ? null
            : $value;
    }
}
