<?php

declare(strict_types=1);

namespace Tests\Commands;

use DurableWorkflow\Cli\Commands\ServerCommand\StartDevCommand;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('invalidOptions')]
    public function test_invalid_options_are_rejected_before_launch(array $options): void
    {
        $this->writeArtisan();
        $tester = new CommandTester(new StartDevCommand());

        self::assertSame(Command::INVALID, $tester->execute($options));
        self::assertFileDoesNotExist($this->directory.'/launched.json');
    }

    public static function invalidOptions(): iterable
    {
        yield 'zero port' => [['--port' => '0']];
        yield 'high port' => [['--port' => '65536']];
        yield 'negative port' => [['--port' => '-1']];
        yield 'noninteger port' => [['--port' => '8080.5']];
        yield 'missing port' => [['--port' => null]];
        yield 'unknown database' => [['--db' => 'invalid']];
        yield 'missing database' => [['--db' => null]];
        yield 'invalid host' => [['--host' => 'http://localhost']];
        yield 'missing host' => [['--host' => null]];
    }

    #[DataProvider('bindAddresses')]
    public function test_success_forwards_bind_address_port_and_sqlite_driver(array $options, string $host, string $url): void
    {
        $this->writeArtisan();
        $tester = new CommandTester(new StartDevCommand());

        self::assertSame(Command::SUCCESS, $tester->execute($options + ['--port' => '9090']));
        $launch = json_decode(file_get_contents($this->directory.'/launched.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['artisan', 'serve', '--port=9090', '--host='.$host], $launch['arguments']);
        self::assertSame('sqlite', $launch['database']);
        self::assertStringContainsString($url, $tester->getDisplay());
        self::assertStringNotContainsString('Server running', $tester->getDisplay());
    }

    public static function bindAddresses(): iterable
    {
        yield 'default loopback' => [[], '127.0.0.1', 'http://127.0.0.1:9090'];
        yield 'explicit all interfaces' => [['--host' => '0.0.0.0'], '0.0.0.0', 'http://0.0.0.0:9090'];
        yield 'IPv6 loopback' => [['--host' => '::1'], '::1', 'http://[::1]:9090'];
    }

    public function test_missing_docker_stops_before_the_http_process(): void
    {
        $this->writeArtisan();
        symlink(PHP_BINARY, $this->directory.'/php');
        putenv('PATH='.$this->directory);
        $tester = new CommandTester(new StartDevCommand());

        self::assertSame(Command::FAILURE, $tester->execute(['--db' => 'mysql']));
        self::assertStringContainsString('Docker Compose is required', $tester->getDisplay());
        self::assertFileDoesNotExist($this->directory.'/launched.json');
    }

    public function test_compose_failure_is_propagated_without_launching_http(): void
    {
        $this->writeArtisan();
        $this->writeDocker('fwrite(STDERR, "Missing Compose service fixture\n"); exit(17);');
        $tester = new CommandTester(new StartDevCommand());

        self::assertSame(17, $tester->execute(['--db' => 'pgsql']));
        self::assertStringContainsString('Missing Compose service fixture', $tester->getDisplay());
        self::assertFileDoesNotExist($this->directory.'/launched.json');
        self::assertStringNotContainsString('Starting Laravel HTTP server', $tester->getDisplay());
    }

    #[DataProvider('composeDatabases')]
    public function test_compose_success_precedes_http_and_selects_the_database(string $database): void
    {
        $this->writeArtisan();
        $this->writeDocker('file_put_contents("compose.json", json_encode($argv));');
        $tester = new CommandTester(new StartDevCommand());

        self::assertSame(Command::SUCCESS, $tester->execute(['--db' => $database]));
        $compose = json_decode(file_get_contents($this->directory.'/compose.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['compose', 'up', '-d', $database, 'redis'], array_slice($compose, 1));
        $launch = json_decode(file_get_contents($this->directory.'/launched.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($database, $launch['database']);
        self::assertTrue($launch['compose_started']);
    }

    public static function composeDatabases(): iterable
    {
        yield 'mysql' => ['mysql'];
        yield 'pgsql' => ['pgsql'];
    }

    private function writeArtisan(): void
    {
        file_put_contents($this->directory.'/artisan', <<<'PHP'
<?php
file_put_contents('launched.json', json_encode([
    'arguments' => $argv,
    'database' => getenv('DB_CONNECTION'),
    'compose_started' => is_file('compose.json'),
]));
PHP);
    }

    private function writeDocker(string $code): void
    {
        file_put_contents($this->directory.'/docker', '#!'.PHP_BINARY."\n<?php\n".$code);
        chmod($this->directory.'/docker', 0755);
        putenv('PATH='.$this->directory.PATH_SEPARATOR.$this->originalPath);
    }
}
