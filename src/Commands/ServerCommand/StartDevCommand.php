<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ServerCommand;

use DurableWorkflow\Cli\Support\CompletionValues;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SignalRegistry\SignalRegistry;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class StartDevCommand extends Command implements SignalableCommandInterface
{
    private ?Process $activeProcess = null;
    private ?int $shutdownDeadline = null;
    private ?int $shutdownSignal = null;

    public function getSubscribedSignals(): array
    {
        return SignalRegistry::isSupported() ? [SIGINT, SIGTERM] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->shutdownSignal ??= $signal;
        if ($this->activeProcess?->isRunning()) {
            $pid = $this->activeProcess->getPid();
            $group = $pid !== null && function_exists('posix_getpgid') && posix_getpgid($pid) === $pid ? $pid : null;
            if ($group !== null) {
                posix_kill(-$group, $signal);
            } else {
                $this->activeProcess->signal($signal);
            }
            $deadline = $this->shutdownDeadline ??= hrtime(true) + 5_000_000_000;
            while (hrtime(true) < $deadline) {
                $running = $this->activeProcess->isRunning();
                if (!$running && ($group === null || !posix_kill(-$group, 0))) {
                    break;
                }
                usleep(10000);
            }
            if ($group !== null && posix_kill(-$group, 0)) {
                posix_kill(-$group, SIGKILL);
            }
            if ($this->activeProcess->isRunning()) {
                $this->activeProcess->stop(0);
            }
        }

        return 128 + $this->shutdownSignal;
    }

    private function childProcess(array $command, string $directory, array $environment = []): Process
    {
        if (SignalRegistry::isSupported() && function_exists('posix_setsid')) {
            // phpmicro provides its own absolute path because PHP_BINARY is empty.
            $launcher = function_exists('micro_get_self_filename')
                ? [micro_get_self_filename()]
                : [PHP_BINARY, (class_exists(\Phar::class) ? \Phar::running(false) : '') ?: dirname(__DIR__, 3).'/bin/dw'];
            return new Process([...$launcher, ...$command], $directory, $environment + ['DW_CLI_EXEC_CHILD' => '1']);
        }

        return new Process($command, $directory, $environment);
    }

    protected function configure(): void
    {
        $this->setName('server:start-dev')
            ->setDescription('Serve an existing Laravel project for local development')
            ->setHelp(<<<'HELP'
Run this command from an installed, configured Laravel project's directory.
An external PHP executable is required, even when using the native dw binary.
This launches Artisan's HTTP server. Configure the database and run migrations
first, and run the project's queue worker and scheduler separately when needed.
Restart this command after editing the project's .env configuration.

SQLite is the default database driver. For <comment>mysql</comment> or
<comment>pgsql</comment>, your project's Docker Compose configuration must
define that service and <comment>redis</comment>. Dependencies are left running
when the HTTP process stops. The bind address defaults to loopback.

For a standalone Durable Workflow Server, follow the Docker-backed quickstart:
https://durable-workflow.com/docs/quickstart/

<comment>Examples:</comment>

  <info>dw server:start-dev</info>
  <info>dw server:start-dev --port=9000</info>
  <info>dw server:start-dev --db=mysql</info>
HELP)
            ->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'Server port', '8080')
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Bind address (IP address or localhost)', '127.0.0.1')
            ->addOption('db', null, InputOption::VALUE_OPTIONAL, 'Database driver (sqlite, mysql, pgsql)', 'sqlite', CompletionValues::DEV_DATABASES);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $port = $input->getOption('port');
        $db = $input->getOption('db');
        $host = $input->getOption('host');

        if (!is_string($port) || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            $output->writeln('<error>--port must be an integer between 1 and 65535.</error>');
            return Command::INVALID;
        }
        if (!in_array($db, CompletionValues::DEV_DATABASES, true)) {
            $output->writeln('<error>--db must be sqlite, mysql or pgsql.</error>');
            return Command::INVALID;
        }
        if (!is_string($host) || ($host !== 'localhost' && filter_var($host, FILTER_VALIDATE_IP) === false)) {
            $output->writeln('<error>--host must be an IP address or localhost.</error>');
            return Command::INVALID;
        }

        $directory = getcwd();
        if ($directory === false || !is_file($directory.'/artisan') || !is_readable($directory.'/artisan')) {
            $output->writeln('<error>No readable artisan file. Run this command from an installed, configured Laravel project.</error>');
            $output->writeln('Standalone Server quickstart: https://durable-workflow.com/docs/quickstart/');
            return Command::FAILURE;
        }

        $finder = new ExecutableFinder();
        $php = $finder->find('php');
        if ($php === null) {
            $output->writeln('<error>An external PHP executable is required on PATH to run artisan.</error>');
            return Command::FAILURE;
        }

        $stream = static function ($type, $buffer) use ($output): void {
            $output->write($buffer);
        };

        try {
            if ($db !== 'sqlite') {
                $docker = $finder->find('docker');
                if ($docker === null) {
                    $output->writeln('<error>Docker Compose is required for mysql or pgsql mode. Install Docker and configure the project services.</error>');
                    return Command::FAILURE;
                }
                $output->writeln("Starting the project's {$db} and redis Compose services...");
                $compose = $this->childProcess([$docker, 'compose', 'up', '-d', $db, 'redis'], $directory);
                $this->activeProcess = $compose;
                $compose->setTimeout(120);
                $exitCode = $compose->run($stream);
                if ($exitCode !== Command::SUCCESS) {
                    $output->writeln('<error>Dependencies failed to start. Check the project Compose configuration and diagnostics above.</error>');
                    return $exitCode;
                }
            }

            $address = str_contains($host, ':') ? '['.$host.']' : $host;
            $output->writeln("<info>Starting Laravel HTTP server at http://{$address}:{$port}</info>");
            $output->writeln("Database driver: {$db}. Press Ctrl+C to stop the HTTP process.");
            $server = $this->childProcess([
                $php, 'artisan', 'serve', '--no-reload', '--port='.$port, '--host='.$host,
            ], $directory, ['DB_CONNECTION' => $db]);
            $server->setTimeout(null);
            $this->activeProcess = $server;
            $server->setTty(Process::isTtySupported());
            $exitCode = $server->run($stream);
            if ($exitCode !== Command::SUCCESS) {
                $output->writeln('<error>Laravel HTTP process failed. Check the project configuration and diagnostics above.</error>');
            }
            return $exitCode;
        } catch (ExceptionInterface $exception) {
            $output->writeln('<error>Development startup failed.</error>');
            $output->writeln($exception->getMessage(), OutputInterface::OUTPUT_RAW);
            return Command::FAILURE;
        }
    }
}
