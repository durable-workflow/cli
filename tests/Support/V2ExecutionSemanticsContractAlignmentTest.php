<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Cli\Application;
use DurableWorkflow\Cli\Support\ExecutionSemantics;
use PHPUnit\Framework\TestCase;

/**
 * Pins the CLI's operator-facing reasoning vocabulary to the v2
 * execution-semantics and idempotency contract frozen in the workflow
 * package at docs/architecture/execution-guarantees.md.
 *
 * The contract is the single reference for duplicate-execution, retry,
 * lease expiry, redelivery, and dedupe-key behaviour across product
 * docs, CLI reasoning, Waterline diagnostics, and test coverage. When
 * the contract renames or narrows a guarantee, the strings on
 * {@see ExecutionSemantics} and the help text this test inspects must
 * be updated in the same change so operator vocabulary does not drift
 * from the contract silently.
 *
 * Required reading before changing this test:
 * - workflow package: docs/architecture/execution-guarantees.md
 * - workflow package: tests/Unit/V2/ExecutionGuaranteesDocumentationTest.php
 */
final class V2ExecutionSemanticsContractAlignmentTest extends TestCase
{
    public function test_contract_reference_points_to_workflow_package_doc(): void
    {
        self::assertSame(
            'durable-workflow/workflow:docs/architecture/execution-guarantees.md',
            ExecutionSemantics::CONTRACT_REFERENCE,
        );
    }

    public function test_every_guidance_code_names_contract_vocabulary(): void
    {
        $expected = [
            'activity_duplicate_execution',
            'workflow_task_replay',
            'workflow_repair_redispatch',
            'duplicate_start_command',
            'activity_terminal_outcome_dedupe',
            'workflow_task_failure_not_retry',
        ];

        foreach ($expected as $code) {
            self::assertArrayHasKey(
                $code,
                ExecutionSemantics::GUIDANCE,
                sprintf('ExecutionSemantics::GUIDANCE is missing contract guidance for code %s.', $code),
            );
            self::assertNotSame(
                '',
                ExecutionSemantics::GUIDANCE[$code],
                sprintf('Contract guidance for %s must be a non-empty string.', $code),
            );
        }
    }

    /**
     * Activity retries are at-least-once and share an activity_execution_id
     * across the retry chain. CLI reasoning must describe duplicate activity
     * attempts as a normal distributed-system event, not a bug, and must
     * direct the operator to the activity_execution_id idempotency surface.
     */
    public function test_activity_duplicate_guidance_cites_at_least_once_and_execution_id_surface(): void
    {
        $guidance = ExecutionSemantics::GUIDANCE['activity_duplicate_execution'];

        self::assertStringContainsString('at-least-once', $guidance);
        self::assertStringContainsString('activity_execution_id', $guidance);
        self::assertStringContainsString('activity_attempt_id', $guidance);
        self::assertStringContainsString('idempotency', $guidance);
    }

    /**
     * Workflow tasks are replayed deterministically against history, not
     * retried against application logic. Repeated workflow-task observation
     * reflects transport or worker activity, not duplicate application
     * execution; replay does not re-invoke activities.
     */
    public function test_workflow_task_replay_guidance_distinguishes_replay_from_retry(): void
    {
        $guidance = ExecutionSemantics::GUIDANCE['workflow_task_replay'];

        self::assertStringContainsString('replayed deterministically', $guidance);
        self::assertStringContainsString('not retried', $guidance);
        self::assertStringContainsString('history', $guidance);
        self::assertStringContainsString('side effects', $guidance);
    }

    /**
     * Repair is engine-level recovery that routes to the same decision set
     * and does not duplicate history; the exactly-once-at-commit guarantee
     * still holds.
     */
    public function test_repair_guidance_names_engine_level_recovery_and_exactly_once_at_commit(): void
    {
        $guidance = ExecutionSemantics::GUIDANCE['workflow_repair_redispatch'];

        self::assertStringContainsString('engine-level recovery', $guidance);
        self::assertStringContainsString('does not duplicate history', $guidance);
        self::assertStringContainsString('exactly-once-at-commit', $guidance);
    }

    /**
     * Duplicate-start behaviour is controlled by workflow_command_id dedupe
     * and the named policy values (reject_duplicate, return_existing_active).
     * A retried start MUST reuse the same workflow_command_id so the engine
     * can recognise the retry.
     */
    public function test_duplicate_start_guidance_names_command_id_and_policy_values(): void
    {
        $guidance = ExecutionSemantics::GUIDANCE['duplicate_start_command'];

        self::assertStringContainsString('workflow_command_id', $guidance);
        self::assertStringContainsString('reject_duplicate', $guidance);
        self::assertStringContainsString('return_existing_active', $guidance);
        self::assertStringContainsString('workflow_instance_id', $guidance);
    }

    /**
     * Each activity_attempt_id has at most one terminal outcome at the
     * durable state layer. A late complete/fail returns recorded=false with
     * a reason — the redelivery path, not a failure.
     */
    public function test_activity_terminal_outcome_guidance_names_attempt_id_and_recorded_false(): void
    {
        $guidance = ExecutionSemantics::GUIDANCE['activity_terminal_outcome_dedupe'];

        self::assertStringContainsString('activity_attempt_id', $guidance);
        self::assertStringContainsString('at most one terminal outcome', $guidance);
        self::assertStringContainsString('recorded=false', $guidance);
        self::assertStringContainsString('redelivery', $guidance);
    }

    /**
     * Workflow-task failure reports a worker-side commit failure; it is not
     * a retry signal against application logic. The engine replays history
     * into a fresh task.
     */
    public function test_workflow_task_failure_guidance_distinguishes_commit_failure_from_application_retry(): void
    {
        $guidance = ExecutionSemantics::GUIDANCE['workflow_task_failure_not_retry'];

        self::assertStringContainsString('worker-side failure', $guidance);
        self::assertStringContainsString('does not retry application logic', $guidance);
        self::assertStringContainsString('replays', $guidance);
    }

    /**
     * The doctor recommendation must cite the contract reference so
     * operators running `dw doctor` see a pointer to the single source of
     * truth for duplicate-execution reasoning before they start
     * troubleshooting.
     */
    public function test_doctor_recommendation_cites_contract_reference(): void
    {
        $recommendation = ExecutionSemantics::doctorRecommendation();

        self::assertSame('semantics.execution_contract', $recommendation['id']);
        self::assertSame('info', $recommendation['severity']);
        self::assertStringContainsString(ExecutionSemantics::CONTRACT_REFERENCE, $recommendation['message']);
        self::assertStringContainsString('at-least-once', $recommendation['message']);
        self::assertStringContainsString('deterministic-replay', $recommendation['message']);
        self::assertStringContainsString('exactly-once-at-the-durable-state-layer', $recommendation['message']);
        self::assertStringContainsString('activity_execution_id', $recommendation['message']);
        self::assertStringContainsString('workflow_command_id', $recommendation['message']);
    }

    /**
     * Workflow-task fail command help must not frame workflow tasks as
     * retried against application logic. The engine replays the same history
     * into a fresh task; this is a distinct semantic from "retry".
     */
    public function test_workflow_task_fail_help_cites_replay_not_retry(): void
    {
        $help = $this->commandHelp('workflow-task:fail');

        self::assertStringContainsString('replayed deterministically', $help);
        self::assertStringContainsString('not retried against application logic', $help);
        self::assertStringNotContainsString('for retry or diagnosis', $help);
    }

    /**
     * Workflow repair help must describe repair as engine-level recovery
     * that routes to the same decision set without duplicating history, and
     * must name the exactly-once-at-commit guarantee.
     */
    public function test_workflow_repair_help_cites_engine_recovery_and_exactly_once_at_commit(): void
    {
        $help = $this->commandHelp('workflow:repair');

        self::assertStringContainsString('engine-level recovery', $help);
        self::assertStringContainsString('does not duplicate history', $help);
        self::assertStringContainsString('exactly-once-at-commit', $help);
    }

    /**
     * Workflow start help must explain the workflow_command_id dedupe
     * surface and the named duplicate-start policies so operators pick one
     * deliberately and retry safely.
     */
    public function test_workflow_start_help_cites_command_id_dedupe_and_policy_values(): void
    {
        $help = $this->commandHelp('workflow:start');

        self::assertStringContainsString('workflow_command_id', $help);
        self::assertStringContainsString('workflow_instance_id', $help);
        self::assertStringContainsString('reject_duplicate', $help);
        self::assertStringContainsString('return_existing_active', $help);
    }

    /**
     * Activity complete help must name activity_attempt_id ownership and
     * the at-most-one terminal outcome guarantee, and must describe the
     * recorded=false response as the redelivery path rather than a failure.
     */
    public function test_activity_complete_help_cites_attempt_id_and_at_most_one_terminal_outcome(): void
    {
        $help = $this->commandHelp('activity:complete');

        self::assertStringContainsString('activity_attempt_id', $help);
        self::assertStringContainsString('at-most-one terminal outcome', $help);
        self::assertStringContainsString('recorded=false', $help);
        self::assertStringContainsString('redelivery', $help);
    }

    /**
     * Activity fail help must name at-least-once, the stable
     * activity_execution_id across retries, and the new
     * activity_attempt_id per attempt so operators can reason about the
     * retry chain and its idempotency key.
     */
    public function test_activity_fail_help_cites_at_least_once_and_execution_id_stability(): void
    {
        $help = $this->commandHelp('activity:fail');

        self::assertStringContainsString('at-least-once', $help);
        self::assertStringContainsString('activity_execution_id', $help);
        self::assertStringContainsString('activity_attempt_id', $help);
        self::assertStringContainsString('recorded=false', $help);
    }

    private function commandHelp(string $commandName): string
    {
        $application = new Application();
        $command = $application->find($commandName);
        $command->mergeApplicationDefinition();
        $help = $command->getProcessedHelp();

        self::assertNotSame('', $help, sprintf('Command %s has no help text.', $commandName));

        return $help;
    }
}
