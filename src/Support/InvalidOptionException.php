<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

/**
 * Raised for local validation failures on user-supplied options.
 *
 * Maps to `ExitCode::INVALID` (2) through BaseCommand's error
 * handler so scripts can distinguish usage/local-validation errors
 * from generic FAILURE (1) and server-side errors.
 */
class InvalidOptionException extends \RuntimeException
{
    public function exitCode(): int
    {
        return ExitCode::INVALID;
    }
}
