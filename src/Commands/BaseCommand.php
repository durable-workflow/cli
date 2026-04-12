<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands;

use DurableWorkflow\Cli\Support\ServerClient;
use Symfony\Component\Console\Command\Command;
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

    protected function renderTable(OutputInterface $output, array $headers, array $rows): void
    {
        $table = new \Symfony\Component\Console\Helper\Table($output);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();
    }
}
