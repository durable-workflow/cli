//! `debug workflow` and `watch workflow`: read-only diagnostics. Every poll is
//! one describe or debug request.

use crate::cli::Input;
use crate::client::Context;
use crate::commands::schedule::{status_cell, truthy};
use crate::commands::workflow_run::is_terminal;
use crate::error::{exit, CliError};
use crate::{formatter, output, php};
use serde_json::{json, Value};
use std::time::Duration;

fn out(line: &str) {
    formatter::writeln_out(line);
}

/// `$value ?? $default` as display text.
fn or(value: Option<&Value>, default: &str) -> String {
    formatter::display_text(&php::or(value, default))
}

/// `is_array($value) ? $value : []`.
fn object<'a>(value: Option<&'a Value>, empty: &'a Value) -> &'a Value {
    value.filter(|value| php::is_array(value)).unwrap_or(empty)
}

pub fn debug(context: &Context, input: &Input) -> Result<i32, CliError> {
    if input.arg("target").unwrap_or_default() != "workflow" {
        return Err(CliError::Invalid(
            "dw debug currently supports only: workflow.".into(),
        ));
    }
    let workflow_id = input.arg("workflow-id").unwrap_or_default();
    let run_id = input.opt("run-id").flatten().filter(|id| !id.is_empty());
    context.preflight(false)?;
    let result = match run_id {
        Some(run_id) => context.workflow_get(
            &["workflows", workflow_id, "runs", run_id, "debug"],
            &[],
            &format!(
                "/workflows/{}/runs/{}/debug",
                rawurlencode(workflow_id),
                rawurlencode(run_id)
            ),
        )?,
        None => context.workflow_get(
            &["workflows", workflow_id, "debug"],
            &[],
            &format!("/workflows/{}/debug", rawurlencode(workflow_id)),
        )?,
    };
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    render_debug(context, &result);
    Ok(0)
}

/// PHP `rawurlencode()`, for the request path named in errors.
fn rawurlencode(text: &str) -> String {
    let mut encoded = String::with_capacity(text.len());
    for byte in text.bytes() {
        if byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'.' | b'_' | b'~') {
            encoded.push(char::from(byte));
        } else {
            encoded.push_str(&format!("%{byte:02X}"));
        }
    }
    encoded
}

fn render_debug(context: &Context, result: &Value) {
    let empty = json!([]);
    let execution = object(result.get("execution"), &empty);
    let task_queue = object(result.get("task_queue"), &empty);
    let stats = object(php::get(task_queue, "stats"), &empty);
    out("<info>Workflow Debug</info>");
    out(&format!(
        "  Connection: {} namespace={} workflow={} run={}",
        formatter::display_text(&context.connection.server),
        formatter::display_text(&context.connection.namespace),
        or(result.get("workflow_id"), "-"),
        or(result.get("run_id"), "-"),
    ));
    out(&format!(
        "  Status: {} ({})",
        status(php::get(execution, "status")),
        status(result.get("diagnostic_status")),
    ));
    out(&format!(
        "  Type: {}",
        or(php::get(execution, "workflow_type"), "-")
    ));
    out(&format!(
        "  Task Queue: {}",
        or(php::get(execution, "task_queue"), "-")
    ));
    out(&format!(
        "  Last Event: {}",
        event_label(php::get(execution, "last_event"))
    ));
    out(&format!(
        "  Next Scheduled: {}",
        scheduled_label(php::get(execution, "next_scheduled_event"))
    ));
    out("");
    let findings = object(result.get("findings"), &empty);
    if php::non_empty_array(findings) {
        out("Findings:");
        for finding in php::values(findings) {
            if !php::is_array(finding) {
                continue;
            }
            out(&format!(
                "  [{}] {}: {}",
                formatter::display_text(
                    &php::or(php::get(finding, "severity"), "info").to_ascii_uppercase()
                ),
                or(php::get(finding, "code"), "-"),
                or(php::get(finding, "message"), "-"),
            ));
        }
        out("");
    }
    out("Task Queue:");
    out(&format!(
        "  Backlog: {}",
        or(php::get(stats, "approximate_backlog_count"), "0")
    ));
    out(&format!(
        "  Backlog Age: {}",
        or(php::get(stats, "approximate_backlog_age"), "-")
    ));
    let count = |path: &str| php::int_cast(nested(stats, path));
    out(&format!(
        "  Workflow Tasks: ready={} leased={} expired={}",
        count("workflow_tasks.ready_count"),
        count("workflow_tasks.leased_count"),
        count("workflow_tasks.expired_lease_count"),
    ));
    out(&format!(
        "  Activity Tasks: ready={} leased={} expired={}",
        count("activity_tasks.ready_count"),
        count("activity_tasks.leased_count"),
        count("activity_tasks.expired_lease_count"),
    ));
    out(&format!(
        "  Pollers: active={} stale={}",
        count("pollers.active_count"),
        count("pollers.stale_count"),
    ));
    out("");
    cancellation_cascade(result);
    pending_workflow_tasks(result.get("pending_workflow_tasks"));
    pending_activities(result.get("pending_activities"));
    recent_failures(result.get("recent_failures"));
}

/// `formatStatus()` of a remote value.
fn status(value: Option<&Value>) -> String {
    value
        .filter(|value| !value.is_null())
        .map(status_cell)
        .unwrap_or_else(|| "-".into())
}

/// 2.x `nested()`: a dotted path through arrays, `0`-like default when absent.
fn nested<'a>(data: &'a Value, path: &str) -> Option<&'a Value> {
    let mut value = data;
    for segment in path.split('.') {
        if !php::is_array(value) || !php::has_key(value, segment) {
            return None;
        }
        value = php::raw_get(value, segment)?;
    }
    Some(value)
}

fn event_label(event: Option<&Value>) -> String {
    let Some(event) = event.filter(|value| php::is_array(value)) else {
        return "-".into();
    };
    format!(
        "#{} {} at {}",
        or(php::get(event, "sequence"), "-"),
        or(php::get(event, "event_type"), "-"),
        or(php::get(event, "timestamp"), "-"),
    )
}

fn scheduled_label(event: Option<&Value>) -> String {
    let Some(event) = event.filter(|value| php::is_array(value)) else {
        return "-".into();
    };
    let at = ["available_at", "lease_expires_at", "wait_deadline_at"]
        .into_iter()
        .find_map(|key| php::get(event, key).filter(|value| !value.is_null()));
    format!(
        "{} {} at {}",
        or(php::get(event, "task_type"), "task"),
        or(php::get(event, "task_status"), "-"),
        or(at, "-"),
    )
}

/// The array members of a non-empty list, or `None` for the "none" line.
fn rows_of(value: Option<&Value>) -> Option<Vec<&Value>> {
    let value = value.filter(|value| php::non_empty_array(value))?;
    Some(
        php::values(value)
            .into_iter()
            .filter(|item| php::is_array(item))
            .collect(),
    )
}

fn cells(item: &Value, keys: &[&str]) -> Vec<String> {
    keys.iter()
        .map(|key| or(php::get(item, key), "-"))
        .collect()
}

fn pending_workflow_tasks(tasks: Option<&Value>) {
    let Some(tasks) = rows_of(tasks) else {
        out("Pending Workflow Tasks: none");
        out("");
        return;
    };
    let rows = tasks
        .into_iter()
        .map(|task| {
            let mut row = cells(
                task,
                &["task_id", "status", "lease_owner", "lease_expires_at"],
            );
            row.push(
                if truthy(php::get(task, "lease_expired")) {
                    "expired"
                } else {
                    "-"
                }
                .into(),
            );
            row
        })
        .collect::<Vec<_>>();
    out("Pending Workflow Tasks:");
    out(&output::render_table(
        &["Task ID", "Status", "Lease Owner", "Lease Expires", "State"],
        &rows,
    ));
    out("");
}

fn pending_activities(activities: Option<&Value>) {
    let Some(activities) = rows_of(activities) else {
        out("Pending Activities: none");
        out("");
        return;
    };
    let empty = json!([]);
    let rows = activities
        .into_iter()
        .map(|activity| {
            let attempt = object(php::get(activity, "current_attempt"), &empty);
            let mut row = cells(
                activity,
                &["activity_execution_id", "activity_type", "status"],
            );
            row.extend(cells(attempt, &["lease_owner", "lease_expires_at"]));
            row
        })
        .collect::<Vec<_>>();
    out("Pending Activities:");
    out(&output::render_table(
        &[
            "Activity ID",
            "Type",
            "Status",
            "Lease Owner",
            "Lease Expires",
        ],
        &rows,
    ));
    out("");
}

fn recent_failures(failures: Option<&Value>) {
    let Some(failures) = rows_of(failures) else {
        out("Recent Failures: none");
        return;
    };
    let rows = failures
        .into_iter()
        .map(|failure| cells(failure, &["exception_class", "message", "created_at"]))
        .collect::<Vec<_>>();
    out("Recent Failures:");
    out(&output::render_table(
        &["Exception", "Message", "Created"],
        &rows,
    ));
}

/// 2.x `CancellationCascadeRenderer`: recorded evidence only, never an
/// inferred callback exit or renewed deadline.
fn cancellation_cascade(diagnostics: &Value) {
    if !php::has_key(diagnostics, "cancellation_cascade_supported")
        && !php::has_key(diagnostics, "cancellation_cascade")
    {
        return;
    }
    out("Cancellation Cascade:");
    let Some(view) = diagnostics
        .get("cancellation_cascade")
        .filter(|value| php::is_array(value))
    else {
        out(
            if diagnostics.get("cancellation_cascade_supported") == Some(&Value::Bool(true)) {
                "  No cooperative cancellation request for this run."
            } else {
                "  Unavailable with this Server runtime."
            },
        );
        out("");
        return;
    };
    if php::get(view, "schema").and_then(Value::as_str)
        != Some("durable-workflow.cancellation-cascade/v1")
    {
        out("  Unrecognized cascade schema. Use --output=json to inspect the response.");
        out("");
        return;
    }
    let empty = json!([]);
    let get = |value: &Value, key: &str| php::get(value, key).cloned().unwrap_or(Value::Null);
    let root = object(php::get(view, "root"), &empty);
    let requester = object(php::get(root, "requester"), &empty);
    line("  Root request: %s", &[get(root, "root_request_id")]);
    line(
        "  Root workflow: %s run=%s",
        &[
            get(root, "root_workflow_instance_id"),
            get(root, "root_workflow_run_id"),
        ],
    );
    line(
        "  Original request: %s deadline=%s",
        &[get(root, "requested_at"), get(root, "cleanup_deadline_at")],
    );
    let requester_name = php::get(requester, "label")
        .filter(|value| !value.is_null())
        .or_else(|| php::get(requester, "id"))
        .cloned()
        .unwrap_or(Value::Null);
    line(
        "  Reason: %s source=%s requester=%s",
        &[get(root, "reason"), get(root, "source"), requester_name],
    );
    line(
        "  Evidence: %s%s",
        &[
            json!(
                if php::get(view, "inspection_complete") == Some(&Value::Bool(true)) {
                    "complete"
                } else {
                    "partial"
                }
            ),
            json!(if php::get(view, "truncated") == Some(&Value::Bool(true)) {
                " (inspection limits reached)"
            } else {
                ""
            }),
        ],
    );
    let selected = get(view, "selected_run_id");
    for run in objects(php::get(view, "runs")) {
        let request = object(php::get(run, "request"), &empty);
        let delivery = object(php::get(run, "delivery"), &empty);
        let cleanup = object(php::get(run, "cleanup"), &empty);
        let run_id = get(run, "run_id");
        line(
            "  Run %s%s: %s workflow=%s phase=%s status=%s",
            &[
                run_id.clone(),
                json!(if run_id == selected {
                    " [selected]"
                } else {
                    ""
                }),
                get(run, "workflow_type"),
                get(run, "workflow_id"),
                get(run, "lifecycle"),
                get(run, "projected_status"),
            ],
        );
        line(
            "    Request: %s parent=%s budget=%s deadline=%s",
            &[
                get(request, "request_id"),
                get(request, "parent_request_id"),
                json!(budget(run, root)),
                get(request, "cleanup_deadline_at"),
            ],
        );
        line(
            "    Delivery: event=%s sequence=%s span=%s kind=%s",
            &[
                get(delivery, "history_event_id"),
                get(delivery, "sequence"),
                get(delivery, "sequence_span"),
                get(delivery, "call_kind"),
            ],
        );
        line(
            "    Cleanup: %s finished=%s terminal=%s event=%s",
            &[
                get(cleanup, "outcome"),
                get(cleanup, "finished_at"),
                get(run, "terminal_event_type"),
                get(run, "terminal_history_event_id"),
            ],
        );
        for stop in objects(php::get(run, "activity_stops")) {
            line(
                "    Activity %s (%s, %s): callback=%s fence=%s stop=%s source=%s",
                &[
                    get(stop, "activity_execution_id"),
                    get(stop, "activity_type"),
                    get(stop, "execution_mode"),
                    get(stop, "callback_state"),
                    get(stop, "fence_history_event_id"),
                    get(stop, "stop_history_event_id"),
                    get(stop, "evidence_source"),
                ],
            );
            line(
                "      Attempt: %s acknowledged=%s after_deadline=%s",
                &[
                    get(stop, "activity_attempt_id"),
                    get(stop, "acknowledged_at"),
                    get(stop, "received_after_deadline"),
                ],
            );
        }
        for recovery in objects(php::get(run, "cleanup_recovery")) {
            let attempt = object(php::get(recovery, "attempt"), &empty);
            line(
                "    Cleanup recovery: activity=%s event=%s at=%s",
                &[
                    get(recovery, "activity_execution_id"),
                    get(recovery, "history_event_id"),
                    get(recovery, "recorded_at"),
                ],
            );
            line(
                "      %s attempt=%s owner=%s -> %s attempt=%s owner=%s callback=%s",
                &[
                    get(attempt, "original_workflow_task_id"),
                    get(attempt, "original_workflow_task_attempt"),
                    get(attempt, "original_lease_owner"),
                    get(attempt, "workflow_task_id"),
                    get(attempt, "workflow_task_attempt"),
                    get(attempt, "lease_owner"),
                    get(attempt, "callback_stop_state"),
                ],
            );
        }
        for propagation in objects(php::get(run, "child_propagation")) {
            line(
                "    Child %s: policy=%s outcome=%s rejection=%s event=%s terminal=%s",
                &[
                    get(propagation, "child_run_id"),
                    get(propagation, "policy"),
                    get(propagation, "request_outcome"),
                    get(propagation, "rejection_reason"),
                    get(propagation, "history_event_id"),
                    get(propagation, "child_terminal_history_event_id"),
                ],
            );
        }
    }
    for edge in objects(php::get(view, "edges")) {
        line(
            "  Relation: %s -> %s kind=%s state=%s",
            &[
                get(edge, "parent_run_id"),
                get(edge, "child_run_id"),
                get(edge, "kind"),
                get(edge, "reference_state"),
            ],
        );
    }
    for finding in objects(php::get(view, "findings")) {
        line(
            "  Finding [%s] run=%s: %s",
            &[
                get(finding, "code"),
                get(finding, "run_id"),
                get(finding, "message"),
            ],
        );
    }
    out("");
}

fn budget(run: &Value, root: &Value) -> &'static str {
    let empty = json!([]);
    let request = object(php::get(run, "request"), &empty);
    if !php::non_empty_array(request) || !php::non_empty_array(root) {
        return "unverified";
    }
    if php::get(run, "same_root_budget") == Some(&Value::Bool(true)) {
        return "original root";
    }
    let root_id = |value: &Value| {
        php::get(value, "root_request_id")
            .cloned()
            .unwrap_or(Value::Null)
    };
    if root_id(request) != root_id(root) {
        "independent root"
    } else {
        "conflict or unverified"
    }
}

/// The array members of an array value.
fn objects(value: Option<&Value>) -> Vec<&Value> {
    value
        .filter(|value| php::is_array(value))
        .map(|value| {
            php::values(value)
                .into_iter()
                .filter(|item| php::is_array(item))
                .collect()
        })
        .unwrap_or_default()
}

/// `sprintf($format, ...array_map($this->text(...), $values))`.
fn line(format: &str, values: &[Value]) {
    let mut parts = format.split("%s");
    let mut text = parts.next().unwrap_or_default().to_string();
    for (part, value) in parts.zip(values) {
        text.push_str(&cascade_text(value));
        text.push_str(part);
    }
    out(&text);
}

/// The renderer's `text()`: scalars only, control characters as spaces,
/// console markup escaped.
fn cascade_text(value: &Value) -> String {
    let text = match value {
        Value::Bool(flag) => if *flag { "true" } else { "false" }.to_string(),
        Value::Null | Value::Array(_) | Value::Object(_) => return "-".into(),
        other => php::to_string(Some(other)),
    };
    let cleaned: String = text
        .chars()
        .map(|c| {
            if matches!(u32::from(c), 0x00..=0x1F | 0x7F..=0x9F) {
                ' '
            } else {
                c
            }
        })
        .collect();
    formatter::escape(&cleaned)
}

/// The fields whose changes `watch` reports.
const TRACKED_FIELDS: [&str; 9] = [
    "status",
    "status_bucket",
    "is_terminal",
    "closed_at",
    "closed_reason",
    "wait_kind",
    "wait_reason",
    "last_progress_at",
    "output",
];

pub fn watch(context: &Context, input: &Input) -> Result<i32, CliError> {
    let resource = input.arg("resource").unwrap_or_default();
    if resource != "workflow" {
        return Err(CliError::Invalid(format!(
            "Unsupported watch resource [{resource}]; supported resources: workflow."
        )));
    }
    let workflow_id = input.arg("id").unwrap_or_default();
    let run_id = input.opt("run-id").flatten();
    let interval = match input.opt("interval").flatten() {
        Some(value) if php::ctype_digit(value) => php::string_to_int(value),
        _ => {
            return Err(CliError::Invalid(
                "--interval must be a non-negative integer.".into(),
            ))
        }
    };
    let max_polls = match input.opt("max-polls").flatten() {
        None | Some("") => None,
        Some(value) if php::ctype_digit(value) && php::string_to_int(value) >= 1 => {
            Some(php::string_to_int(value))
        }
        Some(_) => {
            return Err(CliError::Invalid(
                "--max-polls must be a positive integer.".into(),
            ))
        }
    };
    context.preflight(false)?;
    let fetch = || match run_id {
        Some(run_id) => context.workflow_get(
            &["workflows", workflow_id, "runs", run_id],
            &[],
            &format!("/workflows/{workflow_id}/runs/{run_id}"),
        ),
        None => context.workflow_get(
            &["workflows", workflow_id],
            &[],
            &format!("/workflows/{workflow_id}"),
        ),
    };
    let mut polls: i64 = 1;
    let mut result = fetch()?;
    out(&format!(
        "<comment>Watching workflow {}{} (status: {})</comment>",
        formatter::display_text(workflow_id),
        run_id
            .map(|run_id| format!(" run {}", formatter::display_text(run_id)))
            .unwrap_or_default(),
        scalar(result.get("status")),
    ));
    loop {
        if is_terminal(&result) {
            out(&format!(
                "Terminal state reached: {}",
                scalar(result.get("status"))
            ));
            return Ok(terminal_exit_code(&result));
        }
        if max_polls.is_some_and(|max| polls >= max) {
            out(&format!(
                "<error>Watch stopped after {polls} poll{} before terminal state.</error>",
                if polls == 1 { "" } else { "s" }
            ));
            return Ok(exit::TIMEOUT);
        }
        if interval > 0 {
            std::thread::sleep(Duration::from_secs(
                u64::try_from(interval).unwrap_or(u64::MAX),
            ));
        }
        let previous = result;
        result = fetch()?;
        polls += 1;
        let changes = TRACKED_FIELDS
            .into_iter()
            .filter_map(|field| {
                let before = previous.get(field).cloned().unwrap_or(Value::Null);
                let after = result.get(field).cloned().unwrap_or(Value::Null);
                (before != after).then_some((field, before, after))
            })
            .collect::<Vec<_>>();
        if !changes.is_empty() {
            out(&format!("<info>Change #{polls}</info>"));
            for (field, before, after) in changes {
                out(&format!(
                    "  {field}: {} -> {}",
                    value_text(&before),
                    value_text(&after)
                ));
            }
        }
    }
}

fn terminal_exit_code(result: &Value) -> i32 {
    let completed = |key: &str| result.get(key).and_then(Value::as_str) == Some("completed");
    if completed("status_bucket") || completed("status") {
        exit::SUCCESS
    } else {
        exit::FAILURE
    }
}

/// `formatValue()`: arrays as JSON, everything else as `formatScalar()`.
fn value_text(value: &Value) -> String {
    if php::is_array(value) {
        return formatter::display_text(&php::json_encode(value));
    }
    scalar(Some(value))
}

/// `formatScalar()`.
fn scalar(value: Option<&Value>) -> String {
    match value {
        None | Some(Value::Null) => "-".into(),
        Some(Value::String(text)) if text.is_empty() => "-".into(),
        Some(Value::Bool(flag)) => if *flag { "true" } else { "false" }.into(),
        Some(other) => formatter::display_text(&php::to_string(Some(other))),
    }
}
