<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class HistoryExportCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:history-export')
            ->setDescription('Export a workflow run history as a replay bundle')
            ->setHelp(<<<'HELP'
Export a run's full history as a self-contained JSON bundle suitable
for deterministic replay. Without <comment>--output-file</comment> the
bundle is printed to stdout; with it, stdout stays empty and a human
confirmation is written to stderr so the stdout stream is safe to pipe.

<comment>Examples:</comment>

  <info>dw workflow:history-export chk-42 01HZ... -o bundle.json</info>
  <info>dw workflow:history-export chk-42 01HZ... | jq '.events | length'</info>
HELP)
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addArgument('run-id', InputArgument::REQUIRED, 'Run ID')
            ->addOption('output-file', 'o', InputOption::VALUE_OPTIONAL, 'Write to file instead of stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow-id');
        $runId = $input->getArgument('run-id');

        $result = $this->client($input)->get("/workflows/{$workflowId}/runs/{$runId}/history/export");

        $outputFile = $input->getOption('output-file');

        if ($outputFile !== null) {
            $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            file_put_contents($outputFile, $json."\n");
            $stderr = $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface
                ? $output->getErrorOutput()
                : $output;
            $stderr->writeln('<info>Exported to '.$outputFile.'</info>');

            return Command::SUCCESS;
        }

        // Stdout is the bundle only — compact JSON so downstream jq/wc pipes
        // stay byte-deterministic.
        $output->writeln(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
