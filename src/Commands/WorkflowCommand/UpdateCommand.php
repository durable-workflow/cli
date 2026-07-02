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

<comment>Examples:</comment>

  <info>dw workflow:update chk-42 increase_quota -i '{"by":10}'</info>
  <info>dw workflow:update chk-42 increase_quota -i '{"by":10}' --wait=completed</info>
HELP)
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addArgument('update-name', InputArgument::REQUIRED, 'Update name')
            ->addOption('wait', null, InputOption::VALUE_OPTIONAL, 'Wait policy (accepted, completed)', 'accepted', CompletionValues::UPDATE_WAIT_POLICIES)
            ->addOption('run-id', null, InputOption::VALUE_OPTIONAL, 'Target a specific run ID')
            ->addOption('request-id', null, InputOption::VALUE_OPTIONAL, 'Durable request identifier for retry/idempotency diagnostics')
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
        ];
        $requestId = $this->optionalString($input->getOption('request-id'));
        if ($requestId !== null) {
            $body['request_id'] = $requestId;
        }

        $parsedInput = $this->parseInputArgumentsOption($input);
        if ($parsedInput !== null) {
            $body['input'] = $parsedInput;
        }

        $path = $runId !== null
            ? "/workflows/{$workflowId}/runs/{$runId}/update/{$updateName}"
            : "/workflows/{$workflowId}/update/{$updateName}";

        $result = $this->addNamespaceContext($input, $client->post($path, $body));
        $result = $this->withUpdateDiagnostics(
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

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function withUpdateDiagnostics(
        array $payload,
        string $workflowId,
        string $updateName,
        ?string $runId,
        array $request,
    ): array {
        $payload['workflow_id'] ??= $workflowId;
        $payload['update_name'] ??= $updateName;

        if ($runId !== null) {
            $payload['run_id'] ??= $runId;
        }

        if (! array_key_exists('request_id', $payload) && isset($request['request_id'])) {
            $payload['request_id'] = $request['request_id'];
        }

        $payload['request'] ??= array_filter([
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'update_name' => $updateName,
            'request_id' => $request['request_id'] ?? $payload['request_id'] ?? null,
            'wait_for' => $request['wait_for'] ?? null,
            'input' => $request['input'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        $historyReferences = $this->historyReferences($payload);
        if ($historyReferences !== [] && ! array_key_exists('history_references', $payload)) {
            $payload['history_references'] = $historyReferences;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function historyReferences(array $payload): array
    {
        $references = [];

        foreach ([
            'workflow_sequence',
            'command_sequence',
            'accepted_at',
            'applied_at',
            'rejected_at',
            'closed_at',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $references[$field] = $payload[$field];
            }
        }

        return $references;
    }
}
