<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

final class CompletionValues
{
    public const DEV_DATABASES = ['sqlite', 'mysql', 'pgsql'];

    public const SCHEDULE_OVERLAP_POLICIES = [
        'skip',
        'buffer_one',
        'buffer_all',
        'cancel_other',
        'terminate_other',
        'allow_all',
    ];

    public const SEARCH_ATTRIBUTE_TYPES = [
        'keyword',
        'text',
        'int',
        'double',
        'bool',
        'datetime',
        'keyword_list',
    ];

    public const UPDATE_WAIT_POLICIES = ['accepted', 'completed'];

    public const WORKER_STATUSES = ['active', 'stale', 'draining'];

    public const WORKFLOW_DUPLICATE_POLICIES = ['fail', 'use-existing'];

    public const WORKFLOW_STATUSES = ['running', 'completed', 'failed'];

    private function __construct()
    {
    }
}
