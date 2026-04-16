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
for deterministic replay. Without <comment>--output</comment> the bundle
is printed to stdout.

<comment>Examples:</comment>

  <info>dw workflow:history-export chk-42 01HZ... -o bundle.json</info>
  <info>dw workflow:history-export chk-42 01HZ... | jq '.events | length'</info>
HELP)
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addArgument('run-id', InputArgument::REQUIRED, 'Run ID')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Write to file instead of stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow-id');
        $runId = $input->getArgument('run-id');

        $result = $this->client($input)->get("/workflows/{$workflowId}/runs/{$runId}/history/export");

        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $outputFile = $input->getOption('output');

        if ($outputFile !== null) {
            file_put_contents($outputFile, $json."\n");
            $output->writeln('<info>Exported to '.$outputFile.'</info>');

            return Command::SUCCESS;
        }

        $output->writeln($json);

        return Command::SUCCESS;
    }
}
