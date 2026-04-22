<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Commands;

use RuntimeException;

/**
 * Marker exception thrown by the atomic binary replacer when the
 * failure is caused by the target path (or its parent directory) being
 * read-only for the current user. Letting callers distinguish
 * permission failures from other IO errors is what powers the
 * `permission-denied` status the upgrade command surfaces with a
 * sudo/user-writable hint.
 */
class UpgradePermissionException extends RuntimeException
{
}
