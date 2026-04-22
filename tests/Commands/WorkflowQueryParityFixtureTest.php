<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowCommand\QueryCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkflowQueryParityFixtureTest extends TestCase
{
    public function test_workflow_query_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture();
        $client = new WorkflowQueryParityClient();
        $command = new QueryCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame($fixture['cli']['expected_body'], $client->lastBody);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['input'], $client->lastBody['input'] ?? null, 'query input drifted from fixture semantics.');
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        $path = __DIR__.'/../fixtures/control-plane/workflow-query-parity.json';
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertSame('durable-workflow.polyglot.control-plane-request-fixture', $fixture['schema'] ?? null);
        self::assertSame('workflow.query', $fixture['operation'] ?? null);

        return $fixture;
    }
}

final class WorkflowQueryParityClient extends ServerClient
{
    public ?string $lastMethod = null;

    public ?string $lastPath = null;

    /**
     * @var array<string, mixed>
     */
    public array $lastBody = [];

    public function post(string $path, array $body = []): array
    {
        $this->lastMethod = 'POST';
        $this->lastPath = $path;
        $this->lastBody = $body;

        return [
            'workflow_id' => 'wf-polyglot-231',
            'query_name' => 'order.status',
            'result' => [
                'status' => 'processing',
                'line_items' => 3,
                'currency' => 'USD',
            ],
        ];
    }
}
