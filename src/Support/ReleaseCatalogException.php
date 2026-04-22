<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

use RuntimeException;
use Throwable;

class ReleaseCatalogException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
