<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

/**
 * The request timed out before the server responded.
 */
class TimeoutException extends ServerException
{
    public function exitCode(): int
    {
        return ExitCode::TIMEOUT;
    }
}
