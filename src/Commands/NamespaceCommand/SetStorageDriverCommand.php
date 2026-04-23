<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\NamespaceCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\CompletionValues;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SetStorageDriverCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('namespace:set-storage-driver')
            ->setDescription('Configure external payload storage for a namespace')
            ->setHelp(<<<'HELP'
Configure the external payload storage policy that the server applies
when encoded workflow payloads exceed the namespace threshold.

<comment>Examples:</comment>

  <info>dw namespace:set-storage-driver billing s3 --disk=external-payload-objects --bucket=dw-payloads --prefix=billing/ --threshold-bytes=2097152</info>
  <info>dw namespace:set-storage-driver dev local --uri=file:///var/lib/durable-workflow/payloads --json</info>
HELP)
            ->addArgument('name', InputArgument::REQUIRED, 'Namespace name')
            ->addArgument('driver', InputArgument::REQUIRED, 'Storage driver (local, s3, gcs, azure)', null, CompletionValues::EXTERNAL_STORAGE_DRIVERS)
            ->addOption('threshold-bytes', null, InputOption::VALUE_OPTIONAL, 'Encoded payload size threshold before offload')
            ->addOption('uri', null, InputOption::VALUE_OPTIONAL, 'Driver URI for local/dev or externally managed storage')
            ->addOption('disk', null, InputOption::VALUE_OPTIONAL, 'Server-side filesystem disk name backing the s3, gcs, or azure driver')
            ->addOption('bucket', null, InputOption::VALUE_OPTIONAL, 'Object storage bucket or container name')
            ->addOption('prefix', null, InputOption::VALUE_OPTIONAL, 'Object key prefix for this namespace')
            ->addOption('region', null, InputOption::VALUE_OPTIONAL, 'Object storage region')
            ->addOption('endpoint', null, InputOption::VALUE_OPTIONAL, 'Custom object storage endpoint')
            ->addOption('auth-profile', null, InputOption::VALUE_OPTIONAL, 'Server-side credential profile name')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Disable external payload storage while keeping the policy record')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $body = [
            'driver' => $this->storageDriver($input->getArgument('driver')),
            'enabled' => ! (bool) $input->getOption('disable'),
        ];

        $threshold = $input->getOption('threshold-bytes');
        if ($threshold !== null) {
            $body['threshold_bytes'] = $this->positiveInteger($threshold, '--threshold-bytes');
        }

        $config = array_filter([
            'uri' => $this->stringOption($input, 'uri'),
            'disk' => $this->stringOption($input, 'disk'),
            'bucket' => $this->stringOption($input, 'bucket'),
            'prefix' => $this->stringOption($input, 'prefix'),
            'region' => $this->stringOption($input, 'region'),
            'endpoint' => $this->stringOption($input, 'endpoint'),
            'auth_profile' => $this->stringOption($input, 'auth-profile'),
        ], static fn (?string $value): bool => $value !== null);

        if ($config !== []) {
            $body['config'] = $config;
        }

        $result = $this->client($input)->put("/namespaces/{$name}/external-storage", $body);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $policy = is_array($result['external_payload_storage'] ?? null)
            ? $result['external_payload_storage']
            : $result;

        $output->writeln('<info>External storage updated for namespace: '.($result['name'] ?? $name).'</info>');
        $output->writeln('  Driver: '.($policy['driver'] ?? $body['driver']));
        $output->writeln('  Enabled: '.$this->yesNo($policy['enabled'] ?? $body['enabled']));

        if (($policy['threshold_bytes'] ?? null) !== null) {
            $output->writeln('  Threshold: '.$policy['threshold_bytes'].' bytes');
        }

        $policyConfig = is_array($policy['config'] ?? null) ? $policy['config'] : $config;
        if ($policyConfig !== []) {
            $output->writeln('  Config keys: '.implode(', ', array_keys($policyConfig)));
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

    private function storageDriver(mixed $value): string
    {
        if (! is_scalar($value)) {
            throw new \InvalidArgumentException('driver must be one of: '.implode(', ', CompletionValues::EXTERNAL_STORAGE_DRIVERS).'.');
        }

        $driver = trim((string) $value);
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

    private function yesNo(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no';
    }
}
