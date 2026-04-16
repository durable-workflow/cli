<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

class ApplicationSmokeTest extends TestCase
{
    public function test_commands_load_without_shortcut_collisions(): void
    {
        $application = new Application();
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);

        foreach ([
            'workflow:start',
            'workflow:list',
            'workflow:list-runs',
            'workflow:show-run',
            'schedule:create',
        ] as $command) {
            self::assertSame(0, $tester->run([
                'command' => $command,
                '--help' => true,
            ]));
        }
    }

    public function test_version_output_reports_build_identity(): void
    {
        $application = new Application();
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run([
            '--version' => true,
        ]));

        self::assertMatchesRegularExpression(
            '/^dw \S+ \(commit [^)]+, built [^)]+\)/',
            trim($tester->getDisplay()),
        );
    }

    public function test_every_command_has_description_and_help_with_example(): void
    {
        $application = new Application();
        $application->setAutoExit(false);

        foreach ($application->all() as $name => $command) {
            if (in_array($name, ['help', 'list', '_complete', 'completion'], true)) {
                continue;
            }

            self::assertNotSame(
                '',
                $command->getDescription(),
                "Command {$name} is missing setDescription() text.",
            );

            $help = $command->getHelp();
            self::assertNotSame(
                '',
                $help,
                "Command {$name} is missing setHelp() text.",
            );

            self::assertStringContainsString(
                'dw ',
                $help,
                "Command {$name} help is missing at least one 'dw ...' example.",
            );
        }
    }
}
