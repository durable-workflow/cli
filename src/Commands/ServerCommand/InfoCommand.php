<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ServerCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\ControlPlaneRequestContract;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InfoCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('server:info')
            ->setDescription('Display server version and capabilities');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client($input)->clusterInfo();

        $output->writeln('<info>Durable Workflow Server</info>');
        $output->writeln('  Server ID: '.($result['server_id'] ?? 'unknown'));
        $output->writeln('  Version: '.($result['version'] ?? 'unknown'));
        $output->writeln('  Default Namespace: '.($result['default_namespace'] ?? 'default'));
        $output->writeln('');
        $output->writeln('Capabilities:');

        foreach ($result['capabilities'] ?? [] as $cap => $enabled) {
            $status = $enabled ? '<info>yes</info>' : '<comment>no</comment>';
            $output->writeln("  {$cap}: {$status}");
        }

        $workerFleet = $result['worker_fleet'] ?? null;

        if (is_array($workerFleet) && $workerFleet !== []) {
            $output->writeln('');
            $output->writeln('Worker Fleet:');
            $output->writeln('  Namespace: '.($workerFleet['namespace'] ?? 'unknown'));
            $output->writeln('  Active Workers: '.($workerFleet['active_workers'] ?? 0));
            $output->writeln('  Active Scopes: '.($workerFleet['active_worker_scopes'] ?? 0));

            $buildIds = $workerFleet['build_ids'] ?? [];
            if (is_array($buildIds) && $buildIds !== []) {
                $output->writeln('  Build IDs: '.implode(', ', $buildIds));
            }

            $queues = $workerFleet['queues'] ?? [];
            if (is_array($queues) && $queues !== []) {
                $output->writeln('  Queues: '.implode(', ', $queues));
            }
        }

        $controlPlane = $result['control_plane'] ?? null;

        if (is_array($controlPlane) && $controlPlane !== []) {
            $output->writeln('');
            $output->writeln('Control Plane:');
            $output->writeln('  Version: '.($controlPlane['version'] ?? 'unknown'));
            $output->writeln('  Header: '.($controlPlane['header'] ?? 'unknown'));

            $responseContract = $controlPlane['response_contract'] ?? null;

            if (is_array($responseContract)) {
                $output->writeln(sprintf(
                    '  Response Contract: %s v%s',
                    $responseContract['schema'] ?? 'unknown',
                    $responseContract['version'] ?? 'unknown',
                ));

                $nestedContract = $responseContract['contract'] ?? null;

                if (is_array($nestedContract)) {
                    $output->writeln(sprintf(
                        '  Nested Contract: %s v%s',
                        $nestedContract['schema'] ?? 'unknown',
                        $nestedContract['version'] ?? 'unknown',
                    ));
                    $output->writeln('  Legacy Field Policy: '.($nestedContract['legacy_field_policy'] ?? 'unknown'));
                }
            }

            $requestContract = ControlPlaneRequestContract::fromClusterInfo($result);

            if ($requestContract instanceof ControlPlaneRequestContract) {
                $this->renderRequestContract($output, $requestContract);
            } elseif (array_key_exists('request_contract', $controlPlane)) {
                $output->writeln('  Request Contract: incompatible');
            }
        }

        $workerProtocol = $result['worker_protocol'] ?? null;

        if (is_array($workerProtocol) && $workerProtocol !== []) {
            $output->writeln('');
            $output->writeln('Worker Protocol:');
            $output->writeln('  Version: '.($workerProtocol['version'] ?? 'unknown'));

            $serverCapabilities = $workerProtocol['server_capabilities'] ?? null;

            if (is_array($serverCapabilities)) {
                $output->writeln('  Long Poll Timeout: '.($serverCapabilities['long_poll_timeout'] ?? 'unknown'));

                $commands = $serverCapabilities['supported_workflow_task_commands'] ?? [];
                if (is_array($commands) && $commands !== []) {
                    $output->writeln('  Workflow Task Commands: '.implode(', ', $commands));
                }

                if (array_key_exists('workflow_task_poll_request_idempotency', $serverCapabilities)) {
                    $enabled = $serverCapabilities['workflow_task_poll_request_idempotency'] === true ? 'yes' : 'no';
                    $output->writeln('  Workflow Task Poll Idempotency: '.$enabled);
                }

                if (array_key_exists('history_page_size_default', $serverCapabilities)) {
                    $output->writeln('  History Page Size (default): '.($serverCapabilities['history_page_size_default'] ?? 'unknown'));
                }

                if (array_key_exists('history_page_size_max', $serverCapabilities)) {
                    $output->writeln('  History Page Size (max): '.($serverCapabilities['history_page_size_max'] ?? 'unknown'));
                }
            }
        }

        return self::SUCCESS;
    }

    private function renderRequestContract(
        OutputInterface $output,
        ControlPlaneRequestContract $requestContract,
    ): void
    {
        $output->writeln(sprintf(
            '  Request Contract: %s v%d',
            $requestContract->schema(),
            $requestContract->version(),
        ));

        foreach ($requestContract->operations() as $operation => $operationDefinition) {
            if (! is_string($operation) || ! is_array($operationDefinition)) {
                continue;
            }

            $fields = $operationDefinition['fields'] ?? null;

            if (is_array($fields)) {
                foreach ($fields as $field => $fieldDefinition) {
                    if (! is_string($field) || ! is_array($fieldDefinition)) {
                        continue;
                    }

                    $canonicalValues = $fieldDefinition['canonical_values'] ?? null;

                    if (is_array($canonicalValues) && $canonicalValues !== []) {
                        $output->writeln(sprintf(
                            '  %s %s: %s',
                            ucfirst($operation),
                            $field,
                            implode(', ', $canonicalValues),
                        ));
                    }

                    $rejectedAliases = $fieldDefinition['rejected_aliases'] ?? null;

                    if (is_array($rejectedAliases) && $rejectedAliases !== []) {
                        $aliases = [];

                        foreach ($rejectedAliases as $alias => $canonical) {
                            if (! is_string($alias) || ! is_string($canonical)) {
                                continue;
                            }

                            $aliases[] = sprintf('%s -> %s', $alias, $canonical);
                        }

                        if ($aliases !== []) {
                            $output->writeln(sprintf(
                                '  %s rejected %s aliases: %s',
                                ucfirst($operation),
                                $field,
                                implode(', ', $aliases),
                            ));
                        }
                    }
                }
            }

            $removedFields = $operationDefinition['removed_fields'] ?? null;

            if (is_array($removedFields) && $removedFields !== []) {
                $fields = [];

                foreach ($removedFields as $field => $guidance) {
                    if (! is_string($field) || trim($field) === '') {
                        continue;
                    }

                    $fields[] = is_string($guidance) && trim($guidance) !== ''
                        ? sprintf('%s (%s)', $field, $guidance)
                        : $field;
                }

                if ($fields !== []) {
                    $output->writeln(sprintf(
                        '  Removed %s fields: %s',
                        $operation,
                        implode(', ', $fields),
                    ));
                }
            }
        }
    }
}
