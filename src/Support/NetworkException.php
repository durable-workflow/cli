<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

/**
 * The server could not be reached — connection refused, DNS failure,
 * TLS handshake failure, or other transport-layer error.
 */
class NetworkException extends ServerException
{
    public function exitCode(): int
    {
        return ExitCode::NETWORK;
    }
}
