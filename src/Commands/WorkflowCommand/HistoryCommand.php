<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\WorkflowCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\OutputMode;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class HistoryCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('workflow:history')
            ->setDescription('Show event history for a workflow run')
            ->setHelp(<<<'HELP'
Print every history event for a specific run. Use
<comment>--follow</comment> to long-poll for new events as they arrive;
the command exits when the run reaches a terminal state.

<comment>Examples:</comment>

  <info>dw workflow:history chk-42 01HZ...</info>
  <info>dw workflow:history chk-42 01HZ... --follow</info>

  # Single JSON document (pagination cursor preserved as part of the envelope)
  <info>dw workflow:history chk-42 01HZ... --output=json | jq '.events[].event_type'</info>

  # Stream one event per line as each history page arrives
  <info>dw workflow:history chk-42 01HZ... --output=jsonl | jq '.event_type'</info>
HELP)
            ->addArgument('workflow-id', InputArgument::REQUIRED, 'Workflow ID')
            ->addArgument('run-id', InputArgument::REQUIRED, 'Run ID')
            ->addOption('follow', 'f', InputOption::VALUE_NONE, 'Follow new events (long-poll)')
            ->addOption('page-size', null, InputOption::VALUE_REQUIRED, 'Number of events per page')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON (alias for --output=json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowId = $input->getArgument('workflow-id');
        $runId = $input->getArgument('run-id');
        $follow = $input->getOption('follow');
        $pageSize = $input->getOption('page-size');

        $query = [
            'wait_new_event' => $follow,
        ];

        if ($pageSize !== null) {
            $query['page_size'] = (int) $pageSize;
        }

        $streaming = $this->outputMode($input) === OutputMode::JSONL;
        $allEvents = $streaming ? null : [];
        $nextPageToken = null;
        $result = [];

        do {
            if ($nextPageToken !== null) {
                $query['next_page_token'] = $nextPageToken;
            }

            $result = $this->client($input)->get("/workflows/{$workflowId}/runs/{$runId}/history", $query);

            $events = $result['events'] ?? [];
            if ($streaming) {
                // Stream each page as it arrives so `--output=jsonl` gives
                // downstream consumers bytes on stdout before the full
                // history finishes fetching. No buffering across pages.
                foreach ($events as $event) {
                    $output->writeln(json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                }
            } else {
                $allEvents = array_merge($allEvents, $events);
            }
            $nextPageToken = $result['next_page_token'] ?? null;
        } while ($nextPageToken !== null);

        if ($streaming) {
            return Command::SUCCESS;
        }

        if ($this->wantsJson($input)) {
            $result['events'] = $allEvents;
            unset($result['next_page_token']);

            return $this->renderJsonList($output, $input, $result, 'events');
        }

        if (empty($allEvents)) {
            $output->writeln('<comment>No history events.</comment>');

            return Command::SUCCESS;
        }

        $rows = array_map(fn ($e) => [
            $e['sequence'] ?? '-',
            $e['event_type'] ?? '-',
            $e['timestamp'] ?? '-',
            json_encode($e['payload'] ?? null, JSON_UNESCAPED_SLASHES),
        ], $allEvents);

        $this->renderTable($output, ['#', 'Event Type', 'Time', 'Details'], $rows);

        return Command::SUCCESS;
    }
}
