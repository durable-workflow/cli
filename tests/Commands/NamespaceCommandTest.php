<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\NamespaceCommand\CreateCommand;
use DurableWorkflow\Cli\Commands\NamespaceCommand\DescribeCommand;
use DurableWorkflow\Cli\Commands\NamespaceCommand\ListCommand;
use DurableWorkflow\Cli\Commands\NamespaceCommand\UpdateCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class NamespaceCommandTest extends TestCase
{
    public function test_list_command_renders_namespaces_in_a_table(): void
    {
        $command = new ListCommand();
        $command->setServerClient(new NamespaceFakeClient([
            'namespaces' => [
                [
                    'name' => 'default',
                    'description' => 'Default namespace',
                    'retention_days' => 30,
                    'status' => 'active',
                ],
                [
                    'name' => 'staging',
                    'description' => 'Staging environment',
                    'retention_days' => 7,
                    'status' => 'active',
                ],
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('default', $display);
        self::assertStringContainsString('Default namespace', $display);
        self::assertStringContainsString('30', $display);
        self::assertStringContainsString('staging', $display);
        self::assertStringContainsString('Staging environment', $display);
    }

    public function test_list_command_shows_message_when_no_namespaces_exist(): void
    {
        $command = new ListCommand();
        $command->setServerClient(new NamespaceFakeClient([
            'namespaces' => [],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertStringContainsString('No namespaces found', $tester->getDisplay());
    }

    public function test_create_command_sends_name_description_and_retention(): void
    {
        $client = new NamespaceFakeClient([
            'name' => 'production',
        ]);

        $command = new CreateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'name' => 'production',
            '--description' => 'Production namespace',
            '--retention' => '90',
        ]));

        self::assertSame('/namespaces', $client->lastPostPath);
        self::assertSame('production', $client->lastPostBody['name']);
        self::assertSame('Production namespace', $client->lastPostBody['description']);
        self::assertSame(90, $client->lastPostBody['retention_days']);
        self::assertStringContainsString('Namespace created: production', $tester->getDisplay());
    }

    public function test_describe_command_renders_namespace_details(): void
    {
        $command = new DescribeCommand();
        $command->setServerClient(new NamespaceFakeClient([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
            'created_at' => '2026-04-01T00:00:00Z',
            'updated_at' => '2026-04-10T12:00:00Z',
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'name' => 'default',
        ]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('default', $display);
        self::assertStringContainsString('Default namespace', $display);
        self::assertStringContainsString('30', $display);
        self::assertStringContainsString('active', $display);
    }

    public function test_update_command_sends_put_with_filtered_fields(): void
    {
        $client = new NamespaceFakeClient([
            'name' => 'default',
        ]);

        $command = new UpdateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'name' => 'default',
            '--description' => 'Updated description',
            '--retention' => '60',
        ]));

        self::assertSame('/namespaces/default', $client->lastPutPath);
        self::assertSame('Updated description', $client->lastPutBody['description']);
        self::assertSame(60, $client->lastPutBody['retention_days']);
    }
}

class NamespaceFakeClient extends ServerClient
{
    /** @var array<string, mixed> */
    public array $lastPostBody = [];

    public string $lastPostPath = '';

    /** @var array<string, mixed> */
    public array $lastPutBody = [];

    public string $lastPutPath = '';

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly array $payload,
    ) {}

    public function get(string $path, array $query = []): array
    {
        return $this->payload;
    }

    public function post(string $path, array $body = []): array
    {
        $this->lastPostPath = $path;
        $this->lastPostBody = $body;

        return $this->payload;
    }

    public function put(string $path, array $body = []): array
    {
        $this->lastPutPath = $path;
        $this->lastPutBody = $body;

        return $this->payload;
    }
}
