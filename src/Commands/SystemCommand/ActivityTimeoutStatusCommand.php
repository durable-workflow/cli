<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\SystemCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityTimeoutStatusCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('system:activity-timeout-status')
            ->setDescription('Show expired activity execution diagnostics')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->client($input)->get('/system/activity-timeouts');

        if ($input->getOption('json')) {
            return $this->renderJson($output, $result);
        }

        $count = $result['expired_count'] ?? 0;
        $tag = $count > 0 ? 'comment' : 'info';
        $output->writeln(sprintf('<%s>Expired Activity Executions: %d</%s>', $tag, $count, $tag));
        $output->writeln(sprintf('  Scan limit:     %d', $result['scan_limit'] ?? 0));
        $output->writeln(sprintf('  Scan pressure:  %s', ($result['scan_pressure'] ?? false) ? 'yes' : 'no'));

        $ids = $result['expired_execution_ids'] ?? [];
        if ($ids !== []) {
            $output->writeln('');
            $output->writeln('<info>Expired Execution IDs</info>');
            foreach ($ids as $id) {
                $output->writeln(sprintf('  %s', $id));
            }
        }

        return Command::SUCCESS;
    }
}
