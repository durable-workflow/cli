<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

use RuntimeException;

/**
 * Base exception for server-driven failures.
 *
 * Subclasses carry enough information for the CLI to choose an exit
 * code without re-inspecting HTTP status codes.
 */
class ServerException extends RuntimeException
{
    public function exitCode(): int
    {
        return ExitCode::FAILURE;
    }
}
