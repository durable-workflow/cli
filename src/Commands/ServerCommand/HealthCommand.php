<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ServerCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class HealthCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('server:health')
            ->setDescription('Check the health of the Durable Workflow server')
            ->setHelp(<<<'HELP'
Check server health and exit with <comment>NETWORK (3)</comment> if the
server is unreachable.

<comment>Examples:</comment>

  <info>dw server:health</info>
  <info>dw server:health --server=http://localhost:8080</info>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client($input)->get('/health');

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('Server is '.$this->formatStatus($result['status'] ?? null));
        $output->writeln('Timestamp: '.$result['timestamp']);

        $checks = is_array($result['checks'] ?? null) ? $result['checks'] : [];
        $unhealthyChecks = [];

        if ($checks !== []) {
            $rows = [];

            foreach ($checks as $check => $status) {
                $checkName = (string) $check;
                $statusValue = is_scalar($status) ? (string) $status : 'unknown';
                $healthy = $this->isHealthyCheckStatus($statusValue);

                if (! $healthy) {
                    $unhealthyChecks[] = $checkName;
                }

                $rows[] = [
                    $checkName,
                    $this->formatStatus($statusValue),
                    $healthy ? '-' : $this->healthCheckAction($checkName),
                ];
            }

            $this->renderTable($output, ['Check', 'Status', 'Action'], $rows);
        }

        if ($unhealthyChecks !== []) {
            $output->writeln('');
            $output->writeln('<comment>Next steps:</comment>');
            $output->writeln('  - Run `dw doctor` with the same --env or --server options for connection and auth context.');
            $output->writeln('  - Inspect server logs and dependencies for: '.implode(', ', $unhealthyChecks).'.');
        }

        return Command::SUCCESS;
    }

    private function isHealthyCheckStatus(string $status): bool
    {
        return in_array(strtolower($status), ['ok', 'healthy', 'success', 'succeeded'], true);
    }

    private function healthCheckAction(string $check): string
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $check));

        return match (true) {
            str_contains($normalized, 'database'), str_contains($normalized, 'mysql') => 'Check database connectivity and migrations.',
            str_contains($normalized, 'redis'), str_contains($normalized, 'cache') => 'Check Redis/cache connectivity.',
            str_contains($normalized, 'queue'), str_contains($normalized, 'worker') => 'Check workers and queue consumers.',
            str_contains($normalized, 'storage'), str_contains($normalized, 'payload') => 'Check payload storage configuration.',
            default => 'Inspect the server component named by this check.',
        };
    }
}
