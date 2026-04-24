<?php

declare(strict_types=1);

namespace DurableWorkflow\Cli\Support;

/**
 * Operator-facing reasoning vocabulary aligned with the v2 worker
 * compatibility and routing contract frozen in the workflow package at
 * docs/architecture/worker-compatibility.md.
 *
 * The contract is the single reference for worker build identity,
 * compatibility markers, run pinning, inheritance through retry /
 * continue-as-new / child runs, claim-time routing, the explicit
 * "no compatible worker" operational state, and the rollout /
 * rollback posture. The CLI uses these strings in `dw doctor`
 * recommendations and in command help text so the vocabulary an
 * operator reads on the terminal lines up with the contract they will
 * read in the workflow package and on the public docs site.
 *
 * The matching codes are pinned by
 * tests/Support/V2WorkerCompatibilityContractAlignmentTest.php;
 * renaming or narrowing a guarantee in the contract requires updating
 * both the contract doc and the strings here in the same change.
 */
final class WorkerCompatibility
{
    /** Path to the frozen contract inside the workflow package. */
    public const CONTRACT_REFERENCE = 'durable-workflow/workflow:docs/architecture/worker-compatibility.md';

    /**
     * Operator-facing guidance keyed by reasoning situation. Each entry
     * names the contract semantics for mixed-version deployments
     * (exact-string marker equality, run pinning at start,
     * inheritance, claim-time enforcement, the explicit no-compatible-
     * worker operational state, and the rollout/rollback posture the
     * engine supports without guessing operator intent).
     *
     * @var array<string, string>
     */
    public const GUIDANCE = [
        'marker_is_opaque' =>
            'Compatibility markers are opaque strings. The engine performs '
            . 'exact-string equality and the workers-only `*` wildcard only; '
            . 'it does not order markers, does not interpret semver, and '
            . 'does not diff their contents. A marker is one compatibility '
            . 'family name, not a version comparison surface.',
        'run_pinning_at_start' =>
            'A workflow run is pinned to a compatibility marker exactly once '
            . 'at Start from DW_V2_CURRENT_COMPATIBILITY on the starter '
            . 'process. Changing DW_V2_CURRENT_COMPATIBILITY only affects '
            . 'newly-started runs; in-flight runs keep the marker they were '
            . 'stamped with until they terminate or are continued-as-new '
            . 'onto a fresh run.',
        'compatibility_inheritance' =>
            'Compatibility is inherited through the run lifecycle. Retry '
            . 'runs, continue-as-new runs, child workflows, and the '
            . 'workflow tasks and activity tasks owned by a run all carry '
            . 'the parent run\'s marker. The engine never silently '
            . 're-pins a run onto a different compatibility family.',
        'no_compatible_worker_state' =>
            'The absence of a compatible worker is an explicit operational '
            . 'state, not an error. It reports as supports_required=false '
            . 'on the fleet surface and as compatibility_blocked or '
            . 'compatibility_unsupported on the claim path. Describe it to '
            . 'operators as "no compatible worker is registered yet" '
            . 'rather than as a task failure.',
        'claim_time_enforcement' =>
            'Compatibility is enforced at claim time, not only at poll '
            . 'time. Workflow-task claims that fall outside the worker\'s '
            . 'supported set are rejected with reason code '
            . 'compatibility_blocked; activity-task claims are rejected '
            . 'with compatibility_unsupported. A rejected claim leaves the '
            . 'task on the queue with its original marker for another '
            . 'compatible worker to pick up.',
        'rollout_add_new_marker' =>
            'Adding a new build is additive: deploy a new fleet with a new '
            . 'DW_V2_CURRENT_COMPATIBILITY value and advertise both the '
            . 'old marker and the new marker in the fleet\'s supported '
            . 'list. The new fleet then accepts tasks for in-flight old '
            . 'runs and new-stamped runs at the same time. Starter '
            . 'processes pointed at the new fleet stamp newly-started '
            . 'runs with the new marker.',
        'rollout_drain_old_marker' =>
            'Draining an old build is operator-driven. Stop stamping new '
            . 'runs with the old marker first (change the starter process '
            . 'to the new DW_V2_CURRENT_COMPATIBILITY value), let pinned '
            . 'runs either terminate or continue-as-new onto the new '
            . 'marker, and only then remove the old marker from any '
            . 'worker\'s supported list. Removing the old marker before '
            . 'its runs drain produces the no-compatible-worker state.',
        'rollback_posture' =>
            'Rollback is symmetric. The old fleet keeps its old marker in '
            . 'supported; repoint the starter processes back to the old '
            . 'DW_V2_CURRENT_COMPATIBILITY value. In-flight runs on the '
            . 'new marker keep running on the new fleet until they '
            . 'finish — no run is quietly rerouted to an incompatible '
            . 'build.',
        'rollout_health_signal' =>
            'Rollout health is observable. Automation watching '
            . 'WorkerCompatibilityFleet::detailsForNamespace() with a '
            . 'pinned run\'s marker should require supports_required=true '
            . 'on at least one live heartbeat before declaring the '
            . 'rollout healthy. supports_required=false on every '
            . 'heartbeat for a pinned marker is the stuck-rollout and '
            . 'stuck-rollback signal.',
        'heartbeat_ttl_ceiling' =>
            'The worker compatibility heartbeat TTL '
            . '(DW_V2_COMPATIBILITY_HEARTBEAT_TTL, default 30s) is the '
            . 'upper bound on how stale the fleet view may be. Size '
            . 'rollout windows so the old fleet keeps heartbeating until '
            . 'every in-flight run on the old marker has terminated or '
            . 'been continued onto the new marker.',
        'mismatch_reason_verbatim' =>
            'CLI diagnostics surface the canonical mismatch string '
            . 'produced by WorkerCompatibility::mismatchReason() and '
            . 'WorkerCompatibilityFleet::mismatchReason() verbatim. Do '
            . 'not invent new phrasing: product docs, Waterline, and '
            . 'cloud diagnostics all quote the same string so operators '
            . 'can grep one language across surfaces.',
        'wildcard_marker_workers_only' =>
            'The wildcard marker `*` is a worker advertisement surface '
            . 'only. A worker whose supported list is `*` accepts any '
            . 'marker, which is intended for single-build fleets and '
            . 'test harnesses. Runs themselves are never stamped with '
            . '`*` — that would defeat the purpose of pinning.',
    ];

    /**
     * Operator-facing recommendation cited from `dw doctor` when the
     * connection is otherwise healthy. Points the operator at the
     * worker-compatibility contract before they reach for
     * mixed-version deployment troubleshooting.
     *
     * @return array{id: string, severity: string, message: string}
     */
    public static function doctorRecommendation(): array
    {
        return [
            'id' => 'semantics.worker_compatibility_contract',
            'severity' => 'info',
            'message' => 'Mixed-version deployments are driven by opaque compatibility markers. '
                . 'Consult ' . self::CONTRACT_REFERENCE . ' for the '
                . 'run-pinned-at-start, inheritance-through-retry-continue-as-new-and-child-runs, '
                . 'claim-time-enforcement, and no-compatible-worker-is-an-explicit-operational-state '
                . 'guarantees, plus the operator-driven rollout-and-rollback posture and the '
                . 'heartbeat-TTL ceiling that bound fleet staleness.',
        ];
    }
}
