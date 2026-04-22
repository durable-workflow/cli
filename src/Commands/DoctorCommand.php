<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands;

use DurableWorkflow\Cli\BuildInfo;
use DurableWorkflow\Cli\Support\CompatibilityDiagnostics;
use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\OutputMode;
use DurableWorkflow\Cli\Support\Profile;
use DurableWorkflow\Cli\Support\ResolvedConnection;
use DurableWorkflow\Cli\Support\ServerException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DoctorCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->setName('doctor')
            ->setDescription('Diagnose the resolved dw connection and server compatibility state')
            ->setHelp(<<<'HELP'
Dump the resolved CLI version, server URL, namespace, profile, token source,
TLS verification mode, and server compatibility metadata from /api/cluster/info.

<comment>Examples:</comment>

  <info>dw doctor</info>
  <info>dw doctor --env=prod --output=json</info>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $resolved = $this->resolvedConnection($input);
        $effectiveConfig = $resolved->effectiveConfig($this->profileStore()->path());
        $diagnostic = [
            'dw' => [
                'version' => BuildInfo::version(),
                'commit' => BuildInfo::commit(),
                'build_date' => BuildInfo::buildDate(),
            ],
            'connection' => [
                'server' => $resolved->server,
                'namespace' => $resolved->namespace,
                'profile' => $this->profileDiagnostic($input, $resolved),
                'token' => $this->tokenDiagnostic($input, $resolved),
                'tls' => [
                    'verify' => $resolved->tlsVerify,
                    'source' => $effectiveConfig['tls']['source'] ?? 'default',
                ],
                'effective_config' => $effectiveConfig,
            ],
            'server' => [
                'reachable' => false,
            ],
            'warnings' => [],
            'recommendations' => [],
        ];
        $exitCode = ExitCode::SUCCESS;

        try {
            $clusterInfo = $this->client($input)->get('/cluster/info');
            $diagnostic['server'] = [
                'reachable' => true,
                'version' => $this->scalarString($clusterInfo['version'] ?? null),
                'server_id' => $this->scalarString($clusterInfo['server_id'] ?? null),
                'default_namespace' => $this->scalarString($clusterInfo['default_namespace'] ?? null),
                'control_plane' => $this->controlPlaneDiagnostic($clusterInfo),
                'worker_protocol' => $this->workerProtocolDiagnostic($clusterInfo),
                'client_compatibility' => $clusterInfo['client_compatibility'] ?? null,
                'cluster_info' => $clusterInfo,
            ];

            $diagnostic['warnings'] = CompatibilityDiagnostics::warnings($clusterInfo, BuildInfo::version());
        } catch (ServerException $exception) {
            $diagnostic['server']['error'] = $exception->getMessage();
            $exitCode = $exception->exitCode();
        } catch (\Throwable $exception) {
            $diagnostic['server']['error'] = $exception->getMessage();
            $exitCode = ExitCode::FAILURE;
        }

        $diagnostic['recommendations'] = $this->recommendations($diagnostic, $exitCode);

        if (OutputMode::isMachineReadable($this->outputMode($input))) {
            $this->renderJson($output, $diagnostic);

            return $exitCode;
        }

        $this->renderHuman($output, $diagnostic);

        return $exitCode;
    }

    public function emitsSessionCompatibilityWarning(): bool
    {
        return false;
    }

    /**
     * @return array{name: string|null, source: string|null, config_path: string}
     */
    private function profileDiagnostic(InputInterface $input, ResolvedConnection $resolved): array
    {
        return [
            'name' => $resolved->profile?->name,
            'source' => $this->profileSource($input, $resolved),
            'config_path' => $this->profileStore()->path(),
        ];
    }

    private function profileSource(InputInterface $input, ResolvedConnection $resolved): ?string
    {
        if (! $resolved->profile instanceof Profile) {
            return null;
        }

        if ($this->inputOptionString($input, 'env') !== null) {
            return 'flag';
        }

        if (self::envString('DW_ENV') !== null) {
            return 'DW_ENV';
        }

        return 'current_env';
    }

    /**
     * @return array{present: bool, source: string, env: string|null, profile: string|null}
     */
    private function tokenDiagnostic(InputInterface $input, ResolvedConnection $resolved): array
    {
        $profile = $resolved->profile;
        $tokenSource = $profile?->tokenSource;

        if ($this->inputOptionString($input, 'token') !== null) {
            return [
                'present' => $resolved->token !== null,
                'source' => 'flag',
                'env' => null,
                'profile' => null,
            ];
        }

        if (self::envString('DURABLE_WORKFLOW_AUTH_TOKEN') !== null) {
            return [
                'present' => $resolved->token !== null,
                'source' => 'DURABLE_WORKFLOW_AUTH_TOKEN',
                'env' => 'DURABLE_WORKFLOW_AUTH_TOKEN',
                'profile' => null,
            ];
        }

        if (is_array($tokenSource)) {
            if (($tokenSource['type'] ?? null) === Profile::TOKEN_SOURCE_ENV) {
                return [
                    'present' => $resolved->token !== null,
                    'source' => 'profile_env',
                    'env' => $tokenSource['value'] ?? null,
                    'profile' => $profile?->name,
                ];
            }

            return [
                'present' => $resolved->token !== null,
                'source' => 'profile_literal',
                'env' => null,
                'profile' => $profile?->name,
            ];
        }

        return [
            'present' => false,
            'source' => 'none',
            'env' => null,
            'profile' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     * @return array<string, mixed>|null
     */
    private function controlPlaneDiagnostic(array $clusterInfo): ?array
    {
        $controlPlane = $clusterInfo['control_plane'] ?? null;

        if (! is_array($controlPlane)) {
            return null;
        }

        $requestContract = $controlPlane['request_contract'] ?? null;

        return [
            'version' => $this->scalarString($controlPlane['version'] ?? null),
            'header' => $this->scalarString($controlPlane['header'] ?? null),
            'request_contract' => is_array($requestContract) ? [
                'schema' => $this->scalarString($requestContract['schema'] ?? null),
                'version' => $this->scalarString($requestContract['version'] ?? null),
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $clusterInfo
     * @return array<string, mixed>|null
     */
    private function workerProtocolDiagnostic(array $clusterInfo): ?array
    {
        $workerProtocol = $clusterInfo['worker_protocol'] ?? null;

        if (! is_array($workerProtocol)) {
            return null;
        }

        return [
            'version' => $this->scalarString($workerProtocol['version'] ?? null),
            'server_capabilities' => $workerProtocol['server_capabilities'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $diagnostic
     */
    private function renderHuman(OutputInterface $output, array $diagnostic): void
    {
        $output->writeln('<info>dw doctor</info>');
        $output->writeln(sprintf('  CLI Version: %s', $diagnostic['dw']['version'] ?? 'unknown'));
        $output->writeln(sprintf('  Commit: %s', $diagnostic['dw']['commit'] ?? 'unknown'));
        $output->writeln(sprintf('  Built: %s', $diagnostic['dw']['build_date'] ?? 'unknown'));
        $output->writeln('');

        $connection = $diagnostic['connection'] ?? [];
        $profile = is_array($connection['profile'] ?? null) ? $connection['profile'] : [];
        $token = is_array($connection['token'] ?? null) ? $connection['token'] : [];
        $tls = is_array($connection['tls'] ?? null) ? $connection['tls'] : [];

        $output->writeln('Connection:');
        $output->writeln(sprintf('  Server: %s', $connection['server'] ?? 'unknown'));
        $output->writeln(sprintf('  Namespace: %s', $connection['namespace'] ?? 'unknown'));
        $output->writeln(sprintf(
            '  Profile: %s',
            ($profile['name'] ?? null) !== null
                ? sprintf('%s (%s)', $profile['name'], $profile['source'] ?? 'unknown')
                : 'none',
        ));
        $output->writeln(sprintf(
            '  Token: %s via %s',
            ($token['present'] ?? false) === true ? 'present' : 'not set',
            $token['source'] ?? 'none',
        ));
        $output->writeln(sprintf('  TLS Verify: %s', ($tls['verify'] ?? true) === true ? 'yes' : 'no'));
        $output->writeln('');

        $server = is_array($diagnostic['server'] ?? null) ? $diagnostic['server'] : [];
        $output->writeln('Server:');

        if (($server['reachable'] ?? false) !== true) {
            $output->writeln('  Reachable: no');
            $output->writeln('  Error: '.($server['error'] ?? 'unknown'));
        } else {
            $output->writeln('  Reachable: yes');
            $output->writeln('  Server ID: '.($server['server_id'] ?? 'unknown'));
            $output->writeln('  Version: '.($server['version'] ?? 'unknown'));
            $output->writeln('  Default Namespace: '.($server['default_namespace'] ?? 'unknown'));

            $controlPlane = is_array($server['control_plane'] ?? null) ? $server['control_plane'] : [];
            if ($controlPlane !== []) {
                $output->writeln('  Control Plane: '.($controlPlane['version'] ?? 'unknown'));
            }

            $workerProtocol = is_array($server['worker_protocol'] ?? null) ? $server['worker_protocol'] : [];
            if ($workerProtocol !== []) {
                $output->writeln('  Worker Protocol: '.($workerProtocol['version'] ?? 'unknown'));
            }
        }

        $warnings = $diagnostic['warnings'] ?? [];
        if (is_array($warnings) && $warnings !== []) {
            $output->writeln('');
            $output->writeln('Warnings:');
            foreach ($warnings as $warning) {
                $output->writeln('  '.$warning);
            }
        }

        $recommendations = $diagnostic['recommendations'] ?? [];
        if (is_array($recommendations) && $recommendations !== []) {
            $output->writeln('');
            $output->writeln('Next steps:');
            foreach ($recommendations as $recommendation) {
                if (! is_array($recommendation)) {
                    continue;
                }

                $message = $this->scalarString($recommendation['message'] ?? null);
                if ($message === null) {
                    continue;
                }

                $output->writeln('  - '.$message);

                $command = $this->scalarString($recommendation['command'] ?? null);
                if ($command !== null) {
                    $output->writeln('    Try: '.$command);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $diagnostic
     * @return list<array{id: string, severity: string, message: string, command?: string}>
     */
    private function recommendations(array $diagnostic, int $exitCode): array
    {
        $recommendations = [];
        $connection = is_array($diagnostic['connection'] ?? null) ? $diagnostic['connection'] : [];
        $server = is_array($diagnostic['server'] ?? null) ? $diagnostic['server'] : [];
        $token = is_array($connection['token'] ?? null) ? $connection['token'] : [];
        $tls = is_array($connection['tls'] ?? null) ? $connection['tls'] : [];

        if (($server['reachable'] ?? false) !== true) {
            $recommendations[] = [
                'id' => $exitCode === ExitCode::NETWORK ? 'server.unreachable' : 'server.error',
                'severity' => 'error',
                'message' => $exitCode === ExitCode::NETWORK
                    ? 'Check the server URL, DNS, port, and TLS settings for the selected environment.'
                    : 'Inspect the server error, then retry with --output=json if support needs the full diagnostic payload.',
                'command' => 'dw doctor --server='.($connection['server'] ?? 'http://localhost:8080').' --output=json',
            ];
        }

        if (($token['present'] ?? false) !== true) {
            $recommendations[] = [
                'id' => 'auth.token_missing',
                'severity' => 'warning',
                'message' => 'Set an auth token if the target server requires authenticated operator requests.',
                'command' => 'dw env:set <name> --token-env=DURABLE_WORKFLOW_AUTH_TOKEN',
            ];
        }

        if (($tls['verify'] ?? true) !== true) {
            $recommendations[] = [
                'id' => 'tls.verification_disabled',
                'severity' => 'warning',
                'message' => 'Re-enable TLS verification before using this profile outside a local or disposable test server.',
                'command' => 'dw env:set <name> --tls-verify=true',
            ];
        }

        $warnings = $diagnostic['warnings'] ?? [];
        if (is_array($warnings) && $warnings !== []) {
            $recommendations[] = [
                'id' => 'compatibility.warning',
                'severity' => 'warning',
                'message' => 'Review protocol compatibility before running mutating workflow or worker commands.',
                'command' => 'dw server:info --output=json',
            ];
        }

        if ($recommendations === []) {
            $recommendations[] = [
                'id' => 'doctor.clean',
                'severity' => 'info',
                'message' => 'Connection, auth discovery, TLS settings, and advertised protocol metadata look ready for CLI operations.',
            ];
        }

        return $recommendations;
    }

    private function scalarString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private function inputOptionString(InputInterface $input, string $name): ?string
    {
        if (! $input->hasOption($name)) {
            return null;
        }

        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function envString(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
