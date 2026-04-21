<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\EnvCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\InvalidOptionException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Show details for a dw environment profile. Without an argument,
 * shows the profile selected via `dw env:use`; `dw env:show <name>`
 * targets a specific profile. Literal token values are redacted
 * unless `--show-token` is passed.
 */
class ShowCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->registerOutputOption();
        $this->setName('env:show')
            ->setDescription('Show details for a dw environment profile')
            ->setHelp(<<<'HELP'
Show the details of one dw environment profile.

Without an argument, prints the profile currently selected via
<info>dw env:use</info>. Literal token values are redacted in the
default output; pass <info>--show-token</info> to reveal them.

<comment>Examples:</comment>

  <info>dw env:show</info>
  <info>dw env:show prod</info>
  <info>dw env:show prod --show-token --json</info>
HELP)
            ->addArgument('name', InputArgument::OPTIONAL, 'Profile name (defaults to the current env)')
            ->addOption('show-token', null, InputOption::VALUE_NONE, 'Reveal literal token values (env-var indirection is always shown as a name)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the profile as JSON (alias for --output=json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->profileStore();
        $name = $input->getArgument('name');

        if ($name === null || $name === '') {
            $name = $store->currentEnvName();
            if ($name === null) {
                throw new InvalidOptionException(
                    'No default dw env is set. Pass a profile name or run `dw env:use <name>`.',
                );
            }
        }

        $profile = $store->requireProfile((string) $name, 'env:show');
        $showToken = (bool) $input->getOption('show-token');
        $envelope = $profile->describe($showToken) + [
            'current' => $store->currentEnvName() === $profile->name,
            'config_path' => $store->path(),
        ];

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $envelope);
        }

        $rows = [
            ['name', (string) $envelope['name']],
            ['current', $envelope['current'] ? 'yes' : 'no'],
            ['server', $envelope['server'] ?? '-'],
            ['namespace', $envelope['namespace'] ?? '-'],
            ['tls_verify', ($envelope['tls_verify'] ?? true) ? 'yes' : 'no'],
            ['output', $envelope['output'] ?? '-'],
            ['token_source', self::describeTokenSource($envelope['token_source'] ?? null)],
            ['config_path', (string) $envelope['config_path']],
        ];

        $this->renderTable($output, ['Field', 'Value'], $rows);

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
