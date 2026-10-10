<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\ServerCommand\StartDevCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class StartDevCommandTest extends TestCase
{
    private string $directory;
    private string $originalDirectory;
    private string $originalPath;

    protected function setUp(): void
    {
        $this->originalDirectory = getcwd();
        $this->originalPath = getenv('PATH') ?: '';
        $this->directory = sys_get_temp_dir().'/dw-start-dev-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        chdir($this->directory);
    }

    protected function tearDown(): void
    {
        chdir($this->originalDirectory);
        putenv('PATH='.$this->originalPath);
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function test_missing_project_fails_without_claiming_a_running_server(): void
    {
        $tester = new CommandTester(new StartDevCommand());

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('artisan', $tester->getDisplay());
        self::assertStringNotContainsString('Server running', $tester->getDisplay());
    }

    public function test_missing_external_php_fails_before_launch(): void
    {
        file_put_contents($this->directory.'/artisan', '<?php exit(0);');
        putenv('PATH='.$this->directory.'/no-php');
        $tester = new CommandTester(new StartDevCommand());

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('PHP', $tester->getDisplay());
        self::assertStringNotContainsString('Server running', $tester->getDisplay());
    }

    public function test_http_startup_failure_propagates_the_child_exit_code(): void
    {
        file_put_contents($this->directory.'/artisan', '<?php fwrite(STDERR, "HTTP startup fixture failed\n"); exit(42);');
        $tester = new CommandTester(new StartDevCommand());

        self::assertSame(42, $tester->execute([]));
        self::assertStringContainsString('HTTP startup fixture failed', $tester->getDisplay());
        self::assertStringNotContainsString('Server running', $tester->getDisplay());
    }
}
