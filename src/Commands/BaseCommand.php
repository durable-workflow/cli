<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands;

use DurableWorkflow\Cli\Support\ExitCode;
use DurableWorkflow\Cli\Support\ServerClient;
use DurableWorkflow\Cli\Support\ServerException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BaseCommand extends Command
{
    private ?ServerClient $serverClient = null;

    protected function configure(): void
    {
        $this->addOption('server', 's', InputOption::VALUE_OPTIONAL, 'Server URL', null);
        $this->addOption('namespace', null, InputOption::VALUE_OPTIONAL, 'Target namespace', null);
        $this->addOption('token', null, InputOption::VALUE_OPTIONAL, 'Auth token', null);
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (ConsoleException $e) {
            throw $e;
        } catch (ServerException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return $e->exitCode();
        } catch (\Throwable $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return ExitCode::FAILURE;
        }
    }

    protected function client(InputInterface $input): ServerClient
    {
        if ($this->serverClient instanceof ServerClient) {
            return $this->serverClient;
        }

        return new ServerClient(
            baseUrl: $input->getOption('server'),
            token: $input->getOption('token'),
            namespace: $input->getOption('namespace'),
        );
    }

    public function setServerClient(ServerClient $serverClient): void
    {
        $this->serverClient = $serverClient;
    }

    protected function validateControlPlaneOption(
        ServerClient $client,
        OutputInterface $output,
        string $operation,
        string $field,
        ?string $value,
        string $optionName,
    ): bool {
        if ($value === null) {
            return true;
        }

        try {
            $client->assertControlPlaneOptionValue(
                operation: $operation,
                field: $field,
                value: $value,
                optionName: $optionName,
            );
        } catch (\RuntimeException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return false;
        }

        return true;
    }

    protected function renderJson(OutputInterface $output, array $data): int
    {
        $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }

    /**
     * Declare --json on a mutating command. Pair with {@see wantsJson()} in
     * execute() to return the raw server response instead of the human-readable
     * summary.
     */
    protected function addJsonOption(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function wantsJson(InputInterface $input): bool
    {
        return $input->hasOption('json') && $input->getOption('json') === true;
    }

    protected function renderTable(OutputInterface $output, array $headers, array $rows): void
    {
        $table = new \Symfony\Component\Console\Helper\Table($output);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();
    }
}
