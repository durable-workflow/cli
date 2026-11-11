//! Read one workflow execution or run: `workflow:describe`,
//! `workflow:show-run`, `workflow:history` and `workflow:history-export`.

use crate::cli::Input;
use crate::client::Context;
use crate::commands::workflow::with_namespace;
use crate::error::CliError;
use crate::{formatter, output, php};
use serde_json::Value;
use std::time::Duration;

/// How often `--follow` polls, as in 2.x.
const FOLLOW_POLL_INTERVAL: Duration = Duration::from_secs(2);

pub fn describe(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let workflow_id = input.arg("workflow-id").unwrap_or_default();
    // 2.x tests the option for PHP truthiness: "" and "0" mean the current run.
    let run_id = input
        .opt("run-id")
        .flatten()
        .filter(|run_id| !run_id.is_empty() && *run_id != "0");
    let fetch = || match run_id {
        Some(run_id) => fetch_run(context, workflow_id, run_id),
        None => {
            let result = context.workflow_get(
                &["workflows", workflow_id],
                &[],
                &format!("/workflows/{workflow_id}"),
            )?;
            Ok(with_namespace(result, &context.connection.namespace, None))
        }
    };
    show(context, input, "Workflow Execution", "workflow", fetch)
}

pub fn show_run(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let workflow_id = input.arg("workflow-id").unwrap_or_default();
    let run_id = input.arg("run-id").unwrap_or_default();
    show(context, input, "Workflow Run", "run", || {
        fetch_run(context, workflow_id, run_id)
    })
}

fn fetch_run(context: &Context, workflow_id: &str, run_id: &str) -> Result<Value, CliError> {
    let result = context.workflow_get(
        &["workflows", workflow_id, "runs", run_id],
        &[],
        &format!("/workflows/{workflow_id}/runs/{run_id}"),
    )?;
    Ok(with_namespace(result, &context.connection.namespace, None))
}

fn show(
    context: &Context,
    input: &Input,
    title: &str,
    subject: &str,
    fetch: impl Fn() -> Result<Value, CliError>,
) -> Result<i32, CliError> {
    let mut result = fetch()?;
    if input.flag("follow") && !is_terminal(&result) {
        let mut last = result.get("status").cloned().unwrap_or(Value::Null);
        formatter::writeln_out(&format!(
            "<comment>Following {subject} (status: {})...</comment>",
            status(Some(&last).filter(|value| !value.is_null()), "unknown")
        ));
        while !is_terminal(&result) {
            std::thread::sleep(FOLLOW_POLL_INTERVAL);
            result = fetch()?;
            let current = result.get("status").cloned().unwrap_or(Value::Null);
            if current != last {
                formatter::writeln_out(&format!(
                    "  Status changed: {} -> {}",
                    status(Some(&last).filter(|value| !value.is_null()), "-"),
                    status(Some(&current).filter(|value| !value.is_null()), "-"),
                ));
                last = current;
            }
        }
        formatter::writeln_out("");
    }
    render(context, title, &result)
}

/// 2.x `isTerminal()`: the Server's `is_terminal` when set, otherwise the
/// terminal status buckets and statuses.
fn is_terminal(result: &Value) -> bool {
    match result.get("is_terminal") {
        Some(value) if !value.is_null() => truthy(value),
        _ => {
            let bucket = result.get("status_bucket").and_then(Value::as_str);
            let status = result.get("status").and_then(Value::as_str);
            matches!(bucket, Some("completed" | "failed"))
                || matches!(
                    status,
                    Some("completed" | "failed" | "cancelled" | "terminated" | "timed_out")
                )
        }
    }
}

fn truthy(value: &Value) -> bool {
    match value {
        Value::Null => false,
        Value::Bool(flag) => *flag,
        Value::Number(number) => number.as_f64().is_some_and(|number| number != 0.0),
        Value::String(text) => !(text.is_empty() || text == "0"),
        Value::Array(items) => !items.is_empty(),
        Value::Object(map) => !map.is_empty(),
    }
}

/// `formatStatus($value ?? $default)`, escaping the remote text first.
fn status(value: Option<&Value>, default: &str) -> String {
    let text = match value {
        Some(value) if !value.is_null() => {
            if !php::is_scalar(Some(value)) {
                return "-".into();
            }
            php::to_string(Some(value))
        }
        _ => default.to_string(),
    };
    if text.is_empty() {
        return "-".into();
    }
    output::format_status(Some(&Value::String(formatter::display_text(&text))))
}

fn render(context: &Context, title: &str, result: &Value) -> Result<i32, CliError> {
    if context.mode.machine_readable() {
        output::print_json(result);
        return Ok(0);
    }
    let field = |name: &str| formatter::display_text(&php::or(result.get(name), "-"));
    // Lines go out as they are built, so a field 2.x cannot format stops
    // the output at the same point.
    for line in [
        format!("<info>{title}</info>"),
        format!("  Workflow ID: {}", field("workflow_id")),
        format!("  Run ID: {}", field("run_id")),
        format!("  Type: {}", field("workflow_type")),
        format!("  Namespace: {}", field("namespace")),
        format!("  Business Key: {}", field("business_key")),
        format!("  Status: {}", status(result.get("status"), "")),
        format!(
            "  Status Bucket: {}",
            status(result.get("status_bucket"), "")
        ),
        format!("  Run Number: {}", field("run_number")),
        format!("  Run Count: {}", field("run_count")),
        format!(
            "  Current Run: {}",
            if result.get("is_current_run").is_some_and(truthy) {
                "yes"
            } else {
                "no"
            }
        ),
        format!("  Task Queue: {}", field("task_queue")),
        format!("  Compatibility: {}", field("compatibility")),
        format!("  Payload Codec: {}", field("payload_codec")),
    ] {
        formatter::writeln_out(&line);
    }
    formatter::writeln_out(&format!(
        "  Execution Timeout: {}",
        timeout(result.get("execution_timeout_seconds"))?
    ));
    formatter::writeln_out(&format!(
        "  Run Timeout: {}",
        timeout(result.get("run_timeout_seconds"))?
    ));
    let mut lines = vec![
        format!("  Execution Deadline: {}", field("execution_deadline_at")),
        format!("  Run Deadline: {}", field("run_deadline_at")),
        format!("  Started: {}", field("started_at")),
        format!("  Closed: {}", field("closed_at")),
        format!("  Last Progress: {}", field("last_progress_at")),
        format!("  Closed Reason: {}", field("closed_reason")),
        format!("  Wait Kind: {}", field("wait_kind")),
        format!("  Wait Reason: {}", field("wait_reason")),
    ];
    for (label, key) in [
        ("Input", "input"),
        ("Output", "output"),
        ("Memo", "memo"),
        ("Search Attributes", "search_attributes"),
    ] {
        if let Some(value) = result.get(key).filter(|value| !value.is_null()) {
            lines.push(format!(
                "  {label}: {}",
                formatter::display_text(&php::json_encode(value))
            ));
        }
    }
    if let Some(actions) = result.get("actions").filter(|value| php::is_array(value)) {
        let enabled = php::entries(actions)
            .into_iter()
            .filter(|(_, enabled)| **enabled == Value::Bool(true))
            .map(|(key, _)| formatter::display_text(&key.text()))
            .collect::<Vec<_>>();
        lines.push(format!(
            "  Actions: {}",
            if enabled.is_empty() {
                "-".to_string()
            } else {
                enabled.join(", ")
            }
        ));
    }
    for line in lines {
        formatter::writeln_out(&line);
    }
    Ok(0)
}

/// 2.x `formatTimeout(int|null)`. Its strict type refuses other values.
fn timeout(value: Option<&Value>) -> Result<String, CliError> {
    match value {
        None | Some(Value::Null) => Ok("-".into()),
        Some(value) if php::is_int(Some(value)) => Ok(format!("{}s", php::to_string(Some(value)))),
        Some(_) => Err(CliError::Failure(
            "Server error: workflow timeout fields must be whole seconds.".into(),
        )),
    }
}

pub fn history(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let workflow_id = input.arg("workflow-id").unwrap_or_default();
    let run_id = input.arg("run-id").unwrap_or_default();
    let mut query = vec![(
        "wait_new_event".to_string(),
        if input.flag("follow") { "1" } else { "0" }.to_string(),
    )];
    if let Some(page_size) = input.opt("page-size").flatten() {
        query.push((
            "page_size".into(),
            php::string_to_int(page_size).to_string(),
        ));
    }
    let streaming = context.mode == output::Mode::Jsonl;
    let path = format!("/workflows/{workflow_id}/runs/{run_id}/history");
    let mut events: Vec<Value> = Vec::new();
    let mut result;
    loop {
        result = with_namespace(
            context.workflow_get(
                &["workflows", workflow_id, "runs", run_id, "history"],
                &query,
                &path,
            )?,
            &context.connection.namespace,
            Some("events"),
        );
        match result.get("events") {
            None | Some(Value::Null) => {}
            Some(page) if php::is_array(page) => {
                for event in php::values(page) {
                    if streaming {
                        output::print_json(event);
                    } else {
                        events.push(event.clone());
                    }
                }
            }
            Some(page) => {
                if !streaming {
                    return Err(CliError::Failure(format!(
                        "array_merge(): Argument #2 must be of type array, {} given",
                        php_type(page)
                    )));
                }
            }
        }
        match result.get("next_page_token") {
            None | Some(Value::Null) => break,
            Some(token) => {
                query.retain(|(name, _)| name != "next_page_token");
                query.push(("next_page_token".into(), php::to_string(Some(token))));
            }
        }
    }
    if streaming {
        return Ok(0);
    }
    let namespace = php::to_string(result.get("namespace"));
    if context.mode.machine_readable() {
        let mut envelope = php::array_map(result);
        envelope.insert("events".into(), Value::Array(events));
        envelope.remove("next_page_token");
        let envelope = with_namespace(Value::Object(envelope), &namespace, Some("events"));
        output::print_json(&envelope);
        return Ok(0);
    }
    let namespace = formatter::display_text(&namespace);
    if events.is_empty() {
        formatter::writeln_out(&format!(
            "<comment>No history events in namespace {namespace}.</comment>"
        ));
        return Ok(0);
    }
    formatter::writeln_out(&format!("Namespace: {namespace}"));
    let rows = events
        .iter()
        .map(|event| {
            let field = |name: &str| formatter::display_text(&php::or(php::get(event, name), "-"));
            vec![
                field("sequence"),
                field("event_type"),
                field("timestamp"),
                formatter::display_text(&principal(php::get(event, "principal"))),
                formatter::display_text(&php::json_encode(
                    php::get(event, "payload").unwrap_or(&Value::Null),
                )),
            ]
        })
        .collect::<Vec<_>>();
    formatter::writeln_out(&output::render_table(
        &["#", "Event Type", "Time", "Principal", "Details"],
        &rows,
    ));
    Ok(0)
}

fn php_type(value: &Value) -> &'static str {
    match value {
        Value::Null => "null",
        Value::Bool(_) => "bool",
        Value::Number(number) if number.is_f64() => "float",
        Value::Number(_) => "int",
        Value::String(_) => "string",
        Value::Array(_) | Value::Object(_) => "array",
    }
}

/// 2.x `principalLabel()`.
fn principal(value: Option<&Value>) -> String {
    let Some(principal) = value.filter(|value| php::is_array(value)) else {
        return "-".into();
    };
    let text = |name: &str| {
        php::get(principal, name)
            .and_then(Value::as_str)
            .map(php::trim)
            .filter(|text| !text.is_empty())
            .map(str::to_owned)
    };
    let (id, kind, label) = (text("id"), text("type"), text("label"));
    if id.is_none() && kind.is_none() && label.is_none() {
        return "-".into();
    }
    let identity = label.or(id).unwrap_or_else(|| "-".into());
    match kind {
        Some(kind) => format!("{identity} ({kind})"),
        None => identity,
    }
}

pub fn history_export(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let workflow_id = input.arg("workflow-id").unwrap_or_default();
    let run_id = input.arg("run-id").unwrap_or_default();
    let result = with_namespace(
        context.workflow_history_export(workflow_id, run_id)?,
        &context.connection.namespace,
        None,
    );
    let Some(file) = input.opt("output-file").flatten() else {
        // Stdout is the bundle only, compact, whatever the output mode.
        output::print_json(&result);
        return Ok(0);
    };
    std::fs::write(file, php::json_encode_pretty(&result) + "\n").map_err(|error| {
        CliError::Failure(format!(
            "Unable to write the history export to {file}: {error}"
        ))
    })?;
    formatter::writeln_err(&format!(
        "<info>Exported namespace {} to {}</info>",
        formatter::display_text(&php::to_string(result.get("namespace"))),
        formatter::display_text(file),
    ));
    Ok(0)
}

/// 2.x waits at least this long for a query response.
const QUERY_TIMEOUT: f64 = 35.0;
/// Added to the Server's advertised query task timeout.
const QUERY_TIMEOUT_GRACE: f64 = 10.0;
const QUERY_TIMEOUT_MAX: f64 = 300.0;

pub fn query(context: &Context, input: &Input) -> Result<i32, CliError> {
    let workflow_id = input.arg("workflow-id").unwrap_or_default();
    let query_name = input.arg("query-name").unwrap_or_default();
    // 2.x reads the payload before any request.
    let mut body = serde_json::Map::new();
    if let Some(arguments) = crate::input::parse_arguments(input)? {
        body.insert("input".into(), arguments);
    }
    let info = context.preflight(false)?;
    let run_id = input.opt("run-id").flatten();
    let (segments, path) = match run_id {
        Some(run_id) => (
            vec![
                "workflows",
                workflow_id,
                "runs",
                run_id,
                "query",
                query_name,
            ],
            format!("/workflows/{workflow_id}/runs/{run_id}/query/{query_name}"),
        ),
        None => (
            vec!["workflows", workflow_id, "query", query_name],
            format!("/workflows/{workflow_id}/query/{query_name}"),
        ),
    };
    let body = if body.is_empty() {
        Value::Array(Vec::new())
    } else {
        Value::Object(body)
    };
    let result = with_namespace(
        context.workflow_post(&segments, body, &path, Some(query_timeout(&info)))?,
        &context.connection.namespace,
        None,
    );
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    formatter::writeln_out("<info>Query result</info>");
    let namespace = result
        .get("namespace")
        .filter(|value| {
            php::is_scalar(Some(value)) && !php::trim(&php::to_string(Some(value))).is_empty()
        })
        .map(|value| php::to_string(Some(value)))
        .unwrap_or_else(|| "-".into());
    formatter::writeln_out(&format!(
        "  Namespace: {}",
        formatter::display_text(&namespace)
    ));
    if let Some(name) = result.get("query_name").filter(|value| !value.is_null()) {
        formatter::writeln_out(&format!(
            "  Query: {}",
            formatter::display_text(&php::to_string(Some(name)))
        ));
    }
    // Pretty JSON keeps its line breaks; only console markup is escaped.
    formatter::writeln_out(&formatter::escape(&php::json_encode_pretty(
        result.get("result").unwrap_or(&Value::Null),
    )));
    Ok(0)
}

/// 2.x `queryHttpTimeoutSeconds()`: at least 35 s, or the Server's query task
/// timeout plus 10 s, at most 300 s.
fn query_timeout(info: &Value) -> Duration {
    let advertised = php::get(info, "worker_protocol")
        .and_then(|value| php::get(value, "server_capabilities"))
        .and_then(|value| php::get(value, "query_task_timeouts"))
        .and_then(|value| php::get(value, "control_plane_timeout_seconds"))
        .and_then(Value::as_f64)
        .map(|seconds| seconds.max(0.0));
    let mut seconds = QUERY_TIMEOUT;
    if let Some(advertised) = advertised {
        seconds = seconds.max(advertised + QUERY_TIMEOUT_GRACE);
    }
    Duration::from_secs_f64(seconds.min(QUERY_TIMEOUT_MAX))
}
