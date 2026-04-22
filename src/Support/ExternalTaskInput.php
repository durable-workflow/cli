<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

final class ExternalTaskInput
{
    /**
     * @param  array<string, mixed>  $task
     * @param  array<string, mixed>  $workflow
     * @param  array<string, mixed>  $lease
     * @param  array<string, mixed>  $payloads
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>|null  $deadlines
     * @param  array<string, mixed>|null  $history
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $task,
        public readonly array $workflow,
        public readonly array $lease,
        public readonly array $payloads,
        public readonly array $headers,
        public readonly ?array $deadlines = null,
        public readonly ?array $history = null,
    ) {
    }

    public function isActivityTask(): bool
    {
        return $this->kind === 'activity_task';
    }

    public function isWorkflowTask(): bool
    {
        return $this->kind === 'workflow_task';
    }
}
