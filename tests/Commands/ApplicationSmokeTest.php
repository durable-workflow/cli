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
}
