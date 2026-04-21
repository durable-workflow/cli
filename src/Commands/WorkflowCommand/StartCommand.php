<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\CompletionValues;
use DurableWorkflow\Cli\Support\DetectsTerminalStatus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StartCommand extends BaseCommand
{
    use DetectsTerminalStatus;

    private const WAIT_POLL_INTERVAL_SECONDS = 2;

    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:start')
            ->setDescription('Start a new workflow execution')
            ->setHelp(<<<'HELP'
Start a workflow by type. The server accepts JSON input, memo, and
search attributes. With <comment>--wait</comment> the command blocks until
the workflow reaches a terminal state and exits with
<comment>SUCCESS (0)</comment> on completion or <comment>FAILURE (1)</comment> on
failure / cancellation / termination.

<comment>Examples:</comment>

  # Start with no input
  <info>dw workflow:start --type=orders.Checkout</info>

  # Start with JSON input and a custom workflow id
  <info>dw workflow:start -t orders.Checkout -w chk-42 -i '{"order_id":42}'</info>

  # Start, then wait for terminal state
  <info>dw workflow:start -t orders.Checkout -i '{"order_id":42}' --wait</info>

  # Start with search attributes for visibility filters
  <info>dw workflow:start -t orders.Checkout --search-attr env=prod --search-attr tier=gold</info>
HELP)
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Workflow type')
            ->addOption('workflow-id', 'w', InputOption::VALUE_OPTIONAL, 'Workflow ID (auto-generated if omitted)')
            ->addOption('business-key', null, InputOption::VALUE_OPTIONAL, 'Business key')
            ->addOption('task-queue', null, InputOption::VALUE_OPTIONAL, 'Task queue', 'default')
            ->addOption('duplicate-policy', null, InputOption::VALUE_OPTIONAL, 'Duplicate policy (discover canonical values with server:info)', null, CompletionValues::WORKFLOW_DUPLICATE_POLICIES)
            ->addOption('memo', null, InputOption::VALUE_OPTIONAL, 'Memo JSON')
            ->addOption('search-attr', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Search attributes (key=value)')
            ->addOption('execution-timeout', null, InputOption::VALUE_REQUIRED, 'Execution timeout in seconds (across all runs)')
            ->addOption('run-timeout', null, InputOption::VALUE_REQUIRED, 'Run timeout in seconds (single run)')
            ->addOption('wait', null, InputOption::VALUE_NONE, 'Wait for the workflow to reach a terminal state')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
        $this->addInputOptions('Workflow input payload');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $this->client($input);
        $duplicatePolicy = $this->optionalString($input->getOption('duplicate-policy'));

        if (! $this->validateControlPlaneOption(
            client: $client,
            output: $output,
            operation: 'start',
            field: 'duplicate_policy',
            value: $duplicatePolicy,
            optionName: '--duplicate-policy',
        )) {
            return Command::INVALID;
        }

        $executionTimeout = $input->getOption('execution-timeout');
        $runTimeout = $input->getOption('run-timeout');

        $body = array_filter([
            'workflow_type' => $input->getOption('type'),
            'workflow_id' => $input->getOption('workflow-id'),
            'business_key' => $input->getOption('business-key'),
            'task_queue' => $input->getOption('task-queue'),
            'duplicate_policy' => $duplicatePolicy,
            'input' => $this->parseInputArgumentsOption($input),
            'memo' => $this->parseJsonOption($input->getOption('memo'), 'memo'),
            'execution_timeout_seconds' => $executionTimeout !== null ? (int) $executionTimeout : null,
            'run_timeout_seconds' => $runTimeout !== null ? (int) $runTimeout : null,
        ], fn ($v) => $v !== null);

        $searchAttrs = $input->getOption('search-attr');
        if ($searchAttrs) {
            $attrs = [];
            foreach ($searchAttrs as $attr) {
                [$key, $value] = explode('=', $attr, 2);
                $attrs[$key] = $value;
            }
            $body['search_attributes'] = $attrs;
        }

        $result = $client->post('/workflows', $body);
        $wait = (bool) $input->getOption('wait');
        $wantsJson = $this->wantsJson($input);

        // --wait defers the final emit until the workflow reaches a terminal
        // state, so automation callers using --json receive the terminal
        // describe (and the matching success/failure exit code) instead of
        // the transient start response.
        if ($wait) {
            return $this->waitAndEmit($client, $output, $result, $wantsJson);
        }

        if ($wantsJson) {
            return $this->renderJson($output, $result);
        }

        $this->emitStartBanner($output, $result);

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $startResult
     */
    private function waitAndEmit(
        \DurableWorkflow\Cli\Support\ServerClient $client,
        OutputInterface $output,
        array $startResult,
        bool $wantsJson,
    ): int {
        if (! $wantsJson) {
            $this->emitStartBanner($output, $startResult);
            $output->writeln('');
            $output->writeln('<comment>Waiting for workflow to complete...</comment>');
        }

        $describe = $this->pollUntilTerminal(
            $client,
            $output,
            (string) $startResult['workflow_id'],
            $wantsJson,
        );

        $exit = ($describe['status_bucket'] ?? null) === 'completed'
            ? Command::SUCCESS
            : Command::FAILURE;

        if ($wantsJson) {
            $output->writeln(json_encode($describe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $output->writeln('');
        $output->writeln('<info>Workflow reached terminal state</info>');
        $output->writeln('  Status: '.($describe['status'] ?? '-'));
        $output->writeln('  Closed Reason: '.($describe['closed_reason'] ?? '-'));
        $output->writeln('  Closed At: '.($describe['closed_at'] ?? '-'));

        if (isset($describe['output'])) {
            $output->writeln('  Output: '.json_encode($describe['output'], JSON_UNESCAPED_SLASHES));
        }

        return $exit;
    }

    /**
     * @return array<string, mixed>
     */
    private function pollUntilTerminal(
        \DurableWorkflow\Cli\Support\ServerClient $client,
        OutputInterface $output,
        string $workflowId,
        bool $wantsJson,
    ): array {
        while (true) {
            $describe = $client->get("/workflows/{$workflowId}");

            if ($this->isTerminal($describe)) {
                return $describe;
            }

            if (! $wantsJson) {
                $output->write('.');
            }

            sleep(self::WAIT_POLL_INTERVAL_SECONDS);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function emitStartBanner(OutputInterface $output, array $result): void
    {
        $output->writeln('<info>Workflow started</info>');
        $output->writeln('  Workflow ID: '.$result['workflow_id']);
        $output->writeln('  Run ID: '.$result['run_id']);
        if (isset($result['business_key'])) {
            $output->writeln('  Business Key: '.$result['business_key']);
        }
        if (isset($result['payload_codec'])) {
            $output->writeln('  Payload Codec: '.$result['payload_codec']);
        }
        $output->writeln('  Outcome: '.$result['outcome']);
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return $value;
    }
}
