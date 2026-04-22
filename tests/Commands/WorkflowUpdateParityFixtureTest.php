<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\WorkflowCommand\UpdateCommand;
use DurableWorkflow\Cli\Support\ControlPlaneRequestContract;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkflowUpdateParityFixtureTest extends TestCase
{
    public function test_workflow_update_matches_polyglot_request_fixture(): void
    {
        $fixture = self::fixture();
        $client = new WorkflowUpdateParityClient(self::requestContract());
        $command = new UpdateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute($fixture['cli']['argv']));

        self::assertSame($fixture['request']['method'], $client->lastMethod);
        self::assertSame($fixture['request']['path'], $client->lastPath);
        self::assertSame($fixture['cli']['expected_body'], $client->lastBody);

        $semantic = $fixture['semantic_body'];
        foreach (['wait_for', 'input'] as $field) {
            self::assertSame($semantic[$field], $client->lastBody[$field] ?? null, "{$field} drifted from fixture semantics.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        $path = __DIR__.'/../fixtures/control-plane/workflow-update-parity.json';
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertSame('durable-workflow.polyglot.control-plane-request-fixture', $fixture['schema'] ?? null);
        self::assertSame('workflow.update', $fixture['operation'] ?? null);

        return $fixture;
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
}

final class WorkflowUpdateParityClient extends ServerClient
{
    public ?string $lastMethod = null;

    public ?string $lastPath = null;

    /**
     * @var array<string, mixed>
     */
    public array $lastBody = [];

    public function __construct(private readonly ControlPlaneRequestContract $requestContract) {}

    public function post(string $path, array $body = []): array
    {
        $this->lastMethod = 'POST';
        $this->lastPath = $path;
        $this->lastBody = $body;

        return [
            'workflow_id' => 'wf-polyglot-231',
            'update_name' => 'quota.adjust',
            'update_id' => 'upd-polyglot-231',
            'outcome' => 'update_completed',
            'command_status' => 'accepted',
            'update_status' => 'completed',
            'wait_for' => $body['wait_for'] ?? 'accepted',
        ];
    }

    public function controlPlaneRequestContract(): ?ControlPlaneRequestContract
    {
        return $this->requestContract;
    }
}
