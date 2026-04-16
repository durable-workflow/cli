<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

use Throwable;

/**
 * HTTP-level failure returned by the server (4xx / 5xx).
 *
 * The HTTP status code is preserved as both the exception code and an
 * explicit accessor so callers can map it to an {@see ExitCode}.
 */
class ServerHttpException extends ServerException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function exitCode(): int
    {
        return ExitCode::fromHttpStatus($this->statusCode);
    }
}
