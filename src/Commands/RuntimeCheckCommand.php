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

    /**
     * @var callable(string): bool
     */
    private mixed $extensionLoaded;

    /**
     * @param  (callable(string): bool)|null  $extensionLoaded
     */
    public function __construct(?callable $extensionLoaded = null)
    {
        parent::__construct();

        $this->extensionLoaded = $extensionLoaded ?? extension_loaded(...);
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

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
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
            implode(', ', self::REQUIRED_EXTENSIONS),
        ));

        return ExitCode::SUCCESS;
    }
}
