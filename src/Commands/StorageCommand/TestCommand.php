<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\StorageCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\CompletionValues;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TestCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('storage:test')
            ->setDescription('Run an external payload storage round-trip diagnostic')
            ->setHelp(<<<'HELP'
Ask the server to round-trip a small payload and an over-threshold
payload through the selected namespace's external storage policy.

<comment>Examples:</comment>

  <info>dw storage:test --namespace=billing --large-bytes=2097152</info>
  <info>dw storage:test --driver=s3 --small-bytes=128 --large-bytes=3145728 --json</info>
HELP)
            ->addOption('driver', null, InputOption::VALUE_OPTIONAL, 'Driver override for this diagnostic', null, CompletionValues::EXTERNAL_STORAGE_DRIVERS)
            ->addOption('small-bytes', null, InputOption::VALUE_OPTIONAL, 'Small inline payload size for the round trip', '128')
            ->addOption('large-bytes', null, InputOption::VALUE_OPTIONAL, 'Large offloaded payload size for the round trip', '2097152')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $body = [
            'small_payload_bytes' => $this->positiveInteger($input->getOption('small-bytes'), '--small-bytes'),
            'large_payload_bytes' => $this->positiveInteger($input->getOption('large-bytes'), '--large-bytes'),
        ];

        $driver = $this->stringOption($input, 'driver');
        if ($driver !== null) {
            $body['driver'] = $this->storageDriver($driver);
        }

        $result = $this->client($input)->post('/storage/test', $body);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>External storage round trip: '.($result['status'] ?? 'unknown').'</info>');
        $output->writeln('  Driver: '.($result['driver'] ?? $driver ?? 'configured'));

        foreach (['small_payload' => 'Small payload', 'large_payload' => 'Large payload'] as $key => $label) {
            $payload = is_array($result[$key] ?? null) ? $result[$key] : [];
            $details = array_filter([
                'status='.($payload['status'] ?? 'unknown'),
                isset($payload['bytes']) ? 'bytes='.$payload['bytes'] : null,
                isset($payload['sha256']) ? 'sha256='.$payload['sha256'] : null,
                isset($payload['reference_uri']) ? 'reference='.$payload['reference_uri'] : null,
            ]);

            $output->writeln('  '.$label.': '.implode(' ', $details));
        }

        return Command::SUCCESS;
    }

    private function positiveInteger(mixed $value, string $option): int
    {
        if (! is_scalar($value) || ! preg_match('/^[1-9][0-9]*$/', (string) $value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a positive integer.', $option));
        }

        return (int) $value;
    }

    private function storageDriver(string $driver): string
    {
        if (! in_array($driver, CompletionValues::EXTERNAL_STORAGE_DRIVERS, true)) {
            throw new \InvalidArgumentException('driver must be one of: '.implode(', ', CompletionValues::EXTERNAL_STORAGE_DRIVERS).'.');
        }

        return $driver;
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
