<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands;

use DurableWorkflow\Cli\Support\DetectsTerminalStatus;
use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\InvalidOptionException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class WatchCommand extends BaseCommand
{
    use DetectsTerminalStatus;

    private const DEFAULT_INTERVAL_SECONDS = 2;

    private const TRACKED_FIELDS = [
        'status',
        'status_bucket',
        'is_terminal',
        'closed_at',
        'closed_reason',
        'wait_kind',
        'wait_reason',
        'last_progress_at',
        'output',
    ];

    protected function configure(): void
    {
        parent::configure();
        $this->setName('watch')
            ->setDescription('Watch long-running resources and print state changes')
            ->setHelp(<<<'HELP'
Watch a workflow until it reaches a terminal state. The command polls the
workflow describe endpoint and prints only fields that changed since the
previous poll.

<comment>Examples:</comment>

  <info>dw watch workflow chk-42</info>
  <info>dw watch workflow chk-42 --run-id=01HZ...</info>
  <info>dw watch workflow chk-42 --interval=5 --max-polls=60</info>
HELP)
            ->addArgument('resource', InputArgument::REQUIRED, 'Resource to watch (workflow)')
            ->addArgument('id', InputArgument::REQUIRED, 'Resource ID')
            ->addOption('run-id', null, InputOption::VALUE_OPTIONAL, 'Target a specific workflow run ID')
            ->addOption('interval', 'i', InputOption::VALUE_OPTIONAL, 'Poll interval in seconds', (string) self::DEFAULT_INTERVAL_SECONDS)
            ->addOption('max-polls', null, InputOption::VALUE_OPTIONAL, 'Stop after this many polls if the resource is not terminal');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $resource = (string) $input->getArgument('resource');

        if ($resource !== 'workflow') {
            throw new InvalidOptionException(sprintf(
                'Unsupported watch resource [%s]; supported resources: workflow.',
                $resource,
            ));
        }

        return $this->watchWorkflow($input, $output, (string) $input->getArgument('id'));
    }

    private function watchWorkflow(InputInterface $input, OutputInterface $output, string $workflowId): int
    {
        $runId = $input->getOption('run-id');
        $interval = $this->parseNonNegativeInt($input->getOption('interval'), '--interval');
        $maxPolls = $this->parseOptionalPositiveInt($input->getOption('max-polls'), '--max-polls');
        $polls = 1;

        $result = $this->fetchWorkflow($input, $workflowId, is_string($runId) ? $runId : null);
        $output->writeln(sprintf(
            '<comment>Watching workflow %s%s (status: %s)</comment>',
            $workflowId,
            is_string($runId) ? " run {$runId}" : '',
            $this->formatScalar($result['status'] ?? null),
        ));

        if ($this->isTerminal($result)) {
            $output->writeln(sprintf('Terminal state reached: %s', $this->formatScalar($result['status'] ?? null)));

            return $this->terminalExitCode($result);
        }

        while (true) {
            if ($maxPolls !== null && $polls >= $maxPolls) {
                $output->writeln(sprintf(
                    '<error>Watch stopped after %d poll%s before terminal state.</error>',
                    $polls,
                    $polls === 1 ? '' : 's',
                ));

                return ExitCode::TIMEOUT;
            }

            if ($interval > 0) {
                sleep($interval);
            }

            $previous = $result;
            $result = $this->fetchWorkflow($input, $workflowId, is_string($runId) ? $runId : null);
            $polls++;

            $changes = $this->changedFields($previous, $result);

            if ($changes !== []) {
                $output->writeln(sprintf('<info>Change #%d</info>', $polls));
                foreach ($changes as [$field, $before, $after]) {
                    $output->writeln(sprintf(
                        '  %s: %s -> %s',
                        $field,
                        $this->formatValue($before),
                        $this->formatValue($after),
                    ));
                }
            }

            if ($this->isTerminal($result)) {
                $output->writeln(sprintf('Terminal state reached: %s', $this->formatScalar($result['status'] ?? null)));

                return $this->terminalExitCode($result);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchWorkflow(InputInterface $input, string $workflowId, ?string $runId): array
    {
        return $runId !== null
            ? $this->client($input)->get("/workflows/{$workflowId}/runs/{$runId}")
            : $this->client($input)->get("/workflows/{$workflowId}");
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $current
     * @return list<array{0: string, 1: mixed, 2: mixed}>
     */
    private function changedFields(array $previous, array $current): array
    {
        $changes = [];

        foreach (self::TRACKED_FIELDS as $field) {
            $before = $previous[$field] ?? null;
            $after = $current[$field] ?? null;

            if ($before !== $after) {
                $changes[] = [$field, $before, $after];
            }
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function terminalExitCode(array $result): int
    {
        $statusBucket = $result['status_bucket'] ?? null;
        $status = $result['status'] ?? null;

        return $statusBucket === 'completed' || $status === 'completed'
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    private function parseNonNegativeInt(mixed $value, string $optionName): int
    {
        if (! is_scalar($value) || ! ctype_digit((string) $value)) {
            throw new InvalidOptionException(sprintf('%s must be a non-negative integer.', $optionName));
        }

        return (int) $value;
    }

    private function parseOptionalPositiveInt(mixed $value, string $optionName): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_scalar($value) || ! ctype_digit((string) $value) || (int) $value < 1) {
            throw new InvalidOptionException(sprintf('%s must be a positive integer.', $optionName));
        }

        return (int) $value;
    }

    private function formatValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        return $this->formatScalar($value);
    }

    private function formatScalar(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
