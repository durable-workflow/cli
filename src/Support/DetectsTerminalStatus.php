<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

trait DetectsTerminalStatus
{
    private const TERMINAL_BUCKETS = ['completed', 'failed'];

    private const TERMINAL_STATUSES = [
        'completed',
        'failed',
        'cancelled',
        'terminated',
        'timed_out',
    ];

    /**
     * Determine whether a workflow response represents a terminal state.
     *
     * Prefers the server-provided `is_terminal` field when present,
     * falling back to client-side bucket/status matching for older servers.
     *
     * @param  array<string, mixed>  $result
     */
    private function isTerminal(array $result): bool
    {
        if (isset($result['is_terminal'])) {
            return (bool) $result['is_terminal'];
        }

        $statusBucket = $result['status_bucket'] ?? null;
        $status = $result['status'] ?? null;

        return in_array($statusBucket, self::TERMINAL_BUCKETS, true)
            || in_array($status, self::TERMINAL_STATUSES, true);
    }
}
