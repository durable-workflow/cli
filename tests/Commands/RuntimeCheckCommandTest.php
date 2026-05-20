<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\RuntimeCheckCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RuntimeCheckCommandTest extends TestCase
{
    public function test_runtime_check_passes_when_required_extensions_are_loaded(): void
    {
        $command = new RuntimeCheckCommand(static fn (string $extension): bool => in_array(
            $extension,
            RuntimeCheckCommand::REQUIRED_EXTENSIONS,
            true,
        ));

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Runtime extensions OK', $tester->getDisplay());
    }

    public function test_runtime_check_fails_when_required_extensions_are_missing(): void
    {
        $command = new RuntimeCheckCommand(static fn (string $extension): bool => $extension !== 'curl');
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Missing required runtime extensions: curl', $tester->getDisplay());
    }
}
