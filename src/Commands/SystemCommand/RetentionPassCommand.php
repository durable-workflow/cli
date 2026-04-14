<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\SystemCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RetentionPassCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('system:retention-pass')
            ->setDescription('Run one history retention enforcement sweep on the server')
            ->addOption('run-id', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Limit to specific run IDs')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum runs to process')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $body = [];

        $runIds = $input->getOption('run-id');
        if (is_array($runIds) && $runIds !== []) {
            $body['run_ids'] = $runIds;
        }

        $limit = $input->getOption('limit');
        if ($limit !== null) {
            $body['limit'] = (int) $limit;
        }

        $result = $this->client($input)->post('/system/retention/pass', $body);

        if ($input->getOption('json')) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Retention enforcement pass completed</info>');
        $output->writeln(sprintf('  Processed:  %d', $result['processed'] ?? 0));
        $output->writeln(sprintf('  Pruned:     %d', $result['pruned'] ?? 0));
        $output->writeln(sprintf('  Skipped:    %d', $result['skipped'] ?? 0));
        $output->writeln(sprintf('  Failed:     %d', $result['failed'] ?? 0));

        $hasFailures = false;

        foreach ($result['results'] ?? [] as $entry) {
            if (($entry['outcome'] ?? '') === 'error') {
                $output->writeln(sprintf(
                    '<error>  %s: %s</error>',
                    $entry['run_id'] ?? '?',
                    $entry['reason'] ?? 'unknown error',
                ));
                $hasFailures = true;
            }
        }

        return $hasFailures ? Command::FAILURE : Command::SUCCESS;
    }
}
