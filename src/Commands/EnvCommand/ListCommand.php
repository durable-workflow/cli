<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\EnvCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * List every configured dw environment profile. Marks the one
 * currently selected via `dw env:use`, and redacts literal token
 * values by default so `dw env:list --json` is safe to log.
 */
class ListCommand extends BaseCommand
{
    public function emitsSessionCompatibilityWarning(): bool
    {
        return false;
    }

    protected function configure(): void
    {
        $this->registerOutputOption();
        $this->setName('env:list')
            ->setDescription('List configured dw environment profiles')
            ->setHelp(<<<'HELP'
List every dw environment profile configured on this host.

Literal token values are redacted in the default output; pass
<info>--show-token</info> when you need to audit a profile.

<comment>Examples:</comment>

  <info>dw env:list</info>
  <info>dw env:list --output=json | jq '.envs[].name'</info>
  <info>dw env:list --output=jsonl | jq '.name'</info>
  <info>dw env:list --show-token</info>
HELP)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON (alias for --output=json)')
            ->addOption('show-token', null, InputOption::VALUE_NONE, 'Reveal literal token values (env-var indirection is always shown as a name)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->profileStore();
        $profiles = $store->all();
        ksort($profiles);

        $current = $store->currentEnvName();
        $showToken = (bool) $input->getOption('show-token');

        $envelope = [
            'current_env' => $current,
            'config_path' => $store->path(),
            'envs' => array_values(array_map(
                static fn ($profile) => $profile->describe($showToken) + [
                    'current' => $profile->name === $current,
                ],
                $profiles,
            )),
        ];

        if ($this->wantsJson($input)) {
            return $this->renderJsonList($output, $input, $envelope, 'envs');
        }

        if ($envelope['envs'] === []) {
            $output->writeln('<comment>No dw environments configured.</comment>');
            $output->writeln('<comment>Create one with `dw env:set <name> --server=https://... --namespace=<ns>`.</comment>');

            return Command::SUCCESS;
        }

        $rows = array_map(
            static fn (array $env) => [
                $env['current'] ? '*' : '',
                $env['name'],
                $env['server'] ?? '-',
                $env['namespace'] ?? '-',
                self::describeTokenSource($env['token_source'] ?? null),
                ($env['tls_verify'] ?? true) ? 'yes' : 'no',
                $env['output'] ?? '-',
            ],
            $envelope['envs'],
        );

        $this->renderTable(
            $output,
            ['Current', 'Name', 'Server', 'Namespace', 'Token', 'TLS Verify', 'Output'],
            $rows,
        );

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, mixed>|null  $tokenSource
     */
    private static function describeTokenSource(?array $tokenSource): string
    {
        if ($tokenSource === null) {
            return '-';
        }

        $type = $tokenSource['type'] ?? null;
        if ($type === 'env') {
            return 'env:'.($tokenSource['env'] ?? '?');
        }

        if ($type === 'literal') {
            return 'literal('.($tokenSource['value'] ?? 'redacted').')';
        }

        return '-';
    }
}
