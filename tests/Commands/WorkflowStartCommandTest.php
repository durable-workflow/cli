<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowCommand\StartCommand;
use DurableWorkflow\Cli\Support\ControlPlaneRequestContract;
use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\ResolvedConnection;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Coverage for the dispatch-shaping options on `dw workflow:start`
 * (`--priority`, `--fairness-key`, `--fairness-weight`).
 *
 * These options shape how the server orders and rebalances pending
 * starts under contention. The command must:
 *   - forward each option as the typed JSON field the server expects
 *     (`priority` int, `fairness_key` string, `fairness_weight` int)
 *   - omit any unset option from the request body so older servers
 *     that don't yet model these fields keep working
 *   - preserve `priority: 0` as a valid value (lowest dispatch
 *     priority, distinct from "field absent")
 *   - drop a blank fairness key rather than send an empty string
 *     that would conflict with the 1..64-char server contract
 */
final class WorkflowStartCommandTest extends TestCase
{
    private const MANAGED_RUNTIME_URL = 'https://cloud.example.test/api/runtime/v1/namespaces/11111111-1111-4111-8111-111111111111';

    public function test_namespace_scoped_runtime_url_is_preserved_for_discovery_and_workflow_start(): void
    {
        $requests = [];
        $http = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = [$method, $url];

                self::assertContains('Authorization: Bearer client-runtime-token', $options['headers']);
                self::assertContains(
                    'X-Durable-Workflow-Control-Plane-Version: '.ServerClient::CONTROL_PLANE_VERSION,
                    $options['headers'],
                );
                self::assertContains(
                    'X-Durable-Workflow-Protocol-Version: '.ServerClient::WORKER_PROTOCOL_VERSION,
                    $options['headers'],
                );

                if (str_ends_with($url, '/api/cluster/info')) {
                    return new MockResponse(json_encode(self::compatibleClusterInfo(), JSON_THROW_ON_ERROR));
                }

                if (str_ends_with($url, '/api/workflows')) {
                    return new MockResponse(json_encode(self::workflowStarted(), JSON_THROW_ON_ERROR), [
                        'http_code' => 201,
                        'response_headers' => [
                            ServerClient::CONTROL_PLANE_HEADER.': '.ServerClient::CONTROL_PLANE_VERSION,
                        ],
                    ]);
                }

                self::fail('Unexpected managed runtime request: '.$method.' '.$url);
            },
            self::MANAGED_RUNTIME_URL,
        );

        $tester = new CommandTester(new NamespaceScopedWorkflowStartCommand($http));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--server' => self::MANAGED_RUNTIME_URL,
            '--namespace' => 'managed.customer',
            '--token' => 'client-runtime-token',
            '--type' => 'orders.process',
            '--json' => true,
        ]), $tester->getDisplay());
        self::assertSame([
            ['GET', self::MANAGED_RUNTIME_URL.'/api/cluster/info'],
            ['POST', self::MANAGED_RUNTIME_URL.'/api/workflows'],
        ], $requests);
    }

    public function test_worker_runtime_credential_failure_names_the_required_client_credential(): void
    {
        $http = new MockHttpClient(
            static function (string $method, string $url): MockResponse {
                if (str_ends_with($url, '/api/cluster/info')) {
                    return new MockResponse(json_encode(self::compatibleClusterInfo(), JSON_THROW_ON_ERROR));
                }

                return new MockResponse(json_encode([
                    'reason' => 'forbidden',
                    'message' => 'This runtime credential cannot access the requested operation.',
                ], JSON_THROW_ON_ERROR), [
                    'http_code' => 403,
                ]);
            },
            self::MANAGED_RUNTIME_URL,
        );

        $tester = new CommandTester(new NamespaceScopedWorkflowStartCommand($http));

        self::assertSame(ExitCode::AUTH, $tester->execute([
            '--server' => self::MANAGED_RUNTIME_URL,
            '--token' => 'worker-runtime-token',
            '--type' => 'orders.process',
        ]));
        self::assertStringContainsString('client runtime credential', $tester->getDisplay());
        self::assertStringContainsString('worker runtime credentials', $tester->getDisplay());
        self::assertStringNotContainsString('invalid control-plane response version', $tester->getDisplay());
    }

    public function test_priority_is_forwarded_as_integer(): void
    {
        $client = $this->newClient();
        $tester = $this->newTester($client);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--type' => 'orders.process',
            '--priority' => '3',
        ]));

        self::assertSame('POST', $client->lastMethod);
        self::assertSame('/workflows', $client->lastPath);
        self::assertArrayHasKey('priority', $client->lastBody);
        self::assertSame(3, $client->lastBody['priority']);
    }

    public function test_priority_zero_is_preserved_in_body(): void
    {
        $client = $this->newClient();
        $tester = $this->newTester($client);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--type' => 'orders.process',
            '--priority' => '0',
        ]));

        self::assertArrayHasKey('priority', $client->lastBody);
        self::assertSame(0, $client->lastBody['priority']);
    }

    public function test_fairness_key_is_forwarded_as_string(): void
    {
        $client = $this->newClient();
        $tester = $this->newTester($client);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--type' => 'orders.process',
            '--fairness-key' => 'tenant-acme',
        ]));

        self::assertArrayHasKey('fairness_key', $client->lastBody);
        self::assertSame('tenant-acme', $client->lastBody['fairness_key']);
    }

    public function test_blank_fairness_key_is_dropped_from_body(): void
    {
        $client = $this->newClient();
        $tester = $this->newTester($client);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--type' => 'orders.process',
            '--fairness-key' => '   ',
        ]));

        self::assertArrayNotHasKey(
            'fairness_key',
            $client->lastBody,
            'Whitespace-only --fairness-key must not be sent: it would violate the 1..64 URL-safe-char server contract.',
        );
    }

    public function test_fairness_weight_is_forwarded_as_integer(): void
    {
        $client = $this->newClient();
        $tester = $this->newTester($client);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--type' => 'orders.process',
            '--fairness-weight' => '250',
        ]));

        self::assertArrayHasKey('fairness_weight', $client->lastBody);
        self::assertSame(250, $client->lastBody['fairness_weight']);
    }

    public function test_priority_and_fairness_can_be_set_together(): void
    {
        $client = $this->newClient();
        $tester = $this->newTester($client);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--type' => 'orders.process',
            '--priority' => '1',
            '--fairness-key' => 'tier-gold',
            '--fairness-weight' => '750',
        ]));

        self::assertSame(1, $client->lastBody['priority']);
        self::assertSame('tier-gold', $client->lastBody['fairness_key']);
        self::assertSame(750, $client->lastBody['fairness_weight']);
    }

    public function test_priority_and_fairness_fields_are_omitted_when_unset(): void
    {
        $client = $this->newClient();
        $tester = $this->newTester($client);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--type' => 'orders.process',
        ]));

        self::assertArrayNotHasKey('priority', $client->lastBody);
        self::assertArrayNotHasKey('fairness_key', $client->lastBody);
        self::assertArrayNotHasKey('fairness_weight', $client->lastBody);
    }

    public function test_command_exposes_dispatch_options_in_definition(): void
    {
        $command = new StartCommand();
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('priority'));
        self::assertTrue($definition->hasOption('fairness-key'));
        self::assertTrue($definition->hasOption('fairness-weight'));

        self::assertTrue(
            $definition->getOption('priority')->isValueRequired(),
            '--priority must require a value (it is meaningful only when explicitly set).',
        );
        self::assertTrue(
            $definition->getOption('fairness-key')->isValueRequired(),
            '--fairness-key must require a value.',
        );
        self::assertTrue(
            $definition->getOption('fairness-weight')->isValueRequired(),
            '--fairness-weight must require a value.',
        );
    }

    private function newClient(): WorkflowStartFakeServerClient
    {
        return new WorkflowStartFakeServerClient();
    }

    private function newTester(WorkflowStartFakeServerClient $client): CommandTester
    {
        $command = new StartCommand();
        $command->setServerClient($client);

        return new CommandTester($command);
    }

    /**
     * @return array<string, mixed>
     */
    private static function compatibleClusterInfo(): array
    {
        return [
            'version' => '2.0.0-rc.12',
            'control_plane' => [
                'version' => ServerClient::CONTROL_PLANE_VERSION,
                'request_contract' => [
                    'schema' => ControlPlaneRequestContract::SCHEMA,
                    'version' => ControlPlaneRequestContract::VERSION,
                    'operations' => [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function workflowStarted(): array
    {
        return [
            'workflow_id' => 'wf-managed',
            'run_id' => 'run-managed',
            'workflow_type' => 'orders.process',
            'outcome' => 'started_new',
            'control_plane' => [
                'schema' => 'durable-workflow.v2.control-plane-response',
                'version' => 1,
                'operation' => 'start',
                'workflow_id' => 'wf-managed',
                'run_id' => 'run-managed',
                'outcome' => 'started_new',
                'contract' => [
                    'schema' => 'durable-workflow.v2.control-plane-response.contract',
                    'version' => 1,
                    'legacy_field_policy' => 'reject_non_canonical',
                    'legacy_fields' => [],
                    'required_fields' => ['workflow_id', 'run_id'],
                    'success_fields' => ['outcome'],
                ],
            ],
        ];
    }
}

final class NamespaceScopedWorkflowStartCommand extends StartCommand
{
    public function __construct(private readonly HttpClientInterface $http)
    {
        parent::__construct();
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
}

final class WorkflowStartFakeServerClient extends ServerClient
{
    public ?string $lastMethod = null;

    public ?string $lastPath = null;

    /**
     * @var array<string, mixed>
     */
    public array $lastBody = [];

    public function __construct() {}

    public function post(string $path, array $body = []): array
    {
        $this->lastMethod = 'POST';
        $this->lastPath = $path;
        $this->lastBody = $body;

        return [
            'workflow_id' => $body['workflow_id'] ?? 'wf-test',
            'run_id' => 'run-test',
            'workflow_type' => $body['workflow_type'] ?? 'orders.process',
            'outcome' => 'started_new',
        ];
    }
}
