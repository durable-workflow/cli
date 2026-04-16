<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\SearchAttributeCommand\CreateCommand;
use DurableWorkflow\Cli\Commands\SearchAttributeCommand\DeleteCommand;
use DurableWorkflow\Cli\Commands\SearchAttributeCommand\ListCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SearchAttributeCommandTest extends TestCase
{
    public function test_list_command_renders_system_and_custom_attributes(): void
    {
        $command = new ListCommand();
        $command->setServerClient(new SearchAttributeFakeClient([
            'system_attributes' => [
                'WorkflowType' => 'keyword',
                'StartTime' => 'datetime',
            ],
            'custom_attributes' => [
                'OrderStatus' => 'keyword',
                'Priority' => 'int',
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('WorkflowType', $display);
        self::assertStringContainsString('system', $display);
        self::assertStringContainsString('OrderStatus', $display);
        self::assertStringContainsString('custom', $display);
        self::assertStringContainsString('Priority', $display);
    }

    public function test_list_command_shows_message_when_no_attributes_exist(): void
    {
        $command = new ListCommand();
        $command->setServerClient(new SearchAttributeFakeClient([
            'system_attributes' => [],
            'custom_attributes' => [],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertStringContainsString('No search attributes', $tester->getDisplay());
    }

    public function test_list_command_renders_json_output(): void
    {
        $command = new ListCommand();
        $command->setServerClient(new SearchAttributeFakeClient([
            'system_attributes' => [
                'WorkflowType' => 'keyword',
            ],
            'custom_attributes' => [
                'OrderStatus' => 'keyword',
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--json' => true,
        ]));

        $decoded = json_decode($tester->getDisplay(), true);

        self::assertIsArray($decoded);
        self::assertSame('keyword', $decoded['system_attributes']['WorkflowType']);
        self::assertSame('keyword', $decoded['custom_attributes']['OrderStatus']);
    }

    public function test_create_command_sends_name_and_type(): void
    {
        $client = new SearchAttributeFakeClient([
            'name' => 'OrderStatus',
            'type' => 'keyword',
        ]);

        $command = new CreateCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'name' => 'OrderStatus',
            'type' => 'keyword',
        ]));

        self::assertSame('/search-attributes', $client->lastPostPath);
        self::assertSame('OrderStatus', $client->lastPostBody['name']);
        self::assertSame('keyword', $client->lastPostBody['type']);
        self::assertStringContainsString('OrderStatus', $tester->getDisplay());
    }

    public function test_delete_command_sends_delete_request(): void
    {
        $client = new SearchAttributeFakeClient([]);

        $command = new DeleteCommand();
        $command->setServerClient($client);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'name' => 'OrderStatus',
        ]));

        self::assertSame('/search-attributes/OrderStatus', $client->lastDeletePath);
        self::assertStringContainsString('OrderStatus', $tester->getDisplay());
    }
}

class SearchAttributeFakeClient extends ServerClient
{
    /** @var array<string, mixed> */
    public array $lastPostBody = [];

    public string $lastPostPath = '';

    public string $lastDeletePath = '';

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

    public function delete(string $path): array
    {
        $this->lastDeletePath = $path;

        return $this->payload;
    }
}
