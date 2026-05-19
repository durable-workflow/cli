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
    /**
     * @param  array<string, mixed>|null  $body
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        ?Throwable $previous = null,
        public readonly ?array $body = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function reason(): ?string
    {
        $reason = $this->body['reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validationErrors(): ?array
    {
        $errors = $this->body['validation_errors'] ?? $this->body['errors'] ?? null;

        return is_array($errors) ? $errors : null;
    }

    public function exitCode(): int
    {
        return ExitCode::fromHttpStatus($this->statusCode);
    }
}
