<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\EnvCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Set the default dw env profile. Subsequent commands that do not
 * pass `--env` and do not set `DW_ENV` will resolve through this
 * profile first. Hard-fails if the profile does not exist — a
 * typoed name should not silently leave the previous env active.
 */
class UseCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->registerOutputOption();
        $this->setName('env:use')
            ->setDescription('Set the default dw environment profile')
            ->setHelp(<<<'HELP'
Persist <info>name</info> as the default dw environment profile.

Subsequent commands use this profile unless the caller passes an
explicit <info>--env</info> or sets the <info>DW_ENV</info> environment
variable. Unknown profile names are rejected — use
<info>dw env:list</info> to see the available profiles.

<comment>Examples:</comment>

  <info>dw env:use dev</info>
  <info>dw env:use prod --json</info>
HELP)
            ->addArgument('name', InputArgument::REQUIRED, 'Profile name to use by default')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the resulting profile as JSON (alias for --output=json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->profileStore();
        $name = (string) $input->getArgument('name');
        $profile = $store->setCurrent($name);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $profile->describe() + [
                'current' => true,
                'config_path' => $store->path(),
            ]);
        }

        $output->writeln(sprintf('<info>Using dw env [%s].</info>', $name));
        if ($profile->server !== null) {
            $output->writeln(sprintf('  server: %s', $profile->server));
        }
        if ($profile->namespace !== null) {
            $output->writeln(sprintf('  namespace: %s', $profile->namespace));
        }

        return Command::SUCCESS;
    }
}
