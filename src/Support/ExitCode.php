<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

/**
 * Stable exit codes for the `dw` CLI.
 *
 * Scripts and CI pipelines rely on these values. Symfony Console's 0/1/2
 * remain canonical for success, failure, and usage; dw extends that with
 * specific codes for common server-driven outcomes so callers can react
 * to auth, not-found, network, and server-side conditions without
 * parsing stderr.
 */
final class ExitCode
{
    /** Operation completed successfully. */
    public const SUCCESS = 0;

    /** Generic failure — command ran but did not succeed. */
    public const FAILURE = 1;

    /** Invalid usage — bad arguments, unknown options, or local validation. */
    public const INVALID = 2;

    /** Network failure — could not reach the server (connection refused, DNS, TLS, etc.). */
    public const NETWORK = 3;

    /** Authentication or authorization failure — HTTP 401 or 403. */
    public const AUTH = 4;

    /** Resource not found — HTTP 404. */
    public const NOT_FOUND = 5;

    /** Server error — HTTP 5xx or an unexpected server response. */
    public const SERVER = 6;

    /** Request timed out — the server did not respond within the deadline. */
    public const TIMEOUT = 7;

    private function __construct() {}

    /**
     * Map an HTTP status code to its canonical exit code.
     */
    public static function fromHttpStatus(int $status): int
    {
        if ($status === 401 || $status === 403) {
            return self::AUTH;
        }

        if ($status === 404) {
            return self::NOT_FOUND;
        }

        if ($status === 408) {
            return self::TIMEOUT;
        }

        if ($status >= 500 && $status <= 599) {
            return self::SERVER;
        }

        if ($status >= 400 && $status <= 499) {
            return self::INVALID;
        }

        return self::FAILURE;
    }
}
