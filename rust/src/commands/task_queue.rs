//! `task-queue:*` through the published SDK transport. Drain, promote and
//! resume, and the activity dispatch pause and resume, are one request each;
//! a redirect is refused and a lost response is never submitted again.

use crate::cli::Input;
use crate::client::Context;
use crate::commands::schedule::{
    nonempty_scalar, status_cell, text, text_or, truthy, value_name, with_namespace,
    write_namespace_line,
};
use crate::error::CliError;
use crate::{formatter, output, php};
use durable_workflow::RawRequest;
use serde_json::{json, Value};

pub fn list(context: &Context, _input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let result = with_namespace(
        context.resource_request(RawRequest::get(["task-queues"]))?,
        &context.connection.namespace,
        Some("task_queues"),
    );
    if context.mode.machine_readable() {
        output::print_json_list(context.mode, &result, "task_queues");
        return Ok(0);
    }
    let namespace = text(result.get("namespace"));
    let queues = php::get(&result, "task_queues");
    if !truthy(queues) {
        formatter::writeln_out(&format!(
            "<comment>No task queues found in namespace {namespace}.</comment>"
        ));
        return Ok(0);
    }
    formatter::writeln_out(&format!("Namespace: {namespace}"));
    let queues = array_items(queues)?;
    // Only a Server that reports activity_dispatch (native, Server #577)
    // adds the column; 2.x output is unchanged.
    let dispatch_column = queues
        .iter()
        .any(|queue| reported_dispatch(queue).is_some());
    let rows = queues
        .into_iter()
        .map(|queue| {
            let mut row = vec![
                text(php::get(queue, "name")),
                status_cell(&json!(admission_status(queue, "workflow_tasks"))),
                status_cell(&json!(admission_status(queue, "activity_tasks"))),
                status_cell(&json!(admission_status(queue, "query_tasks"))),
            ];
            if dispatch_column {
                row.push(match reported_dispatch(queue) {
                    Some(dispatch) => status_cell(&json!(dispatch_state(Some(dispatch)))),
                    None => "-".into(),
                });
            }
            row
        })
        .collect::<Vec<_>>();
    let mut headers = vec![
        "Task Queue",
        "Workflow Admission",
        "Activity Admission",
        "Query Admission",
    ];
    if dispatch_column {
        headers.push("Activity Dispatch");
    }
    formatter::writeln_out(&output::render_table(&headers, &rows));
    Ok(0)
}

/// The items of a non-empty list value; 2.x's `array_map()` refuses others.
fn array_items(value: Option<&Value>) -> Result<Vec<&Value>, CliError> {
    match value {
        Some(value) if php::is_array(value) => Ok(php::values(value)),
        Some(value) => Err(CliError::Failure(format!(
            "array_map(): Argument #2 ($array) must be of type array, {} given",
            value_name(value)
        ))),
        None => Ok(Vec::new()),
    }
}

/// The first of `keys` with a non-null value: `$a[k1] ?? $a[k2] ?? null`.
fn first<'a>(value: Option<&'a Value>, keys: &[&str]) -> Option<&'a Value> {
    keys.iter()
        .find_map(|key| php::array_get(value, key).filter(|value| !value.is_null()))
}

/// 2.x `admissionStatus()`.
fn admission_status(queue: &Value, kind: &str) -> String {
    let admission = php::get(queue, "admission");
    let Some(entry) = php::array_get(admission, kind).filter(|value| php::is_array(value)) else {
        return "-".into();
    };
    let entry = Some(entry);
    let status = php::or(php::array_get(entry, "status"), "-");
    if kind == "query_tasks" {
        return match first(entry, &["remaining_pending_capacity"]) {
            None => status,
            Some(remaining) => format!("{status} ({} left)", php::to_string(Some(remaining))),
        };
    }
    let mut details = Vec::new();
    let remaining = first(
        entry,
        &[
            "server_remaining_active_lease_capacity",
            "remaining_server_capacity",
            "available_slot_count",
        ],
    );
    if let Some(remaining) = remaining {
        details.push(format!("{} leases left", php::to_string(Some(remaining))));
    }
    for (key, template) in [
        (
            "server_remaining_namespace_active_lease_capacity",
            " namespace leases left",
        ),
        ("server_remaining_dispatch_capacity", "/min left"),
        (
            "server_remaining_namespace_dispatch_capacity",
            " namespace/min left",
        ),
    ] {
        if let Some(value) = first(entry, &[key]) {
            details.push(format!("{}{template}", php::to_string(Some(value))));
        }
    }
    // `$group !== null && $group !== ''`: only an empty string is blank.
    let group = first(entry, &["server_dispatch_budget_group"])
        .filter(|group| !matches!(group, Value::String(text) if text.is_empty()));
    let group_remaining = first(entry, &["server_remaining_budget_group_dispatch_capacity"]);
    if let Some(group) = group {
        let mut detail = format!("group {}", php::to_string(Some(group)));
        if let Some(remaining) = group_remaining {
            detail.push_str(&format!(" {}/min left", php::to_string(Some(remaining))));
        }
        details.push(detail);
    } else if let Some(remaining) = group_remaining {
        details.push(format!(
            "{} group/min left",
            php::to_string(Some(remaining))
        ));
    }
    if details.is_empty() {
        status
    } else {
        format!("{status} ({})", details.join(", "))
    }
}

pub fn describe(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let task_queue = input.arg("task-queue").unwrap_or_default();
    let result = with_namespace(
        context.resource_request(RawRequest::get(["task-queues", task_queue]))?,
        &context.connection.namespace,
        None,
    );
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let mut lines = vec![
        format!(
            "<info>Task Queue: {}</info>",
            text(php::get(&result, "name"))
        ),
        format!("Namespace: {}", text(result.get("namespace"))),
        String::new(),
        "Stats:".into(),
    ];
    let stats = php::get(&result, "stats");
    let stat = |path: &[&str], default: &str| {
        let value = path
            .iter()
            .try_fold(stats, |value, key| Some(php::array_get(value, key)))
            .flatten();
        text_or(value, default)
    };
    for (label, path, default) in [
        ("Backlog", &["approximate_backlog_count"][..], "0"),
        ("Backlog Age", &["approximate_backlog_age"][..], "-"),
        (
            "Workflow Ready",
            &["workflow_tasks", "ready_count"][..],
            "0",
        ),
        (
            "Workflow Leased",
            &["workflow_tasks", "leased_count"][..],
            "0",
        ),
        (
            "Workflow Expired Leases",
            &["workflow_tasks", "expired_lease_count"][..],
            "0",
        ),
        (
            "Activity Ready",
            &["activity_tasks", "ready_count"][..],
            "0",
        ),
        (
            "Activity Leased",
            &["activity_tasks", "leased_count"][..],
            "0",
        ),
        (
            "Activity Expired Leases",
            &["activity_tasks", "expired_lease_count"][..],
            "0",
        ),
    ] {
        lines.push(format!("  {label}: {}", stat(path, default)));
    }
    let pollers = php::array_get(stats, "pollers");
    lines.push(format!(
        "  Pollers: active={} stale={}",
        php::int_cast(php::array_get(pollers, "active_count")),
        php::int_cast(php::array_get(pollers, "stale_count")),
    ));
    lines.push(String::new());
    if let Some(dispatch) = reported_dispatch(&result) {
        lines.push(format!(
            "Activity Dispatch: {}",
            status_cell(&json!(dispatch_state(Some(dispatch))))
        ));
        lines.extend(
            dispatch_detail_lines(Some(dispatch))
                .into_iter()
                .map(|line| format!("  {line}")),
        );
        lines.push(String::new());
    }
    for line in lines {
        formatter::writeln_out(&line);
    }

    if let Some(admission) =
        php::get(&result, "admission").filter(|value| php::non_empty_array(value))
    {
        formatter::writeln_out("Admission:");
        let rows = [
            ("Workflow Tasks", "workflow_tasks", false),
            ("Activity Tasks", "activity_tasks", false),
            ("Query Tasks", "query_tasks", true),
        ]
        .into_iter()
        .map(|(label, kind, query)| admission_row(label, php::get(admission, kind), query))
        .collect::<Vec<_>>();
        formatter::writeln_out(&output::render_table(
            &["Kind", "Status", "Capacity", "Dispatch", "Source"],
            &rows,
        ));
        formatter::writeln_out("");
    }

    let leases = php::get(&result, "current_leases");
    if truthy(leases) {
        let mut rows = Vec::new();
        for lease in array_items(leases)? {
            if !php::is_array(lease) {
                return Err(CliError::Failure(format!(
                    "DurableWorkflow\\Cli\\Commands\\TaskQueueCommand\\DescribeCommand::{{closure:DurableWorkflow\\Cli\\Commands\\TaskQueueCommand\\DescribeCommand::execute():74}}(): Argument #1 ($lease) must be of type array, {} given",
                    value_name(lease)
                )));
            }
            let expired = truthy(php::get(lease, "is_expired"));
            rows.push(vec![
                text_or(php::get(lease, "task_id"), "-"),
                text_or(php::get(lease, "task_type"), "-"),
                text_or(php::get(lease, "workflow_id"), "-"),
                text_or(php::get(lease, "lease_owner"), "-"),
                text_or(php::get(lease, "lease_expires_at"), "-"),
                output::format_status(Some(&json!(if expired { "EXPIRED" } else { "active" }))),
            ]);
        }
        formatter::writeln_out("Current Leases:");
        formatter::writeln_out(&output::render_table(
            &[
                "Task ID",
                "Type",
                "Workflow ID",
                "Lease Owner",
                "Lease Expires",
                "State",
            ],
            &rows,
        ));
        formatter::writeln_out("");
    }

    let pollers = php::get(&result, "pollers");
    if !truthy(pollers) {
        formatter::writeln_out("<comment>No active pollers.</comment>");
        return Ok(0);
    }
    let rows = array_items(pollers)?
        .into_iter()
        .map(|poller| {
            vec![
                text_or(php::get(poller, "worker_id"), "-"),
                text_or(php::get(poller, "runtime"), "-"),
                text_or(php::get(poller, "build_id"), "-"),
                text_or(php::get(poller, "last_heartbeat_at"), "-"),
                status_cell(php::get(poller, "status").unwrap_or(&Value::Null)),
            ]
        })
        .collect::<Vec<_>>();
    formatter::writeln_out("Pollers:");
    formatter::writeln_out(&output::render_table(
        &[
            "Worker ID",
            "Runtime",
            "Build ID",
            "Last Heartbeat",
            "Status",
        ],
        &rows,
    ));
    Ok(0)
}

/// `$value ?? '-'` for a sprintf `%s` part.
fn dash(value: Option<&Value>) -> String {
    php::or(value, "-")
}

/// 2.x `admissionRow()`.
fn admission_row(label: &str, admission: Option<&Value>, query_tasks: bool) -> Vec<String> {
    let Some(fields) = admission.filter(|value| php::is_array(value)) else {
        return vec![label.into(), "-".into(), "-".into(), "-".into(), "-".into()];
    };
    let entry = Some(fields);
    let get = |key: &str| first(entry, &[key]);
    let mut capacity = Vec::new();
    let mut dispatch = Vec::new();
    let mut source = get("budget_source");
    if query_tasks {
        let (pending, limit) = (
            get("approximate_pending_count"),
            get("max_pending_per_queue"),
        );
        if pending.is_some() || limit.is_some() {
            capacity.push(format!("pending {}/{}", dash(pending), dash(limit)));
        }
        if php::has_key(fields, "remaining_pending_capacity") {
            capacity.push(format!(
                "remaining {}",
                php::to_string(php::get(fields, "remaining_pending_capacity"))
            ));
        }
        if php::has_key(fields, "lock_supported") {
            let supported = truthy(php::get(fields, "lock_supported"));
            capacity.push(format!("lock {}", if supported { "yes" } else { "no" }));
        }
    } else {
        let active = first(
            entry,
            &[
                "server_active_lease_count",
                "active_lease_count",
                "leased_count",
            ],
        );
        let limit = first(
            entry,
            &[
                "server_max_active_leases_per_queue",
                "server_limit",
                "configured_slot_count",
            ],
        );
        if active.is_some() || limit.is_some() {
            capacity.push(format!("active {}/{}", dash(active), dash(limit)));
        }
        if let Some(remaining) = first(entry, &["server_remaining_active_lease_capacity"])
            .or_else(|| get("remaining_server_capacity"))
            .or_else(|| get("available_slot_count"))
        {
            capacity.push(format!("remaining {}", php::to_string(Some(remaining))));
        }
        let (namespace_active, namespace_limit) = (
            get("server_namespace_active_lease_count"),
            get("server_max_active_leases_per_namespace"),
        );
        if namespace_active.is_some() || namespace_limit.is_some() {
            capacity.push(format!(
                "namespace active {}/{}",
                dash(namespace_active),
                dash(namespace_limit)
            ));
        }
        if let Some(remaining) = get("server_remaining_namespace_active_lease_capacity") {
            capacity.push(format!(
                "namespace remaining {}",
                php::to_string(Some(remaining))
            ));
        }
        let (count, limit) = (
            get("server_dispatch_count_this_minute"),
            get("server_max_dispatches_per_minute"),
        );
        if count.is_some() || limit.is_some() {
            dispatch.push(format!("dispatch {}/{}/min", dash(count), dash(limit)));
        }
        if let Some(remaining) = get("server_remaining_dispatch_capacity") {
            dispatch.push(format!(
                "dispatch remaining {}",
                php::to_string(Some(remaining))
            ));
        }
        let (count, limit) = (
            get("server_namespace_dispatch_count_this_minute"),
            get("server_max_dispatches_per_minute_per_namespace"),
        );
        if count.is_some() || limit.is_some() {
            dispatch.push(format!(
                "namespace dispatch {}/{}/min",
                dash(count),
                dash(limit)
            ));
        }
        if let Some(remaining) = get("server_remaining_namespace_dispatch_capacity") {
            dispatch.push(format!(
                "namespace dispatch remaining {}",
                php::to_string(Some(remaining))
            ));
        }
        let group = get("server_dispatch_budget_group")
            .filter(|group| !matches!(group, Value::String(text) if text.is_empty()));
        let (count, limit) = (
            get("server_budget_group_dispatch_count_this_minute"),
            get("server_max_dispatches_per_minute_per_budget_group"),
        );
        if count.is_some() || limit.is_some() {
            dispatch.push(match group {
                Some(group) => format!(
                    "group {} {}/{}/min",
                    php::to_string(Some(group)),
                    dash(count),
                    dash(limit)
                ),
                None => format!("group {}/{}/min", dash(count), dash(limit)),
            });
        } else if let Some(group) = group {
            dispatch.push(format!("group {}", php::to_string(Some(group))));
        }
        if let Some(remaining) = get("server_remaining_budget_group_dispatch_capacity") {
            dispatch.push(format!(
                "group remaining {}",
                php::to_string(Some(remaining))
            ));
        }
        if first(
            entry,
            &["server_max_active_leases_per_queue", "server_limit"],
        )
        .is_some()
            || get("server_max_active_leases_per_namespace").is_some()
            || get("server_max_dispatches_per_minute").is_some()
            || get("server_max_dispatches_per_minute_per_namespace").is_some()
            || get("server_max_dispatches_per_minute_per_budget_group").is_some()
        {
            source = get("server_budget_source").or(source);
        }
    }
    let joined = |parts: Vec<String>| {
        if parts.is_empty() {
            "-".to_string()
        } else {
            formatter::display_text(&parts.join("; "))
        }
    };
    let source = source
        .filter(|value| php::is_scalar(Some(value)) && !php::to_string(Some(value)).is_empty())
        .map(|value| text(Some(value)))
        .unwrap_or_else(|| "-".into());
    vec![
        label.into(),
        status_cell(php::array_get(entry, "status").unwrap_or(&Value::Null)),
        joined(capacity),
        joined(dispatch),
        source,
    ]
}

pub fn build_ids(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let task_queue = input.arg("task-queue").unwrap_or_default();
    let result = with_namespace(
        context.resource_request(RawRequest::get(["task-queues", task_queue, "build-ids"]))?,
        &context.connection.namespace,
        None,
    );
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let shown_queue = match php::get(&result, "task_queue").filter(|value| !value.is_null()) {
        Some(value) => text(Some(value)),
        None => formatter::display_text(task_queue),
    };
    formatter::writeln_out(&format!("<info>Task Queue: {shown_queue}</info>"));
    formatter::writeln_out(&format!("Namespace: {}", text(result.get("namespace"))));
    if let Some(stale) = php::get(&result, "stale_after_seconds").filter(|value| !value.is_null()) {
        formatter::writeln_out(&format!("Stale threshold: {}s", text(Some(stale))));
    }
    formatter::writeln_out("");
    let build_ids = php::get(&result, "build_ids");
    if !truthy(build_ids) {
        formatter::writeln_out("<comment>No workers have registered on this task queue.</comment>");
        return Ok(0);
    }
    let mut rows = Vec::new();
    for entry in array_items(build_ids)? {
        if !php::is_array(entry) {
            return Err(CliError::Failure(format!(
                "DurableWorkflow\\Cli\\Commands\\TaskQueueCommand\\BuildIdsCommand::{{closure:DurableWorkflow\\Cli\\Commands\\TaskQueueCommand\\BuildIdsCommand::execute():70}}(): Argument #1 ($entry) must be of type array, {} given",
                value_name(entry)
            )));
        }
        let field = |name: &str, default: &str| text_or(php::get(entry, name), default);
        rows.push(vec![
            formatter::display_text(&build_id_label(php::get(entry, "build_id"))),
            status_cell(php::get(entry, "rollout_status").unwrap_or(&Value::Null)),
            field("active_worker_count", "0"),
            field("draining_worker_count", "0"),
            field("stale_worker_count", "0"),
            field("total_worker_count", "0"),
            if php::get(entry, "new_start_selected") == Some(&Value::Bool(true)) {
                "yes".into()
            } else {
                "-".into()
            },
            field("promoted_at", "-"),
            formatter::display_text(&fingerprint_conflicts(php::get(
                entry,
                "workflow_definition_fingerprint_conflicts",
            ))),
            formatter::display_text(&join_list(php::get(entry, "runtimes"))),
            formatter::display_text(&join_list(php::get(entry, "sdk_versions"))),
            field("last_heartbeat_at", "-"),
            field("first_seen_at", "-"),
        ]);
    }
    formatter::writeln_out(&output::render_table(
        &[
            "Build ID",
            "Rollout",
            "Active",
            "Draining",
            "Stale",
            "Total",
            "New Starts",
            "Promoted At",
            "Definition Conflicts",
            "Runtimes",
            "SDK Versions",
            "Last Heartbeat",
            "First Seen",
        ],
        &rows,
    ));
    Ok(0)
}

/// 2.x `formatBuildId()`.
fn build_id_label(value: Option<&Value>) -> String {
    match value {
        Some(Value::String(text)) if !text.is_empty() => text.clone(),
        _ => "(unversioned)".into(),
    }
}

/// 2.x `formatFingerprintConflicts()`.
fn fingerprint_conflicts(value: Option<&Value>) -> String {
    let Some(conflicts) = value.filter(|value| php::non_empty_array(value)) else {
        return "-".into();
    };
    let rendered = php::values(conflicts)
        .into_iter()
        .filter(|conflict| php::is_array(conflict))
        .filter_map(|conflict| {
            let workflow_type = php::get(conflict, "workflow_type")?.as_str()?;
            let count = php::get(conflict, "fingerprint_count")?;
            let numeric = match count {
                Value::Number(_) => true,
                Value::String(text) => crate::commands::schedule::numeric(text).is_some(),
                _ => false,
            };
            numeric.then(|| format!("{workflow_type}:{}", php::int_cast(Some(count))))
        })
        .collect::<Vec<_>>();
    if rendered.is_empty() {
        "-".into()
    } else {
        rendered.join(", ")
    }
}

/// 2.x `joinList()`: the string entries, comma separated.
fn join_list(value: Option<&Value>) -> String {
    let strings = value
        .filter(|value| php::is_array(value))
        .map(|values| {
            php::values(values)
                .into_iter()
                .filter_map(Value::as_str)
                .collect::<Vec<_>>()
        })
        .unwrap_or_default();
    if strings.is_empty() {
        "-".into()
    } else {
        strings.join(", ")
    }
}

pub fn drain(context: &Context, input: &Input) -> Result<i32, CliError> {
    build_id_change(
        context,
        input,
        "drain",
        "Drained",
        Some(("drained_at", "Drained at")),
        "Workers registered under this build_id will keep running their in-flight work but stop claiming new tasks. Use <info>dw task-queue:resume</info> to undo this.",
    )
}

pub fn promote(context: &Context, input: &Input) -> Result<i32, CliError> {
    build_id_change(
        context,
        input,
        "promote",
        "Promoted",
        Some(("promoted_at", "Promoted at")),
        "Fresh workflow starts now pin to this build_id. Existing runs keep their current compatibility marker and continue routing to compatible workers.",
    )
}

pub fn resume(context: &Context, input: &Input) -> Result<i32, CliError> {
    build_id_change(
        context,
        input,
        "resume",
        "Resumed",
        None,
        "The cohort is marked active again. Workers that were flagged as draining have been flipped back to active and will resume claiming new tasks.",
    )
}

/// One drain, promote or resume request for a build_id cohort.
fn build_id_change(
    context: &Context,
    input: &Input,
    action: &str,
    done: &str,
    timestamp: Option<(&str, &str)>,
    closing: &str,
) -> Result<i32, CliError> {
    let task_queue = input.arg("task-queue").unwrap_or_default();
    let build_id = match target_build_id(input) {
        Ok(build_id) => build_id,
        Err(message) => return Ok(crate::command_usage_error(&input.command, message)),
    };
    context.preflight(false)?;
    let result = with_namespace(
        context.resource_request(
            RawRequest::post(["task-queues", task_queue, "build-ids", action])
                .json(json!({ "build_id": build_id })),
        )?,
        &context.connection.namespace,
        None,
    );
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let rendered = match php::get(&result, "build_id").filter(|value| !value.is_null()) {
        Some(value) => build_id_label(Some(value)),
        None => build_id_label(build_id.map(|id| json!(id)).as_ref()),
    };
    let shown_queue = match php::get(&result, "task_queue").filter(|value| !value.is_null()) {
        Some(value) => text(Some(value)),
        None => formatter::display_text(task_queue),
    };
    formatter::writeln_out(&format!(
        "<info>{done} build_id {} on task queue {shown_queue}.</info>",
        formatter::display_text(&rendered)
    ));
    write_namespace_line(&result);
    if let Some((key, label)) = timestamp {
        if let Some(Value::String(at)) = php::get(&result, key)
            .filter(|value| matches!(value, Value::String(text) if !text.is_empty()))
        {
            formatter::writeln_out(&format!("{label}: {}", formatter::display_text(at)));
        }
    }
    formatter::writeln_out("");
    formatter::writeln_out(closing);
    Ok(0)
}

/// 2.x `resolveTargetBuildId()`: a build_id, or `None` for the unversioned
/// cohort. 2.x throws Symfony's console option exception for a missing or
/// conflicting target, so the refusal is a usage error.
fn target_build_id(input: &Input) -> Result<Option<&str>, &'static str> {
    let unversioned = input.flag("unversioned");
    let build_id = input.opt("build-id").flatten().filter(|id| !id.is_empty());
    match (unversioned, build_id) {
        (true, Some(_)) => Err("--unversioned cannot be combined with --build-id. Choose one."),
        (true, None) => Ok(None),
        (false, Some(id)) => Ok(Some(id)),
        (false, None) => Err("One of --build-id <value> or --unversioned is required."),
    }
}

/// Cluster info capability for the activity dispatch pause routes. Native
/// Server advertises it (Server #577); published PHP Server 2.x has no such
/// routes.
const ACTIVITY_DISPATCH_PAUSE: &str = "task_queue_activity_dispatch_pause";

const ACTIVITY_DISPATCH_UNSUPPORTED: &str = "Pausing activity dispatch on a task queue needs a native Durable Workflow Server 3.8.0 or later; published PHP Server 2.x has no such route.";

const ACTIVITY_DISPATCH_NEXT_STEP: &str = "Connect to a Server that advertises this capability. To stop one build's workers from claiming tasks instead, use dw task-queue:drain.";

/// `task-queue:activity-dispatch`: one read of the queue's pause state.
pub fn activity_dispatch(context: &Context, input: &Input) -> Result<i32, CliError> {
    activity_dispatch_request(context, input, None)
}

/// `task-queue:pause-activities`: one pause request, never resent.
pub fn pause_activities(context: &Context, input: &Input) -> Result<i32, CliError> {
    activity_dispatch_request(context, input, Some("pause"))
}

/// `task-queue:resume-activities`: one resume request, never resent.
pub fn resume_activities(context: &Context, input: &Input) -> Result<i32, CliError> {
    activity_dispatch_request(context, input, Some("resume"))
}

fn activity_dispatch_request(
    context: &Context,
    input: &Input,
    action: Option<&str>,
) -> Result<i32, CliError> {
    let task_queue = input.arg("task-queue").unwrap_or_default();
    // The Server validates the reason (at most 1000 characters, else 422);
    // an empty one is no reason.
    let reason = match action {
        Some("pause") => input.opt_str("reason"),
        _ => None,
    };
    let info = context.preflight(false)?;
    crate::compat::require_capability(
        &info,
        crate::build_info::version(),
        ACTIVITY_DISPATCH_PAUSE,
        ACTIVITY_DISPATCH_UNSUPPORTED,
        ACTIVITY_DISPATCH_NEXT_STEP,
    )
    .map_err(CliError::Compatibility)?;
    let request = match action {
        None => RawRequest::get(["task-queues", task_queue, "activity-dispatch"]),
        Some(action) => {
            let body = match reason {
                Some(reason) => json!({ "reason": reason }),
                None => json!({}),
            };
            RawRequest::post(["task-queues", task_queue, "activity-dispatch", action]).json(body)
        }
    };
    let result = with_namespace(
        context.resource_request(request)?,
        &context.connection.namespace,
        None,
    );
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let shown_queue = match php::get(&result, "task_queue").filter(|value| !value.is_null()) {
        Some(value) => text(Some(value)),
        None => formatter::display_text(task_queue),
    };
    let dispatch = php::get(&result, "activity_dispatch");
    let outcome = php::get(&result, "outcome").and_then(Value::as_str);
    let state = dispatch_state(dispatch);
    let headline = match (action, outcome) {
        (None, _) => format!("<info>Task Queue: {shown_queue}</info>"),
        (_, Some("paused")) => {
            format!("<info>Paused activity dispatch on task queue {shown_queue}.</info>")
        }
        (_, Some("already_paused")) => format!(
            "<comment>Activity dispatch on task queue {shown_queue} was already paused; nothing changed.</comment>"
        ),
        (_, Some("resumed")) => {
            format!("<info>Resumed activity dispatch on task queue {shown_queue}.</info>")
        }
        (_, Some("not_paused")) => format!(
            "<comment>Activity dispatch on task queue {shown_queue} was not paused; nothing changed.</comment>"
        ),
        _ => format!("Activity dispatch on task queue {shown_queue} is {state}."),
    };
    formatter::writeln_out(&headline);
    write_namespace_line(&result);
    formatter::writeln_out(&format!(
        "  Activity Dispatch: {}",
        status_cell(&json!(state))
    ));
    for line in dispatch_detail_lines(dispatch) {
        formatter::writeln_out(&format!("  {line}"));
    }
    let mut closing = Vec::new();
    if outcome == Some("already_paused") && reason.is_some() {
        closing.push(
            "The original pause and its reason are kept; the --reason given here was not recorded."
                .to_string(),
        );
    }
    if action.is_some() && state == "paused" {
        closing.push("No activity task on this queue is leased until the pause is lifted. Ready tasks keep their attempt count, activity deadlines keep running, and workflow tasks, signals, queries and updates are delivered as before. Use <info>dw task-queue:resume-activities</info> to resume.".to_string());
    } else if outcome == Some("resumed") {
        closing.push("Ready activity tasks on this queue can be leased again. An activity whose deadline passed during the pause stays timed out.".to_string());
    }
    if !closing.is_empty() {
        formatter::writeln_out("");
        for line in closing {
            formatter::writeln_out(&line);
        }
    }
    Ok(0)
}

/// `paused` or `active`: the Server's `state`, else its `paused` flag.
fn dispatch_state(dispatch: Option<&Value>) -> String {
    match php::array_get(dispatch, "state") {
        Some(Value::String(state)) if !state.is_empty() => formatter::display_text(state),
        _ => match php::array_get(dispatch, "paused") {
            Some(Value::Bool(true)) => "paused".into(),
            Some(Value::Bool(false)) => "active".into(),
            _ => "-".into(),
        },
    }
}

/// The last pause and, once it ended, its resume, one `Label: value` line
/// each. A queue that was never paused has none.
fn dispatch_detail_lines(dispatch: Option<&Value>) -> Vec<String> {
    let paused = dispatch_state(dispatch) == "paused";
    let field = |key: &str| {
        php::array_get(dispatch, key)
            .filter(|value| nonempty_scalar(Some(value)))
            .map(|value| text(Some(value)))
    };
    let principal = |key: &str| php::array_get(dispatch, key).and_then(principal_label);
    // A new pause replaces the last cycle, so a paused queue has no resume.
    let labels = if paused {
        vec![
            ("Reason", field("reason")),
            ("Paused at", field("paused_at")),
            ("Paused by", principal("paused_by")),
        ]
    } else {
        vec![
            ("Last pause reason", field("reason")),
            ("Last paused at", field("paused_at")),
            ("Last paused by", principal("paused_by")),
            ("Resumed at", field("resumed_at")),
            ("Resumed by", principal("resumed_by")),
        ]
    };
    labels
        .into_iter()
        .filter_map(|(label, value)| value.map(|value| format!("{label}: {value}")))
        .collect()
}

/// A recorded principal (`type`, `id`, optional `label`) as display text:
/// `Operator (auth:token role:operator)`, or `auth:token role:operator`
/// without a label.
fn principal_label(principal: &Value) -> Option<String> {
    if !php::is_array(principal) {
        return nonempty_scalar(Some(principal)).then(|| text(Some(principal)));
    }
    let part = |key: &str| {
        php::get(principal, key)
            .filter(|value| nonempty_scalar(Some(value)))
            .map(|value| text(Some(value)))
    };
    let identity = [part("type"), part("id")]
        .into_iter()
        .flatten()
        .collect::<Vec<_>>()
        .join(" ");
    match (part("label"), identity.is_empty()) {
        (Some(label), false) => Some(format!("{label} ({identity})")),
        (Some(label), true) => Some(label),
        (None, false) => Some(identity),
        (None, true) => None,
    }
}

/// The queue's `activity_dispatch` object when the Server reports one
/// (native Server with the pause capability); `None` leaves 2.x output
/// unchanged.
fn reported_dispatch(queue: &Value) -> Option<&Value> {
    php::get(queue, "activity_dispatch").filter(|value| value.is_object())
}
