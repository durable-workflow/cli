<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

class CompletionCommandTest extends TestCase
{
    public function test_completion_command_dumps_scripts_for_supported_shells(): void
    {
        $bash = $this->runCli([
            'command' => 'completion',
            'shell' => 'bash',
        ]);

        self::assertStringContainsString('_sf_dw', $bash);
        self::assertStringContainsString('_complete', $bash);

        $zsh = $this->runCli([
            'command' => 'completion',
            'shell' => 'zsh',
        ]);

        self::assertStringContainsString('#compdef dw', $zsh);

        $fish = $this->runCli([
            'command' => 'completion',
            'shell' => 'fish',
        ]);

        self::assertStringContainsString("complete -c 'dw'", $fish);
    }

    public function test_internal_completion_suggests_commands_and_option_names(): void
    {
        $commands = $this->complete(1, ['dw', 'workflow:l']);

        self::assertStringContainsString('workflow:list', $commands);
        self::assertStringContainsString('workflow:list-runs', $commands);

        $options = $this->complete(2, ['dw', 'workflow:list', '--st']);

        self::assertStringContainsString('--status', $options);
        self::assertStringContainsString('--server', $options);
    }

    public function test_internal_completion_suggests_stable_option_values(): void
    {
        self::assertSame(
            ['sqlite', 'mysql', 'pgsql'],
            $this->completionLines($this->complete(2, ['dw', 'server:start-dev', '--db=s'])),
        );

        self::assertSame(
            ['accepted', 'completed'],
            $this->completionLines($this->complete(4, ['dw', 'workflow:update', 'wf-1', 'approve', '--wait=c'])),
        );

        self::assertSame(
            ['fail', 'use-existing'],
            $this->completionLines($this->complete(2, ['dw', 'workflow:start', '--duplicate-policy=f'])),
        );

        self::assertSame(
            ['json', 'raw', 'base64'],
            $this->completionLines($this->complete(2, ['dw', 'workflow:start', '--input-encoding=b'])),
        );

        self::assertSame(
            ['running', 'completed', 'failed'],
            $this->completionLines($this->complete(2, ['dw', 'workflow:list', '--status=r'])),
        );

        self::assertSame(
            ['active', 'stale', 'draining'],
            $this->completionLines($this->complete(2, ['dw', 'worker:list', '--status=a'])),
        );

        self::assertSame(
            ['skip', 'buffer_one', 'buffer_all', 'cancel_other', 'terminate_other', 'allow_all'],
            $this->completionLines($this->complete(3, ['dw', 'schedule:trigger', 'daily-report', '--overlap-policy=b'])),
        );

        self::assertSame(
            ['keyword', 'text', 'int', 'double', 'bool', 'datetime', 'keyword_list'],
            $this->completionLines($this->complete(3, ['dw', 'search-attribute:create', 'OrderStatus', 'k'])),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCli(array $input): string
    {
        $originalArgv = $_SERVER['argv'] ?? null;
        $originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        $_SERVER['argv'] = ['dw'];
        $_SERVER['PHP_SELF'] = '/usr/local/bin/dw';

        $application = new Application();
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);

        try {
            self::assertSame(0, $tester->run($input));

            return $tester->getDisplay();
        } finally {
            if ($originalArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalArgv;
            }

            if ($originalPhpSelf === null) {
                unset($_SERVER['PHP_SELF']);
            } else {
                $_SERVER['PHP_SELF'] = $originalPhpSelf;
            }
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function complete(int $current, array $tokens): string
    {
        return $this->runCli([
            'command' => '_complete',
            '--shell' => 'bash',
            '--api-version' => '1',
            '--current' => (string) $current,
            '--input' => $tokens,
        ]);
    }

    /**
     * @return list<string>
     */
    private function completionLines(string $output): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", $output)),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
