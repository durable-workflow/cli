<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\BridgeCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\InvalidOptionException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class WebhookCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('bridge:webhook')
            ->setDescription('Send a bounded webhook bridge adapter event')
            ->setHelp(<<<'HELP'
Send one authenticated integration event through the server bridge-adapter
surface. Bridge adapters can start, signal, or update workflows, but they do
not become workflow runtimes or own workflow state transitions.

<comment>Examples:</comment>

  <info>dw bridge:webhook stripe --action=start_workflow --idempotency-key=stripe-event-1001 --target='{"workflow_type":"orders.fulfillment","task_queue":"external-workflows","business_key":"order-1001"}' -i '{"order_id":"order-1001"}'</info>

  <info>dw bridge:webhook pagerduty --action=signal_workflow --idempotency-key=pd-event-3003 --target='{"workflow_id":"wf-remediation-42","signal_name":"incident_escalated"}' -i '{"severity":"critical"}' --json</info>
HELP)
            ->addArgument('adapter', InputArgument::REQUIRED, 'Bridge adapter key')
            ->addOption('action', null, InputOption::VALUE_REQUIRED, 'Bridge action: start_workflow, signal_workflow, or update_workflow')
            ->addOption('idempotency-key', null, InputOption::VALUE_REQUIRED, 'Stable provider event or dedupe key')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Target JSON object')
            ->addOption('correlation', null, InputOption::VALUE_OPTIONAL, 'Operator-safe correlation JSON object')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the server response as JSON');
        $this->addInputOptions('Bridge input payload');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $adapter = trim((string) $input->getArgument('adapter'));
        $action = $this->requiredOption($input, 'action');
        $idempotencyKey = $this->requiredOption($input, 'idempotency-key');
        $target = $this->requiredJsonObject($input->getOption('target'), 'target');
        $correlation = $this->optionalJsonObject($input->getOption('correlation'), 'correlation');

        if ($adapter === '') {
            throw new InvalidOptionException('adapter is required.');
        }

        $body = [
            'action' => $action,
            'idempotency_key' => $idempotencyKey,
            'target' => $target,
        ];

        $payload = $this->parseInputOption($input);
        if ($payload !== null) {
            $body['input'] = $payload;
        }

        if ($correlation !== null) {
            $body['correlation'] = $correlation;
        }

        $result = $this->client($input)->post('/bridge-adapters/webhook/'.rawurlencode($adapter), $body);

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $this->renderOutcome($output, $result);

        return Command::SUCCESS;
    }

    private function requiredOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidOptionException(sprintf('--%s is required.', $name));
        }

        return trim($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function requiredJsonObject(mixed $value, string $option): array
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidOptionException(sprintf('--%s is required and must be a JSON object.', $option));
        }

        $decoded = $this->parseJsonOption($value, $option);

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidOptionException(sprintf('--%s must be a JSON object.', $option));
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function optionalJsonObject(mixed $value, string $option): ?array
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = $this->parseJsonOption($value, $option);

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidOptionException(sprintf('--%s must be a JSON object.', $option));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function renderOutcome(OutputInterface $output, array $result): void
    {
        $accepted = ($result['accepted'] ?? false) === true ? 'yes' : 'no';

        $output->writeln('<info>Bridge adapter outcome</info>');
        $output->writeln('  Adapter: '.($result['adapter'] ?? '-'));
        $output->writeln('  Action: '.($result['action'] ?? '-'));
        $output->writeln('  Accepted: '.$accepted);
        $output->writeln('  Outcome: '.($result['outcome'] ?? '-'));

        foreach ([
            'reason' => 'Reason',
            'control_plane_outcome' => 'Control Plane Outcome',
            'idempotency_key' => 'Idempotency Key',
            'workflow_id' => 'Workflow ID',
            'run_id' => 'Run ID',
            'command_id' => 'Command ID',
        ] as $field => $label) {
            if (isset($result[$field]) && is_scalar($result[$field]) && (string) $result[$field] !== '') {
                $output->writeln('  '.$label.': '.$result[$field]);
            }
        }

        if (isset($result['target']) && is_array($result['target'])) {
            $output->writeln('  Target: '.json_encode($result['target'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
    }
}
