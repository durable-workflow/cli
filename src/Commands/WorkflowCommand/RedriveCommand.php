<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RedriveCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:redrive')
            ->setDescription('Continue a failed workflow run from its failed activity')
            ->setHelp(<<<'HELP'
Create a new run that reuses recorded successful activity results and retries
the failed activity. The failed source run remains closed and readable. Runs
without a verified failure boundary are refused.

<comment>Examples:</comment>

  <info>dw workflow:redrive chk-42 01HZ...</info>
  <info>dw workflow:redrive chk-42 01HZ... --request-id incident-42 --json</info>
HELP)
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addArgument('run-id', InputArgument::REQUIRED, 'Failed run ID')
            ->addOption('request-id', null, InputOption::VALUE_REQUIRED, 'Idempotency key for retrying this request')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the command response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = (string) $input->getArgument('workflow-id');
        $runId = (string) $input->getArgument('run-id');
        $requestId = $input->getOption('request-id');
        $body = is_string($requestId) && $requestId !== '' ? ['request_id' => $requestId] : [];
        $result = $this->addNamespaceContext(
            $input,
            $this->client($input)->post("/workflows/{$workflowId}/runs/{$runId}/redrive", $body),
        );

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Redrive requested</info>');
        $output->writeln('  Workflow ID: '.($result['workflow_id'] ?? $workflowId));
        $this->writeNamespaceLine($output, $result);
        $output->writeln('  Source Run ID: '.($result['continued_from_run_id'] ?? $runId));
        $output->writeln('  New Run ID: '.($result['run_id'] ?? '-'));
        $output->writeln('  Resume Step: '.($result['resume_step_sequence'] ?? '-'));

        return Command::SUCCESS;
    }
}
