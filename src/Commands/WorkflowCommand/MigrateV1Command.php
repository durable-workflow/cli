<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\InvalidOptionException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MigrateV1Command extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:migrate-v1')
            ->setDescription('Project a Waterline v1 workflow into standalone read-only visibility')
            ->setHelp(<<<'HELP'
Project one public Waterline v1 workflow detail response into the standalone
server. The operation creates read-only identity, status, task-queue context,
and typed history visibility; it never transfers or advances v1 execution.

Export the source through Waterline's public hybrid migration API, then pass
that JSON file (or - for stdin) with a deployment-stable source identifier.

<comment>Examples:</comment>

  <info>dw workflow:migrate-v1 waterline-v1-42.json --source-id=legacy-prod --json</info>
  <info>curl .../waterline/api/flows/v1:42 | dw workflow:migrate-v1 - --source-id=legacy-prod --dry-run --json</info>
HELP)
            ->addArgument('projection', InputArgument::REQUIRED, 'Waterline v1 detail JSON file; use - for stdin')
            ->addOption('source-id', null, InputOption::VALUE_REQUIRED, 'Stable identifier for the source Waterline deployment')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate identity and report the mapped standalone IDs without writing')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the projection report as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sourceId = $input->getOption('source-id');
        if (! is_string($sourceId) || trim($sourceId) === '') {
            throw new InvalidOptionException('--source-id is required.');
        }

        $projection = $this->readProjection((string) $input->getArgument('projection'));
        $result = $this->addNamespaceContext($input, $this->client($input)->post('/workflows/import/waterline-v1', [
            'source_id' => trim($sourceId),
            'workflow' => $projection,
            'dry_run' => (bool) $input->getOption('dry-run'),
        ]));

        if ($this->wantsJson($input)) {
            return $this->renderJson($output, $result);
        }

        $identity = is_array($result['identity'] ?? null) ? $result['identity'] : [];
        $waterline = is_array($identity['waterline'] ?? null) ? $identity['waterline'] : [];
        $standalone = is_array($identity['standalone'] ?? null) ? $identity['standalone'] : [];

        $output->writeln('<info>Waterline v1 projection</info>');
        $output->writeln('  Status: '.($result['status'] ?? '-'));
        $output->writeln('  Source: '.($waterline['source_id'] ?? '-'));
        $output->writeln('  Waterline ID: '.($waterline['qualified_workflow_id'] ?? '-'));
        $output->writeln('  Workflow ID: '.($standalone['workflow_id'] ?? '-'));
        $output->writeln('  Run ID: '.($standalone['run_id'] ?? '-'));
        $output->writeln('  Namespace: '.($standalone['namespace'] ?? ($result['namespace'] ?? '-')));
        $output->writeln('  Read Only: yes');

        $unsupported = is_array($result['unsupported_fields'] ?? null) ? $result['unsupported_fields'] : [];
        if ($unsupported !== []) {
            $output->writeln('  Unsupported Fields:');
            foreach ($unsupported as $field) {
                if (! is_array($field)) {
                    continue;
                }
                $output->writeln(sprintf(
                    '    - %s: %s (%s)',
                    $field['field'] ?? '-',
                    $field['reason'] ?? '-',
                    $field['remediation'] ?? '-',
                ));
            }
        }

        return Command::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function readProjection(string $path): array
    {
        $contents = $path === '-'
            ? stream_get_contents(STDIN)
            : @file_get_contents($path);

        if (! is_string($contents)) {
            throw new InvalidOptionException(sprintf('Unable to read Waterline projection from [%s].', $path));
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidOptionException('Waterline projection must be valid JSON: '.$exception->getMessage());
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidOptionException('Waterline projection must be a JSON object.');
        }

        if (is_array($decoded['data'] ?? null) && ! array_is_list($decoded['data'])) {
            $decoded = $decoded['data'];
        }

        return $decoded;
    }
}
