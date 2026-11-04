<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Application;
use DurableWorkflow\Cli\Support\ExitCode;
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
        self::assertStringContainsString('activity:list', $this->complete(1, ['dw', 'activity:l']));

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
            ['running', 'completed', 'failed'],
            $this->completionLines($this->complete(2, ['dw', 'activity:list', '--status=r'])),
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

    public function test_completion_refuses_unsafe_executable_names_before_emitting_shell_code(): void
    {
        foreach (['dw two', "dw'quote", 'dw;command', 'dw$(command)', '-dw', '2dw', ''] as $commandName) {
            $tester = $this->runCliAs($commandName, [
                'command' => $commandName === 'dw two' ? 'complet' : 'completion',
                'shell' => 'bash',
            ], ExitCode::INVALID, true);

            self::assertSame('', $tester->getDisplay(), $commandName);
            self::assertStringContainsString(
                'Cannot generate shell completion for this executable name.',
                $tester->getErrorOutput(),
                $commandName,
            );
            self::assertStringNotContainsString('_complete', $tester->getErrorOutput(), $commandName);
        }
    }

    public function test_completion_debug_never_opens_the_predictable_temporary_path(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('The symlink sentinel is a Unix-only control.');
        }

        $commandName = 'dw_security_'.getmypid().'_'.bin2hex(random_bytes(4));
        $debugPath = sys_get_temp_dir().'/sf_'.$commandName.'.log';
        $sentinel = tempnam(sys_get_temp_dir(), 'dw-completion-sentinel-');
        self::assertNotFalse($sentinel);
        file_put_contents($sentinel, "unchanged\n");
        self::assertFalse(file_exists($debugPath) || is_link($debugPath));
        self::assertTrue(symlink($sentinel, $debugPath));

        $originalDebug = getenv('SYMFONY_COMPLETION_DEBUG');
        putenv('SYMFONY_COMPLETION_DEBUG=1');

        try {
            $complete = $this->runCliAs($commandName, [
                'command' => '_complete',
                '--shell' => 'bash',
                '--api-version' => '1',
                '--current' => '1',
                '--input' => ['dw', 'workflow:l'],
            ], 0, true);

            self::assertStringContainsString('workflow:list', $complete->getDisplay());
            self::assertSame('1', getenv('SYMFONY_COMPLETION_DEBUG'));
            self::assertSame("unchanged\n", file_get_contents($sentinel));

            $tail = $this->runCliAs($commandName, [
                'command' => 'completion',
                '--debug' => true,
            ], ExitCode::INVALID, true);

            self::assertSame('', $tail->getDisplay());
            self::assertStringContainsString(
                'Shell completion debug logging is disabled in this maintenance release.',
                $tail->getErrorOutput(),
            );
            self::assertTrue(is_link($debugPath));
            self::assertSame("unchanged\n", file_get_contents($sentinel));
        } finally {
            if ($originalDebug === false) {
                putenv('SYMFONY_COMPLETION_DEBUG');
            } else {
                putenv('SYMFONY_COMPLETION_DEBUG='.$originalDebug);
            }
            if (is_link($debugPath)) {
                unlink($debugPath);
            }
            if (is_file($sentinel)) {
                unlink($sentinel);
            }
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCli(array $input): string
    {
        return $this->runCliAs('dw', $input)->getDisplay();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCliAs(
        string $executableName,
        array $input,
        int $expectedStatus = 0,
        bool $captureStderrSeparately = false,
    ): ApplicationTester {
        $originalArgv = $_SERVER['argv'] ?? null;
        $originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        $_SERVER['argv'] = [$executableName];
        $_SERVER['PHP_SELF'] = '/usr/local/bin/'.$executableName;

        $application = new Application();
        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);

        try {
            self::assertSame($expectedStatus, $tester->run($input, [
                'capture_stderr_separately' => $captureStderrSeparately,
            ]));

            return $tester;
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
