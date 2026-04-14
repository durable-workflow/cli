<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\SystemCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RetentionStatusCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('system:retention-status')
            ->setDescription('Show history retention diagnostics for the current namespace')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client($input)->get('/system/retention');

        if ($input->getOption('json')) {
            return $this->renderJson($output, $result);
        }

        $count = $result['expired_run_count'] ?? 0;
        $tag = $count > 0 ? 'comment' : 'info';

        $output->writeln(sprintf('<info>History Retention</info>'));
        $output->writeln(sprintf('  Namespace:      %s', $result['namespace'] ?? 'unknown'));
        $output->writeln(sprintf('  Retention days: %d', $result['retention_days'] ?? 0));
        $output->writeln(sprintf('  Cutoff:         %s', $result['cutoff'] ?? 'unknown'));
        $output->writeln(sprintf('<%s>  Expired runs:   %d</%s>', $tag, $count, $tag));
        $output->writeln(sprintf('  Scan limit:     %d', $result['scan_limit'] ?? 0));
        $output->writeln(sprintf('  Scan pressure:  %s', ($result['scan_pressure'] ?? false) ? 'yes' : 'no'));

        $ids = $result['expired_run_ids'] ?? [];
        if ($ids !== []) {
            $output->writeln('');
            $output->writeln('<info>Expired Run IDs</info>');
            foreach ($ids as $id) {
                $output->writeln(sprintf('  %s', $id));
            }
        }

        return Command::SUCCESS;
    }
}
