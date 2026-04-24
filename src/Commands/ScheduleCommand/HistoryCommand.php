<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands\ScheduleCommand;

use DurableWorkflow\Cli\Commands\BaseCommand;
use DurableWorkflow\Cli\Support\InvalidOptionException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class HistoryCommand extends BaseCommand
{
    private const SERVER_PAGE_LIMIT = 500;

    protected function configure(): void
    {
        parent::configure();
        $this->setName('schedule:history')
            ->setDescription('Show the audit history stream for a schedule')
            ->setHelp(<<<'HELP'
Print every lifecycle event recorded against a schedule (create, pause,
resume, update, trigger, trigger-skipped, delete) in the order the
server recorded them. Useful for post-mortem review of a schedule's
trigger posture, and works for deleted schedules as well.

<comment>Examples:</comment>

  <info>dw schedule:history daily-report</info>
  <info>dw schedule:history daily-report --limit 200</info>
  <info>dw schedule:history daily-report --output=jsonl | jq 'select(.event_type=="ScheduleTriggered")'</info>
  <info>dw schedule:history daily-report --after-sequence 42</info>
  <info>dw schedule:history daily-report --all --output=json</info>
HELP)
            ->addArgument('schedule-id', InputArgument::REQUIRED, 'Schedule ID')
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of events to fetch per page (1-500). Defaults to the server default.',
            )
            ->addOption(
                'after-sequence',
                null,
                InputOption::VALUE_REQUIRED,
                'Only show events with a sequence number greater than this value (cursor).',
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Page through every event for the schedule instead of stopping at the first page.',
            )
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON (alias for --output=json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scheduleId = (string) $input->getArgument('schedule-id');
        $limit = $this->parseLimitOption($input);
        $afterSequence = $this->parseAfterSequenceOption($input);
        $fetchAll = (bool) $input->getOption('all');

        $client = $this->client($input);
        $path = '/schedules/'.$scheduleId.'/history';

        $events = [];
        $cursor = $afterSequence;
        $nextCursor = null;
        $hasMore = false;
        $namespace = null;

        do {
            $query = [];
            if ($limit !== null) {
                $query['limit'] = (string) $limit;
            }
            if ($cursor !== null) {
                $query['after_sequence'] = (string) $cursor;
            }

            $response = $client->get($path, $query);

            $pageEvents = $response['events'] ?? [];
            if (is_array($pageEvents)) {
                foreach ($pageEvents as $event) {
                    $events[] = $event;
                }
            }

            $namespace = $response['namespace'] ?? $namespace;
            $hasMore = (bool) ($response['has_more'] ?? false);
            $nextCursor = $response['next_cursor'] ?? null;

            $cursor = $nextCursor;
        } while ($fetchAll && $hasMore && $cursor !== null);

        $envelope = [
            'schedule_id' => $scheduleId,
            'namespace' => $namespace,
            'events' => $events,
            'has_more' => $fetchAll ? false : $hasMore,
            'next_cursor' => $fetchAll ? null : $nextCursor,
        ];

        if ($this->wantsJson($input)) {
            return $this->renderJsonList($output, $input, $envelope, 'events');
        }

        if ($events === []) {
            $output->writeln('<comment>No audit events recorded for schedule '.$scheduleId.'.</comment>');

            return Command::SUCCESS;
        }

        $rows = array_map(static function (array $event): array {
            $refs = [];
            if (! empty($event['workflow_instance_id'])) {
                $refs[] = 'wf='.$event['workflow_instance_id'];
            }
            if (! empty($event['workflow_run_id'])) {
                $refs[] = 'run='.$event['workflow_run_id'];
            }

            return [
                (string) ($event['sequence'] ?? '-'),
                (string) ($event['event_type'] ?? '-'),
                (string) ($event['recorded_at'] ?? '-'),
                $refs === [] ? '-' : implode(' ', $refs),
            ];
        }, $events);

        $this->renderTable(
            $output,
            ['Seq', 'Event', 'Recorded At', 'Workflow Refs'],
            $rows,
        );

        if ($hasMore && ! $fetchAll) {
            $output->writeln(sprintf(
                '<comment>More events available. Re-run with --after-sequence=%s (or --all) to continue.</comment>',
                (string) ($nextCursor ?? '?'),
            ));
        }

        return Command::SUCCESS;
    }

    private function parseLimitOption(InputInterface $input): ?int
    {
        $raw = $input->getOption('limit');
        if ($raw === null) {
            return null;
        }

        if (! is_string($raw) && ! is_int($raw)) {
            throw new InvalidOptionException('--limit must be an integer between 1 and '.self::SERVER_PAGE_LIMIT.'.');
        }

        if (is_string($raw) && ! preg_match('/^\d+$/', $raw)) {
            throw new InvalidOptionException('--limit must be an integer between 1 and '.self::SERVER_PAGE_LIMIT.'.');
        }

        $value = (int) $raw;

        if ($value < 1 || $value > self::SERVER_PAGE_LIMIT) {
            throw new InvalidOptionException('--limit must be an integer between 1 and '.self::SERVER_PAGE_LIMIT.'.');
        }

        return $value;
    }

    private function parseAfterSequenceOption(InputInterface $input): ?int
    {
        $raw = $input->getOption('after-sequence');
        if ($raw === null) {
            return null;
        }

        if (! is_string($raw) && ! is_int($raw)) {
            throw new InvalidOptionException('--after-sequence must be a non-negative integer.');
        }

        if (is_string($raw) && ! preg_match('/^\d+$/', $raw)) {
            throw new InvalidOptionException('--after-sequence must be a non-negative integer.');
        }

        return (int) $raw;
    }
}
