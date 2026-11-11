//! `schedule:*` through the published SDK transport.
//!
//! Every command makes one request per Server page or mutation. A redirect
//! is refused rather than followed, and a mutation whose response is lost is
//! reported as a transport failure, never submitted again.

use crate::cli::Input;
use crate::client::Context;
use crate::error::CliError;
use crate::payload::{self, Payload};
use crate::{formatter, output, php};
use durable_workflow::RawRequest;
use serde_json::{json, Map, Value};

pub fn list(context: &Context, input: &Input) -> Result<i32, CliError> {
    let status = non_blank(input, "status")?;
    let workflow_type = non_blank(input, "type")?;
    let query = non_blank(input, "query")?;
    let page_size = bounded_integer(input, "limit", 200)?;
    let next_page_token = non_blank(input, "next-page-token")?;
    context.preflight(false)?;
    let mut request = RawRequest::get(["schedules"]);
    for (name, value) in [
        ("status", status.map(str::to_owned)),
        ("workflow_type", workflow_type.map(str::to_owned)),
        ("query", query.map(str::to_owned)),
        ("page_size", page_size.map(|size| size.to_string())),
        ("next_page_token", next_page_token.map(str::to_owned)),
    ] {
        if let Some(value) = value {
            request = request.query(name, value);
        }
    }
    let result = with_namespace(
        context.resource_request(request)?,
        &context.connection.namespace,
        Some("schedules"),
    );
    if context.mode.machine_readable() {
        output::print_json_list(context.mode, &result, "schedules");
        return Ok(0);
    }
    let namespace = text(result.get("namespace"));
    let schedules = php::get(&result, "schedules");
    if !truthy(schedules) {
        formatter::writeln_out(&format!(
            "<comment>No schedules found in namespace {namespace}.</comment>"
        ));
        return Ok(0);
    }
    formatter::writeln_out(&format!("Namespace: {namespace}"));
    let schedules = schedules.expect("a truthy value is present");
    if !php::is_array(schedules) {
        return Err(CliError::Failure(format!(
            "array_map(): Argument #2 ($array) must be of type array, {} given",
            value_name(schedules)
        )));
    }
    let cells = ["schedule_id", "workflow_type", "next_fire", "last_fire"];
    if php::values(schedules).into_iter().any(|schedule| {
        cells
            .iter()
            .any(|cell| php::get(schedule, cell).is_some_and(php::is_array))
    }) {
        // Symfony's table refuses an array cell.
        return Err(CliError::Failure(
            "A cell must be a TableCell, a scalar or an object implementing \"__toString()\", \"array\" given."
                .into(),
        ));
    }
    let rows = php::values(schedules)
        .into_iter()
        .map(|schedule| {
            let status = match php::get(schedule, "status") {
                Some(status) => status.clone(),
                None if truthy(php::get(schedule, "paused")) => json!("paused"),
                None => json!("active"),
            };
            vec![
                text_or(php::get(schedule, "schedule_id"), "-"),
                text_or(php::get(schedule, "workflow_type"), "-"),
                status_cell(&status),
                text_or(php::get(schedule, "next_fire"), "-"),
                text_or(php::get(schedule, "last_fire"), "-"),
            ]
        })
        .collect::<Vec<_>>();
    formatter::writeln_out(&output::render_table(
        &[
            "Schedule ID",
            "Workflow Type",
            "Status",
            "Next Fire",
            "Last Fire",
        ],
        &rows,
    ));
    if let Some(token) =
        php::as_str(result.get("next_page_token")).filter(|token| !token.is_empty())
    {
        formatter::writeln_out(&format!(
            "Next page token: {}",
            formatter::display_text(token)
        ));
    }
    Ok(0)
}

pub fn describe(context: &Context, input: &Input) -> Result<i32, CliError> {
    let schedule_id = input.arg("schedule-id").unwrap_or_default();
    context.preflight(false)?;
    let result = with_namespace(
        context.resource_request(RawRequest::get(["schedules", schedule_id]))?,
        &context.connection.namespace,
        None,
    );
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    formatter::writeln_out(&format!(
        "<info>Schedule: {}</info>",
        formatter::display_text(schedule_id)
    ));
    write_namespace_line(&result);
    for (label, key) in [("Spec", "spec"), ("Action", "action"), ("State", "state")] {
        let encoded = php::json_encode(php::get(&result, key).unwrap_or(&Value::Null));
        formatter::writeln_out(&format!("  {label}: {}", formatter::display_text(&encoded)));
    }
    formatter::writeln_out(&format!(
        "  Overlap Policy: {}",
        text_or(php::get(&result, "overlap_policy"), "skip")
    ));
    let jitter = php::get(&result, "jitter_seconds");
    if greater_than_zero(jitter) {
        formatter::writeln_out(&format!("  Jitter: {}s", text(jitter)));
    }
    if let Some(max_runs) = php::get(&result, "max_runs") {
        formatter::writeln_out(&format!(
            "  Max Runs: {} ({} remaining)",
            php::int_cast(Some(max_runs)),
            text_or(php::get(&result, "remaining_actions"), "?")
        ));
    }
    let info = php::get(&result, "info");
    let buffered = php::array_get(info, "buffered_actions");
    if truthy(buffered) {
        let buffered = buffered.expect("a truthy value is present");
        if !php::is_array(buffered) {
            return Err(CliError::Failure(format!(
                "count(): Argument #1 ($value) must be of type Countable|array, {} given",
                value_name(buffered)
            )));
        }
        formatter::writeln_out(&format!(
            "  Buffered Actions: {} pending",
            php::values(buffered).len()
        ));
    }
    let skipped = php::array_get(info, "skipped_trigger_count");
    if greater_than_zero(skipped) {
        formatter::writeln_out(&format!(
            "  Skipped: {} (last: {})",
            php::int_cast(skipped),
            text_or(php::array_get(info, "last_skip_reason"), "-")
        ));
    }
    Ok(0)
}

pub fn history(context: &Context, input: &Input) -> Result<i32, CliError> {
    let schedule_id = input.arg("schedule-id").unwrap_or_default();
    let limit = bounded_integer(input, "limit", 500)?;
    let after_sequence = match input.opt("after-sequence").flatten() {
        None => None,
        Some(raw) if digits(raw) => Some(php::string_to_int(raw)),
        Some(_) => {
            return Err(CliError::Invalid(
                "--after-sequence must be a non-negative integer.".into(),
            ))
        }
    };
    let fetch_all = input.flag("all");
    context.preflight(false)?;

    let mut events = Vec::new();
    let mut namespace = json!(context.connection.namespace);
    let mut cursor = after_sequence.map(|sequence| json!(sequence));
    let mut next_cursor;
    let mut has_more;
    loop {
        let mut request = RawRequest::get(["schedules", schedule_id, "history"]);
        if let Some(limit) = limit {
            request = request.query("limit", limit.to_string());
        }
        if let Some(cursor) = &cursor {
            request = request.query("after_sequence", php::to_string(Some(cursor)));
        }
        let response = with_namespace(
            context.resource_request(request)?,
            &context.connection.namespace,
            Some("events"),
        );
        if let Some(page) = php::get(&response, "events").filter(|page| php::is_array(page)) {
            events.extend(php::values(page).into_iter().cloned());
        }
        if let Some(value) = php::get(&response, "namespace") {
            namespace = value.clone();
        }
        has_more = truthy(php::get(&response, "has_more"));
        next_cursor = php::get(&response, "next_cursor").cloned();
        cursor = next_cursor.clone();
        if !(fetch_all && has_more && cursor.is_some()) {
            break;
        }
    }

    let envelope = json!({
        "schedule_id": schedule_id,
        "namespace": namespace,
        "events": events,
        "has_more": if fetch_all { false } else { has_more },
        "next_cursor": if fetch_all { None } else { next_cursor.clone() },
    });
    if context.mode.machine_readable() {
        output::print_json_list(context.mode, &envelope, "events");
        return Ok(0);
    }
    let namespace = text(Some(&namespace));
    if events.is_empty() {
        formatter::writeln_out(&format!(
            "<comment>No audit events recorded for schedule {} in namespace {namespace}.</comment>",
            formatter::display_text(schedule_id)
        ));
        return Ok(0);
    }
    formatter::writeln_out(&format!("Namespace: {namespace}"));
    let mut rows = Vec::new();
    for event in &events {
        if !php::is_array(event) {
            return Err(CliError::Failure(format!(
                "DurableWorkflow\\Cli\\Commands\\ScheduleCommand\\HistoryCommand::{{closure:DurableWorkflow\\Cli\\Commands\\ScheduleCommand\\HistoryCommand::execute():121}}(): Argument #1 ($event) must be of type array, {} given",
                value_name(event)
            )));
        }
        let mut refs = Vec::new();
        for (key, prefix) in [("workflow_instance_id", "wf="), ("workflow_run_id", "run=")] {
            let value = php::get(event, key);
            if truthy(value) {
                refs.push(format!("{prefix}{}", php::to_string(value)));
            }
        }
        rows.push(vec![
            text_or(php::get(event, "sequence"), "-"),
            text_or(php::get(event, "event_type"), "-"),
            text_or(php::get(event, "recorded_at"), "-"),
            if refs.is_empty() {
                "-".into()
            } else {
                formatter::display_text(&refs.join(" "))
            },
        ]);
    }
    formatter::writeln_out(&output::render_table(
        &["Seq", "Event", "Recorded At", "Workflow Refs"],
        &rows,
    ));
    if has_more && !fetch_all {
        formatter::writeln_out(&format!(
            "<comment>More events available. Re-run with --after-sequence={} (or --all) to continue.</comment>",
            text_or(next_cursor.as_ref(), "?")
        ));
    }
    Ok(0)
}

pub fn create(context: &Context, input: &Input) -> Result<i32, CliError> {
    let cron = input.opt("cron").flatten();
    let interval = input.opt("interval").flatten();
    if cron.is_none() && interval.is_none() {
        return Err(CliError::Invalid(
            "Either --cron or --interval is required.".into(),
        ));
    }
    let mut spec = schedule_spec(cron, interval);
    if let Some(timezone) = input.opt("timezone").flatten() {
        spec.insert("timezone".into(), json!(timezone));
    }
    let schedule_id = input.opt("schedule-id").flatten();
    let workflow_type = input.opt("workflow-type").flatten();
    let task_queue = input.opt("task-queue").flatten();
    let arguments = payload::parse_arguments(input)?;
    let body = json!({
        "schedule_id": schedule_id,
        "spec": spec,
        "action": action(
            workflow_type,
            task_queue,
            arguments.as_ref(),
            input.opt("execution-timeout").flatten(),
            input.opt("run-timeout").flatten(),
        ),
        "overlap_policy": input.opt("overlap-policy").flatten(),
        "jitter_seconds": integer_option(input, "jitter"),
        "max_runs": integer_option(input, "max-runs"),
        "paused": input.flag("paused"),
        "note": input.opt("note").flatten(),
    });
    context.preflight(false)?;
    let body = payload::request_body(body, &[arguments.as_ref()])?;
    let result = with_namespace(
        context.resource_request(RawRequest::post(["schedules"]).json(body))?,
        &context.connection.namespace,
        None,
    );
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    formatter::writeln_out(&format!(
        "<info>Schedule created: {}</info>",
        text(php::get(&result, "schedule_id"))
    ));
    write_namespace_line(&result);
    Ok(0)
}

pub fn update(context: &Context, input: &Input) -> Result<i32, CliError> {
    let schedule_id = input.arg("schedule-id").unwrap_or_default();
    let mut body = Map::new();
    let cron = input.opt("cron").flatten();
    let interval = input.opt("interval").flatten();
    if cron.is_some() || interval.is_some() {
        body.insert("spec".into(), Value::Object(schedule_spec(cron, interval)));
    }
    let workflow_type = input.opt("workflow-type").flatten();
    let task_queue = input.opt("task-queue").flatten();
    let execution_timeout = input.opt("execution-timeout").flatten();
    let run_timeout = input.opt("run-timeout").flatten();
    let arguments = payload::parse_arguments(input)?;
    if workflow_type.is_some()
        || task_queue.is_some()
        || execution_timeout.is_some()
        || run_timeout.is_some()
        || arguments.is_some()
    {
        body.insert(
            "action".into(),
            action(
                workflow_type,
                task_queue,
                arguments.as_ref(),
                execution_timeout,
                run_timeout,
            ),
        );
    }
    if let Some(policy) = input.opt("overlap-policy").flatten() {
        body.insert("overlap_policy".into(), json!(policy));
    }
    for (option, field) in [("jitter", "jitter_seconds"), ("max-runs", "max_runs")] {
        let value = integer_option(input, option);
        if !value.is_null() {
            body.insert(field.into(), value);
        }
    }
    if let Some(note) = input.opt("note").flatten() {
        body.insert("note".into(), json!(note));
    }
    if body.is_empty() {
        return Err(CliError::Invalid(
            "No update fields provided. Use --cron, --interval, --workflow-type, --task-queue, --input, --input-file, --execution-timeout, --run-timeout, --overlap-policy, --jitter, --max-runs, or --note."
                .into(),
        ));
    }
    context.preflight(false)?;
    let body = payload::request_body(Value::Object(body), &[arguments.as_ref()])?;
    let result =
        context.resource_request(RawRequest::put(["schedules", schedule_id]).json(body))?;
    mutation_result(
        context,
        result,
        &format!("Schedule updated: {}", formatter::display_text(schedule_id)),
    )
}

pub fn delete(context: &Context, input: &Input) -> Result<i32, CliError> {
    let schedule_id = input.arg("schedule-id").unwrap_or_default();
    context.preflight(false)?;
    let result = context.resource_request(RawRequest::delete(["schedules", schedule_id]))?;
    mutation_result(
        context,
        result,
        &format!("Schedule deleted: {}", formatter::display_text(schedule_id)),
    )
}

pub fn pause(context: &Context, input: &Input) -> Result<i32, CliError> {
    state_change(context, input, "pause", "Schedule paused")
}

pub fn resume(context: &Context, input: &Input) -> Result<i32, CliError> {
    state_change(context, input, "resume", "Schedule resumed")
}

fn state_change(
    context: &Context,
    input: &Input,
    verb: &str,
    label: &str,
) -> Result<i32, CliError> {
    let schedule_id = input.arg("schedule-id").unwrap_or_default();
    let body = optional_fields(input, &[("note", "note")]);
    context.preflight(false)?;
    let body = payload::request_body(body, &[])?;
    let result =
        context.resource_request(RawRequest::post(["schedules", schedule_id, verb]).json(body))?;
    mutation_result(
        context,
        result,
        &format!("{label}: {}", formatter::display_text(schedule_id)),
    )
}

pub fn trigger(context: &Context, input: &Input) -> Result<i32, CliError> {
    let schedule_id = input.arg("schedule-id").unwrap_or_default();
    let body = optional_fields(input, &[("overlap-policy", "overlap_policy")]);
    context.preflight(false)?;
    let body = payload::request_body(body, &[])?;
    let result = with_namespace(
        context
            .resource_request(RawRequest::post(["schedules", schedule_id, "trigger"]).json(body))?,
        &context.connection.namespace,
        None,
    );
    let outcome = match php::get(&result, "outcome") {
        None => "unknown",
        Some(Value::String(outcome)) => outcome.as_str(),
        // 2.x passes the outcome to a string-typed helper.
        Some(other) => {
            return Err(CliError::Failure(format!(
                "DurableWorkflow\\Cli\\Commands\\ScheduleCommand\\TriggerCommand::exitCodeForOutcome(): Argument #1 ($outcome) must be of type string, {} given",
                value_name(other)
            )))
        }
    };
    let exit_code = i32::from(!matches!(
        outcome,
        "triggered" | "buffered" | "buffer_full" | "skipped"
    ));
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(exit_code);
    }
    let id = formatter::display_text(schedule_id);
    let namespace = text(php::get(&result, "namespace"));
    let reason = |default: &str| text_or(php::get(&result, "reason"), default);
    formatter::writeln_out(&match outcome {
        "triggered" => format!(
            "<info>Schedule triggered:</info> {id} (namespace {namespace}) -> {}",
            text_or(php::get(&result, "workflow_id"), "(unknown)")
        ),
        "buffered" => format!(
            "<comment>Schedule buffered:</comment> {id} (namespace {namespace}, buffer depth: {})",
            php::int_cast(php::get(&result, "buffer_depth"))
        ),
        "buffer_full" => format!(
            "<comment>Buffer full:</comment> {id} (namespace {namespace}) - {}",
            reason("previous workflow still running")
        ),
        "skipped" => format!(
            "<comment>Skipped:</comment> {id} (namespace {namespace}) - {}",
            reason("schedule exhausted")
        ),
        "trigger_failed" => format!(
            "<error>Trigger failed:</error> {id} (namespace {namespace}) - {}",
            reason("unknown error")
        ),
        other => format!(
            "<info>Schedule trigger outcome:</info> {id} (namespace {namespace}, {})",
            formatter::display_text(other)
        ),
    });
    Ok(exit_code)
}

pub fn backfill(context: &Context, input: &Input) -> Result<i32, CliError> {
    let schedule_id = input.arg("schedule-id").unwrap_or_default();
    let body = optional_fields(
        input,
        &[
            ("start-time", "start_time"),
            ("end-time", "end_time"),
            ("overlap-policy", "overlap_policy"),
        ],
    );
    context.preflight(false)?;
    let body = payload::request_body(body, &[])?;
    let result = context
        .resource_request(RawRequest::post(["schedules", schedule_id, "backfill"]).json(body))?;
    mutation_result(
        context,
        result,
        &format!(
            "Backfill started for: {}",
            formatter::display_text(schedule_id)
        ),
    )
}

fn mutation_result(context: &Context, result: Value, message: &str) -> Result<i32, CliError> {
    let result = with_namespace(result, &context.connection.namespace, None);
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    formatter::writeln_out(&format!("<info>{message}</info>"));
    write_namespace_line(&result);
    Ok(0)
}

fn schedule_spec(cron: Option<&str>, interval: Option<&str>) -> Map<String, Value> {
    let mut spec = Map::new();
    if let Some(cron) = cron {
        spec.insert("cron_expressions".into(), json!([cron]));
    }
    if let Some(interval) = interval {
        spec.insert("intervals".into(), json!([{"every": interval}]));
    }
    spec
}

/// The scheduled workflow action, without the fields the caller left out.
fn action(
    workflow_type: Option<&str>,
    task_queue: Option<&str>,
    arguments: Option<&Payload>,
    execution_timeout: Option<&str>,
    run_timeout: Option<&str>,
) -> Value {
    let mut action = Map::new();
    if let Some(workflow_type) = workflow_type {
        action.insert("workflow_type".into(), json!(workflow_type));
    }
    if let Some(task_queue) = task_queue {
        action.insert("task_queue".into(), json!(task_queue));
    }
    if let Some(arguments) = arguments {
        action.insert("input".into(), arguments.value.clone());
    }
    if let Some(seconds) = execution_timeout {
        action.insert(
            "execution_timeout_seconds".into(),
            json!(php::string_to_int(seconds)),
        );
    }
    if let Some(seconds) = run_timeout {
        action.insert(
            "run_timeout_seconds".into(),
            json!(php::string_to_int(seconds)),
        );
    }
    Value::Object(action)
}

/// `array_filter([...])` of option values: only the options given a value.
fn optional_fields(input: &Input, fields: &[(&str, &str)]) -> Value {
    Value::Object(
        fields
            .iter()
            .filter_map(|(option, field)| {
                input
                    .opt(option)
                    .flatten()
                    .map(|value| ((*field).to_string(), json!(value)))
            })
            .collect(),
    )
}

/// `$option !== null ? (int) $option : null`.
fn integer_option(input: &Input, name: &str) -> Value {
    input
        .opt(name)
        .flatten()
        .map_or(Value::Null, |value| json!(php::string_to_int(value)))
}

/// The 2.x `nonBlankOption()`.
fn non_blank<'a>(input: &'a Input, name: &str) -> Result<Option<&'a str>, CliError> {
    match input.opt(name).flatten() {
        Some(value) if php::trim(value).is_empty() => {
            Err(CliError::Invalid(format!("--{name} must not be blank.")))
        }
        value => Ok(value),
    }
}

/// `preg_match('/^\d+$/', $value)`, whose `$` also matches before a final newline.
pub(crate) fn digits(value: &str) -> bool {
    php::ctype_digit(value.strip_suffix('\n').unwrap_or(value))
}

/// An optional page size between 1 and `max`.
fn bounded_integer(input: &Input, name: &str, max: i64) -> Result<Option<i64>, CliError> {
    let Some(raw) = input.opt(name).flatten() else {
        return Ok(None);
    };
    let value = php::string_to_int(raw);
    if !digits(raw) || !(1..=max).contains(&value) {
        return Err(CliError::Invalid(format!(
            "--{name} must be an integer between 1 and {max}."
        )));
    }
    Ok(Some(value))
}

/// 2.x `addNamespaceContext()`: the resolved namespace where the Server
/// left none, on the response and on each listed item.
pub(crate) fn with_namespace(value: Value, fallback: &str, items_key: Option<&str>) -> Value {
    let mut body = php::array_map(value);
    if !nonempty_scalar(body.get("namespace")) {
        body.insert("namespace".into(), json!(fallback));
    }
    let namespace = php::to_string(body.get("namespace"));
    if let Some(items) = items_key
        .and_then(|key| body.get_mut(key))
        .filter(|items| php::is_array(items))
    {
        let enrich = |item: &mut Value| {
            if php::is_array(item) && !nonempty_scalar(php::get(item, "namespace")) {
                let mut fields = php::array_map(item.take());
                fields.insert("namespace".into(), json!(namespace));
                *item = Value::Object(fields);
            }
        };
        match items {
            Value::Array(items) => items.iter_mut().for_each(enrich),
            Value::Object(items) => items.values_mut().for_each(enrich),
            _ => {}
        }
    }
    Value::Object(body)
}

/// 2.x `writeNamespaceLine()`.
pub(crate) fn write_namespace_line(result: &Value) {
    let namespace = php::get(result, "namespace").filter(|value| nonempty_scalar(Some(value)));
    formatter::writeln_out(&format!("  Namespace: {}", text_or(namespace, "-")));
}

pub(crate) fn nonempty_scalar(value: Option<&Value>) -> bool {
    php::is_scalar(value) && !php::trim(&php::to_string(value)).is_empty()
}

/// A remote value as display text.
pub(crate) fn text(value: Option<&Value>) -> String {
    formatter::display_text(&php::to_string(value))
}

/// `$value ?? $default` as display text.
pub(crate) fn text_or(value: Option<&Value>, default: &str) -> String {
    formatter::display_text(&php::or(value, default))
}

/// `formatStatus()` of a remote value, escaped before the CLI adds its style.
pub(crate) fn status_cell(status: &Value) -> String {
    let escaped = php::is_scalar(Some(status))
        .then(|| json!(formatter::display_text(&php::to_string(Some(status)))));
    output::format_status(escaped.as_ref())
}

/// PHP `(bool) $value`; a missing value is false.
pub(crate) fn truthy(value: Option<&Value>) -> bool {
    match value {
        None | Some(Value::Null) => false,
        Some(Value::Bool(flag)) => *flag,
        Some(Value::Number(number)) => number.as_f64().is_some_and(|number| number != 0.0),
        Some(Value::String(text)) => !text.is_empty() && text != "0",
        Some(Value::Array(items)) => !items.is_empty(),
        Some(Value::Object(map)) => !map.is_empty(),
    }
}

/// PHP 8 `$value > 0`, where a missing value is `0`.
fn greater_than_zero(value: Option<&Value>) -> bool {
    match value {
        None | Some(Value::Null) => false,
        Some(Value::Bool(flag)) => *flag,
        Some(Value::Number(number)) => number.as_f64().is_some_and(|number| number > 0.0),
        // A numeric string compares as a number; any other string compares
        // with "0" as text.
        Some(Value::String(text)) => match numeric(text) {
            Some(number) => number > 0.0,
            None => text.as_str() > "0",
        },
        Some(Value::Array(_) | Value::Object(_)) => true,
    }
}

/// PHP 8 `is_numeric()` for a string: optional surrounding whitespace and a
/// decimal or exponent number, without hexadecimal.
pub(crate) fn numeric(text: &str) -> Option<f64> {
    let blank = [' ', '\t', '\n', '\r', '\x0B', '\x0C'];
    let trimmed = text.trim_matches(blank);
    let unsigned = trimmed.strip_prefix(['+', '-']).unwrap_or(trimmed);
    let starts_well = unsigned.starts_with(|c: char| c.is_ascii_digit() || c == '.');
    let valid = starts_well
        && unsigned
            .chars()
            .all(|c| c.is_ascii_digit() || matches!(c, '.' | 'e' | 'E' | '+' | '-'))
        && !unsigned.contains(['x', 'X'])
        && !unsigned.eq_ignore_ascii_case("inf")
        && !unsigned.eq_ignore_ascii_case("nan");
    valid.then(|| trimmed.parse().ok()).flatten()
}

/// The name PHP gives a value's type in a TypeError.
pub(crate) fn value_name(value: &Value) -> &'static str {
    match value {
        Value::Null => "null",
        Value::Bool(true) => "true",
        Value::Bool(false) => "false",
        Value::Number(number) if number.is_f64() => "float",
        Value::Number(_) => "int",
        Value::String(_) => "string",
        Value::Array(_) | Value::Object(_) => "array",
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn php_comparisons_with_zero() {
        for (value, expected) in [
            (json!(1), true),
            (json!(0), false),
            (json!(-1), false),
            (json!(0.5), true),
            (json!("5"), true),
            (json!(" 5"), true),
            (json!("0"), false),
            (json!("abc"), true),
            (json!(""), false),
            (json!("-abc"), false),
            (json!(true), true),
            (json!(false), false),
            (json!([]), true),
        ] {
            assert_eq!(greater_than_zero(Some(&value)), expected, "{value}");
        }
        assert!(!greater_than_zero(None));
    }

    #[test]
    fn php_truthiness() {
        for (value, expected) in [
            (json!("0"), false),
            (json!(""), false),
            (json!("0.0"), true),
            (json!(0.0), false),
            (json!([]), false),
            (json!({}), false),
            (json!([0]), true),
            (json!(null), false),
        ] {
            assert_eq!(truthy(Some(&value)), expected, "{value}");
        }
    }

    #[test]
    fn integer_options_follow_the_2x_pattern() {
        assert!(digits("12"));
        assert!(digits("12\n"));
        assert!(!digits("12\n\n"));
        assert!(!digits("-1"));
        assert!(!digits(""));
        assert!(!digits("1.5"));
    }
}
