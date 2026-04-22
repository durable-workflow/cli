<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowCommand\ListCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkflowListParityFixtureTest extends TestCase
{
    public function test_workflow_list_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture();
        $client = new WorkflowListParityClient($fixture['response_body']);
        $command = new ListCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame($fixture['cli']['expected_query'], $client->lastQuery);
        self::assertSame([
            [
                'operation' => 'list',
                'field' => 'status',
                'value' => $fixture['semantic_body']['status'],
                'option' => '--status',
            ],
        ], $client->validatedOptions);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame(
            $semantic['workflow_ids'],
            array_column($decoded['workflows'] ?? [], 'workflow_id'),
            'workflow ids drifted from fixture semantics.',
        );
        self::assertSame(
            $semantic['next_page_token'],
            $decoded['next_page_token'] ?? null,
            'pagination token drifted from fixture semantics.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        $path = __DIR__.'/../fixtures/control-plane/workflow-list-parity.json';
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertSame('durable-workflow.polyglot.control-plane-request-fixture', $fixture['schema'] ?? null);
        self::assertSame('workflow.list', $fixture['operation'] ?? null);

        return $fixture;
    }
}

final class WorkflowListParityClient extends ServerClient
{
    public ?string $lastMethod = null;

    public ?string $lastPath = null;

    /**
     * @var array<string, mixed>
     */
    public array $lastQuery = [];

    /**
     * @var list<array{operation: string, field: string, value: string|null, option: string|null}>
     */
    public array $validatedOptions = [];

    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(private readonly array $response)
    {
        parent::__construct('http://localhost');
    }

    public function get(string $path, array $query = []): array
    {
        $this->lastMethod = 'GET';
        $this->lastPath = $path;
        $this->lastQuery = $query;

        return $this->response;
    }

    public function assertControlPlaneOptionValue(
        string $operation,
        string $field,
        ?string $value,
        ?string $optionName = null,
    ): void {
        $this->validatedOptions[] = [
            'operation' => $operation,
            'field' => $field,
            'value' => $value,
            'option' => $optionName,
        ];
    }
}
