<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

final class JsonPayloadEnvelope
{
    /**
     * @return array{codec: string, blob: string}
     */
    public static function fromValue(mixed $value): array
    {
        return [
            'codec' => 'json',
            'blob' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function __construct() {}
}
