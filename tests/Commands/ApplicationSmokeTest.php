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
}
