<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\EnvCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\InvalidOptionException;
use DurableWorkflow\Cli\Support\Profile;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Create or update a named dw environment profile.
 *
 * `dw env:set` merges the supplied fields into any existing profile
 * of the same name; fields that are not passed keep their previous
 * values. To clear a field, pass the empty string, e.g.
 * `--token-env=""`.
 *
 * Token sources come in two forms. The preferred form is env-var
 * indirection (`--token-env=PROD_DW_TOKEN`), which keeps the actual
 * secret out of the config file. The literal form (`--token=<value>`)
 * is supported for parity with the `--token` global flag but writes
 * the raw value to disk — use it with care.
 */
class SetCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->registerOutputOption();
        $this->setName('env:set')
            ->setDescription('Create or update a dw environment profile')
            ->setHelp(<<<'HELP'
Create or update a named dw environment profile.

<comment>Examples:</comment>

  <info>dw env:set dev --server=http://localhost:8080 --namespace=default</info>
  <info>dw env:set prod --server=https://api.example.com --namespace=orders --token-env=PROD_DW_TOKEN</info>
  <info>dw env:set staging --tls-verify=false --output=json</info>

Pass <info>--token-env=""</info> to clear a previously stored token
source. Pass <info>--make-default</info> to also run <info>dw env:use</info>
for this profile after it is written.
HELP)
            ->addArgument('name', InputArgument::REQUIRED, 'Profile name (e.g. dev, staging, prod)')
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server URL (https://... recommended in production)', null)
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Default namespace for this profile', null)
            ->addOption('token-env', null, InputOption::VALUE_REQUIRED, 'Name of the environment variable that holds the bearer token at invocation time', null)
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Literal bearer token (stored on disk; prefer --token-env)', null)
            ->addOption('tls-verify', null, InputOption::VALUE_REQUIRED, 'Verify TLS certificates (true/false)', null)
            ->addOption('profile-output', null, InputOption::VALUE_REQUIRED, 'Default --output mode for commands run against this profile (table|json|jsonl)', null)
            ->addOption('make-default', null, InputOption::VALUE_NONE, 'Also set this profile as the default (shorthand for `dw env:use <name>`)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the resulting profile as JSON (alias for --output=json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        if ($name === '') {
            throw new InvalidOptionException('Profile name must not be empty.');
        }

        $this->assertValidProfileName($name);

        $store = $this->profileStore();
        $existing = $store->get($name);

        $server = self::mergeString($input, 'server', $existing?->server);
        $namespace = self::mergeString($input, 'namespace', $existing?->namespace);
        $tokenSource = self::mergeTokenSource($input, $existing?->tokenSource);
        $tlsVerify = self::mergeTls($input, $existing?->tlsVerify ?? true);
        $outputMode = self::mergeOutputMode($input, $existing?->output);

        $profile = new Profile(
            name: $name,
            server: $server,
            namespace: $namespace,
            tokenSource: $tokenSource,
            tlsVerify: $tlsVerify,
            output: $outputMode,
        );

        $store->put($profile);

        if ($input->getOption('make-default')) {
            $store->setCurrent($name);
        }

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $profile->describe() + [
                'current' => $store->currentEnvName() === $name,
                'config_path' => $store->path(),
            ]);
        }

        $output->writeln(sprintf(
            '<info>Saved dw env [%s] to %s.</info>',
            $name,
            $store->path(),
        ));

        if ($input->getOption('make-default')) {
            $output->writeln(sprintf('<info>Set [%s] as the current dw env.</info>', $name));
        }

        return Command::SUCCESS;
    }

    private function assertValidProfileName(string $name): void
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $name)) {
            throw new InvalidOptionException(sprintf(
                'Invalid profile name [%s]: must start with a letter or digit and contain only letters, digits, dots, dashes, and underscores.',
                $name,
            ));
        }
    }

    /**
     * Return the new string value (null to clear) or the existing value when
     * the option was not passed at all. Passing `--foo=""` explicitly clears
     * the field; omitting `--foo` preserves it.
     */
    private static function mergeString(InputInterface $input, string $option, ?string $existing): ?string
    {
        if (! self::optionProvided($input, $option)) {
            return $existing;
        }

        $value = $input->getOption($option);
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Build the new {type, value} token-source tuple from --token / --token-env,
     * or return the existing tuple when neither was passed.
     *
     * @param  array{type: string, value: string}|null  $existing
     * @return array{type: 'literal'|'env', value: string}|null
     */
    private static function mergeTokenSource(InputInterface $input, ?array $existing): ?array
    {
        $tokenEnvProvided = self::optionProvided($input, 'token-env');
        $tokenProvided = self::optionProvided($input, 'token');

        if (! $tokenEnvProvided && ! $tokenProvided) {
            if ($existing === null) {
                return null;
            }
            /** @var array{type: 'literal'|'env', value: string} $existing */
            return $existing;
        }

        if ($tokenEnvProvided && $tokenProvided) {
            $tokenEnvValue = $input->getOption('token-env');
            $tokenValue = $input->getOption('token');
            if ($tokenEnvValue !== '' && $tokenValue !== '') {
                throw new InvalidOptionException('Pass either --token or --token-env, not both.');
            }
        }

        if ($tokenEnvProvided) {
            $value = $input->getOption('token-env');
            if (! is_string($value) || $value === '') {
                return null;
            }
            self::assertEnvVarName($value);
            return ['type' => Profile::TOKEN_SOURCE_ENV, 'value' => $value];
        }

        $value = $input->getOption('token');
        if (! is_string($value) || $value === '') {
            return null;
        }

        return ['type' => Profile::TOKEN_SOURCE_LITERAL, 'value' => $value];
    }

    private static function mergeTls(InputInterface $input, bool $existing): bool
    {
        if (! self::optionProvided($input, 'tls-verify')) {
            return $existing;
        }

        $value = (string) $input->getOption('tls-verify');
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new InvalidOptionException(
                '--tls-verify must be one of: true, false, yes, no, on, off, 1, 0.',
            ),
        };
    }

    private static function mergeOutputMode(InputInterface $input, ?string $existing): ?string
    {
        if (! self::optionProvided($input, 'profile-output')) {
            return $existing;
        }

        $value = $input->getOption('profile-output');
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (! in_array($value, Profile::OUTPUT_MODES, true)) {
            throw new InvalidOptionException(sprintf(
                '--profile-output must be one of: %s.',
                implode(', ', Profile::OUTPUT_MODES),
            ));
        }

        return $value;
    }

    private static function optionProvided(InputInterface $input, string $option): bool
    {
        if (! $input->hasOption($option)) {
            return false;
        }

        return $input->getOption($option) !== null;
    }

    private static function assertEnvVarName(string $name): void
    {
        if (! preg_match('/\A[A-Z_][A-Z0-9_]*\z/', $name)) {
            throw new InvalidOptionException(sprintf(
                'Invalid --token-env value [%s]: environment variable names must match /[A-Z_][A-Z0-9_]*/.',
                $name,
            ));
        }
    }
}
