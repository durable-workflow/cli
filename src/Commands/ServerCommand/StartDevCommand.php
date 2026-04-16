<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ServerCommand;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class StartDevCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('server:start-dev')
            ->setDescription('Start a local development server with all dependencies')
            ->setHelp(<<<'HELP'
Boot a local development server for exploration. Uses SQLite by
default so no external services are required; switch to
<comment>mysql</comment> or <comment>pgsql</comment> to bring up
Docker-backed dependencies.

<comment>Examples:</comment>

  <info>dw server:start-dev</info>
  <info>dw server:start-dev --port=9000</info>
  <info>dw server:start-dev --db=mysql</info>
HELP)
            ->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'Server port', '8080')
            ->addOption('db', null, InputOption::VALUE_OPTIONAL, 'Database driver (sqlite, mysql, pgsql)', 'sqlite');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $port = $input->getOption('port');
        $db = $input->getOption('db');

        $output->writeln('<info>Starting Durable Workflow development server...</info>');
        $output->writeln("  Port: {$port}");
        $output->writeln("  Database: {$db}");
        $output->writeln('');

        if ($db === 'sqlite') {
            $output->writeln('Using SQLite — no external database required.');
            $output->writeln('');
        } else {
            $output->writeln("Make sure {$db} is running and configured in .env");
            $output->writeln('');
        }

        // Check if docker compose is available for non-sqlite
        if ($db !== 'sqlite') {
            $output->writeln('Starting dependencies with Docker Compose...');
            $compose = new Process(['docker', 'compose', 'up', '-d', $db, 'redis']);
            $compose->setTimeout(120);
            $compose->run(function ($type, $buffer) use ($output) {
                $output->write($buffer);
            });
        }

        $output->writeln("<info>Server running at http://localhost:{$port}</info>");
        $output->writeln('Press Ctrl+C to stop.');
        $output->writeln('');

        // Start the PHP development server
        $server = new Process([
            'php', 'artisan', 'serve', '--port='.$port, '--host=0.0.0.0',
        ]);
        $server->setTimeout(null);
        $server->setTty(Process::isTtySupported());
        $server->run(function ($type, $buffer) use ($output) {
            $output->write($buffer);
        });

        return Command::SUCCESS;
    }
}
