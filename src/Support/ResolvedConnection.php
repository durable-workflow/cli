<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

/**
 * The effective server/namespace/token tuple a command should use
 * after flag > env > profile > default precedence has been applied.
 * See ProfileResolver for how each field is chosen.
 */
final class ResolvedConnection
{
    public function __construct(
        public readonly string $server,
        public readonly string $namespace,
        public readonly ?string $token,
        public readonly bool $tlsVerify,
        public readonly ?Profile $profile,
    ) {}
}
