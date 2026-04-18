<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Cli\Support\ControlPlaneRequestContract;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ServerClientTest extends TestCase
{
    public function test_it_rejects_non_canonical_control_plane_aliases(): void
    {
        $response = new MockResponse(json_encode([
            'control_plane' => [
                'schema' => 'durable-workflow.v2.control-plane-response',
                'version' => 1,
                'operation' => 'signal',
                'operation_name' => 'advance',
                'operation_name_field' => 'signal_name',
                'workflow_id' => 'wf-123',
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
            'workflow_id' => 'wf-123',
            'signal' => 'advance',
            'wait_policy' => 'accepted',
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 202,
            'response_headers' => [
                'X-Durable-Workflow-Control-Plane-Version: 2',
            ],
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('non-canonical control-plane field [signal]');

        $client->post('/workflows/wf-123/signal/advance');
    }

    public function test_it_reads_the_server_published_request_contract_from_cluster_info(): void
    {
        $response = new MockResponse(json_encode([
            'version' => '3.0.0',
            'control_plane' => [
                'version' => ServerClient::CONTROL_PLANE_VERSION,
                'request_contract' => [
                    'schema' => ControlPlaneRequestContract::SCHEMA,
                    'version' => ControlPlaneRequestContract::VERSION,
                    'operations' => [
                        'start' => [
                            'fields' => [
                                'duplicate_policy' => [
                                    'canonical_values' => ['fail', 'use-existing'],
                                    'rejected_aliases' => [
                                        'use_existing' => 'use-existing',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 200,
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $contract = $client->controlPlaneRequestContract();

        self::assertNotNull($contract);
        self::assertSame(ControlPlaneRequestContract::SCHEMA, $contract->schema());
        self::assertSame(ControlPlaneRequestContract::VERSION, $contract->version());
        self::assertSame(
            ['fail', 'use-existing'],
            $contract->manifest()['start']['fields']['duplicate_policy']['canonical_values'] ?? null,
        );
    }

    public function test_it_uses_protocol_manifest_not_top_level_app_version_for_compatibility(): void
    {
        $response = new MockResponse(json_encode([
            'version' => 'not-semver',
            'control_plane' => [
                'version' => ServerClient::CONTROL_PLANE_VERSION,
                'request_contract' => [
                    'schema' => ControlPlaneRequestContract::SCHEMA,
                    'version' => ControlPlaneRequestContract::VERSION,
                    'operations' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 200,
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        self::assertSame('not-semver', $client->clusterInfo()['version']);
    }

    public function test_it_rejects_cluster_info_without_control_plane_manifest(): void
    {
        $response = new MockResponse(json_encode([
            'version' => '2.0.0',
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 200,
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing control_plane manifest');

        $client->clusterInfo();
    }

    public function test_it_rejects_unsupported_control_plane_version(): void
    {
        $response = new MockResponse(json_encode([
            'version' => '2.0.0',
            'control_plane' => [
                'version' => '3',
                'request_contract' => [
                    'schema' => ControlPlaneRequestContract::SCHEMA,
                    'version' => ControlPlaneRequestContract::VERSION,
                    'operations' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 200,
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsupported control_plane.version [3]');

        $client->clusterInfo();
    }

    public function test_it_uses_the_server_request_contract_to_reject_non_canonical_request_values(): void
    {
        $response = new MockResponse(json_encode([
            'control_plane' => [
                'version' => ServerClient::CONTROL_PLANE_VERSION,
                'request_contract' => [
                    'schema' => ControlPlaneRequestContract::SCHEMA,
                    'version' => ControlPlaneRequestContract::VERSION,
                    'operations' => [
                        'start' => [
                            'fields' => [
                                'duplicate_policy' => [
                                    'canonical_values' => ['fail', 'use-existing'],
                                    'rejected_aliases' => [
                                        'use_existing' => 'use-existing',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 200,
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Server contract rejects --duplicate-policy value [use_existing]; use [use-existing].',
        );

        $client->assertControlPlaneOptionValue('start', 'duplicate_policy', 'use_existing', '--duplicate-policy');
    }

    public function test_it_requires_a_versioned_request_contract_when_validating_request_values(): void
    {
        $response = new MockResponse(json_encode([
            'control_plane' => [
                'version' => ServerClient::CONTROL_PLANE_VERSION,
                'request_contract' => [
                    'start' => [
                        'fields' => [
                            'duplicate_policy' => [
                                'canonical_values' => ['fail', 'use-existing'],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 200,
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Server compatibility error: invalid control_plane.request_contract metadata; expected durable-workflow.v2.control-plane-request.contract v1.',
        );

        $client->assertControlPlaneOptionValue('start', 'duplicate_policy', 'use-existing', '--duplicate-policy');
    }

    public function test_it_normalizes_the_shared_control_plane_contract_for_workflow_responses(): void
    {
        $response = new MockResponse(json_encode([
            'control_plane' => [
                'schema' => 'durable-workflow.v2.control-plane-response',
                'version' => 1,
                'operation' => 'signal',
                'operation_name' => 'advance',
                'operation_name_field' => 'signal_name',
                'workflow_id' => 'wf-123',
                'outcome' => 'signal_received',
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
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 202,
            'response_headers' => [
                'X-Durable-Workflow-Control-Plane-Version: 2',
            ],
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $payload = $client->post('/workflows/wf-123/signal/advance');

        self::assertSame('wf-123', $payload['workflow_id']);
        self::assertSame('advance', $payload['signal_name']);
        self::assertSame('signal_received', $payload['outcome']);
        self::assertSame('signal', $payload['control_plane_operation']);
        self::assertSame('2', $payload['control_plane_version']);
        self::assertSame(1, $payload['control_plane_schema_version']);
    }

    public function test_it_normalizes_the_shared_control_plane_contract_for_workflow_start_responses(): void
    {
        $response = new MockResponse(json_encode([
            'control_plane' => [
                'schema' => 'durable-workflow.v2.control-plane-response',
                'version' => 1,
                'operation' => 'start',
                'workflow_id' => 'wf-123',
                'run_id' => 'run-123',
                'workflow_type' => 'orders.process',
                'outcome' => 'started_new',
                'business_key' => 'order-123',
                'status' => 'pending',
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
                    'required_fields' => [],
                    'success_fields' => ['workflow_id', 'outcome'],
                ],
            ],
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 201,
            'response_headers' => [
                'X-Durable-Workflow-Control-Plane-Version: 2',
            ],
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $payload = $client->post('/workflows', [
            'workflow_type' => 'orders.process',
        ]);

        self::assertSame('wf-123', $payload['workflow_id']);
        self::assertSame('run-123', $payload['run_id']);
        self::assertSame('orders.process', $payload['workflow_type']);
        self::assertSame('started_new', $payload['outcome']);
        self::assertSame('order-123', $payload['business_key']);
        self::assertSame('pending', $payload['status']);
    }

    public function test_it_normalizes_the_shared_control_plane_contract_for_workflow_read_responses(): void
    {
        $response = new MockResponse(json_encode([
            'control_plane' => [
                'schema' => 'durable-workflow.v2.control-plane-response',
                'version' => 1,
                'operation' => 'describe_run',
                'workflow_id' => 'wf-123',
                'run_id' => 'run-123',
                'workflow_type' => 'orders.process',
                'status' => 'running',
                'run_number' => 1,
                'run_count' => 2,
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
                    'required_fields' => ['workflow_id'],
                    'success_fields' => ['run_id'],
                ],
            ],
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => [
                'X-Durable-Workflow-Control-Plane-Version: 2',
            ],
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $payload = $client->get('/workflows/wf-123/runs/run-123');

        self::assertSame('wf-123', $payload['workflow_id']);
        self::assertSame('run-123', $payload['run_id']);
        self::assertSame('orders.process', $payload['workflow_type']);
        self::assertSame('running', $payload['status']);
        self::assertSame(1, $payload['run_number']);
        self::assertSame(2, $payload['run_count']);
    }

    public function test_it_normalizes_the_shared_control_plane_contract_for_workflow_list_responses(): void
    {
        $response = new MockResponse(json_encode([
            'workflows' => [
                [
                    'workflow_id' => 'wf-123',
                    'workflow_type' => 'orders.process',
                    'status' => 'running',
                ],
            ],
            'control_plane' => [
                'schema' => 'durable-workflow.v2.control-plane-response',
                'version' => 1,
                'operation' => 'list',
                'workflow_count' => 1,
                'next_page_token' => 'next-token',
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
                    'required_fields' => [],
                    'success_fields' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => [
                'X-Durable-Workflow-Control-Plane-Version: 2',
            ],
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $payload = $client->get('/workflows');

        self::assertCount(1, $payload['workflows']);
        self::assertSame(1, $payload['workflow_count']);
        self::assertSame('next-token', $payload['next_page_token']);
    }

    public function test_it_normalizes_workflow_history_responses_with_the_control_plane_contract(): void
    {
        $response = new MockResponse(json_encode([
            'workflow_id' => 'wf-123',
            'run_id' => 'run-123',
            'events' => [
                [
                    'sequence' => 1,
                    'event_type' => 'WorkflowStarted',
                ],
            ],
            'next_page_token' => null,
            'control_plane' => [
                'schema' => 'durable-workflow.v2.control-plane-response',
                'version' => 1,
                'operation' => 'history',
                'workflow_id' => 'wf-123',
                'run_id' => 'run-123',
                'next_page_token' => null,
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
                    'required_fields' => ['workflow_id', 'run_id'],
                    'success_fields' => ['next_page_token'],
                ],
            ],
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => [
                'X-Durable-Workflow-Control-Plane-Version: 2',
            ],
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $payload = $client->get('/workflows/wf-123/runs/run-123/history');

        self::assertSame('wf-123', $payload['workflow_id']);
        self::assertSame('run-123', $payload['run_id']);
        self::assertCount(1, $payload['events']);
        self::assertSame('durable-workflow.v2.control-plane-response', $payload['control_plane_schema']);
        self::assertSame(1, $payload['control_plane_schema_version']);
        self::assertSame('history', $payload['control_plane_operation']);
    }

    public function test_it_leaves_workflow_history_export_bundles_outside_the_control_plane_contract(): void
    {
        $response = new MockResponse(json_encode([
            'schema' => 'durable-workflow.v2.history-export',
            'workflow' => [
                'instance_id' => 'wf-123',
                'run_id' => 'run-123',
            ],
            'history_events' => [],
            'integrity' => [
                'checksum_algorithm' => 'sha256',
                'checksum' => str_repeat('a', 64),
            ],
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => [
                'X-Durable-Workflow-Control-Plane-Version: 2',
            ],
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $payload = $client->get('/workflows/wf-123/runs/run-123/history/export');

        self::assertSame('durable-workflow.v2.history-export', $payload['schema']);
        self::assertSame('wf-123', $payload['workflow']['instance_id']);
        self::assertArrayNotHasKey('control_plane_schema', $payload);
    }

    public function test_it_rejects_workflow_responses_without_the_v2_control_plane_header(): void
    {
        $response = new MockResponse(json_encode([
            'workflow_id' => 'wf-123',
            'signal_name' => 'advance',
            'outcome' => 'signal_received',
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 202,
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid control-plane response version');

        $client->post('/workflows/wf-123/signal/advance');
    }

    public function test_it_accepts_versioned_worker_protocol_responses(): void
    {
        $response = new MockResponse(json_encode([
            'worker_id' => 'worker-1',
            'registered' => true,
            'protocol_version' => ServerClient::WORKER_PROTOCOL_VERSION,
            'server_capabilities' => [
                'workflow_task_poll_request_idempotency' => true,
            ],
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 201,
            'response_headers' => [
                'X-Durable-Workflow-Protocol-Version: '.ServerClient::WORKER_PROTOCOL_VERSION,
            ],
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $payload = $client->post('/worker/register', [
            'worker_id' => 'worker-1',
            'task_queue' => 'default',
            'runtime' => 'php',
        ]);

        self::assertSame('worker-1', $payload['worker_id']);
        self::assertSame(ServerClient::WORKER_PROTOCOL_VERSION, $payload['protocol_version']);
    }

    public function test_it_rejects_worker_responses_without_the_worker_protocol_header(): void
    {
        $response = new MockResponse(json_encode([
            'worker_id' => 'worker-1',
            'registered' => true,
            'protocol_version' => ServerClient::WORKER_PROTOCOL_VERSION,
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 201,
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid worker-protocol response version');

        $client->post('/worker/register');
    }

    public function test_it_rejects_worker_responses_without_the_body_protocol_version(): void
    {
        $response = new MockResponse(json_encode([
            'worker_id' => 'worker-1',
            'registered' => true,
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 201,
            'response_headers' => [
                'X-Durable-Workflow-Protocol-Version: '.ServerClient::WORKER_PROTOCOL_VERSION,
            ],
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid worker-protocol response body');

        $client->post('/worker/register');
    }

    public function test_it_keeps_control_plane_error_responses_usable_without_success_only_fields(): void
    {
        $response = new MockResponse(json_encode([
            'control_plane' => [
                'schema' => 'durable-workflow.v2.control-plane-response',
                'version' => 1,
                'operation' => 'signal',
                'operation_name' => 'advance',
                'operation_name_field' => 'signal_name',
                'workflow_id' => 'wf-123',
                'message' => 'Workflow not found.',
                'reason' => 'instance_not_found',
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
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 404,
            'response_headers' => [
                'X-Durable-Workflow-Control-Plane-Version: 2',
            ],
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Server error: Workflow not found.');

        $client->post('/workflows/wf-123/signal/advance');
    }

    public function test_it_rejects_control_plane_command_error_responses_without_the_shared_contract(): void
    {
        $response = new MockResponse(json_encode([
            'message' => 'Workflow not found.',
            'reason' => 'instance_not_found',
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 404,
            'response_headers' => [
                'X-Durable-Workflow-Control-Plane-Version: 2',
            ],
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing the shared control-plane contract');

        $client->post('/workflows/wf-123/signal/advance');
    }

    public function test_it_requires_the_shared_control_plane_contract_for_control_plane_success_responses(): void
    {
        $response = new MockResponse(json_encode([
            'workflow_id' => 'wf-123',
            'outcome' => 'cancelled',
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => [
                'X-Durable-Workflow-Control-Plane-Version: 2',
            ],
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing the shared control-plane contract');

        $client->post('/workflows/wf-123/cancel');
    }

    public function test_it_requires_the_shared_control_plane_contract_for_workflow_read_success_responses(): void
    {
        $response = new MockResponse(json_encode([
            'workflow_id' => 'wf-123',
            'run_id' => 'run-123',
            'workflow_type' => 'orders.process',
            'status' => 'running',
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => [
                'X-Durable-Workflow-Control-Plane-Version: 2',
            ],
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing the shared control-plane contract');

        $client->get('/workflows/wf-123');
    }

    public function test_it_uses_server_emitted_contract_metadata_without_a_cli_operation_whitelist(): void
    {
        $response = new MockResponse(json_encode([
            'control_plane' => [
                'schema' => 'vendor.example.control-plane-envelope',
                'version' => 77,
                'operation' => 'custom_diagnostic',
                'workflow_id' => 'wf-123',
                'message' => 'ready',
                'contract' => [
                    'schema' => 'vendor.example.control-plane-definition',
                    'version' => 15,
                    'legacy_field_policy' => 'server_emitted_metadata',
                    'legacy_fields' => [
                        'query' => 'query_name',
                        'signal' => 'signal_name',
                        'update' => 'update_name',
                        'wait_policy' => 'wait_for',
                    ],
                    'required_fields' => ['workflow_id'],
                    'success_fields' => ['message'],
                ],
            ],
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => [
                'X-Durable-Workflow-Control-Plane-Version: 2',
            ],
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $payload = $client->post('/workflows/wf-123/custom-diagnostic');

        self::assertSame('custom_diagnostic', $payload['control_plane_operation']);
        self::assertSame('vendor.example.control-plane-envelope', $payload['control_plane_schema']);
        self::assertSame(77, $payload['control_plane_schema_version']);
        self::assertSame('ready', $payload['message']);
    }

    public function test_it_rejects_workflow_responses_with_an_invalid_nested_contract_version(): void
    {
        $response = new MockResponse(json_encode([
            'control_plane' => [
                'schema' => 'durable-workflow.v2.control-plane-response',
                'version' => 1,
                'operation' => 'signal',
                'operation_name' => 'advance',
                'operation_name_field' => 'signal_name',
                'workflow_id' => 'wf-123',
                'outcome' => 'signal_received',
                'contract' => [
                    'schema' => 'durable-workflow.v2.control-plane-response.contract',
                    'version' => 0,
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
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 202,
            'response_headers' => [
                'X-Durable-Workflow-Control-Plane-Version: 2',
            ],
        ]);

        $client = new ServerClient(
            baseUrl: 'http://example.test',
            namespace: 'default',
            http: new MockHttpClient($response, 'http://example.test'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid nested control-plane contract version');

        $client->post('/workflows/wf-123/signal/advance');
    }
}
