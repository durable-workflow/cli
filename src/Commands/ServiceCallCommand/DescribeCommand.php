<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ServiceCallCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DescribeCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('service-call:describe')
            ->setDescription('Inspect a durable Nexus service call and its original target')
            ->setHelp(<<<'HELP'
Read one service call in the selected endpoint namespace. The JSON response
preserves the server's outcome, attempt records, original caller and target,
deadlines, typed failure and retained payload references. Inspection does not
retry, cancel or otherwise change the call, and does not fetch external objects.

  <info>dw service-call:describe billing invoices create call-42 --namespace=shared</info>
  <info>dw service-call:describe billing invoices create call-42 --json</info>
HELP)
            ->addArgument('endpoint', InputArgument::REQUIRED, 'Endpoint name')
            ->addArgument('service', InputArgument::REQUIRED, 'Service name')
            ->addArgument('operation', InputArgument::REQUIRED, 'Operation name')
            ->addArgument('call-id', InputArgument::REQUIRED, 'Original service call ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the complete server response as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $segments = array_map(
            static fn (string $name): string => rawurlencode((string) $input->getArgument($name)),
            ['endpoint', 'service', 'operation', 'call-id'],
        );
        $result = $this->addNamespaceContext($input, $this->client($input)->get(sprintf(
            '/service-endpoints/%s/services/%s/operations/%s/service-calls/%s',
            ...$segments,
        )));

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $output->writeln('<info>Nexus Service Call</info>');
        foreach ([
            'Namespace' => 'namespace', 'Call ID' => 'id', 'Status' => 'status',
            'Outcome' => 'outcome', 'Deadline' => 'call_deadline_at',
            'Caller Namespace' => 'caller_namespace', 'Caller Workflow' => 'caller_workflow_instance_id',
            'Caller Run' => 'caller_workflow_run_id', 'Target Namespace' => 'target_namespace',
            'Target Workflow' => 'linked_workflow_instance_id', 'Target Run' => 'linked_workflow_run_id',
            'Target Update' => 'linked_workflow_update_id', 'Retry Attempts' => 'retry_attempt_count',
            'Failure Type' => 'caller_observed_error_type', 'Failure Message' => 'typed_error_message',
            'Output' => 'output', 'Output Reference' => 'output_payload_reference',
            'Failure Reference' => 'failure_payload_reference', 'Outcome Metadata' => 'outcome_metadata',
            'Attempts' => 'service_call_attempts',
        ] as $label => $field) {
            $value = $result[$field] ?? null;
            $text = '-';
            if (array_key_exists($field, $result)) {
                $text = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
            $output->writeln('  '.$label.': '.OutputFormatter::escape($text));
        }

        return Command::SUCCESS;
    }
}
