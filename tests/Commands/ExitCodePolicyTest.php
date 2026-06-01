<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\CompatibilityDiagnostics;
use DurableWorkflow\Cli\Support\CompatibilityException;
use DurableWorkflow\Cli\Support\ControlPlaneRequestContract;
use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\NetworkException;
use DurableWorkflow\Cli\Support\ResolvedConnection;
use DurableWorkflow\Cli\Support\ServerClient;
use DurableWorkflow\Cli\Support\ServerHttpException;
use DurableWorkflow\Cli\Support\TimeoutException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ExitCodePolicyTest extends TestCase
{
    /**
     * @dataProvider httpStatusCases
     */
    public function test_http_status_maps_to_expected_exit_code(int $status, int $expected): void
    {
        self::assertSame($expected, ExitCode::fromHttpStatus($status));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function httpStatusCases(): iterable
    {
        yield '401 unauthorized → AUTH' => [401, ExitCode::AUTH];
        yield '403 forbidden → AUTH' => [403, ExitCode::AUTH];
        yield '404 not found → NOT_FOUND' => [404, ExitCode::NOT_FOUND];
        yield '408 request timeout → TIMEOUT' => [408, ExitCode::TIMEOUT];
        yield '400 bad request → INVALID' => [400, ExitCode::INVALID];
        yield '422 unprocessable → INVALID' => [422, ExitCode::INVALID];
        yield '500 server error → SERVER' => [500, ExitCode::SERVER];
        yield '503 service unavailable → SERVER' => [503, ExitCode::SERVER];
        yield '200 ok → FAILURE (non-error status)' => [200, ExitCode::FAILURE];
    }

    public function test_base_command_translates_network_exception_to_network_exit_code(): void
    {
        $command = new ThrowingBaseCommand(new NetworkException('Connection refused'));
        $tester = new CommandTester($command);

        self::assertSame(ExitCode::NETWORK, $tester->execute([]));
        self::assertStringContainsString('Connection refused', $tester->getDisplay());
        self::assertStringContainsString('Next steps:', $tester->getDisplay());
        self::assertStringContainsString('dw doctor --output=json', $tester->getDisplay());
    }

    public function test_base_command_translates_timeout_exception_to_timeout_exit_code(): void
    {
        $command = new ThrowingBaseCommand(new TimeoutException('deadline exceeded'));
        $tester = new CommandTester($command);

        self::assertSame(ExitCode::TIMEOUT, $tester->execute([]));
        self::assertStringContainsString('deadline exceeded', $tester->getDisplay());
    }

    public function test_base_command_translates_auth_http_status_to_auth_exit_code(): void
    {
        $command = new ThrowingBaseCommand(new ServerHttpException('Unauthorized', 401));
        $tester = new CommandTester($command);

        self::assertSame(ExitCode::AUTH, $tester->execute([]));
        self::assertStringContainsString('Unauthorized', $tester->getDisplay());
        self::assertStringContainsString('Check the selected environment, auth token source, and namespace permissions.', $tester->getDisplay());
    }

    public function test_base_command_translates_not_found_http_status_to_not_found_exit_code(): void
    {
        $command = new ThrowingBaseCommand(new ServerHttpException('Workflow not found', 404));
        $tester = new CommandTester($command);

        self::assertSame(ExitCode::NOT_FOUND, $tester->execute([]));
        self::assertStringContainsString('dw throwing --help', $tester->getDisplay());
    }

    public function test_base_command_translates_server_http_status_to_server_exit_code(): void
    {
        $command = new ThrowingBaseCommand(new ServerHttpException('internal', 502));
        $tester = new CommandTester($command);

        self::assertSame(ExitCode::SERVER, $tester->execute([]));
        self::assertStringContainsString('dw server:health --output=json', $tester->getDisplay());
    }

    public function test_base_command_translates_validation_http_status_to_invalid_exit_code(): void
    {
        $command = new ThrowingBaseCommand(new ServerHttpException('bad input', 422));
        $tester = new CommandTester($command);

        self::assertSame(ExitCode::INVALID, $tester->execute([]));
        self::assertStringContainsString('Inspect the request options', $tester->getDisplay());
    }

    public function test_base_command_translates_unexpected_throwable_to_failure_exit_code(): void
    {
        $command = new ThrowingBaseCommand(new \RuntimeException('boom'));
        $tester = new CommandTester($command);

        self::assertSame(ExitCode::FAILURE, $tester->execute([]));
        self::assertStringContainsString('boom', $tester->getDisplay());
    }

    public function test_base_command_emits_structured_compatibility_error(): void
    {
        $diagnostic = [
            'cli_version' => '0.1.5',
            'server_version' => '0.2.221',
            'compatibility_window' => 'cli >=0.1,<1.0',
            'next_step' => CompatibilityDiagnostics::NEXT_STEP,
            'detail' => 'unsupported control_plane.version [3]; dw 0.1.5 sends control_plane.version 2.',
        ];
        $command = new ThrowingBaseCommand(new CompatibilityException(
            CompatibilityDiagnostics::failureMessage($diagnostic),
            $diagnostic,
        ));
        $tester = new CommandTester($command);

        self::assertSame(ExitCode::COMPATIBILITY, $tester->execute([
            '--output' => 'json',
        ]));

        $envelope = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(ExitCode::COMPATIBILITY, $envelope['exit_code']);
        self::assertSame('0.1.5', $envelope['compatibility']['cli_version'] ?? null);
        self::assertSame('0.2.221', $envelope['compatibility']['server_version'] ?? null);
        self::assertSame('cli >=0.1,<1.0', $envelope['compatibility']['compatibility_window'] ?? null);
        self::assertSame('compatibility.unsupported', $envelope['recommendations'][0]['id'] ?? null);
        self::assertStringContainsString('Next step:', $envelope['error']);
    }

    public function test_base_command_refuses_before_mutation_when_compatibility_preflight_fails(): void
    {
        $previousCliVersion = getenv('DW_CLI_VERSION');
        putenv('DW_CLI_VERSION=0.1.5');

        try {
            $requests = [];
            $http = new MockHttpClient(
                static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                    $requests[] = [$method, $url];

                    if (str_ends_with($url, '/api/cluster/info')) {
                        return new MockResponse(json_encode([
                            'version' => '0.2.221',
                            'control_plane' => [
                                'version' => ServerClient::CONTROL_PLANE_VERSION,
                                'request_contract' => [
                                    'schema' => ControlPlaneRequestContract::SCHEMA,
                                    'version' => ControlPlaneRequestContract::VERSION,
                                    'operations' => [],
                                ],
                            ],
                            'client_compatibility' => [
                                'clients' => [
                                    'cli' => [
                                        'supported_versions' => '>=0.2,<1.0',
                                    ],
                                ],
                            ],
                        ], JSON_THROW_ON_ERROR), [
                            'http_code' => 200,
                        ]);
                    }

                    self::fail('Mutation request should not be sent after compatibility preflight fails.');
                },
                'http://example.test',
            );
            $command = new PreflightMutatingCommand($http);
            $tester = new CommandTester($command);

            self::assertSame(ExitCode::COMPATIBILITY, $tester->execute([
                '--output' => 'json',
            ]));
            self::assertCount(1, $requests);
            self::assertSame('GET', $requests[0][0]);
            self::assertStringEndsWith('/api/cluster/info', $requests[0][1]);

            $envelope = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('0.1.5', $envelope['compatibility']['cli_version'] ?? null);
            self::assertSame('0.2.221', $envelope['compatibility']['server_version'] ?? null);
            self::assertSame(
                'cli >=0.2,<1.0; control-plane version 2',
                $envelope['compatibility']['compatibility_window'] ?? null,
            );
        } finally {
            if (is_string($previousCliVersion)) {
                putenv('DW_CLI_VERSION='.$previousCliVersion);
            } else {
                putenv('DW_CLI_VERSION');
            }
        }
    }

    public function test_exit_codes_remain_distinct(): void
    {
        $values = [
            ExitCode::SUCCESS,
            ExitCode::FAILURE,
            ExitCode::INVALID,
            ExitCode::NETWORK,
            ExitCode::AUTH,
            ExitCode::NOT_FOUND,
            ExitCode::SERVER,
            ExitCode::TIMEOUT,
            ExitCode::COMPATIBILITY,
        ];

        self::assertCount(count($values), array_unique($values), 'exit codes must remain distinct');
    }

    public function test_readme_documents_compatibility_exit_contract(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 2).'/README.md');
        self::assertIsString($readme, 'README.md must be readable.');

        self::assertStringContainsString('| 8 | `COMPATIBILITY` |', $readme);
        self::assertStringContainsString('exits with `COMPATIBILITY` (`8`)', $readme);
        self::assertStringContainsString('"exit_code": 8', $readme);
        self::assertStringContainsString('"cli_version": "0.1.73"', $readme);
        self::assertStringContainsString('"server_version": "0.2.221"', $readme);
        self::assertStringContainsString(
            '"compatibility_window": "cli >=0.1,<1.0; control-plane version 2; worker protocol same-major <= 1.0"',
            $readme,
        );
        self::assertStringContainsString(
            '"next_step": "Upgrade dw, pin dw to a supported release, or connect to a compatible server."',
            $readme,
        );
        self::assertStringContainsString('missing control_plane.request_contract', $readme);
        self::assertStringNotContainsString('Upgrade the server or use a compatible CLI version.', $readme);
    }

    public function test_symfony_canonical_codes_preserved(): void
    {
        self::assertSame(Command::SUCCESS, ExitCode::SUCCESS);
        self::assertSame(Command::FAILURE, ExitCode::FAILURE);
        self::assertSame(Command::INVALID, ExitCode::INVALID);
    }
}

class ThrowingBaseCommand extends BaseCommand
{
    public function __construct(private readonly \Throwable $toThrow)
    {
        parent::__construct('throwing');
    }

    protected function client(InputInterface $input, ?float $timeout = null): ServerClient
    {
        throw new \LogicException('not used');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        throw $this->toThrow;
    }
}

class PreflightMutatingCommand extends BaseCommand
{
    public function __construct(private readonly HttpClientInterface $http)
    {
        parent::__construct('workflow:start');
    }

    protected function makeClient(ResolvedConnection $resolved, ?float $timeout = null): ServerClient
    {
        return new ServerClient(
            baseUrl: $resolved->server,
            token: $resolved->token,
            namespace: $resolved->namespace,
            tlsVerify: $resolved->tlsVerify,
            http: $this->http,
            timeout: $timeout,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->client($input)->post('/workflows', [
            'workflow_type' => 'preflight-test',
        ]);

        return Command::SUCCESS;
    }
}
