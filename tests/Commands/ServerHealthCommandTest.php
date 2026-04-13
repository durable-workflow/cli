<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\ServerCommand\HealthCommand;
use DurableWorkflow\Cli\Support\ServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ServerHealthCommandTest extends TestCase
{
    public function test_health_command_renders_healthy_status(): void
    {
        $command = new HealthCommand();
        $command->setServerClient(new HealthFakeClient([
            'status' => 'ok',
            'timestamp' => '2026-04-13T14:00:00Z',
            'checks' => [
                'database' => 'ok',
                'redis' => 'ok',
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('ok', $display);
        self::assertStringContainsString('database', $display);
    }

    public function test_health_command_renders_degraded_check(): void
    {
        $command = new HealthCommand();
        $command->setServerClient(new HealthFakeClient([
            'status' => 'degraded',
            'timestamp' => '2026-04-13T14:00:00Z',
            'checks' => [
                'database' => 'ok',
                'redis' => 'error',
            ],
        ]));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();

        self::assertStringContainsString('degraded', $display);
        self::assertStringContainsString('error', $display);
    }

    public function test_health_command_returns_failure_on_connection_error(): void
    {
        $command = new HealthCommand();
        $command->setServerClient(new HealthFailingClient());

        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([]));

        self::assertStringContainsString('Connection refused', $tester->getDisplay());
    }
}

class HealthFakeClient extends ServerClient
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly array $payload,
    ) {}

    public function get(string $path, array $query = []): array
    {
        return $this->payload;
    }
}

class HealthFailingClient extends ServerClient
{
    public function __construct() {}

    public function get(string $path, array $query = []): array
    {
        throw new \RuntimeException('Connection refused');
    }
}
