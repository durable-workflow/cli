<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ArchiveCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:archive')
            ->setDescription('Archive a terminal workflow run')
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addOption('reason', null, InputOption::VALUE_OPTIONAL, 'Archive reason');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow-id');

        $body = array_filter([
            'reason' => $input->getOption('reason'),
        ], fn ($v) => $v !== null);

        $result = $this->client($input)->post("/workflows/{$workflowId}/archive", $body);

        $output->writeln('<info>Archive requested</info>');
        $output->writeln('  Workflow ID: '.$result['workflow_id']);
        $output->writeln('  Outcome: '.$result['outcome']);
        if (isset($result['command_status'])) {
            $output->writeln('  Command Status: '.$result['command_status']);
        }
        if (isset($result['command_id'])) {
            $output->writeln('  Command ID: '.$result['command_id']);
        }

        return Command::SUCCESS;
    }
}
