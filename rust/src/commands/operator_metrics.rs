//! The human `system:operator-metrics` snapshot, section by section as 2.x
//! renders it.

use crate::commands::schedule::truthy;
use crate::{formatter, output, php};
use serde_json::{json, Value};

fn out(line: &str) {
    formatter::writeln_out(line);
}

/// `(int) ($section[$key] ?? 0)`.
fn int(section: &Value, key: &str) -> i64 {
    php::int_cast(php::get(section, key))
}

fn has(section: &Value, key: &str) -> bool {
    php::is_array(section) && php::has_key(section, key)
}

/// `is_string($section[$key] ?? null)`, as display text.
fn string(section: &Value, key: &str) -> Option<String> {
    php::get(section, key)
        .and_then(Value::as_str)
        .map(formatter::display_text)
}

/// A non-empty string field.
fn nonempty(section: &Value, key: &str) -> Option<String> {
    php::get(section, key)
        .and_then(Value::as_str)
        .filter(|text| !text.is_empty())
        .map(formatter::display_text)
}

/// `is_array($value) ? $value : []`.
fn section<'a>(value: Option<&'a Value>, empty: &'a Value) -> &'a Value {
    value.filter(|value| php::is_array(value)).unwrap_or(empty)
}

/// `$section === []`.
fn is_empty(section: &Value) -> bool {
    !php::non_empty_array(section)
}

pub fn render(result: &Value) {
    let empty = json!([]);
    let namespace = result.get("namespace").and_then(Value::as_str);
    let metrics = section(result.get("operator_metrics"), &empty);
    out(&format!(
        "<info>Operator metrics</info>{}{}",
        namespace
            .map(|namespace| format!(" for namespace {}", formatter::display_text(namespace)))
            .unwrap_or_default(),
        string(metrics, "generated_at")
            .map(|at| format!(" (generated {at})"))
            .unwrap_or_default(),
    ));
    out("");
    let part = |key: &str| section(php::get(metrics, key), &empty);
    runs(part("runs"));
    tasks(part("tasks"));
    backlog(part("backlog"));
    repair(part("repair"));
    projections(part("projections"));
    workers(part("workers"));
    backend(part("backend"));
    matching_role(part("matching_role"));
    schedules(part("schedules"));
    activities(part("activities"));
    repair_policy(part("repair_policy"));
}

fn runs(runs: &Value) {
    out("<info>Runs</info>");
    out(&format!(
        "  Repair needed:        {}",
        int(runs, "repair_needed")
    ));
    if has(runs, "max_repair_needed_age_ms") {
        out(&format!(
            "  Oldest repair-needed age: {} ms",
            int(runs, "max_repair_needed_age_ms")
        ));
    }
    if let Some(at) = string(runs, "oldest_repair_needed_at") {
        out(&format!("  Oldest repair-needed at:  {at}"));
    }
    out(&format!(
        "  Claim failed:         {}",
        int(runs, "claim_failed")
    ));
    out(&format!(
        "  Compatibility blocked: {}",
        int(runs, "compatibility_blocked")
    ));
    if has(runs, "waiting") {
        out(&format!(
            "  Waiting (durable resume): {}",
            int(runs, "waiting")
        ));
    }
    if has(runs, "max_wait_age_ms") {
        out(&format!(
            "  Oldest wait age:      {} ms",
            int(runs, "max_wait_age_ms")
        ));
    }
    if let Some(at) = string(runs, "oldest_wait_started_at") {
        out(&format!("  Oldest wait started at: {at}"));
    }
    out("");
}

fn tasks(tasks: &Value) {
    out("<info>Tasks</info>");
    out(&format!(
        "  Queue depth:          {} ready ({} due), {} delayed, {} leased",
        int(tasks, "ready"),
        int(tasks, "ready_due"),
        int(tasks, "delayed"),
        int(tasks, "leased"),
    ));
    out(&format!(
        "  Unhealthy (duplicate-risk roll-up): {} (dispatch failed {}, claim failed {}, dispatch overdue {}, lease expired {})",
        int(tasks, "unhealthy"),
        int(tasks, "dispatch_failed"),
        int(tasks, "claim_failed"),
        int(tasks, "dispatch_overdue"),
        int(tasks, "lease_expired"),
    ));
    for (age_key, age_label, at_key, at_label) in [
        (
            "max_unhealthy_age_ms",
            "  Oldest unhealthy age:     ",
            "oldest_unhealthy_at",
            "  Oldest unhealthy at:      ",
        ),
        (
            "max_lease_expired_age_ms",
            "  Oldest lease-expired age: ",
            "oldest_lease_expired_at",
            "  Oldest lease expired at:  ",
        ),
        (
            "max_ready_due_age_ms",
            "  Oldest ready-due age:     ",
            "oldest_ready_due_at",
            "  Oldest ready-due at:      ",
        ),
        (
            "max_dispatch_overdue_age_ms",
            "  Oldest dispatch-overdue age: ",
            "oldest_dispatch_overdue_since",
            "  Oldest dispatch-overdue since: ",
        ),
        (
            "max_claim_failed_age_ms",
            "  Oldest claim-failed age:  ",
            "oldest_claim_failed_at",
            "  Oldest claim failed at:   ",
        ),
        (
            "max_dispatch_failed_age_ms",
            "  Oldest dispatch-failed age: ",
            "oldest_dispatch_failed_at",
            "  Oldest dispatch failed at: ",
        ),
    ] {
        if has(tasks, age_key) {
            out(&format!("{age_label}{} ms", int(tasks, age_key)));
        }
        if let Some(at) = string(tasks, at_key) {
            out(&format!("{at_label}{at}"));
        }
    }
    out("");
}

fn backlog(backlog: &Value) {
    out("<info>Backlog</info>");
    for (label, key) in [
        ("  Runnable tasks:       ", "runnable_tasks"),
        ("  Delayed tasks:        ", "delayed_tasks"),
        ("  Leased tasks:         ", "leased_tasks"),
        ("  Unhealthy tasks:      ", "unhealthy_tasks"),
        ("  Repair needed runs:   ", "repair_needed_runs"),
        ("  Claim failed runs:    ", "claim_failed_runs"),
        (
            "  Compatibility blocked runs: ",
            "compatibility_blocked_runs",
        ),
    ] {
        out(&format!("{label}{}", int(backlog, key)));
    }
    out(&format!(
        "  Oldest compatibility-blocked age: {} ms",
        int(backlog, "max_compatibility_blocked_age_ms")
    ));
    if let Some(at) = string(backlog, "oldest_compatibility_blocked_started_at") {
        out(&format!("  Oldest compatibility-blocked at:  {at}"));
    }
    out("");
}

fn repair(repair: &Value) {
    out("<info>Stuck-run detectors</info>");
    out(&format!(
        "  Missing-task candidates: {} ({} selected this pass)",
        int(repair, "missing_task_candidates"),
        int(repair, "selected_missing_task_candidates"),
    ));
    out(&format!(
        "  Oldest missing-task age: {} ms",
        int(repair, "max_missing_run_age_ms")
    ));
    if let Some(at) = string(repair, "oldest_missing_run_started_at") {
        out(&format!("  Oldest missing run at:   {at}"));
    }
    out("");
}

fn projections(projections: &Value) {
    let empty = json!([]);
    let summaries = section(php::get(projections, "run_summaries"), &empty);
    let age = has(summaries, "max_missing_run_age_ms");
    let started = string(summaries, "oldest_missing_run_started_at");
    if !age && started.is_none() {
        return;
    }
    out("<info>Projection lag</info>");
    if age {
        out(&format!(
            "  Run-summary missing age: {} ms",
            int(summaries, "max_missing_run_age_ms")
        ));
    }
    if let Some(at) = started {
        out(&format!("  Oldest run-summary missing run at: {at}"));
    }
    out("");
}

fn workers(workers: &Value) {
    let required = php::get(workers, "required_compatibility")
        .filter(|value| php::is_scalar(Some(value)) && !php::to_string(Some(value)).is_empty())
        .map(|value| formatter::display_text(&php::to_string(Some(value))))
        .unwrap_or_else(|| "(unset)".into());
    let active = int(workers, "active_workers");
    let scopes = int(workers, "active_worker_scopes");
    let supporting = int(workers, "active_workers_supporting_required");
    out("<info>Worker compatibility fleet</info>");
    out(&format!("  Required compatibility: {required}"));
    out(&format!(
        "  Active workers:         {active} ({scopes} queue scopes, {supporting} supporting required)"
    ));
    if active > 0 && supporting == 0 {
        out("  <comment>No active worker supports the required compatibility marker — new ready tasks will remain claim-blocked.</comment>");
    }
    let empty = json!([]);
    let fleet = section(php::get(workers, "fleet"), &empty);
    let mut rows = Vec::new();
    for entry in php::values(fleet) {
        if !php::is_array(entry) {
            continue;
        }
        let supported = php::get(entry, "supported")
            .filter(|value| php::is_array(value))
            .map(|value| {
                php::values(value)
                    .into_iter()
                    .filter_map(Value::as_str)
                    .collect::<Vec<_>>()
                    .join(",")
            })
            .unwrap_or_default();
        let field = |key: &str, default: &str| {
            formatter::display_text(&php::or(php::get(entry, key), default))
        };
        rows.push(vec![
            field("worker_id", ""),
            field("connection", "-"),
            field("queue", "-"),
            if supported.is_empty() {
                "-".into()
            } else {
                formatter::display_text(&supported)
            },
            if php::get(entry, "supports_required") == Some(&Value::Bool(true)) {
                "yes".into()
            } else {
                "no".into()
            },
            string(entry, "recorded_at").unwrap_or_else(|| "-".into()),
        ]);
    }
    if !rows.is_empty() {
        out("");
        out(&output::render_table(
            &[
                "Worker",
                "Connection",
                "Queue",
                "Supported",
                "Supports required",
                "Recorded",
            ],
            &rows,
        ));
    }
    out("");
}

fn backend(backend: &Value) {
    let supported = php::get(backend, "supported") == Some(&Value::Bool(true));
    out("<info>Backend capabilities (admission roll-up)</info>");
    out(&format!(
        "  Supported:            {}",
        if supported { "yes" } else { "no" }
    ));
    if let Some(severity) = nonempty(backend, "severity") {
        out(&format!("  Severity:             {severity}"));
    }
    let empty = json!([]);
    for component in ["database", "queue", "cache"] {
        let detail = section(php::get(backend, component), &empty);
        let first = if component == "cache" {
            "store"
        } else {
            "connection"
        };
        let label = format!(
            "{}/{}",
            php::or(php::get(detail, first), "unknown"),
            php::or(php::get(detail, "driver"), "unknown")
        );
        out(&format!(
            "  {:<22} {}",
            format!("{}:", php::ucfirst(component)),
            formatter::display_text(&label)
        ));
    }
    let issues = section(php::get(backend, "issues"), &empty);
    if !is_empty(issues) {
        out("  <comment>Issues:</comment>");
        for issue in php::values(issues) {
            if !php::is_array(issue) {
                continue;
            }
            let summary = ["summary", "code", "component"]
                .into_iter()
                .find_map(|key| php::get(issue, key).filter(|value| !value.is_null()))
                .map(|value| php::to_string(Some(value)))
                .unwrap_or_else(|| "capability issue".into());
            let severity = php::or(php::get(issue, "severity"), "warning");
            out(&format!(
                "    [{}] {}",
                formatter::display_text(&severity),
                formatter::display_text(&summary)
            ));
        }
    }
    out("");
}

fn matching_role(role: &Value) {
    if is_empty(role) {
        return;
    }
    out("<info>Matching-role (this node)</info>");
    if has(role, "queue_wake_enabled") {
        out(&format!(
            "  Queue wake enabled:   {}",
            if truthy(php::get(role, "queue_wake_enabled")) {
                "yes"
            } else {
                "no"
            }
        ));
    }
    if let Some(shape) = nonempty(role, "shape") {
        out(&format!("  Shape:                {shape}"));
    }
    if let Some(mode) = nonempty(role, "task_dispatch_mode") {
        out(&format!("  Task dispatch mode:   {mode}"));
    }
    let primitives = php::get(role, "partition_primitives")
        .filter(|value| php::is_array(value))
        .map(|value| {
            php::values(value)
                .into_iter()
                .filter_map(Value::as_str)
                .filter(|text| !text.is_empty())
                .map(formatter::display_text)
                .collect::<Vec<_>>()
        })
        .unwrap_or_default();
    if !primitives.is_empty() {
        out(&format!(
            "  Partition primitives: {}",
            primitives.join(", ")
        ));
    }
    if let Some(model) = nonempty(role, "backpressure_model") {
        out(&format!("  Backpressure model:  {model}"));
    }
    let empty = json!([]);
    let limits = section(php::get(role, "discovery_limits"), &empty);
    if !is_empty(limits) {
        out("  Discovery limits:");
        for (key, label, unit) in [
            ("poll_batch_cap", "    Poll batch cap:              ", ""),
            (
                "availability_ceiling_seconds",
                "    Availability ceiling:        ",
                "s",
            ),
            (
                "wake_signal_ttl_seconds",
                "    Wake signal TTL:             ",
                "s",
            ),
            (
                "workflow_task_lease_seconds",
                "    Workflow task lease:         ",
                "s",
            ),
            (
                "activity_task_lease_seconds",
                "    Activity task lease:         ",
                "s",
            ),
        ] {
            if has(limits, key) {
                out(&format!("{label}{}{unit}", int(limits, key)));
            }
        }
    }
    out("");
}

fn schedules(schedules: &Value) {
    if is_empty(schedules) {
        return;
    }
    out("<info>Schedules</info>");
    out(&format!(
        "  Active {}, paused {}, missed {}, oldest overdue {} ms",
        int(schedules, "active"),
        int(schedules, "paused"),
        int(schedules, "missed"),
        int(schedules, "max_overdue_ms"),
    ));
    if let Some(at) = string(schedules, "oldest_overdue_at") {
        out(&format!("  Oldest overdue at:    {at}"));
    }
    out(&format!(
        "  Lifetime fires: {} ({} failures)",
        int(schedules, "fires_total"),
        int(schedules, "failures_total"),
    ));
    out("");
}

fn activities(activities: &Value) {
    if is_empty(activities) {
        return;
    }
    out("<info>Activities</info>");
    out(&format!(
        "  Open {} (pending {}, running {}), retrying {}",
        int(activities, "open"),
        int(activities, "pending"),
        int(activities, "running"),
        int(activities, "retrying"),
    ));
    if has(activities, "max_retrying_age_ms") {
        out(&format!(
            "  Oldest retrying age:  {} ms",
            int(activities, "max_retrying_age_ms")
        ));
    }
    if let Some(at) = string(activities, "oldest_retrying_started_at") {
        out(&format!("  Oldest retrying started at: {at}"));
    }
    if has(activities, "timeout_overdue") {
        out(&format!(
            "  Timeout overdue:      {}",
            int(activities, "timeout_overdue")
        ));
    }
    if has(activities, "max_timeout_overdue_age_ms") {
        out(&format!(
            "  Oldest timeout-overdue age: {} ms",
            int(activities, "max_timeout_overdue_age_ms")
        ));
    }
    if let Some(at) = string(activities, "oldest_timeout_overdue_at") {
        out(&format!("  Oldest timeout-overdue at:  {at}"));
    }
    out(&format!(
        "  Failed attempts:      {} (max attempts on a single execution: {})",
        int(activities, "failed_attempts"),
        int(activities, "max_attempt_count"),
    ));
    out("");
}

fn repair_policy(policy: &Value) {
    out("<info>Repair policy</info>");
    out(&format!(
        "  Redispatch after:     {}s",
        int(policy, "redispatch_after_seconds")
    ));
    out(&format!(
        "  Loop throttle:        {}s",
        int(policy, "loop_throttle_seconds")
    ));
    out(&format!(
        "  Scan limit:           {}",
        int(policy, "scan_limit")
    ));
    out(&format!(
        "  Backoff cap:          {}s",
        int(policy, "failure_backoff_max_seconds")
    ));
}
