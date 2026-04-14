<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
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
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Workflow type')
            ->addOption('workflow-id', 'w', InputOption::VALUE_OPTIONAL, 'Workflow ID (auto-generated if omitted)')
            ->addOption('business-key', null, InputOption::VALUE_OPTIONAL, 'Business key')
            ->addOption('task-queue', null, InputOption::VALUE_OPTIONAL, 'Task queue', 'default')
            ->addOption('duplicate-policy', null, InputOption::VALUE_OPTIONAL, 'Duplicate policy (discover canonical values with server:info)')
            ->addOption('input', 'i', InputOption::VALUE_OPTIONAL, 'Input JSON')
            ->addOption('memo', null, InputOption::VALUE_OPTIONAL, 'Memo JSON')
            ->addOption('search-attr', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Search attributes (key=value)')
            ->addOption('execution-timeout', null, InputOption::VALUE_REQUIRED, 'Execution timeout in seconds (across all runs)')
            ->addOption('run-timeout', null, InputOption::VALUE_REQUIRED, 'Run timeout in seconds (single run)')
            ->addOption('wait', null, InputOption::VALUE_NONE, 'Wait for the workflow to reach a terminal state');
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
            'input' => $input->getOption('input') ? json_decode($input->getOption('input'), true) : null,
            'memo' => $input->getOption('memo') ? json_decode($input->getOption('memo'), true) : null,
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

        if ($input->getOption('wait')) {
            return $this->waitForCompletion($client, $output, $result['workflow_id']);
        }

        return Command::SUCCESS;
    }

    private function waitForCompletion(
        \DurableWorkflow\Cli\Support\ServerClient $client,
        OutputInterface $output,
        string $workflowId,
    ): int {
        $output->writeln('');
        $output->writeln('<comment>Waiting for workflow to complete...</comment>');

        while (true) {
            sleep(self::WAIT_POLL_INTERVAL_SECONDS);

            $describe = $client->get("/workflows/{$workflowId}");

            if ($this->isTerminal($describe)) {
                $output->writeln('');
                $output->writeln('<info>Workflow reached terminal state</info>');
                $output->writeln('  Status: '.($describe['status'] ?? '-'));
                $output->writeln('  Closed Reason: '.($describe['closed_reason'] ?? '-'));
                $output->writeln('  Closed At: '.($describe['closed_at'] ?? '-'));

                if (isset($describe['output'])) {
                    $output->writeln('  Output: '.json_encode($describe['output'], JSON_UNESCAPED_SLASHES));
                }

                $statusBucket = $describe['status_bucket'] ?? null;

                return $statusBucket === 'completed' ? Command::SUCCESS : Command::FAILURE;
            }

            $output->write('.');
        }
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
