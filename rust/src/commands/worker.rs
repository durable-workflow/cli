//! `worker:*` through the published SDK transport. Register and deregister
//! are one request each; a redirect is refused and a lost response is never
//! submitted again. `worker:register` speaks the worker protocol.

use crate::cli::Input;
use crate::client::Context;
use crate::commands::schedule::{status_cell, text, text_or, truthy, value_name, with_namespace};
use crate::error::CliError;
use crate::{formatter, output, php};
use durable_workflow::RawRequest;
use serde_json::{json, Map, Value};

pub fn list(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(true)?;
    let mut request = RawRequest::get(["workers"]);
    for (option, name) in [("task-queue", "task_queue"), ("status", "status")] {
        if let Some(value) = input.opt(option).flatten() {
            request = request.query(name, value);
        }
    }
    let result = with_namespace(
        context.resource_request(request)?,
        &context.connection.namespace,
        Some("workers"),
    );
    if context.mode.machine_readable() {
        output::print_json_list(context.mode, &result, "workers");
        return Ok(0);
    }
    let namespace = text(result.get("namespace"));
    let workers = php::get(&result, "workers");
    if !truthy(workers) {
        formatter::writeln_out(&format!(
            "<comment>No workers found in namespace {namespace}.</comment>"
        ));
        return Ok(0);
    }
    formatter::writeln_out(&format!("Namespace: {namespace}"));
    let workers = workers.expect("a truthy value is present");
    if !php::is_array(workers) {
        return Err(CliError::Failure(format!(
            "array_map(): Argument #2 ($array) must be of type array, {} given",
            value_name(workers)
        )));
    }
    let mut rows = Vec::new();
    for worker in php::values(workers) {
        if !php::is_array(worker) {
            return Err(CliError::Failure(format!(
                "DurableWorkflow\\Cli\\Commands\\WorkerCommand\\ListCommand::{{closure:DurableWorkflow\\Cli\\Commands\\WorkerCommand\\ListCommand::execute():70}}(): Argument #1 ($worker) must be of type array, {} given",
                value_name(worker)
            )));
        }
        rows.push(vec![
            text(php::get(worker, "worker_id")),
            text_or(php::get(worker, "task_queue"), "-"),
            text_or(php::get(worker, "runtime"), "-"),
            text_or(php::get(worker, "build_id"), "-"),
            status_cell(php::get(worker, "status").unwrap_or(&Value::Null)),
            slots_summary(worker),
            text_or(php::get(worker, "last_heartbeat_at"), "-"),
        ]);
    }
    formatter::writeln_out(&output::render_table(
        &[
            "Worker ID",
            "Task Queue",
            "Runtime",
            "Build ID",
            "Status",
            "Slots (free/cap)",
            "Last Heartbeat",
        ],
        &rows,
    ));
    Ok(0)
}

/// `$slots[$key] ?? $worker[$fallback] ?? null`.
fn slot<'a>(worker: &'a Value, key: &str, fallback: Option<&str>) -> Option<&'a Value> {
    let slots = php::get(worker, "task_slots").filter(|value| php::is_array(value));
    php::array_get(slots, key)
        .filter(|value| !value.is_null())
        .or_else(|| fallback.and_then(|fallback| php::get(worker, fallback)))
        .filter(|value| !value.is_null())
}

/// 2.x `formatSlotPair()` / `slotLine()` labels: a whole available count,
/// and a whole capacity above zero.
fn slot_labels(available: Option<&Value>, capacity: Option<&Value>) -> Option<(String, String)> {
    let available = available
        .filter(|value| php::is_int(Some(value)))
        .map(|value| php::to_string(Some(value)))
        .unwrap_or_else(|| "-".into());
    let capacity = capacity
        .filter(|value| php::is_int(Some(value)) && php::int_cast(Some(value)) > 0)
        .map(|value| php::to_string(Some(value)))
        .unwrap_or_else(|| "-".into());
    (available != "-" || capacity != "-").then_some((available, capacity))
}

/// 2.x `formatSlots()`.
fn slots_summary(worker: &Value) -> String {
    let pair = |available: &str, capacity: &str, fallback: &str| {
        slot_labels(
            slot(worker, available, None),
            slot(worker, capacity, Some(fallback)),
        )
        .map(|(available, capacity)| format!("{available}/{capacity}"))
        .unwrap_or_else(|| "-".into())
    };
    let workflow = pair(
        "workflow_available",
        "workflow_capacity",
        "max_concurrent_workflow_tasks",
    );
    let activity = pair(
        "activity_available",
        "activity_capacity",
        "max_concurrent_activity_tasks",
    );
    if workflow == "-" && activity == "-" {
        "-".into()
    } else {
        format!("wf {workflow} / act {activity}")
    }
}

pub fn describe(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(true)?;
    let worker_id = input.arg("worker-id").unwrap_or_default();
    let result = with_namespace(
        context.resource_request(RawRequest::get(["workers", worker_id]))?,
        &context.connection.namespace,
        None,
    );
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let field = |name: &str| text_or(php::get(&result, name), "-");
    let mut lines = vec![
        format!(
            "<info>Worker: {}</info>",
            text(php::get(&result, "worker_id"))
        ),
        format!("  Namespace: {}", field("namespace")),
        format!("  Task Queue: {}", field("task_queue")),
        format!("  Runtime: {}", field("runtime")),
        format!("  SDK Version: {}", field("sdk_version")),
        format!("  Build ID: {}", field("build_id")),
        format!(
            "  Status: {}",
            status_cell(php::get(&result, "status").unwrap_or(&Value::Null))
        ),
        format!(
            "  Max Concurrent Workflow Tasks: {}",
            field("max_concurrent_workflow_tasks")
        ),
        format!(
            "  Max Concurrent Activity Tasks: {}",
            field("max_concurrent_activity_tasks")
        ),
        format!(
            "  Max Concurrent Worker Sessions: {}",
            field("max_concurrent_worker_sessions")
        ),
    ];
    if php::get(&result, "task_slots").is_some_and(php::is_array) {
        lines.push("  Task Slots (available / capacity):".into());
        for (label, available, capacity, fallback) in [
            (
                "Workflow",
                "workflow_available",
                "workflow_capacity",
                "max_concurrent_workflow_tasks",
            ),
            (
                "Activity",
                "activity_available",
                "activity_capacity",
                "max_concurrent_activity_tasks",
            ),
            (
                "Sessions",
                "session_available",
                "session_capacity",
                "max_concurrent_worker_sessions",
            ),
        ] {
            let line = slot_labels(
                slot(&result, available, None),
                slot(&result, capacity, Some(fallback)),
            )
            .map(|(available, capacity)| format!("{available} / {capacity}"))
            .unwrap_or_else(|| "-".into());
            lines.push(format!("    {label}: {line}"));
        }
    }
    if let Some(metrics) =
        php::get(&result, "process_metrics").filter(|value| php::non_empty_array(value))
    {
        lines.push("  Process Metrics:".into());
        let metric = |name: &str| php::get(metrics, name).filter(|value| !value.is_null());
        if let Some(cpu) = metric("cpu_percent") {
            lines.push(format!("    CPU: {}%", render_number(cpu)));
        }
        if let Some(memory) = metric("memory_bytes") {
            lines.push(format!(
                "    Memory: {}",
                human_bytes(php::int_cast(Some(memory)))
            ));
        }
        if let Some(uptime) = metric("process_uptime_seconds") {
            lines.push(format!(
                "    Uptime: {}",
                human_duration(php::int_cast(Some(uptime)))
            ));
        }
        if let Some(pid) = metric("process_id") {
            lines.push(format!("    PID: {}", php::int_cast(Some(pid))));
        }
        if truthy(php::get(metrics, "host")) {
            lines.push(format!("    Host: {}", text(php::get(metrics, "host"))));
        }
    }
    let interval = php::get(&result, "heartbeat_interval_seconds")
        .filter(|value| php::is_int(Some(value)) && php::int_cast(Some(value)) > 0)
        .map(|value| format!("{}s", php::int_cast(Some(value))))
        .unwrap_or_else(|| "-".into());
    lines.push(format!("  Heartbeat Interval: {interval}"));
    lines.push(format!("  Last Heartbeat: {}", field("last_heartbeat_at")));
    lines.push(format!("  Registered: {}", field("registered_at")));
    lines.push(format!("  Updated: {}", field("updated_at")));
    for (key, label) in [
        ("supported_workflow_types", "Workflow Types"),
        ("supported_activity_types", "Activity Types"),
    ] {
        let types = php::get(&result, key);
        if truthy(types) {
            lines.push(format!("  {label}:"));
            if let Some(types) = types.filter(|value| php::is_array(value)) {
                for kind in php::values(types) {
                    lines.push(format!("    - {}", text(Some(kind))));
                }
            }
        }
    }
    for line in lines {
        formatter::writeln_out(&line);
    }
    Ok(0)
}

/// 2.x `renderNumber()`.
fn render_number(value: &Value) -> String {
    match value {
        Value::Number(number) if number.is_f64() => {
            let fixed = format!("{:.2}", number.as_f64().unwrap_or(0.0));
            let trimmed = fixed.trim_end_matches('0').trim_end_matches('.');
            if trimmed.is_empty() {
                "0".into()
            } else {
                trimmed.into()
            }
        }
        Value::Number(_) => php::to_string(Some(value)),
        _ => "-".into(),
    }
}

/// 2.x `humanBytes()`.
fn human_bytes(bytes: i64) -> String {
    const KIB: f64 = 1024.0;
    let value = bytes as f64;
    if bytes < 1024 {
        format!("{bytes} B")
    } else if bytes < 1024 * 1024 {
        format!("{:.1} KiB", value / KIB)
    } else if bytes < 1024 * 1024 * 1024 {
        format!("{:.1} MiB", value / (KIB * KIB))
    } else {
        format!("{:.2} GiB", value / (KIB * KIB * KIB))
    }
}

/// 2.x `humanDuration()`.
fn human_duration(seconds: i64) -> String {
    if seconds < 60 {
        format!("{seconds}s")
    } else if seconds < 3600 {
        format!("{}m{:02}s", seconds / 60, seconds % 60)
    } else if seconds < 86400 {
        format!("{}h{:02}m", seconds / 3600, (seconds % 3600) / 60)
    } else {
        format!("{}d{:02}h", seconds / 86400, (seconds % 86400) / 3600)
    }
}

/// `RegisterCommand::DIAGNOSTIC_CAPABILITY_MANIFEST`.
fn capability_manifest() -> Value {
    json!({
        "local_activities": {
            "supported": false,
            "minimum_protocol_version": "1.18",
            "reason": "external_diagnostic_worker_does_not_execute_record_local_activity",
        },
        "worker_sessions": {
            "supported": false,
            "minimum_protocol_version": "1.18",
            "reason": "external_diagnostic_worker_has_no_session_lifecycle",
        },
        "sticky_execution": {
            "supported": false,
            "minimum_protocol_version": "1.18",
            "reason": "external_diagnostic_worker_uses_complete_durable_history_replay",
        },
    })
}

pub fn register(context: &Context, input: &Input) -> Result<i32, CliError> {
    let mut body = Map::new();
    // `$argument ?: null`: "" and "0" let the Server assign an ID.
    if let Some(worker_id) = input
        .arg("worker-id")
        .filter(|id| !id.is_empty() && *id != "0")
    {
        body.insert("worker_id".into(), json!(worker_id));
    }
    if let Some(task_queue) = input.opt("task-queue").flatten() {
        body.insert("task_queue".into(), json!(task_queue));
    }
    let runtime = input
        .opt("runtime")
        .flatten()
        .map(php::trim)
        .filter(|runtime| !runtime.is_empty())
        .unwrap_or("external");
    body.insert("runtime".into(), json!(runtime));
    for (option, field) in [("sdk-version", "sdk_version"), ("build-id", "build_id")] {
        if let Some(value) = input.opt(option).flatten() {
            body.insert(field.into(), json!(value));
        }
    }
    body.insert("capability_manifest".into(), capability_manifest());
    for (option, field) in [
        ("workflow-type", "supported_workflow_types"),
        ("activity-type", "supported_activity_types"),
    ] {
        let values = input
            .opt_all(option)
            .into_iter()
            .map(php::trim)
            .filter(|value| !value.is_empty())
            .collect::<Vec<_>>();
        if !values.is_empty() {
            body.insert(field.into(), json!(values));
        }
    }
    for (option, field) in [
        ("max-workflow-tasks", "max_concurrent_workflow_tasks"),
        ("max-activity-tasks", "max_concurrent_activity_tasks"),
    ] {
        if let Some(value) = positive_int_option(input, option)? {
            body.insert(field.into(), json!(value));
        }
    }
    context.preflight(true)?;
    let result = context.worker_request(
        RawRequest::post(["worker", "register"]).json(Value::Object(body.clone())),
        "/worker/register",
    )?;
    if php::get(&result, "registered") != Some(&Value::Bool(true)) {
        return Err(CliError::Failure(
            "Worker registration was not confirmed by the server; expected registered=true in the /worker/register response."
                .into(),
        ));
    }
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    formatter::writeln_out("<info>Worker registered</info>");
    formatter::writeln_out(&format!(
        "  Worker ID: {}",
        text_or(php::get(&result, "worker_id"), "-")
    ));
    formatter::writeln_out(&format!(
        "  Task Queue: {}",
        text_or(body.get("task_queue"), "-")
    ));
    formatter::writeln_out(&format!("  Runtime: {}", text_or(body.get("runtime"), "-")));
    formatter::writeln_out(&format!(
        "  Build ID: {}",
        text_or(body.get("build_id"), "(unversioned)")
    ));
    Ok(0)
}

/// 2.x `positiveIntOption()`.
pub(crate) fn positive_int_option(input: &Input, option: &str) -> Result<Option<i64>, CliError> {
    let Some(value) = input
        .opt(option)
        .flatten()
        .filter(|value| !value.is_empty())
    else {
        return Ok(None);
    };
    if !php::ctype_digit(value) || php::string_to_int(value) < 1 {
        return Err(CliError::Invalid(format!(
            "--{option} must be a positive integer."
        )));
    }
    Ok(Some(php::string_to_int(value)))
}

pub fn deregister(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(true)?;
    let worker_id = input.arg("worker-id").unwrap_or_default();
    let result = context.resource_request(RawRequest::delete(["workers", worker_id]))?;
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let shown = match php::get(&result, "worker_id").filter(|value| !value.is_null()) {
        Some(value) => text(Some(value)),
        None => formatter::display_text(worker_id),
    };
    formatter::writeln_out(&format!(
        "<info>Worker {shown}: {}</info>",
        text_or(php::get(&result, "outcome"), "unknown")
    ));
    Ok(0)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn numbers_bytes_and_durations_match_2x() {
        assert_eq!(render_number(&json!(12.5)), "12.5");
        assert_eq!(render_number(&json!(3.0)), "3");
        assert_eq!(render_number(&json!(0.001)), "0");
        assert_eq!(render_number(&json!(7)), "7");
        assert_eq!(render_number(&json!("7")), "-");
        assert_eq!(human_bytes(512), "512 B");
        assert_eq!(human_bytes(1536), "1.5 KiB");
        assert_eq!(human_bytes(5 * 1024 * 1024), "5.0 MiB");
        assert_eq!(human_bytes(3 * 1024 * 1024 * 1024), "3.00 GiB");
        assert_eq!(human_duration(59), "59s");
        assert_eq!(human_duration(61), "1m01s");
        assert_eq!(human_duration(3661), "1h01m");
        assert_eq!(human_duration(90061), "1d01h");
    }
}
