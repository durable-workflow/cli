<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\BridgeCommand\WebhookCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class BridgeCommandTest extends TestCase
{
    public function test_webhook_command_posts_bridge_adapter_envelope(): void
    {
        $client = new BridgeFakeServerClient([
            'schema' => 'durable-workflow.v2.bridge-adapter-outcome.contract',
            'version' => 1,
            'adapter' => 'stripe',
            'action' => 'start_workflow',
            'accepted' => true,
            'outcome' => 'accepted',
            'idempotency_key' => 'stripe-event-1001',
            'target' => [
                'workflow_type' => 'orders.fulfillment',
                'task_queue' => 'external-workflows',
                'business_key' => 'order-1001',
            ],
            'workflow_id' => 'bridge-stripe-abc123',
            'run_id' => 'run-1',
        ]);

        $command = new WebhookCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'adapter' => 'stripe',
            '--action' => 'start_workflow',
            '--idempotency-key' => 'stripe-event-1001',
            '--target' => '{"workflow_type":"orders.fulfillment","task_queue":"external-workflows","business_key":"order-1001"}',
            '--input' => '{"order_id":"order-1001"}',
            '--correlation' => '{"provider":"stripe","event_type":"checkout.session.completed"}',
        ]));

        self::assertSame('/bridge-adapters/webhook/stripe', $client->lastPostPath);
        self::assertSame([
            'action' => 'start_workflow',
            'idempotency_key' => 'stripe-event-1001',
            'target' => [
                'workflow_type' => 'orders.fulfillment',
                'task_queue' => 'external-workflows',
                'business_key' => 'order-1001',
            ],
            'input' => [
                'order_id' => 'order-1001',
            ],
            'correlation' => [
                'provider' => 'stripe',
                'event_type' => 'checkout.session.completed',
            ],
        ], $client->lastPostBody);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Bridge adapter outcome', $display);
        self::assertStringContainsString('Adapter: stripe', $display);
        self::assertStringContainsString('Workflow ID: bridge-stripe-abc123', $display);
    }

    public function test_webhook_command_renders_json_response(): void
    {
        $command = new WebhookCommand();
        $command->setServerClient(new BridgeFakeServerClient([
            'schema' => 'durable-workflow.v2.bridge-adapter-outcome.contract',
            'version' => 1,
            'adapter' => 'pagerduty',
            'action' => 'signal_workflow',
            'accepted' => false,
            'outcome' => 'duplicate',
            'reason' => 'duplicate_start',
            'idempotency_key' => 'pd-event-3003',
            'target' => [
                'workflow_id' => 'wf-remediation-42',
                'signal_name' => 'incident_escalated',
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'adapter' => 'pagerduty',
            '--action' => 'signal_workflow',
            '--idempotency-key' => 'pd-event-3003',
            '--target' => '{"workflow_id":"wf-remediation-42","signal_name":"incident_escalated"}',
            '--json' => true,
        ]));

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('pagerduty', $decoded['adapter']);
        self::assertSame('signal_workflow', $decoded['action']);
        self::assertSame('duplicate', $decoded['outcome']);
    }

    public function test_webhook_command_rejects_non_object_target(): void
    {
        $command = new WebhookCommand();
        $command->setServerClient(new BridgeFakeServerClient([]));

        $tester = new CommandTester($command);

        self::assertSame(Command::INVALID, $tester->execute([
            'adapter' => 'stripe',
            '--action' => 'start_workflow',
            '--idempotency-key' => 'stripe-event-1001',
            '--target' => '["not","an","object"]',
        ]));

        self::assertStringContainsString('--target must be a JSON object.', $tester->getDisplay());
    }
}

class BridgeFakeServerClient extends ServerClient
{
    /**
     * @var array<string, mixed>
     */
    public array $lastPostBody = [];

    public string $lastPostPath = '';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(private readonly array $payload)
    {
    }

    public function post(string $path, array $body = []): array
    {
        $this->lastPostPath = $path;
        $this->lastPostBody = $body;

        return $this->payload;
    }
}
