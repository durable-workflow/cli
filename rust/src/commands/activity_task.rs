//! Standalone activities (`activity:*`) and the worker-protocol task
//! commands (`workflow-task:*`, `query-task:*`).
//!
//! Every completion and failure is one request: a redirect is refused and a
//! lost response is reported as a transport failure, never submitted again.
//! Polls are one long-poll request each.

use crate::cli::Input;
use crate::client::{uses_worker_protocol, Context};
use crate::commands::schedule::{status_cell, text, text_or, with_namespace, write_namespace_line};
use crate::commands::worker::positive_int_option;
use crate::error::CliError;
use crate::payload::{self, Payload};
use crate::{formatter, output, php};
use durable_workflow::RawRequest;
use serde_json::{json, Map, Value};

pub fn activity_list(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let mut request = RawRequest::get(["activities"]);
    if let Some(status) = input.opt("status").flatten() {
        request = request.query("status", status);
    }
    let limit = input
        .opt("limit")
        .flatten()
        .map(php::string_to_int)
        .unwrap_or(0);
    request = request.query("page_size", limit.to_string());
    if let Some(token) = input.opt("next-page-token").flatten() {
        request = request.query("next_page_token", token);
    }
    let result = with_namespace(
        context.resource_request(request)?,
        &context.connection.namespace,
        Some("activities"),
    );
    if context.mode.machine_readable() {
        output::print_json_list(context.mode, &result, "activities");
        return Ok(0);
    }
    let namespace = text(result.get("namespace"));
    let activities = result
        .get("activities")
        .filter(|value| php::non_empty_array(value));
    let Some(activities) = activities else {
        formatter::writeln_out(&format!(
            "<comment>No activities found in namespace {namespace}.</comment>"
        ));
        return Ok(0);
    };
    formatter::writeln_out(&format!("Namespace: {namespace}"));
    let rows = php::values(activities)
        .into_iter()
        .map(|activity| {
            if !php::is_array(activity) {
                return vec!["-".to_string(); 8];
            }
            let field = |name: &str| text_or(php::get(activity, name), "-");
            let status = php::get(activity, "activity_status")
                .filter(|value| !value.is_null())
                .or_else(|| php::get(activity, "status"));
            vec![
                field("activity_id"),
                field("activity_type"),
                status_cell(status.unwrap_or(&Value::Null)),
                status_cell(php::get(activity, "current_attempt_status").unwrap_or(&Value::Null)),
                field("current_attempt_id"),
                field("task_queue"),
                field("started_at"),
                field("closed_at"),
            ]
        })
        .collect::<Vec<_>>();
    formatter::writeln_out(&output::render_table(
        &[
            "Activity ID",
            "Type",
            "Status",
            "Attempt",
            "Attempt ID",
            "Task Queue",
            "Started",
            "Closed",
        ],
        &rows,
    ));
    Ok(0)
}

pub fn activity_describe(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let activity_id = input.arg("activity-id").unwrap_or_default();
    let result = with_namespace(
        context.resource_request(RawRequest::get(["activities", activity_id]))?,
        &context.connection.namespace,
        None,
    );
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let field = |name: &str| text_or(result.get(name), "-");
    let status = result
        .get("activity_status")
        .filter(|value| !value.is_null())
        .or_else(|| result.get("status"));
    formatter::writeln_out("<info>Activity Execution</info>");
    write_namespace_line(&result);
    for line in [
        format!(
            "  Activity ID: {}",
            match result.get("activity_id").filter(|value| !value.is_null()) {
                Some(value) => text(Some(value)),
                None => formatter::display_text(activity_id),
            }
        ),
        format!(
            "  Activity Execution ID: {}",
            field("activity_execution_id")
        ),
        format!("  Type: {}", field("activity_type")),
        format!("  Class: {}", field("activity_class")),
        format!("  Status: {}", status_cell(status.unwrap_or(&Value::Null))),
        format!("  Attempt Count: {}", field("attempt_count")),
        format!("  Current Attempt ID: {}", field("current_attempt_id")),
        format!(
            "  Current Attempt Status: {}",
            status_cell(result.get("current_attempt_status").unwrap_or(&Value::Null))
        ),
        format!("  Workflow Run ID: {}", field("workflow_run_id")),
        format!("  Task Queue: {}", field("task_queue")),
        format!("  Started: {}", field("started_at")),
        format!("  Closed: {}", field("closed_at")),
        format!("  Closed Reason: {}", field("closed_reason")),
    ] {
        formatter::writeln_out(&line);
    }
    let attempts = result
        .get("attempts")
        .filter(|value| php::non_empty_array(value));
    if let Some(attempts) = attempts {
        let rows = php::values(attempts)
            .into_iter()
            .map(|attempt| {
                if !php::is_array(attempt) {
                    return vec!["-".to_string(); 6];
                }
                let field = |name: &str| text_or(php::get(attempt, name), "-");
                let id = php::get(attempt, "activity_attempt_id")
                    .filter(|value| !value.is_null())
                    .or_else(|| php::get(attempt, "id"));
                vec![
                    text_or(id, "-"),
                    field("attempt_number"),
                    field("status"),
                    field("lease_owner"),
                    field("started_at"),
                    field("closed_at"),
                ]
            })
            .collect::<Vec<_>>();
        formatter::writeln_out("");
        formatter::writeln_out("Attempts:");
        formatter::writeln_out(&output::render_table(
            &[
                "Attempt ID",
                "Number",
                "Status",
                "Lease Owner",
                "Started",
                "Closed",
            ],
            &rows,
        ));
    }
    Ok(0)
}

/// An option's value as PHP's `getOption()` gives it: `null` when it was
/// written without a value.
fn option_value(input: &Input, name: &str) -> Value {
    input
        .opt(name)
        .flatten()
        .map_or(Value::Null, |value| json!(value))
}

/// The preflight and one worker-protocol request with the body PHP sends.
fn worker_post(
    context: &Context,
    input: &Input,
    segments: &[&str],
    body: Map<String, Value>,
    payloads: &[Option<&Payload>],
) -> Result<Value, CliError> {
    context.preflight(uses_worker_protocol(&input.command))?;
    let body = payload::request_body(Value::Object(body), payloads)?;
    let path = format!("/{}", segments.join("/"));
    context.worker_request(RawRequest::post(segments.iter().copied()).json(body), &path)
}

pub fn activity_complete(context: &Context, input: &Input) -> Result<i32, CliError> {
    let task_id = input.arg("task-id").unwrap_or_default();
    let mut body = Map::new();
    body.insert(
        "activity_attempt_id".into(),
        json!(input.arg("attempt-id").unwrap_or_default()),
    );
    body.insert("lease_owner".into(), option_value(input, "lease-owner"));
    let result = payload::parse(input)?;
    if let Some(result) = &result {
        body.insert("result".into(), result.value.clone());
    }
    let response = worker_post(
        context,
        input,
        &["worker", "activity-tasks", task_id, "complete"],
        body,
        &[result.as_ref()],
    )?;
    activity_outcome(context, &response, "Activity completed")
}

pub fn activity_fail(context: &Context, input: &Input) -> Result<i32, CliError> {
    let task_id = input.arg("task-id").unwrap_or_default();
    let retryable = input.flag("retryable");
    let non_retryable = input.flag("non-retryable");
    if retryable && non_retryable {
        return Err(CliError::Invalid(
            "--retryable and --non-retryable cannot both be set.".into(),
        ));
    }
    let mut failure = Map::new();
    failure.insert("message".into(), option_value(input, "message"));
    failure.insert("type".into(), option_value(input, "type"));
    failure.insert("non_retryable".into(), json!(non_retryable));
    if let Some(kind) = input.opt("failure-kind").flatten() {
        failure.insert("kind".into(), json!(kind));
    }
    if retryable {
        failure.insert("retryable".into(), json!(true));
    }
    if let Some(timeout_type) = input.opt("timeout-type").flatten() {
        failure.insert("timeout_type".into(), json!(timeout_type));
    }
    if input.flag("cancelled") {
        failure.insert("cancelled".into(), json!(true));
    }
    if input.flag("malformed-output") {
        failure.insert("malformed_output".into(), json!(true));
    }
    let mut body = Map::new();
    body.insert(
        "activity_attempt_id".into(),
        json!(input.arg("attempt-id").unwrap_or_default()),
    );
    body.insert("lease_owner".into(), option_value(input, "lease-owner"));
    body.insert("failure".into(), Value::Object(failure));
    let response = worker_post(
        context,
        input,
        &["worker", "activity-tasks", task_id, "fail"],
        body,
        &[],
    )?;
    activity_outcome(context, &response, "Activity failed")
}

fn activity_outcome(context: &Context, result: &Value, title: &str) -> Result<i32, CliError> {
    if context.mode.machine_readable() {
        output::print_json(result);
        return Ok(0);
    }
    formatter::writeln_out(&format!("<info>{title}</info>"));
    formatter::writeln_out(&format!("  Task ID: {}", text(result.get("task_id"))));
    formatter::writeln_out(&format!(
        "  Attempt ID: {}",
        text(result.get("activity_attempt_id"))
    ));
    Ok(0)
}

/// 2.x `positiveAttempt()`.
fn positive_attempt(value: &str, label: &str) -> Result<i64, CliError> {
    if !php::ctype_digit(value) || php::string_to_int(value) < 1 {
        return Err(CliError::Invalid(format!(
            "{label} must be a positive integer."
        )));
    }
    Ok(php::string_to_int(value))
}

/// `$value ?? $fallback` for a human line.
fn text_or_value(value: Option<&Value>, fallback: &str) -> String {
    match value.filter(|value| !value.is_null()) {
        Some(value) => text(Some(value)),
        None => formatter::display_text(fallback),
    }
}

fn leased_task_line(label: &str, task: &Value, key: &str) {
    formatter::writeln_out(&format!("  {label}: {}", text_or(php::get(task, key), "-")));
}

/// A poll result: JSON as returned, or the leased task's fields. Returns the
/// task when one was leased and printed, for any extra lines.
fn render_leased<'a>(
    context: &Context,
    result: &'a Value,
    kind: &str,
    fields: &[(&str, &str)],
) -> Option<&'a Value> {
    if context.mode.machine_readable() {
        output::print_json(result);
        return None;
    }
    let Some(task) = result.get("task").filter(|task| php::is_array(task)) else {
        formatter::writeln_out(&format!("<comment>No {kind} task available.</comment>"));
        return None;
    };
    formatter::writeln_out(&format!("<info>{} task leased</info>", php::ucfirst(kind)));
    for (label, key) in fields {
        leased_task_line(label, task, key);
    }
    Some(task)
}

/// Completes or fails one workflow task with exactly one request, then prints
/// JSON or the shared result lines. Returns the result for extra lines.
#[allow(clippy::too_many_arguments)]
fn workflow_task_outcome(
    context: &Context,
    input: &Input,
    task_id: &str,
    attempt: i64,
    action: &str,
    body: Map<String, Value>,
    payloads: &[Option<&Payload>],
    title: &str,
) -> Result<Option<Value>, CliError> {
    let result = worker_post(
        context,
        input,
        &["worker", "workflow-tasks", task_id, action],
        body,
        payloads,
    )?;
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(None);
    }
    formatter::writeln_out(&format!("<info>{title}</info>"));
    formatter::writeln_out(&format!(
        "  Task ID: {}",
        text_or_value(result.get("task_id"), task_id)
    ));
    formatter::writeln_out(&format!(
        "  Attempt: {}",
        text_or_value(result.get("workflow_task_attempt"), &attempt.to_string())
    ));
    formatter::writeln_out(&format!(
        "  Outcome: {}",
        text_or(result.get("outcome"), "-")
    ));
    Ok(Some(result))
}

fn history_count(value: Option<&Value>) -> usize {
    value
        .filter(|value| php::is_array(value))
        .map_or(0, |value| php::values(value).len())
}

pub fn workflow_task_poll(context: &Context, input: &Input) -> Result<i32, CliError> {
    let mut body = Map::new();
    let mut add = |field: &str, value: Option<Value>| {
        if let Some(value) = value.filter(|value| !value.is_null() && value.as_str() != Some("")) {
            body.insert(field.into(), value);
        }
    };
    add(
        "worker_id",
        input.arg("worker-id").map(|value| json!(value)),
    );
    add("task_queue", Some(option_value(input, "task-queue")));
    add("build_id", Some(option_value(input, "build-id")));
    add(
        "poll_request_id",
        Some(option_value(input, "poll-request-id")),
    );
    add(
        "history_page_size",
        positive_int_option(input, "history-page-size")?.map(|value| json!(value)),
    );
    add(
        "accept_history_encoding",
        Some(option_value(input, "accept-history-encoding")),
    );
    let result = worker_post(
        context,
        input,
        &["worker", "workflow-tasks", "poll"],
        body,
        &[],
    )?;
    let Some(task) = render_leased(
        context,
        &result,
        "workflow",
        &[
            ("Task ID", "task_id"),
            ("Workflow ID", "workflow_id"),
            ("Run ID", "run_id"),
            ("Attempt", "workflow_task_attempt"),
            ("Lease Owner", "lease_owner"),
        ],
    ) else {
        return Ok(0);
    };
    formatter::writeln_out(&format!(
        "  History Events: {}",
        history_count(php::get(task, "history_events"))
    ));
    if let Some(token) = php::get(task, "next_history_page_token").filter(|value| !value.is_null())
    {
        formatter::writeln_out(&format!("  Next History Page Token: {}", text(Some(token))));
    }
    Ok(0)
}

/// `parseJsonOption()` as a request value: JSON `null` is sent as null.
fn json_option(raw: &str, option: &str) -> Result<Payload, CliError> {
    Ok(payload::parse_json_option(Some(raw), option)?.unwrap_or(Payload::null()))
}

pub fn workflow_task_complete(context: &Context, input: &Input) -> Result<i32, CliError> {
    let task_id = input.arg("task-id").unwrap_or_default();
    let attempt = positive_attempt(input.arg("attempt").unwrap_or_default(), "attempt")?;
    let raw_commands = input.opt_all("command");
    let complete_result = input
        .opt("complete-result")
        .flatten()
        .filter(|value| !value.is_empty());
    let mut payloads = Vec::new();
    let commands = if raw_commands.is_empty() {
        let mut command = Map::new();
        command.insert("type".into(), json!("complete_workflow"));
        if let Some(raw) = complete_result {
            let result = json_option(raw, "complete-result")?;
            command.insert("result".into(), result.value.clone());
            payloads.push(result);
        }
        vec![Value::Object(command)]
    } else {
        if complete_result.is_some() {
            return Err(CliError::Invalid(
                "Use either --command or --complete-result, not both.".into(),
            ));
        }
        let mut commands = Vec::new();
        for raw in raw_commands {
            let command = payload::parse_json_option(Some(raw), "command")?
                .filter(|command| php::is_array(&command.value))
                .ok_or_else(|| {
                    CliError::Invalid("--command must decode to a JSON object.".into())
                })?;
            commands.push(command.value.clone());
            payloads.push(command);
        }
        commands
    };
    let mut body = Map::new();
    body.insert("lease_owner".into(), option_value(input, "lease-owner"));
    body.insert("workflow_task_attempt".into(), json!(attempt));
    body.insert("commands".into(), Value::Array(commands));
    if let Some(result) = workflow_task_outcome(
        context,
        input,
        task_id,
        attempt,
        "complete",
        body,
        &payloads.iter().map(Some).collect::<Vec<_>>(),
        "Workflow task completed",
    )? {
        formatter::writeln_out(&format!(
            "  Run Status: {}",
            status_cell(result.get("run_status").unwrap_or(&Value::Null))
        ));
    }
    Ok(0)
}

/// The failure 2.x sends: the required message, then any non-empty
/// optional text fields.
fn task_failure(input: &Input, reason: bool) -> Result<Map<String, Value>, CliError> {
    let message = input
        .opt("message")
        .flatten()
        .filter(|message| !php::trim(message).is_empty())
        .ok_or_else(|| CliError::Invalid("--message is required.".into()))?;
    let mut failure = Map::new();
    failure.insert("message".into(), json!(message));
    if reason {
        failure.insert("reason".into(), option_value(input, "reason"));
    }
    for (field, option) in [("type", "type"), ("stack_trace", "stack-trace")] {
        if let Some(value) = input
            .opt(option)
            .flatten()
            .filter(|value| !value.is_empty())
        {
            failure.insert(field.into(), json!(value));
        }
    }
    Ok(failure)
}

pub fn workflow_task_fail(context: &Context, input: &Input) -> Result<i32, CliError> {
    let task_id = input.arg("task-id").unwrap_or_default();
    let attempt = positive_attempt(input.arg("attempt").unwrap_or_default(), "attempt")?;
    let failure = task_failure(input, false)?;
    let mut body = Map::new();
    body.insert("lease_owner".into(), option_value(input, "lease-owner"));
    body.insert("workflow_task_attempt".into(), json!(attempt));
    body.insert("failure".into(), Value::Object(failure));
    workflow_task_outcome(
        context,
        input,
        task_id,
        attempt,
        "fail",
        body,
        &[],
        "Workflow task failed",
    )?;
    Ok(0)
}

pub fn workflow_task_history(context: &Context, input: &Input) -> Result<i32, CliError> {
    let task_id = input.arg("task-id").unwrap_or_default();
    let attempt = positive_attempt(
        input.opt("attempt").flatten().unwrap_or_default(),
        "--attempt",
    )?;
    let mut body = Map::new();
    body.insert(
        "next_history_page_token".into(),
        json!(input.arg("page-token").unwrap_or_default()),
    );
    body.insert("lease_owner".into(), option_value(input, "lease-owner"));
    body.insert("workflow_task_attempt".into(), json!(attempt));
    let result = worker_post(
        context,
        input,
        &["worker", "workflow-tasks", task_id, "history"],
        body,
        &[],
    )?;
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    formatter::writeln_out("<info>Workflow task history page fetched</info>");
    formatter::writeln_out(&format!("  Task ID: {}", formatter::display_text(task_id)));
    formatter::writeln_out(&format!("  Attempt: {attempt}"));
    formatter::writeln_out(&format!(
        "  Events: {}",
        history_count(result.get("history_events"))
    ));
    if let Some(token) = result
        .get("next_history_page_token")
        .filter(|value| !value.is_null())
    {
        formatter::writeln_out(&format!("  Next History Page Token: {}", text(Some(token))));
    }
    Ok(0)
}

pub fn query_task_poll(context: &Context, input: &Input) -> Result<i32, CliError> {
    let mut body = Map::new();
    body.insert(
        "worker_id".into(),
        json!(input.arg("worker-id").unwrap_or_default()),
    );
    body.insert("task_queue".into(), option_value(input, "task-queue"));
    let result = worker_post(
        context,
        input,
        &["worker", "query-tasks", "poll"],
        body,
        &[],
    )?;
    render_leased(
        context,
        &result,
        "query",
        &[
            ("Task ID", "query_task_id"),
            ("Workflow ID", "workflow_id"),
            ("Run ID", "run_id"),
            ("Query", "query_name"),
            ("Attempt", "query_task_attempt"),
        ],
    );
    Ok(0)
}

pub fn query_task_complete(context: &Context, input: &Input) -> Result<i32, CliError> {
    let task_id = input.arg("query-task-id").unwrap_or_default();
    let attempt = positive_attempt(input.arg("attempt").unwrap_or_default(), "attempt")?;
    let raw = input
        .opt("result")
        .flatten()
        .filter(|raw| !php::trim(raw).is_empty())
        .ok_or_else(|| CliError::Invalid("--result is required.".into()))?;
    let result_payload = json_option(raw, "result")?;
    let mut body = Map::new();
    body.insert("lease_owner".into(), option_value(input, "lease-owner"));
    body.insert("query_task_attempt".into(), json!(attempt));
    body.insert("result".into(), result_payload.value.clone());
    let result = worker_post(
        context,
        input,
        &["worker", "query-tasks", task_id, "complete"],
        body,
        &[Some(&result_payload)],
    )?;
    query_task_outcome(context, &result, "Query task completed", task_id, attempt)
}

pub fn query_task_fail(context: &Context, input: &Input) -> Result<i32, CliError> {
    let task_id = input.arg("query-task-id").unwrap_or_default();
    let attempt = positive_attempt(input.arg("attempt").unwrap_or_default(), "attempt")?;
    let failure = task_failure(input, true)?;
    let mut body = Map::new();
    body.insert("lease_owner".into(), option_value(input, "lease-owner"));
    body.insert("query_task_attempt".into(), json!(attempt));
    body.insert("failure".into(), Value::Object(failure));
    let result = worker_post(
        context,
        input,
        &["worker", "query-tasks", task_id, "fail"],
        body,
        &[],
    )?;
    query_task_outcome(context, &result, "Query task failed", task_id, attempt)
}

fn query_task_outcome(
    context: &Context,
    result: &Value,
    title: &str,
    task_id: &str,
    attempt: i64,
) -> Result<i32, CliError> {
    if context.mode.machine_readable() {
        output::print_json(result);
        return Ok(0);
    }
    formatter::writeln_out(&format!("<info>{title}</info>"));
    formatter::writeln_out(&format!(
        "  Task ID: {}",
        text_or_value(result.get("query_task_id"), task_id)
    ));
    formatter::writeln_out(&format!(
        "  Attempt: {}",
        text_or_value(result.get("query_task_attempt"), &attempt.to_string())
    ));
    formatter::writeln_out(&format!(
        "  Outcome: {}",
        text_or(result.get("outcome"), "-")
    ));
    Ok(0)
}
