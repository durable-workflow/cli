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
            ->setDescription('Display server version, capabilities, and role topology')
            ->setHelp(<<<'HELP'
Print server version, role topology, negotiated control-plane and
worker protocol versions, and the canonical enum values the server
accepts (duplicate policies, wait policies, etc.).

<comment>Examples:</comment>

  <info>dw server:info</info>
  <info>dw server:info --env=prod</info>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client($input)->clusterInfo();

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Durable Workflow Server</info>');
        $output->writeln('  Server ID: '.($result['server_id'] ?? 'unknown'));
        $output->writeln('  Version: '.($result['version'] ?? 'unknown'));
        $output->writeln('  Default Namespace: '.($result['default_namespace'] ?? 'default'));
        $output->writeln('');
        $output->writeln('Capabilities:');

        foreach ($result['capabilities'] ?? [] as $cap => $value) {
            $this->renderCapability($output, (string) $cap, $value, indent: 2);
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

        $limits = $result['limits'] ?? null;

        if (is_array($limits) && $limits !== []) {
            $output->writeln('');
            $output->writeln('Limits:');

            foreach ($limits as $limitName => $limitValue) {
                $label = str_replace('_', ' ', ucfirst((string) $limitName));

                if (is_int($limitValue) && $limitValue >= 1024 && str_contains((string) $limitName, 'bytes')) {
                    $output->writeln(sprintf('  %s: %s (%s)', $label, number_format($limitValue), $this->formatBytes($limitValue)));
                } else {
                    $output->writeln(sprintf('  %s: %s', $label, number_format((int) $limitValue)));
                }
            }
        }

        $clientCompatibility = $result['client_compatibility'] ?? null;

        if (is_array($clientCompatibility) && $clientCompatibility !== []) {
            $output->writeln('');
            $output->writeln('Client Compatibility:');
            $output->writeln('  Authority: '.($clientCompatibility['authority'] ?? 'unknown'));
            $output->writeln('  Top-level Version Role: '.($clientCompatibility['top_level_version_role'] ?? 'unknown'));

            if (array_key_exists('fail_closed', $clientCompatibility)) {
                $output->writeln('  Fail Closed: '.($clientCompatibility['fail_closed'] === true ? 'yes' : 'no'));
            }
        }

        $this->renderTopology($output, $result['topology'] ?? null);
        $this->renderCoordinationHealth($output, $result['coordination_health'] ?? null);

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

                $compression = $serverCapabilities['response_compression'] ?? null;
                if (is_array($compression)) {
                    $output->writeln('  Response Compression: '.($compression !== [] ? implode(', ', $compression) : 'disabled'));
                }

                $historyCompression = $serverCapabilities['history_compression'] ?? null;
                if (is_array($historyCompression)) {
                    $encodings = $historyCompression['supported_encodings'] ?? [];
                    $threshold = $historyCompression['compression_threshold'] ?? null;
                    $output->writeln('  History Compression: '.(is_array($encodings) && $encodings !== [] ? implode(', ', $encodings) : 'disabled'));
                    if ($threshold !== null) {
                        $output->writeln('  History Compression Threshold: '.$threshold.' events');
                    }
                }

                $invocable = $serverCapabilities['invocable_carrier'] ?? null;
                if (is_array($invocable)) {
                    $output->writeln(sprintf(
                        '  Invocable Carrier: %s v%s (%s)',
                        $invocable['schema'] ?? 'unknown',
                        $invocable['version'] ?? 'unknown',
                        $invocable['carrier_type'] ?? 'unknown',
                    ));
                }
            }

            $invocableContract = $workerProtocol['invocable_carrier_contract'] ?? null;
            if (is_array($invocableContract)) {
                $output->writeln(sprintf(
                    '  Invocable Carrier Contract: %s v%s',
                    $invocableContract['schema'] ?? 'unknown',
                    $invocableContract['version'] ?? 'unknown',
                ));
                $output->writeln('  Invocable Carrier Type: '.($invocableContract['carrier_type'] ?? 'unknown'));

                $scope = $invocableContract['scope'] ?? null;
                $taskKinds = is_array($scope) && is_array($scope['task_kinds'] ?? null)
                    ? $scope['task_kinds']
                    : [];
                if ($taskKinds !== []) {
                    $output->writeln('  Invocable Task Kinds: '.implode(', ', $taskKinds));
                }

                $request = $invocableContract['request'] ?? null;
                if (is_array($request)) {
                    $output->writeln('  Invocable Request Content-Type: '.($request['content_type'] ?? 'unknown'));
                }

                $response = $invocableContract['response'] ?? null;
                if (is_array($response)) {
                    $output->writeln('  Invocable Response Content-Type: '.($response['content_type'] ?? 'unknown'));
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * Render a single capability entry.
     *
     * Flat lists render inline (`commas`). Associative capability maps
     * render as nested key/value lines so output stays readable for
     * namespaced capabilities like `payload_codecs_engine_specific.php`.
     */
    private function renderCapability(OutputInterface $output, string $name, mixed $value, int $indent): void
    {
        $pad = str_repeat(' ', $indent);

        if (is_array($value)) {
            if ($value === []) {
                $output->writeln(sprintf('%s%s: <comment>none</comment>', $pad, $name));
                return;
            }

            if (array_is_list($value)) {
                $allScalar = true;
                foreach ($value as $item) {
                    if (! is_string($item) && ! is_int($item) && ! is_float($item) && ! is_bool($item) && $item !== null) {
                        $allScalar = false;
                        break;
                    }
                }

                if ($allScalar) {
                    $output->writeln(sprintf(
                        '%s%s: <info>%s</info>',
                        $pad,
                        $name,
                        implode(', ', array_map(
                            static fn (mixed $item): string => is_bool($item) ? ($item ? 'yes' : 'no') : (string) $item,
                            $value,
                        )),
                    ));
                    return;
                }
            }

            $output->writeln(sprintf('%s%s:', $pad, $name));
            foreach ($value as $childKey => $childValue) {
                $this->renderCapability($output, (string) $childKey, $childValue, $indent + 2);
            }
            return;
        }

        if (is_bool($value)) {
            $output->writeln(sprintf(
                '%s%s: %s',
                $pad,
                $name,
                $value ? '<info>yes</info>' : '<comment>no</comment>',
            ));
            return;
        }

        $output->writeln(sprintf('%s%s: <info>%s</info>', $pad, $name, (string) $value));
    }

    private function renderTopology(OutputInterface $output, mixed $topology): void
    {
        if (! is_array($topology) || $topology === []) {
            return;
        }

        $output->writeln('');
        $output->writeln('Topology:');

        $schema = $this->stringValue($topology['schema'] ?? null) ?? 'unknown';
        $version = $this->stringValue($topology['version'] ?? null) ?? 'unknown';
        $output->writeln(sprintf('  Manifest: %s v%s', $schema, $version));

        $supportedShapes = $this->stringList($topology['supported_shapes'] ?? null);
        if ($supportedShapes !== []) {
            $output->writeln('  Supported Shapes: '.implode(', ', $supportedShapes));
        }

        $currentShape = $this->stringValue($topology['current_shape'] ?? null);
        if ($currentShape !== null) {
            $output->writeln('  Current Shape: '.$currentShape);
        }

        $currentProcessClass = $this->stringValue($topology['current_process_class'] ?? null);
        if ($currentProcessClass !== null) {
            $output->writeln('  Current Process Class: '.$currentProcessClass);
        }

        $currentRoles = $this->stringList($topology['current_roles'] ?? null);
        if ($currentRoles !== []) {
            $output->writeln('  Current Roles: '.implode(', ', $currentRoles));
        }

        $executionMode = $this->stringValue($topology['execution_mode'] ?? null);
        if ($executionMode !== null) {
            $output->writeln('  Execution Mode: '.$executionMode);
        }

        $matchingRole = $topology['matching_role'] ?? null;
        if (is_array($matchingRole)) {
            $shape = $this->stringValue($matchingRole['shape'] ?? null) ?? 'unknown';
            $queueWakeEnabled = $this->formatOptionalBool($matchingRole['queue_wake_enabled'] ?? null);
            $wakeOwner = $this->stringValue($matchingRole['wake_owner'] ?? null) ?? 'unknown';
            $taskDispatchMode = $this->stringValue($matchingRole['task_dispatch_mode'] ?? null) ?? 'unknown';

            $output->writeln(sprintf(
                '  Matching Role: %s (queue_wake_enabled=%s, wake_owner=%s, task_dispatch_mode=%s)',
                $shape,
                $queueWakeEnabled,
                $wakeOwner,
                $taskDispatchMode,
            ));

            $partitionPrimitives = $this->stringList($matchingRole['partition_primitives'] ?? null);
            if ($partitionPrimitives !== []) {
                $output->writeln('  Matching Partitions: '.implode(', ', $partitionPrimitives));
            }

            $backpressureModel = $this->stringValue($matchingRole['backpressure_model'] ?? null);
            if ($backpressureModel !== null) {
                $output->writeln('  Matching Backpressure: '.$backpressureModel);
            }
        }

        $roleCatalog = is_array($topology['role_catalog'] ?? null) ? $topology['role_catalog'] : [];
        $roleCatalogLines = [];

        foreach ($currentRoles as $role) {
            $definition = $roleCatalog[$role] ?? null;

            if (! is_array($definition)) {
                continue;
            }

            $roleCatalogLines[] = sprintf(
                '    %s: plane=%s, external_http=%s, runs_user_code=%s, interface=%s',
                $role,
                $this->stringValue($definition['plane'] ?? null) ?? 'unknown',
                $this->formatOptionalBool($definition['accepts_external_http'] ?? null),
                $this->formatOptionalBool($definition['runs_user_code'] ?? null),
                $this->stringValue($definition['steady_state_interface'] ?? null) ?? 'unknown',
            );
        }

        if ($roleCatalogLines !== []) {
            $output->writeln('  Current Role Traits:');
            foreach ($roleCatalogLines as $line) {
                $output->writeln($line);
            }
        }

        $authorityBoundaries = is_array($topology['authority_boundaries'] ?? null)
            ? $topology['authority_boundaries']
            : [];
        $boundaryLines = [];

        foreach ($currentRoles as $role) {
            $definition = $authorityBoundaries[$role] ?? null;

            if (! is_array($definition)) {
                continue;
            }

            $writes = $this->stringList($definition['writes'] ?? null);

            if ($writes === []) {
                continue;
            }

            $boundaryLines[] = sprintf('    %s: %s', $role, implode(', ', $writes));
        }

        if ($boundaryLines !== []) {
            $output->writeln('  Current Write Boundaries:');
            foreach ($boundaryLines as $line) {
                $output->writeln($line);
            }
        }

        $scalingBoundaries = is_array($topology['scaling_boundaries'] ?? null)
            ? $topology['scaling_boundaries']
            : [];
        $scalingLines = [];

        foreach ($scalingBoundaries as $role => $boundary) {
            if (! is_string($role)) {
                continue;
            }

            $boundaryValue = $this->stringValue($boundary);

            if ($boundaryValue === null) {
                continue;
            }

            $scalingLines[] = sprintf('    %s: %s', $role, $boundaryValue);
        }

        if ($scalingLines !== []) {
            $output->writeln('  Scaling Boundaries:');
            foreach ($scalingLines as $line) {
                $output->writeln($line);
            }
        }

        $failureDomains = is_array($topology['failure_domains'] ?? null)
            ? $topology['failure_domains']
            : [];
        $failureLines = [];

        foreach ($failureDomains as $failure => $definition) {
            if (! is_string($failure) || ! is_array($definition)) {
                continue;
            }

            $signal = $this->stringValue($definition['operator_signal'] ?? null);
            $effect = $this->stringValue($definition['effect'] ?? null);

            if ($signal === null && $effect === null) {
                continue;
            }

            $parts = [];

            if ($signal !== null) {
                $parts[] = 'signal='.$signal;
            }

            if ($effect !== null) {
                $parts[] = 'effect='.$effect;
            }

            $failureLines[] = sprintf('    %s: %s', $failure, implode(', ', $parts));
        }

        if ($failureLines !== []) {
            $output->writeln('  Failure Domains:');
            foreach ($failureLines as $line) {
                $output->writeln($line);
            }
        }
    }

    private function renderCoordinationHealth(OutputInterface $output, mixed $coordinationHealth): void
    {
        if (! is_array($coordinationHealth) || $coordinationHealth === []) {
            return;
        }

        $output->writeln('');
        $output->writeln('Coordination Health:');

        $schema = $this->stringValue($coordinationHealth['schema'] ?? null) ?? 'unknown';
        $version = $this->stringValue($coordinationHealth['version'] ?? null) ?? 'unknown';
        $output->writeln(sprintf('  Manifest: %s v%s', $schema, $version));

        $namespaceScope = $this->stringValue($coordinationHealth['namespace_scope'] ?? null);
        if ($namespaceScope !== null) {
            $output->writeln('  Namespace Scope: '.$namespaceScope);
        }

        $status = $this->stringValue($coordinationHealth['status'] ?? null) ?? 'unknown';
        $httpStatus = $this->stringValue($coordinationHealth['http_status'] ?? null) ?? 'unknown';
        $output->writeln(sprintf('  Status: %s (http %s)', $status, $httpStatus));

        $generatedAt = $this->stringValue($coordinationHealth['generated_at'] ?? null);
        if ($generatedAt !== null) {
            $output->writeln('  Generated At: '.$generatedAt);
        }

        $categories = $this->formatScalarMap($coordinationHealth['categories'] ?? null);
        if ($categories !== null) {
            $output->writeln('  Categories: '.$categories);
        }

        $warningChecks = $this->stringList($coordinationHealth['warning_checks'] ?? null);
        $errorChecks = $this->stringList($coordinationHealth['error_checks'] ?? null);
        $output->writeln('  Warning Checks: '.($warningChecks !== [] ? implode(', ', $warningChecks) : 'none'));
        $output->writeln('  Error Checks: '.($errorChecks !== [] ? implode(', ', $errorChecks) : 'none'));

        $checks = is_array($coordinationHealth['checks'] ?? null)
            ? $coordinationHealth['checks']
            : [];
        $checkLines = [];

        foreach ($checks as $check) {
            if (! is_array($check)) {
                continue;
            }

            $name = $this->stringValue($check['name'] ?? null) ?? 'unknown';
            $status = $this->stringValue($check['status'] ?? null) ?? 'unknown';
            $parts = ['status='.$status];

            $category = $this->stringValue($check['category'] ?? null);
            if ($category !== null) {
                $parts[] = 'category='.$category;
            }

            $message = $this->stringValue($check['message'] ?? null);
            if ($message !== null) {
                $parts[] = 'message='.$message;
            }

            $checkLines[] = sprintf('    %s: %s', $name, implode(', ', $parts));
        }

        if ($checkLines !== []) {
            $output->writeln('  Checks:');
            foreach ($checkLines as $line) {
                $output->writeln($line);
            }
        }
    }

    private function stringValue(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' ? $value : null;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            $string = $this->stringValue($item);

            if ($string !== null) {
                $list[] = $string;
            }
        }

        return $list;
    }

    private function formatOptionalBool(mixed $value): string
    {
        return $value === true ? 'yes' : ($value === false ? 'no' : 'unknown');
    }

    private function formatScalarMap(mixed $value): ?string
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        if (array_is_list($value)) {
            $items = $this->stringList($value);

            return $items !== [] ? implode(', ', $items) : null;
        }

        $items = [];

        foreach ($value as $key => $entry) {
            if (! is_string($key)) {
                continue;
            }

            if (is_bool($entry)) {
                $items[] = sprintf('%s=%s', $key, $entry ? 'yes' : 'no');
                continue;
            }

            $entryValue = $this->stringValue($entry);

            if ($entryValue === null) {
                continue;
            }

            $items[] = sprintf('%s=%s', $key, $entryValue);
        }

        return $items !== [] ? implode(', ', $items) : null;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return sprintf('%.0f MB', $bytes / (1024 * 1024));
        }

        if ($bytes >= 1024) {
            return sprintf('%.0f KB', $bytes / 1024);
        }

        return sprintf('%d B', $bytes);
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
