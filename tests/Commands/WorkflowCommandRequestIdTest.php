<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowCommand\SignalCommand;
use DurableWorkflow\Cli\Commands\WorkflowCommand\UpdateCommand;
use DurableWorkflow\Cli\Support\ControlPlaneRequestContract;
use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\NetworkException;
use DurableWorkflow\Cli\Support\ServerClient;
use DurableWorkflow\Cli\Support\ServerHttpException;
use DurableWorkflow\Cli\Support\TimeoutException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Shared issue #216: every `workflow:signal` and `workflow:update` request
 * carries a durable command key in the `request_id` body field. The CLI
 * generates one per invocation when `--request-id` is omitted, prints the
 * key it used on every path (success, conflict, transport failure) so an
 * operator can retry with it, and never sends the key as an HTTP header.
 */
final class WorkflowCommandRequestIdTest extends TestCase
{
    private const SIGNAL_KEY_PATTERN = '/\Acli-signal-[0-9a-f]{32}\z/';

    private const UPDATE_KEY_PATTERN = '/\Acli-update-[0-9a-f]{32}\z/';

    public function test_signal_generates_one_cli_signal_key_per_invocation_when_request_id_is_omitted(): void
    {
        $client = new CommandRequestIdFakeClient(self::signalReceipt());

        $tester = $this->runSignal($client, [
            'workflow-id' => 'wf-1',
            'signal-name' => 'approve',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(1, $client->postCalls);

        $key = $client->lastPostBody['request_id'] ?? null;
        self::assertIsString($key);
        self::assertMatchesRegularExpression(self::SIGNAL_KEY_PATTERN, $key);
        self::assertSame(['request_id'], array_keys($client->lastPostBody));
        self::assertStringContainsString('Request ID: '.$key, $tester->getDisplay());

        $second = new CommandRequestIdFakeClient(self::signalReceipt());
        $this->runSignal($second, [
            'workflow-id' => 'wf-1',
            'signal-name' => 'approve',
        ]);

        self::assertMatchesRegularExpression(self::SIGNAL_KEY_PATTERN, $second->lastPostBody['request_id']);
        self::assertNotSame($key, $second->lastPostBody['request_id'], 'Each invocation must get its own key.');
    }

    public function test_update_generates_one_cli_update_key_when_request_id_is_omitted(): void
    {
        $client = new CommandRequestIdFakeClient(self::updateReceipt(), contract: self::requestContract());

        $tester = $this->runUpdate($client, [
            'workflow-id' => 'wf-1',
            'update-name' => 'approve',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $key = $client->lastPostBody['request_id'] ?? null;
        self::assertIsString($key);
        self::assertMatchesRegularExpression(self::UPDATE_KEY_PATTERN, $key);
        self::assertSame(['wait_for' => 'accepted', 'request_id' => $key], $client->lastPostBody);

        $decoded = $this->json($tester);

        self::assertSame($key, $decoded['request_id']);
        self::assertSame($key, $decoded['request']['request_id']);
        self::assertSame($key, $decoded['update_diagnostics']['request_id']);
        self::assertSame($key, $decoded['cli_fields']['request_id']);
    }

    public function test_signal_sends_an_explicit_request_id_verbatim_and_renders_receipt_flags(): void
    {
        $key = 'Approve-Order.42:Attempt/1';
        $client = new CommandRequestIdFakeClient(self::signalReceipt([
            'request_id' => $key,
            'retained' => true,
            'intent_verified' => true,
        ]));

        $tester = $this->runSignal($client, [
            'workflow-id' => 'wf-1',
            'signal-name' => 'approve',
            '--request-id' => $key,
            '--input' => '[{"approved":true}]',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([
            'request_id' => $key,
            'input' => [['approved' => true]],
        ], $client->lastPostBody);

        $display = $tester->getDisplay();

        self::assertStringContainsString('Signal sent', $display);
        self::assertStringContainsString('Request ID: '.$key, $display);
        self::assertStringContainsString('Retained: yes', $display);
        self::assertStringContainsString('Intent Verified: yes', $display);
    }

    public function test_update_sends_an_explicit_request_id_verbatim(): void
    {
        $key = 'Quota-Adjust.7:Attempt/2';
        $client = new CommandRequestIdFakeClient(self::updateReceipt(), contract: self::requestContract());

        $tester = $this->runUpdate($client, [
            'workflow-id' => 'wf-1',
            'update-name' => 'approve',
            '--wait' => 'completed',
            '--request-id' => $key,
            '--input' => '[{"by":10}]',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([
            'wait_for' => 'completed',
            'request_id' => $key,
            'input' => [['by' => 10]],
        ], $client->lastPostBody);
        self::assertStringContainsString('Request ID: '.$key, $tester->getDisplay());
    }

    public function test_signal_json_output_includes_request_id_and_passes_through_retained_and_intent_verified(): void
    {
        $client = new CommandRequestIdFakeClient(self::signalReceipt([
            'request_id' => 'retry-1',
            'retained' => false,
            'intent_verified' => true,
        ]));

        $tester = $this->runSignal($client, [
            'workflow-id' => 'wf-1',
            'signal-name' => 'approve',
            '--request-id' => 'retry-1',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $decoded = $this->json($tester);

        self::assertSame('retry-1', $decoded['request_id']);
        self::assertFalse($decoded['retained']);
        self::assertTrue($decoded['intent_verified']);
        self::assertSame('default', $decoded['namespace']);
        self::assertSame('signal_received', $decoded['outcome']);
    }

    public function test_signal_json_output_includes_the_generated_key_when_an_older_server_omits_it(): void
    {
        $client = new CommandRequestIdFakeClient(self::signalReceipt());

        $tester = $this->runSignal($client, [
            'workflow-id' => 'wf-1',
            'signal-name' => 'approve',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $decoded = $this->json($tester);

        self::assertSame($client->lastPostBody['request_id'], $decoded['request_id']);
        self::assertMatchesRegularExpression(self::SIGNAL_KEY_PATTERN, $decoded['request_id']);
        self::assertArrayNotHasKey('retained', $decoded, 'The CLI must not fabricate receipt flags an older server did not report.');
        self::assertArrayNotHasKey('intent_verified', $decoded);
    }

    public function test_update_output_passes_through_retained_and_intent_verified(): void
    {
        $receipt = self::updateReceipt([
            'request_id' => 'quota-7',
            'retained' => true,
            'intent_verified' => true,
        ]);

        $human = $this->runUpdate(
            new CommandRequestIdFakeClient($receipt, contract: self::requestContract()),
            ['workflow-id' => 'wf-1', 'update-name' => 'approve', '--request-id' => 'quota-7'],
        );

        self::assertSame(Command::SUCCESS, $human->getStatusCode());
        self::assertStringContainsString('Request ID: quota-7', $human->getDisplay());
        self::assertStringContainsString('Retained: yes', $human->getDisplay());
        self::assertStringContainsString('Intent Verified: yes', $human->getDisplay());

        $json = $this->runUpdate(
            new CommandRequestIdFakeClient($receipt, contract: self::requestContract()),
            ['workflow-id' => 'wf-1', 'update-name' => 'approve', '--request-id' => 'quota-7', '--json' => true],
        );

        self::assertSame(Command::SUCCESS, $json->getStatusCode());

        $decoded = $this->json($json);

        self::assertSame('quota-7', $decoded['request_id']);
        self::assertTrue($decoded['retained']);
        self::assertTrue($decoded['intent_verified']);
        self::assertTrue($decoded['update_diagnostics']['retained']);
        self::assertTrue($decoded['update_diagnostics']['intent_verified']);
        self::assertContains('workflow:update.retained', $decoded['cli_fields']['fields_present']);
        self::assertContains('workflow:update.intent_verified', $decoded['cli_fields']['fields_present']);
    }

    public function test_signal_request_id_conflict_names_the_key_and_the_retained_command_in_human_output(): void
    {
        $body = self::conflictBody('signal', 'approve-1');
        $client = new CommandRequestIdFakeClient([], exception: new ServerHttpException(
            'Server error: Request id already identifies a different signal.',
            409,
            body: $body,
        ));

        $tester = $this->runSignal($client, [
            'workflow-id' => 'wf-1',
            'signal-name' => 'reject',
            '--request-id' => 'approve-1',
            '--input' => '[{"approved":false}]',
        ]);

        self::assertSame(ExitCode::INVALID, $tester->getStatusCode());
        self::assertNotSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('approve-1', $client->lastPostBody['request_id']);

        $display = $tester->getDisplay();

        self::assertStringContainsString('Request id already identifies a different signal.', $display);
        self::assertStringContainsString('Request ID: approve-1', $display);
        self::assertStringContainsString('Reason: request_id_conflict', $display);
        self::assertStringContainsString('Retained command: id cmd-retained-1, type signal, outcome signal_received', $display);
        self::assertStringContainsString('Conflicting parts: name, arguments', $display);
        self::assertStringContainsString('Next steps:', $display);
        self::assertStringContainsString('Request id [approve-1] already identifies a signal command with a different name/arguments.', $display);
        self::assertStringContainsString('pass a new --request-id', $display);
    }

    public function test_signal_request_id_conflict_json_error_is_structured(): void
    {
        $body = self::conflictBody('signal', 'approve-1');
        $client = new CommandRequestIdFakeClient([], exception: new ServerHttpException(
            'Server error: Request id already identifies a different signal.',
            409,
            body: $body,
        ));

        $tester = $this->runSignal($client, [
            'workflow-id' => 'wf-1',
            'signal-name' => 'reject',
            '--request-id' => 'approve-1',
            '--json' => true,
        ]);

        self::assertSame(ExitCode::INVALID, $tester->getStatusCode());

        $decoded = $this->json($tester);

        self::assertSame('Server error: Request id already identifies a different signal.', $decoded['error']);
        self::assertSame(ExitCode::INVALID, $decoded['exit_code']);
        self::assertSame('workflow:signal', $decoded['command']);
        self::assertSame(409, $decoded['status_code']);
        self::assertSame('request_id_conflict', $decoded['reason']);
        self::assertSame('approve-1', $decoded['request_id']);
        self::assertSame('cmd-retained-1', $decoded['retained_command_id']);
        self::assertSame('signal', $decoded['retained_command_type']);
        self::assertSame('signal_received', $decoded['retained_outcome']);
        self::assertSame(['name', 'arguments'], $decoded['conflict']);
        self::assertSame('wf-1', $decoded['workflow_id']);
        self::assertSame('reject', $decoded['signal_name']);
        self::assertSame($body, $decoded['server_response']);
        self::assertSame(['request_id.conflict'], array_column($decoded['recommendations'], 'id'));
    }

    public function test_update_request_id_conflict_json_error_is_structured(): void
    {
        $body = self::conflictBody('update', 'quota-7', ['arguments', 'target']);
        $client = new CommandRequestIdFakeClient([], exception: new ServerHttpException(
            'Server error: Request id already identifies a different update.',
            409,
            body: $body,
        ), contract: self::requestContract());

        $tester = $this->runUpdate($client, [
            'workflow-id' => 'wf-1',
            'update-name' => 'approve',
            '--run-id' => 'run-2',
            '--request-id' => 'quota-7',
            '--input' => '[{"by":20}]',
            '--json' => true,
        ]);

        self::assertSame(ExitCode::INVALID, $tester->getStatusCode());
        self::assertSame('/workflows/wf-1/runs/run-2/update/approve', $client->lastPostPath);

        $decoded = $this->json($tester);

        self::assertSame(409, $decoded['status_code']);
        self::assertSame('request_id_conflict', $decoded['reason']);
        self::assertSame('quota-7', $decoded['request_id']);
        self::assertSame('cmd-retained-1', $decoded['retained_command_id']);
        self::assertSame('update', $decoded['retained_command_type']);
        self::assertSame('update_completed', $decoded['retained_outcome']);
        self::assertSame(['arguments', 'target'], $decoded['conflict']);
        self::assertSame('wf-1', $decoded['workflow_id']);
        self::assertSame('run-2', $decoded['run_id']);
        self::assertSame('approve', $decoded['update_name']);
        self::assertSame('refused', $decoded['state']);
        self::assertSame('update_refused', $decoded['outcome']);
        self::assertSame('quota-7', $decoded['request']['request_id']);
        self::assertSame([['by' => 20]], $decoded['request']['input']);
        self::assertSame(409, $decoded['update_diagnostics']['error']['status_code']);
        self::assertSame('request_id_conflict', $decoded['update_diagnostics']['error']['reason']);
        self::assertSame('quota-7', $decoded['cli_fields']['request_id']);
        self::assertSame($body, $decoded['server_response']);
        self::assertSame(['request_id.conflict'], array_column($decoded['recommendations'], 'id'));
    }

    public function test_signal_transport_failure_prints_the_key_that_was_sent(): void
    {
        $client = new CommandRequestIdFakeClient([], exception: new NetworkException('Server unreachable: connection reset'));

        $tester = $this->runSignal($client, [
            'workflow-id' => 'wf-1',
            'signal-name' => 'approve',
        ]);

        self::assertSame(ExitCode::NETWORK, $tester->getStatusCode());

        $key = $client->lastPostBody['request_id'] ?? null;
        self::assertIsString($key);
        self::assertMatchesRegularExpression(self::SIGNAL_KEY_PATTERN, $key);

        $display = $tester->getDisplay();

        self::assertStringContainsString('Server unreachable: connection reset', $display);
        self::assertStringContainsString('Request ID: '.$key, $display);
        self::assertStringContainsString('--request-id='.$key, $display);
    }

    public function test_update_timeout_json_error_includes_the_key_that_was_sent(): void
    {
        $client = new CommandRequestIdFakeClient(
            [],
            exception: new TimeoutException('Request timed out: idle timeout reached'),
            contract: self::requestContract(),
        );

        $tester = $this->runUpdate($client, [
            'workflow-id' => 'wf-1',
            'update-name' => 'approve',
            '--wait' => 'completed',
            '--json' => true,
        ]);

        self::assertSame(ExitCode::TIMEOUT, $tester->getStatusCode());

        $key = $client->lastPostBody['request_id'] ?? null;
        self::assertIsString($key);
        self::assertMatchesRegularExpression(self::UPDATE_KEY_PATTERN, $key);

        $decoded = $this->json($tester);

        self::assertSame(ExitCode::TIMEOUT, $decoded['exit_code']);
        self::assertSame($key, $decoded['request_id']);
        self::assertSame($key, $decoded['request']['request_id']);
        self::assertSame($key, $decoded['cli_fields']['request_id']);

        $retry = array_values(array_filter(
            $decoded['recommendations'],
            static fn (array $recommendation): bool => $recommendation['id'] === 'request_id.retry',
        ));
        self::assertCount(1, $retry);
        self::assertStringContainsString('--request-id='.$key, $retry[0]['message']);
    }

    public function test_update_server_error_json_includes_the_retry_key(): void
    {
        $client = new CommandRequestIdFakeClient(
            [],
            exception: new ServerHttpException('Server error: HTTP 502', 502, body: null),
            contract: self::requestContract(),
        );

        $tester = $this->runUpdate($client, [
            'workflow-id' => 'wf-1',
            'update-name' => 'approve',
            '--request-id' => 'quota-7',
            '--json' => true,
        ]);

        self::assertSame(ExitCode::SERVER, $tester->getStatusCode());

        $decoded = $this->json($tester);

        self::assertSame('quota-7', $decoded['request_id']);
        self::assertContains('request_id.retry', array_column($decoded['recommendations'], 'id'));
    }

    public function test_request_id_longer_than_255_bytes_is_rejected_before_sending(): void
    {
        $client = new CommandRequestIdFakeClient(self::signalReceipt());

        $tester = $this->runSignal($client, [
            'workflow-id' => 'wf-1',
            'signal-name' => 'approve',
            '--request-id' => str_repeat('k', 256),
        ]);

        self::assertSame(ExitCode::INVALID, $tester->getStatusCode());
        self::assertSame(0, $client->postCalls);
        self::assertStringContainsString('--request-id must be 1 to 255 bytes; got 256 bytes.', $tester->getDisplay());

        $longest = str_repeat('k', 255);
        $tester = $this->runSignal($client, [
            'workflow-id' => 'wf-1',
            'signal-name' => 'approve',
            '--request-id' => $longest,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame($longest, $client->lastPostBody['request_id']);
    }

    public function test_the_published_server_accepts_255_unicode_characters_as_one_key(): void
    {
        $key = str_repeat('é', 255);
        $client = new CommandRequestIdFakeClient(self::signalReceipt());
        $tester = $this->runSignal($client, [
            'workflow-id' => 'wf-1', 'signal-name' => 'approve', '--request-id' => $key,
        ]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame($key, $client->lastPostBody['request_id']);
    }

    public function test_blank_request_id_is_treated_as_omitted(): void
    {
        $client = new CommandRequestIdFakeClient(self::signalReceipt());

        $tester = $this->runSignal($client, [
            'workflow-id' => 'wf-1',
            'signal-name' => 'approve',
            '--request-id' => '   ',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertMatchesRegularExpression(self::SIGNAL_KEY_PATTERN, $client->lastPostBody['request_id']);
    }

    public function test_signal_and_update_send_the_key_in_the_body_and_never_as_an_x_request_id_header(): void
    {
        /** @var list<array{url: string, options: array<string, mixed>}> $requests */
        $requests = [];

        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            if (str_ends_with($url, '/api/cluster/info')) {
                return new MockResponse(json_encode([
                    'version' => '0.2.210',
                    'control_plane' => [
                        'version' => ServerClient::CONTROL_PLANE_VERSION,
                        'request_contract' => [
                            'schema' => ControlPlaneRequestContract::SCHEMA,
                            'version' => ControlPlaneRequestContract::VERSION,
                            'operations' => [
                                'update' => [
                                    'fields' => [
                                        'wait_for' => ['canonical_values' => ['accepted', 'completed']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            $requests[] = ['url' => $url, 'options' => $options];

            $operation = str_contains($url, '/update/') ? 'update' : 'signal';

            return new MockResponse(json_encode(self::controlPlaneEnvelope($operation), JSON_THROW_ON_ERROR), [
                'http_code' => 202,
                'response_headers' => [
                    ServerClient::CONTROL_PLANE_HEADER.': '.ServerClient::CONTROL_PLANE_VERSION,
                ],
            ]);
        });

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: $http,
        );

        $signal = $this->runSignal($client, [
            'workflow-id' => 'wf-123',
            'signal-name' => 'advance',
            '--request-id' => 'signal-key-1',
            '--json' => true,
        ]);
        self::assertSame(Command::SUCCESS, $signal->getStatusCode(), $signal->getDisplay());

        $update = $this->runUpdate($client, [
            'workflow-id' => 'wf-123',
            'update-name' => 'advance',
            '--json' => true,
        ]);
        self::assertSame(Command::SUCCESS, $update->getStatusCode(), $update->getDisplay());

        self::assertCount(2, $requests);
        self::assertStringEndsWith('/api/workflows/wf-123/signal/advance', $requests[0]['url']);
        self::assertStringEndsWith('/api/workflows/wf-123/update/advance', $requests[1]['url']);

        $updateKey = $this->json($update)['request_id'];
        self::assertMatchesRegularExpression(self::UPDATE_KEY_PATTERN, $updateKey);

        foreach ([['signal-key-1', $requests[0]], [$updateKey, $requests[1]]] as [$expectedKey, $request]) {
            self::assertSame($expectedKey, self::requestBody($request['options'])['request_id'] ?? null);
            self::assertNull(
                self::headerValue($request['options'], 'X-Request-Id'),
                'The command key travels only in the request body; X-Request-Id stays correlation-only.',
            );
            self::assertSame('default', self::headerValue($request['options'], 'X-Namespace'));
        }

        self::assertSame('signal-key-1', $this->json($signal)['request_id']);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runSignal(ServerClient $client, array $arguments): CommandTester
    {
        $command = new SignalCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);
        $tester->execute($arguments);

        return $tester;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runUpdate(ServerClient $client, array $arguments): CommandTester
    {
        $command = new UpdateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);
        $tester->execute($arguments);

        return $tester;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(CommandTester $tester): array
    {
        $decoded = json_decode(trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function signalReceipt(array $overrides = []): array
    {
        return array_replace([
            'workflow_id' => 'wf-1',
            'signal_name' => 'approve',
            'outcome' => 'signal_received',
            'command_status' => 'accepted',
            'command_id' => 'cmd-1',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function updateReceipt(array $overrides = []): array
    {
        return array_replace([
            'workflow_id' => 'wf-1',
            'update_name' => 'approve',
            'update_id' => 'upd-1',
            'outcome' => 'update_accepted',
            'command_status' => 'accepted',
            'update_status' => 'accepted',
            'wait_for' => 'accepted',
        ], $overrides);
    }

    /**
     * @param list<string> $conflict
     * @return array<string, mixed>
     */
    private static function conflictBody(string $commandType, string $requestId, array $conflict = ['name', 'arguments']): array
    {
        return [
            'message' => sprintf('Request id already identifies a different %s.', $commandType),
            'reason' => 'request_id_conflict',
            'request_id' => $requestId,
            'retained_command_id' => 'cmd-retained-1',
            'retained_command_type' => $commandType,
            'retained_outcome' => $commandType === 'signal' ? 'signal_received' : 'update_completed',
            'conflict' => $conflict,
        ];
    }

    private static function requestContract(): ControlPlaneRequestContract
    {
        return new ControlPlaneRequestContract([
            'update' => [
                'fields' => [
                    'wait_for' => [
                        'canonical_values' => ['accepted', 'completed'],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function controlPlaneEnvelope(string $operation): array
    {
        $body = [
            'control_plane' => [
                'schema' => 'durable-workflow.v2.control-plane-response',
                'version' => 1,
                'operation' => $operation,
                'operation_name' => 'advance',
                'operation_name_field' => $operation.'_name',
                'workflow_id' => 'wf-123',
                'outcome' => $operation.'_accepted',
                'contract' => [
                    'schema' => 'durable-workflow.v2.control-plane-response.contract',
                    'version' => 1,
                    'legacy_field_policy' => 'reject_non_canonical',
                    'legacy_fields' => [
                        'query' => 'query_name',
                        'signal' => 'signal_name',
                        'update' => 'update_name',
                        'wait_policy' => 'wait_for',
                    ],
                    'required_fields' => ['workflow_id', 'operation_name', 'operation_name_field'],
                    'success_fields' => ['outcome'],
                ],
            ],
        ];

        if ($operation === 'update') {
            $body['update_id'] = 'upd-123';
            $body['update_status'] = 'accepted';
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function requestBody(array $options): array
    {
        $body = $options['body'] ?? null;

        if (is_string($body)) {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);

            return $decoded;
        }

        $json = $options['json'] ?? null;
        self::assertIsArray($json, 'The mocked request carried neither a JSON body string nor a json option.');

        return $json;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function headerValue(array $options, string $wantedHeader): ?string
    {
        foreach (['headers', 'normalized_headers'] as $optionKey) {
            foreach (($options[$optionKey] ?? []) as $name => $value) {
                if (is_string($name) && strcasecmp($name, $wantedHeader) === 0) {
                    $value = is_array($value) ? ($value[0] ?? null) : $value;

                    return is_string($value) ? trim((string) preg_replace('/^[^:]+:\s*/', '', $value)) : null;
                }

                foreach (is_array($value) ? $value : [$value] as $line) {
                    if (is_string($line) && stripos($line, $wantedHeader.':') === 0) {
                        return trim(substr($line, strlen($wantedHeader) + 1));
                    }
                }
            }
        }

        return null;
    }
}

final class CommandRequestIdFakeClient extends ServerClient
{
    /**
     * @var array<string, mixed>
     */
    public array $lastPostBody = [];

    public string $lastPostPath = '';

    public int $postCalls = 0;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly array $payload,
        private readonly ?\Throwable $exception = null,
        private readonly ?ControlPlaneRequestContract $contract = null,
    ) {
    }

    public function post(string $path, array $body = []): array
    {
        $this->postCalls++;
        $this->lastPostPath = $path;
        $this->lastPostBody = $body;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->payload;
    }

    public function controlPlaneRequestContract(): ?ControlPlaneRequestContract
    {
        return $this->contract;
    }
}
