<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class QueryCommand extends BaseCommand
{
    private const CLUSTER_INFO_TIMEOUT_SECONDS = 5.0;

    private const DEFAULT_HTTP_TIMEOUT_SECONDS = 35.0;

    private const HTTP_TIMEOUT_GRACE_SECONDS = 10.0;

    private const MAX_HTTP_TIMEOUT_SECONDS = 300.0;

    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:query')
            ->setDescription('Query a workflow\'s state')
            ->setHelp(<<<'HELP'
Invoke a side-effect-free query handler on a running workflow and
print the handler's result. Queries do not mutate state.

<comment>Examples:</comment>

  <info>dw workflow:query chk-42 status</info>
  <info>dw workflow:query chk-42 total --input='{"currency":"USD"}'</info>
HELP)
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addArgument('query-name', InputArgument::REQUIRED, 'Query name')
            ->addOption('run-id', null, InputOption::VALUE_OPTIONAL, 'Target a specific run ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the command response as JSON');
        $this->addInputOptions('Query input payload');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow-id');
        $queryName = $input->getArgument('query-name');
        $runId = $input->getOption('run-id');

        $body = [];
        $parsedInput = $this->parseInputArgumentsOption($input);
        if ($parsedInput !== null) {
            $body['input'] = $parsedInput;
        }

        $path = $runId !== null
            ? "/workflows/{$workflowId}/runs/{$runId}/query/{$queryName}"
            : "/workflows/{$workflowId}/query/{$queryName}";

        $result = $this->addNamespaceContext($input, $this->client($input, $this->queryHttpTimeoutSeconds($input))->post($path, $body));

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Query result</info>');
        $this->writeNamespaceLine($output, $result);
        if (isset($result['query_name'])) {
            $output->writeln('  Query: '.$result['query_name']);
        }
        $output->writeln(json_encode($result['result'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }

    private function queryHttpTimeoutSeconds(InputInterface $input): float
    {
        $timeout = self::DEFAULT_HTTP_TIMEOUT_SECONDS;

        try {
            $serverTimeout = $this->serverQueryTimeoutSeconds(
                $this->freshClient($input, self::CLUSTER_INFO_TIMEOUT_SECONDS)->clusterInfoUnchecked(),
            );

            if ($serverTimeout !== null) {
                $timeout = max($timeout, $serverTimeout + self::HTTP_TIMEOUT_GRACE_SECONDS);
            }
        } catch (\Throwable) {
            return $timeout;
        }

        return min(self::MAX_HTTP_TIMEOUT_SECONDS, $timeout);
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     */
    private function serverQueryTimeoutSeconds(array $clusterInfo): ?float
    {
        $workerProtocol = $clusterInfo['worker_protocol'] ?? null;
        if (! is_array($workerProtocol)) {
            return null;
        }

        $capabilities = $workerProtocol['server_capabilities'] ?? null;
        if (! is_array($capabilities)) {
            return null;
        }

        $timeouts = $capabilities['query_task_timeouts'] ?? null;
        if (! is_array($timeouts)) {
            return null;
        }

        $seconds = $timeouts['control_plane_timeout_seconds'] ?? null;

        return is_int($seconds) || is_float($seconds) ? max(0.0, (float) $seconds) : null;
    }
}
