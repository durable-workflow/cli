<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:update')
            ->setDescription('Send an update to a workflow')
            ->setHelp(<<<'HELP'
Send a synchronous update to a workflow and wait for the outcome.
<comment>--wait=accepted</comment> returns when the workflow has
validated the update; <comment>--wait=completed</comment> returns when
the handler has finished and a result is available.

<comment>Examples:</comment>

  <info>dw workflow:update chk-42 increase_quota -i '{"by":10}'</info>
  <info>dw workflow:update chk-42 increase_quota -i '{"by":10}' --wait=completed</info>
HELP)
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addArgument('update-name', InputArgument::REQUIRED, 'Update name')
            ->addOption('input', 'i', InputOption::VALUE_OPTIONAL, 'Update input JSON')
            ->addOption('wait', null, InputOption::VALUE_OPTIONAL, 'Wait policy (accepted, completed)', 'accepted')
            ->addOption('run-id', null, InputOption::VALUE_OPTIONAL, 'Target a specific run ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow-id');
        $updateName = $input->getArgument('update-name');
        $runId = $input->getOption('run-id');
        $client = $this->client($input);
        $waitFor = $this->optionalString($input->getOption('wait')) ?? 'accepted';

        if (! $this->validateControlPlaneOption(
            client: $client,
            output: $output,
            operation: 'update',
            field: 'wait_for',
            value: $waitFor,
            optionName: '--wait',
        )) {
            return Command::INVALID;
        }

        $body = [
            'wait_for' => $waitFor,
        ];
        if ($input->getOption('input')) {
            $body['input'] = json_decode($input->getOption('input'), true);
        }

        $path = $runId !== null
            ? "/workflows/{$workflowId}/runs/{$runId}/update/{$updateName}"
            : "/workflows/{$workflowId}/update/{$updateName}";

        $result = $client->post($path, $body);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Update sent</info>');
        $output->writeln('  Workflow ID: '.$result['workflow_id']);
        $output->writeln('  Update: '.$result['update_name']);
        $output->writeln('  Update ID: '.$result['update_id']);
        $output->writeln('  Outcome: '.$result['outcome']);
        if (isset($result['command_status'])) {
            $output->writeln('  Command Status: '.$result['command_status']);
        }
        if (isset($result['update_status'])) {
            $output->writeln('  Update Status: '.$result['update_status']);
        }
        if (isset($result['wait_for'])) {
            $output->writeln('  Wait For: '.$result['wait_for']);
        }

        return Command::SUCCESS;
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === ''
            ? null
            : $value;
    }
}
