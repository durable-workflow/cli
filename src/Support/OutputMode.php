<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

/**
 * Output-mode contract for every `dw` command.
 *
 * Scripts depend on stdout never carrying human diagnostics when the
 * caller opts into a machine-readable mode. Errors in that mode are
 * emitted as a JSON envelope on stdout; human text is routed to stderr
 * when running without `--output=json` / `--output=jsonl`.
 */
final class OutputMode
{
    public const TABLE = 'table';
    public const JSON = 'json';
    public const JSONL = 'jsonl';

    public const ALL = [self::TABLE, self::JSON, self::JSONL];

    private function __construct() {}

    public static function isMachineReadable(string $mode): bool
    {
        return $mode === self::JSON || $mode === self::JSONL;
    }
}
