<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands;

use DurableWorkflow\Cli\Support\ExitCode;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RuntimeCheckCommand extends Command
{
    public const REQUIRED_EXTENSIONS = [
        'curl',
        'mbstring',
        'openssl',
        'phar',
        'tokenizer',
        'ctype',
        'filter',
        'fileinfo',
        'iconv',
        'sockets',
    ];

    public const REQUIRED_EXTENSIONS_WINDOWS = [
        'mbstring',
        'openssl',
        'phar',
        'tokenizer',
        'ctype',
        'filter',
        'fileinfo',
        'iconv',
        'sockets',
    ];

    /**
     * @var callable(string): bool
     */
    private mixed $extensionLoaded;

    private string $osFamily;

    /**
     * @param  (callable(string): bool)|null  $extensionLoaded
     */
    public function __construct(?callable $extensionLoaded = null, ?string $osFamily = null)
    {
        parent::__construct();

        $this->extensionLoaded = $extensionLoaded ?? extension_loaded(...);
        $this->osFamily = $osFamily ?? PHP_OS_FAMILY;
    }

    protected function configure(): void
    {
        $this->setName('runtime:check')
            ->setHidden(true)
            ->setDescription('Validate standalone runtime capabilities')
            ->setHelp(<<<'HELP'
Validate that a packaged standalone `dw` binary has the PHP extensions
required by the published release contract.

<comment>Examples:</comment>

  <info>dw runtime:check</info>
  <info>dw runtime:check >/tmp/dw-runtime.txt</info>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $missing = [];
        $requiredExtensions = $this->requiredExtensions();

        foreach ($requiredExtensions as $extension) {
            if (! ($this->extensionLoaded)($extension)) {
                $missing[] = $extension;
            }
        }

        if ($missing !== []) {
            $output->writeln(sprintf('Missing required runtime extensions: %s', implode(', ', $missing)));

            return ExitCode::FAILURE;
        }

        $output->writeln(sprintf(
            'Runtime extensions OK: %s',
            implode(', ', $requiredExtensions),
        ));

        return ExitCode::SUCCESS;
    }

    /**
     * @return list<string>
     */
    public function requiredExtensions(): array
    {
        if ($this->osFamily === 'Windows') {
            return self::REQUIRED_EXTENSIONS_WINDOWS;
        }

        return self::REQUIRED_EXTENSIONS;
    }
}
