<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

class CompatibilityException extends ServerException
{
    /**
     * @param  array<string, mixed>  $diagnostic
     */
    public function __construct(
        string $message,
        private readonly array $diagnostic,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function exitCode(): int
    {
        return ExitCode::COMPATIBILITY;
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostic(): array
    {
        return $this->diagnostic;
    }

    public function nextStep(): string
    {
        $nextStep = $this->diagnostic['next_step'] ?? null;

        return is_string($nextStep) && $nextStep !== ''
            ? $nextStep
            : CompatibilityDiagnostics::NEXT_STEP;
    }
}
