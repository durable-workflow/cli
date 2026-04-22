<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\BridgeCommand\WebhookCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class BridgeParityFixtureTest extends TestCase
{
    public function test_bridge_webhook_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture();
        $client = new BridgeWebhookParityClient($fixture['response_body']);
        $command = new WebhookCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame($fixture['cli']['expected_body'], $client->lastBody);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['adapter'], basename($client->lastPath));
        self::assertSame($semantic['action'], $client->lastBody['action'] ?? null);
        self::assertSame($semantic['idempotency_key'], $client->lastBody['idempotency_key'] ?? null);
        self::assertSame($semantic['target'], $client->lastBody['target'] ?? null);
        self::assertSame($semantic['input'], $client->lastBody['input'] ?? null);
        self::assertSame($semantic['correlation'], $client->lastBody['correlation'] ?? null);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Bridge adapter outcome', $display);
        self::assertStringContainsString('Adapter: '.$semantic['adapter'], $display);
        self::assertStringContainsString('Workflow ID: '.$semantic['workflow_id'], $display);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        $path = __DIR__.'/../fixtures/control-plane/bridge-webhook-parity.json';
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertSame('durable-workflow.polyglot.control-plane-request-fixture', $fixture['schema'] ?? null);
        self::assertSame('bridge.webhook', $fixture['operation'] ?? null);

        return $fixture;
    }
}

final class BridgeWebhookParityClient extends ServerClient
{
    public ?string $lastMethod = null;

    public ?string $lastPath = null;

    /**
     * @var array<string, mixed>
     */
    public array $lastBody = [];

    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(private readonly array $response)
    {
        parent::__construct('http://localhost');
    }

    public function post(string $path, array $body = []): array
    {
        $this->lastMethod = 'POST';
        $this->lastPath = $path;
        $this->lastBody = $body;

        return $this->response;
    }
}
