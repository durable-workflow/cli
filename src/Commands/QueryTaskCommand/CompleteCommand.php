<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\QueryTaskCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\InvalidOptionException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CompleteCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('query-task:complete')
            ->setDescription('Complete a leased routed workflow query task')
            ->setHelp(<<<'HELP'
Complete a routed workflow query task through the worker protocol. The result
is sent as JSON plus a matching JSON result envelope so non-PHP workers and
operators share the same wire contract.

<comment>Examples:</comment>

  <info>dw query-task:complete query-123 1 --lease-owner=cli-worker --result='{"ready":true}'</info>
  <info>dw query-task:complete query-123 1 --lease-owner=cli-worker --result='42' --json</info>
HELP)
            ->addArgument('query-task-id', InputArgument::REQUIRED, 'Query task ID')
            ->addArgument('attempt', InputArgument::REQUIRED, 'Query task attempt number')
            ->addOption('lease-owner', null, InputOption::VALUE_REQUIRED, 'Lease owner identity', 'cli')
            ->addOption('result', null, InputOption::VALUE_REQUIRED, 'JSON query result')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queryTaskId = (string) $input->getArgument('query-task-id');
        $attempt = $this->positiveAttempt((string) $input->getArgument('attempt'));
        $rawResult = $input->getOption('result');

        if (! is_string($rawResult) || trim($rawResult) === '') {
            throw new InvalidOptionException('--result is required.');
        }

        $resultPayload = $this->parseJsonOption($rawResult, 'result');
        $result = $this->client($input)->post("/worker/query-tasks/{$queryTaskId}/complete", [
            'lease_owner' => $input->getOption('lease-owner'),
            'query_task_attempt' => $attempt,
            'result' => $resultPayload,
            'result_envelope' => [
                'codec' => 'json',
                'blob' => json_encode($resultPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ],
        ]);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Query task completed</info>');
        $output->writeln('  Task ID: '.($result['query_task_id'] ?? $queryTaskId));
        $output->writeln('  Attempt: '.($result['query_task_attempt'] ?? $attempt));
        $output->writeln('  Outcome: '.($result['outcome'] ?? '-'));

        return Command::SUCCESS;
    }

    private function positiveAttempt(string $value): int
    {
        if (! ctype_digit($value) || (int) $value < 1) {
            throw new InvalidOptionException('attempt must be a positive integer.');
        }

        return (int) $value;
    }
}
