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
 * Remove a dw environment profile. If the deleted profile was the
 * current default, the default is cleared rather than silently
 * falling back to another profile — matching the hard-fail rule
 * for the rest of the env surface.
 */
class DeleteCommand extends BaseCommand
{
    public function emitsSessionCompatibilityWarning(): bool
    {
        return false;
    }

    protected function configure(): void
    {
        $this->registerOutputOption();
        $this->setName('env:delete')
            ->setDescription('Delete a dw environment profile')
            ->setHelp(<<<'HELP'
Delete a named dw environment profile from the config file.

If the deleted profile is the current default (set via
<info>dw env:use</info>), the default is cleared — the next command
without <info>--env</info> and without <info>DW_ENV</info> will fall
back to environment variables and the built-in defaults.

<comment>Examples:</comment>

  <info>dw env:delete staging</info>
HELP)
            ->addArgument('name', InputArgument::REQUIRED, 'Profile name to delete')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the deletion result as JSON (alias for --output=json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->profileStore();
        $name = (string) $input->getArgument('name');
        $previousCurrent = $store->currentEnvName();
        $removed = $store->delete($name);

        $envelope = [
            'name' => $removed->name,
            'deleted' => true,
            'cleared_current_env' => $previousCurrent === $name,
            'config_path' => $store->path(),
        ];

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $envelope);
        }

        $output->writeln(sprintf('<info>Deleted dw env [%s].</info>', $name));
        if ($envelope['cleared_current_env']) {
            $output->writeln('<comment>Cleared the current dw env.</comment>');
        }

        return Command::SUCCESS;
    }
}
