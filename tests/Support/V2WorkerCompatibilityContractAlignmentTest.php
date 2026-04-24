<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Cli\Application;
use DurableWorkflow\Cli\Support\WorkerCompatibility;
use PHPUnit\Framework\TestCase;

/**
 * Pins the CLI's operator-facing reasoning vocabulary to the v2 worker
 * compatibility and routing contract frozen in the workflow package at
 * docs/architecture/worker-compatibility.md.
 *
 * The contract is the single reference for worker build identity,
 * compatibility markers, run pinning at start, inheritance through
 * retry / continue-as-new / child runs, claim-time routing, the
 * explicit "no compatible worker" operational state, and the
 * rollout / rollback posture. When the contract renames or narrows a
 * guarantee, the strings on {@see WorkerCompatibility} and the help
 * text this test inspects must be updated in the same change so
 * operator vocabulary does not drift from the contract silently.
 *
 * Required reading before changing this test:
 * - workflow package: docs/architecture/worker-compatibility.md
 * - workflow package: tests/Unit/V2/WorkerCompatibilityDocumentationTest.php
 */
final class V2WorkerCompatibilityContractAlignmentTest extends TestCase
{
    public function test_contract_reference_points_to_workflow_package_doc(): void
    {
        self::assertSame(
            'durable-workflow/workflow:docs/architecture/worker-compatibility.md',
            WorkerCompatibility::CONTRACT_REFERENCE,
        );
    }

    public function test_every_guidance_code_names_contract_vocabulary(): void
    {
        $expected = [
            'marker_is_opaque',
            'run_pinning_at_start',
            'compatibility_inheritance',
            'no_compatible_worker_state',
            'claim_time_enforcement',
            'rollout_add_new_marker',
            'rollout_drain_old_marker',
            'rollback_posture',
            'rollout_health_signal',
            'heartbeat_ttl_ceiling',
            'mismatch_reason_verbatim',
            'wildcard_marker_workers_only',
        ];

        foreach ($expected as $code) {
            self::assertArrayHasKey(
                $code,
                WorkerCompatibility::GUIDANCE,
                sprintf('WorkerCompatibility::GUIDANCE is missing contract guidance for code %s.', $code),
            );
            self::assertNotSame(
                '',
                WorkerCompatibility::GUIDANCE[$code],
                sprintf('Contract guidance for %s must be a non-empty string.', $code),
            );
        }
    }

    /**
     * Compatibility markers are opaque: the engine performs only
     * exact-string equality and the workers-only `*` wildcard. CLI
     * reasoning must not suggest that the engine orders, diffs, or
     * semver-interprets markers.
     */
    public function test_marker_opaque_guidance_names_exact_string_equality_and_no_semver(): void
    {
        $guidance = WorkerCompatibility::GUIDANCE['marker_is_opaque'];

        self::assertStringContainsString('opaque', $guidance);
        self::assertStringContainsString('exact-string equality', $guidance);
        self::assertStringContainsString('semver', $guidance);
        self::assertStringContainsString('compatibility family', $guidance);
    }

    /**
     * A run is pinned at Start from DW_V2_CURRENT_COMPATIBILITY and the
     * pin is immutable against in-flight config changes.
     */
    public function test_run_pinning_guidance_names_start_time_stamp_from_env_and_in_flight_immutability(): void
    {
        $guidance = WorkerCompatibility::GUIDANCE['run_pinning_at_start'];

        self::assertStringContainsString('DW_V2_CURRENT_COMPATIBILITY', $guidance);
        self::assertStringContainsString('at Start', $guidance);
        self::assertStringContainsString('in-flight runs keep the marker', $guidance);
        self::assertStringContainsString('continued-as-new', $guidance);
    }

    /**
     * Retry, continue-as-new, and child runs inherit the parent run's
     * compatibility marker. Workflow tasks and activity tasks carry it too.
     */
    public function test_inheritance_guidance_names_retry_can_and_children(): void
    {
        $guidance = WorkerCompatibility::GUIDANCE['compatibility_inheritance'];

        self::assertStringContainsString('Retry', $guidance);
        self::assertStringContainsString('continue-as-new', $guidance);
        self::assertStringContainsString('child workflows', $guidance);
        self::assertStringContainsString('never silently', $guidance);
    }

    /**
     * The absence of a compatible worker is an explicit operational state
     * (supports_required=false / compatibility_blocked /
     * compatibility_unsupported) and must be described as such rather
     * than as a task failure.
     */
    public function test_no_compatible_worker_guidance_names_explicit_operational_state_codes(): void
    {
        $guidance = WorkerCompatibility::GUIDANCE['no_compatible_worker_state'];

        self::assertStringContainsString('explicit operational state', $guidance);
        self::assertStringContainsString('supports_required=false', $guidance);
        self::assertStringContainsString('compatibility_blocked', $guidance);
        self::assertStringContainsString('compatibility_unsupported', $guidance);
        self::assertStringContainsString('no compatible worker is registered yet', $guidance);
    }

    /**
     * Claim-time enforcement is the correctness boundary for routing.
     * Workflow-task claims reject with compatibility_blocked; activity
     * claims reject with compatibility_unsupported.
     */
    public function test_claim_time_enforcement_guidance_names_reason_codes_and_task_stays_on_queue(): void
    {
        $guidance = WorkerCompatibility::GUIDANCE['claim_time_enforcement'];

        self::assertStringContainsString('claim time', $guidance);
        self::assertStringContainsString('compatibility_blocked', $guidance);
        self::assertStringContainsString('compatibility_unsupported', $guidance);
        self::assertStringContainsString('leaves the task on the queue', $guidance);
    }

    /**
     * Adding a new marker is additive: the new fleet advertises both old
     * and new markers in supported so it accepts in-flight old-marker
     * runs alongside new-stamped runs.
     */
    public function test_rollout_add_guidance_names_additive_supported_list(): void
    {
        $guidance = WorkerCompatibility::GUIDANCE['rollout_add_new_marker'];

        self::assertStringContainsString('DW_V2_CURRENT_COMPATIBILITY', $guidance);
        self::assertStringContainsString('supported list', $guidance);
        self::assertStringContainsString('old marker and the new marker', $guidance);
        self::assertStringContainsString('in-flight', $guidance);
    }

    /**
     * Draining an old marker is operator-ordered: stop stamping new
     * runs first, let pinned runs drain, then remove from supported.
     * Removing early produces the no-compatible-worker state.
     */
    public function test_rollout_drain_guidance_names_stamp_first_drain_then_remove_order(): void
    {
        $guidance = WorkerCompatibility::GUIDANCE['rollout_drain_old_marker'];

        self::assertStringContainsString('Stop stamping', $guidance);
        self::assertStringContainsString('drain', $guidance);
        self::assertStringContainsString('remove the old marker', $guidance);
        self::assertStringContainsString('no-compatible-worker', $guidance);
    }

    /**
     * Rollback is symmetric — old fleet keeps old marker in supported
     * so repointing starters is safe; the engine does not quietly
     * reroute runs across markers.
     */
    public function test_rollback_guidance_names_symmetric_posture_and_no_silent_reroute(): void
    {
        $guidance = WorkerCompatibility::GUIDANCE['rollback_posture'];

        self::assertStringContainsString('old marker', $guidance);
        self::assertStringContainsString('DW_V2_CURRENT_COMPATIBILITY', $guidance);
        self::assertStringContainsString('no run is quietly rerouted', $guidance);
    }

    /**
     * Rollout health is observable via WorkerCompatibilityFleet
     * supports_required on live heartbeats. A single true signal is
     * sufficient; an all-false signal means the rollout or rollback is
     * stuck.
     */
    public function test_health_signal_guidance_names_supports_required_and_stuck_state(): void
    {
        $guidance = WorkerCompatibility::GUIDANCE['rollout_health_signal'];

        self::assertStringContainsString('supports_required=true', $guidance);
        self::assertStringContainsString('supports_required=false', $guidance);
        self::assertStringContainsString('at least one live heartbeat', $guidance);
        self::assertStringContainsString('stuck', $guidance);
    }

    /**
     * Heartbeat TTL is the upper bound on fleet-view staleness. Default
     * 30s, configured by DW_V2_COMPATIBILITY_HEARTBEAT_TTL.
     */
    public function test_heartbeat_ttl_guidance_names_env_name_and_default_and_upper_bound(): void
    {
        $guidance = WorkerCompatibility::GUIDANCE['heartbeat_ttl_ceiling'];

        self::assertStringContainsString('DW_V2_COMPATIBILITY_HEARTBEAT_TTL', $guidance);
        self::assertStringContainsString('30s', $guidance);
        self::assertStringContainsString('upper bound', $guidance);
    }

    /**
     * Mismatch reason strings are produced by WorkerCompatibility and
     * WorkerCompatibilityFleet and MUST be surfaced verbatim. CLI,
     * Waterline, docs, and cloud all quote the same string.
     */
    public function test_mismatch_reason_guidance_names_verbatim_surface_across_tools(): void
    {
        $guidance = WorkerCompatibility::GUIDANCE['mismatch_reason_verbatim'];

        self::assertStringContainsString('mismatchReason', $guidance);
        self::assertStringContainsString('verbatim', $guidance);
        self::assertStringContainsString('WorkerCompatibilityFleet', $guidance);
    }

    /**
     * The `*` wildcard is a worker advertisement only; runs are never
     * stamped with `*`.
     */
    public function test_wildcard_guidance_names_worker_only_and_runs_never_stamped(): void
    {
        $guidance = WorkerCompatibility::GUIDANCE['wildcard_marker_workers_only'];

        self::assertStringContainsString('`*`', $guidance);
        self::assertStringContainsString('single-build', $guidance);
        self::assertStringContainsString('test harnesses', $guidance);
        self::assertStringContainsString('never stamped', $guidance);
    }

    /**
     * The doctor recommendation must cite the contract reference so
     * operators running `dw doctor` see a pointer to the single source
     * of truth for mixed-version deployment reasoning before they start
     * troubleshooting.
     */
    public function test_doctor_recommendation_cites_contract_reference(): void
    {
        $recommendation = WorkerCompatibility::doctorRecommendation();

        self::assertSame('semantics.worker_compatibility_contract', $recommendation['id']);
        self::assertSame('info', $recommendation['severity']);
        self::assertStringContainsString(WorkerCompatibility::CONTRACT_REFERENCE, $recommendation['message']);
        self::assertStringContainsString('opaque compatibility markers', $recommendation['message']);
        self::assertStringContainsString('run-pinned-at-start', $recommendation['message']);
        self::assertStringContainsString('claim-time-enforcement', $recommendation['message']);
        self::assertStringContainsString('no-compatible-worker-is-an-explicit-operational-state', $recommendation['message']);
        self::assertStringContainsString('rollout-and-rollback', $recommendation['message']);
        self::assertStringContainsString('heartbeat-TTL', $recommendation['message']);
    }

    /**
     * Workflow start help must name the run-pinned-at-start posture,
     * the DW_V2_CURRENT_COMPATIBILITY env surface, inheritance
     * through retry / continue-as-new / child workflows, the
     * workers-only `*` wildcard, and the claim-time enforcement reason
     * codes so operators reason correctly about mixed-version behavior.
     */
    public function test_workflow_start_help_cites_compatibility_pinning_and_claim_time_enforcement(): void
    {
        $help = $this->commandHelp('workflow:start');

        self::assertStringContainsString('DW_V2_CURRENT_COMPATIBILITY', $help);
        self::assertStringContainsString('DW_V2_SUPPORTED_COMPATIBILITIES', $help);
        self::assertStringContainsString('pinned', $help);
        self::assertStringContainsString('Retry', $help);
        self::assertStringContainsString('continue-as-new', $help);
        self::assertStringContainsString('child workflows', $help);
        self::assertStringContainsString('compatibility_blocked', $help);
        self::assertStringContainsString('compatibility_unsupported', $help);
    }

    /**
     * Worker register help must describe markers as opaque strings, the
     * engine's exact-string equality, and the workers-only `*` wildcard
     * so operators registering diagnostic workers pick a marker
     * deliberately instead of guessing a comparison surface.
     */
    public function test_worker_register_help_cites_opaque_marker_and_wildcard_scope(): void
    {
        $help = $this->commandHelp('worker:register');

        self::assertStringContainsString('opaque', $help);
        self::assertStringContainsString('exact-string equality', $help);
        self::assertStringContainsString('single-build', $help);
        self::assertStringContainsString('test harnesses', $help);
        self::assertStringContainsString('never stamped', $help);
    }

    /**
     * Task-queue build-ids help must name the markers-as-family model,
     * the inheritance through retry / continue-as-new / child runs, and
     * the explicit "no compatible worker is registered yet" operational
     * state operators would produce by removing an active marker too
     * early.
     */
    public function test_task_queue_build_ids_help_cites_inheritance_and_no_compatible_worker_state(): void
    {
        $help = $this->commandHelp('task-queue:build-ids');

        self::assertStringContainsString('compatibility markers', $help);
        self::assertStringContainsString('compatibility family', $help);
        self::assertStringContainsString('Retry', $help);
        self::assertStringContainsString('continue-as-new', $help);
        self::assertStringContainsString('child runs', $help);
        self::assertStringContainsString('no compatible worker is registered yet', $help);
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
