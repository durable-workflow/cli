<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

/**
 * Operator-facing reasoning vocabulary aligned with the v2 execution-semantics
 * and idempotency contract frozen in the workflow package at
 * docs/architecture/execution-guarantees.md.
 *
 * The contract is the single reference for duplicate-execution, retry, lease
 * expiry, redelivery, and dedupe-key behaviour across product docs, CLI
 * reasoning, Waterline diagnostics, and test coverage. The CLI uses these
 * strings in `dw doctor` recommendations and in command help text so the
 * vocabulary an operator reads on the terminal lines up with the contract
 * they will read in the workflow package and on the public docs site.
 *
 * The matching codes are pinned by
 * tests/Support/V2ExecutionSemanticsContractAlignmentTest.php; renaming or
 * narrowing a guarantee in the contract requires updating both the contract
 * doc and the strings here in the same change.
 */
final class ExecutionSemantics
{
    /** Path to the frozen contract inside the workflow package. */
    public const CONTRACT_REFERENCE = 'durable-workflow/workflow:docs/architecture/execution-guarantees.md';

    /**
     * Operator-facing guidance keyed by reasoning situation. Each entry names
     * the contract semantics (at-least-once, deterministic replay, exactly-once
     * at the durable state layer) and the framework idempotency surface the
     * operator should reason about for that situation.
     *
     * @var array<string, string>
     */
    public const GUIDANCE = [
        'activity_duplicate_execution' =>
            'Activity attempts are at-least-once. The same activity_execution_id '
            . 'can produce more than one activity_attempt_id under retry, lease '
            . 'expiry, or redelivery — that is an expected distributed-system '
            . 'event, not a bug. Use activity_execution_id as the idempotency '
            . 'key against external services so retries dedupe at the boundary.',
        'workflow_task_replay' =>
            'Workflow tasks are replayed deterministically, not retried against '
            . 'application logic. The engine re-reads history events to rebuild '
            . 'workflow state; replay does not re-invoke activities or external '
            . 'side effects. Repeated workflow-task observations indicate worker '
            . 'or transport activity, not duplicate application execution.',
        'workflow_repair_redispatch' =>
            'Repair is engine-level recovery, not application failure. '
            . 'TaskRepair re-enqueues a workflow task that a previous worker '
            . 'could not durably commit; it routes to the same decision set and '
            . 'does not duplicate history. The exactly-once-at-commit guarantee '
            . 'still holds for the typed history rows the decision batch writes.',
        'duplicate_start_command' =>
            'Start commands are deduped by workflow_command_id. The duplicate-'
            . 'start policy named on the request decides whether a second start '
            . 'with the same workflow_instance_id returns reject_duplicate or '
            . 'return_existing_active. A retried start MUST send the same '
            . 'workflow_command_id so the engine can recognise the retry.',
        'activity_terminal_outcome_dedupe' =>
            'Each activity_attempt_id has at most one terminal outcome at the '
            . 'durable state layer. A late complete or fail call against an '
            . 'attempt that already settled returns recorded=false with a '
            . 'reason code; that is the redelivery path, not a failure. Pair '
            . 'the call with the leased activity_attempt_id so the server can '
            . 'enforce the dedupe.',
        'workflow_task_failure_not_retry' =>
            'Workflow-task fail records a worker-side failure on the named '
            . 'attempt; it does not retry application logic. The engine '
            . 'replays the same history into a fresh task; persistent '
            . 'workflow-task failure is a host or determinism issue, not a '
            . 'transient retry signal.',
    ];

    /**
     * Operator-facing recommendation cited from `dw doctor` when the
     * connection is otherwise healthy. Read once, then linked from the CLI
     * surfaces an operator reaches for when they observe duplicate work.
     *
     * @return array{id: string, severity: string, message: string}
     */
    public static function doctorRecommendation(): array
    {
        return [
            'id' => 'semantics.execution_contract',
            'severity' => 'info',
            'message' => 'Duplicate activity attempts and replayed workflow tasks are normal v2 behaviour. '
                . 'Consult ' . self::CONTRACT_REFERENCE . ' for the at-least-once, '
                . 'deterministic-replay, and exactly-once-at-the-durable-state-layer guarantees, and '
                . 'use activity_execution_id / workflow_command_id as the framework idempotency surfaces.',
        ];
    }
}
