<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowCommand\DescribeCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkflowDescribeParityFixtureTest extends TestCase
{
    public function test_workflow_describe_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture();
        $client = new WorkflowDescribeParityClient($fixture['response_body']);
        $command = new DescribeCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame([], $client->lastQuery);

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['response_body'], $decoded);

        $semantic = $fixture['semantic_body'];
        self::assertSame($semantic['workflow_id'], $decoded['workflow_id'] ?? null, 'describe workflow id drifted from fixture semantics.');
    }

    public function test_migrated_describe_output_satisfies_the_published_schema(): void
    {
        $response = [
            'workflow_id' => 'v1:legacy-prod:abc123',
            'run_id' => 'def456',
            'workflow_type' => 'App\\Workflows\\LegacyOrderWorkflow',
            'namespace' => 'migration',
            'status' => 'completed',
            'status_bucket' => 'completed',
            'task_queue' => 'orders',
            'action_blocked_reason' => 'v1_projection_read_only',
            'migration_projection' => [
                'origin' => [
                    'engine_source' => 'v1',
                    'source_id' => 'legacy-prod',
                ],
                'identity' => [
                    'waterline' => [
                        'qualified_workflow_id' => 'v1:42',
                    ],
                    'standalone' => [
                        'namespace' => 'migration',
                        'workflow_id' => 'v1:legacy-prod:abc123',
                        'run_id' => 'def456',
                    ],
                ],
                'task_queue_context' => [
                    'task_queue' => 'orders',
                    'execution_owner' => 'v1',
                ],
                'unsupported_fields' => [[
                    'field' => 'runtime.replay',
                    'reason' => 'v1_history_not_replayable_as_v2',
                    'remediation' => 'Retain v1 storage for replay and payload recovery.',
                ]],
                'projection' => [
                    'read_only' => true,
                    'execution_owner' => 'v1',
                ],
            ],
        ];
        $command = new DescribeCommand();
        $command->setServerClient(new WorkflowDescribeParityClient($response));
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'workflow-id' => $response['workflow_id'],
            '--namespace' => 'migration',
            '--json' => true,
        ]));

        $document = json_decode($tester->getDisplay(), false, flags: JSON_THROW_ON_ERROR);
        $schemaContents = file_get_contents(__DIR__.'/../../schemas/output/workflow-run.schema.json');
        self::assertIsString($schemaContents);
        $schema = json_decode($schemaContents, true, flags: JSON_THROW_ON_ERROR);

        self::assertJsonSchemaMatches($document, $schema);
    }

    /**
     * Validate the JSON Schema keywords used by the workflow-run contract.
     *
     * @param array<string, mixed>|bool $schema
     */
    private static function assertJsonSchemaMatches(mixed $value, array|bool $schema, string $path = '$'): void
    {
        if ($schema === true) {
            return;
        }

        if ($schema === false) {
            self::fail("{$path} is rejected by the schema.");
        }

        if (isset($schema['type'])) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            self::assertTrue(
                in_array(true, array_map(
                    static fn (string $type): bool => self::matchesJsonType($value, $type),
                    $types,
                ), true),
                sprintf('%s must have JSON type %s.', $path, implode('|', $types)),
            );
        }

        if (is_object($value)) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

            foreach (($schema['required'] ?? []) as $required) {
                self::assertTrue(property_exists($value, $required), "{$path}.{$required} is required.");
            }

            foreach (get_object_vars($value) as $name => $propertyValue) {
                if (array_key_exists($name, $properties)) {
                    self::assertJsonSchemaMatches($propertyValue, $properties[$name], "{$path}.{$name}");
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    self::fail("{$path}.{$name} is not allowed by the schema.");
                }
            }
        }

        if (is_array($value) && isset($schema['items'])) {
            foreach ($value as $index => $item) {
                self::assertJsonSchemaMatches($item, $schema['items'], "{$path}[{$index}]");
            }
        }
    }

    private static function matchesJsonType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_object($value),
            'array' => is_array($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        $path = __DIR__.'/../fixtures/control-plane/workflow-describe-parity.json';
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertSame('durable-workflow.polyglot.control-plane-request-fixture', $fixture['schema'] ?? null);
        self::assertSame('workflow.describe', $fixture['operation'] ?? null);

        return $fixture;
    }
}

final class WorkflowDescribeParityClient extends ServerClient
{
    public ?string $lastMethod = null;

    public ?string $lastPath = null;

    /**
     * @var array<string, mixed>
     */
    public array $lastQuery = [];

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
}
