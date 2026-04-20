<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\SystemCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ActivityTimeoutPassCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('system:activity-timeout-pass')
            ->setDescription('Run one activity timeout enforcement sweep on the server')
            ->setHelp(<<<'HELP'
Force the server to enforce activity timeouts for any executions that
have already passed their deadline.

<comment>Examples:</comment>

  <info>dw system:activity-timeout-pass</info>
  <info>dw system:activity-timeout-pass --limit=100</info>
  <info>dw system:activity-timeout-pass --execution-id=act-123</info>
HELP)
            ->addOption('execution-id', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Limit to specific execution IDs')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum executions to process')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $body = [];

        $executionIds = $input->getOption('execution-id');
        if (is_array($executionIds) && $executionIds !== []) {
            $body['execution_ids'] = $executionIds;
        }

        $limit = $input->getOption('limit');
        if ($limit !== null) {
            $body['limit'] = (int) $limit;
        }

        $result = $this->client($input)->post('/system/activity-timeouts/pass', $body);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Activity timeout enforcement pass completed</info>');
        $output->writeln(sprintf('  Processed:  %d', $result['processed'] ?? 0));
        $output->writeln(sprintf('  Enforced:   %d', $result['enforced'] ?? 0));
        $output->writeln(sprintf('  Skipped:    %d', $result['skipped'] ?? 0));
        $output->writeln(sprintf('  Failed:     %d', $result['failed'] ?? 0));

        $hasFailures = false;

        foreach ($result['results'] ?? [] as $entry) {
            if (($entry['outcome'] ?? '') === 'error') {
                $output->writeln(sprintf(
                    '<error>  %s: %s</error>',
                    $entry['execution_id'] ?? '?',
                    $entry['reason'] ?? 'unknown error',
                ));
                $hasFailures = true;
            }
        }

        return $hasFailures ? Command::FAILURE : Command::SUCCESS;
    }
}
