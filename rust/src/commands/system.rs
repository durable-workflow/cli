//! `system:*` operator commands, `service-call:describe` and
//! `bridge:webhook` through the published SDK transport. The `*-pass`
//! commands and the webhook are one request each; a redirect is refused and a
//! lost response is never submitted again.

use crate::cli::Input;
use crate::client::Context;
use crate::commands::schedule::{
    greater_than_zero, text, text_or, truthy, value_name, with_namespace,
};
use crate::error::CliError;
use crate::{formatter, output, payload, php};
use durable_workflow::RawRequest;
use serde_json::{json, Map, Value};

/// `sprintf('%d', $value ?? 0)`.
fn int(value: Option<&Value>) -> i64 {
    php::int_cast(value)
}

fn yes_no(value: Option<&Value>) -> &'static str {
    if truthy(value) {
        "yes"
    } else {
        "no"
    }
}

/// `$value ?? []` iterated by `foreach`: array members only.
fn members(value: Option<&Value>) -> Vec<&Value> {
    value
        .filter(|value| php::is_array(value))
        .map(php::values)
        .unwrap_or_default()
}

/// The body of a `*-pass` request: PHP sends `[]` when nothing was set.
fn pass_body(fields: Map<String, Value>) -> Value {
    if fields.is_empty() {
        json!([])
    } else {
        Value::Object(fields)
    }
}

fn pass(context: &Context, segments: &[&str], body: Value) -> Result<Value, CliError> {
    context.preflight(false)?;
    let body = payload::request_body(body, &[])?;
    context.resource_request(RawRequest::post(segments.iter().copied()).json(body))
}

fn get(context: &Context, segments: &[&str]) -> Result<Value, CliError> {
    context.preflight(false)?;
    context.resource_request(RawRequest::get(segments.iter().copied()))
}

/// The `<error>` lines for failed entries; true when there were any.
fn error_lines(
    entries: Option<&Value>,
    id_key: &str,
    label: Option<&str>,
    message_key: &str,
) -> bool {
    let mut failed = false;
    for entry in members(entries) {
        if label.is_none() && php::get(entry, "outcome").and_then(Value::as_str) != Some("error") {
            continue;
        }
        let prefix = label.map(|label| format!("{label} ")).unwrap_or_default();
        formatter::writeln_out(&format!(
            "<error>  {prefix}{}: {}</error>",
            text_or(php::get(entry, id_key), "?"),
            text_or(php::get(entry, message_key), "unknown error"),
        ));
        failed = true;
    }
    failed
}

pub fn repair_status(context: &Context, _input: &Input) -> Result<i32, CliError> {
    let result = get(context, &["system", "repair"])?;
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let empty = json!([]);
    let policy = result.get("policy").unwrap_or(&empty);
    let candidates = result.get("candidates").unwrap_or(&empty);
    let out = formatter::writeln_out;
    out("<info>Task Repair Policy</info>");
    out(&format!(
        "  Redispatch after:     {}s",
        int(php::get(policy, "redispatch_after_seconds"))
    ));
    out(&format!(
        "  Loop throttle:        {}s",
        int(php::get(policy, "loop_throttle_seconds"))
    ));
    out(&format!(
        "  Scan limit:           {}",
        int(php::get(policy, "scan_limit"))
    ));
    out(&format!(
        "  Scan strategy:        {}",
        text_or(php::get(policy, "scan_strategy"), "unknown")
    ));
    out(&format!(
        "  Backoff max:          {}s",
        int(php::get(policy, "failure_backoff_max_seconds"))
    ));
    out("");
    let total = php::get(candidates, "total_candidates");
    let tag = if greater_than_zero(total) {
        "comment"
    } else {
        "info"
    };
    out(&format!("<{tag}>Repair Candidates: {}</{tag}>", int(total)));
    out(&format!(
        "  Existing task:        {}",
        int(php::get(candidates, "existing_task_candidates"))
    ));
    out(&format!(
        "  Missing task:         {}",
        int(php::get(candidates, "missing_task_candidates"))
    ));
    out(&format!(
        "  Scan pressure:        {}",
        yes_no(php::get(candidates, "scan_pressure"))
    ));
    for (label, key) in [
        (
            "  Oldest task candidate: ",
            "oldest_task_candidate_created_at",
        ),
        ("  Oldest missing run:    ", "oldest_missing_run_started_at"),
    ] {
        if let Some(value) = php::get(candidates, key).filter(|value| !value.is_null()) {
            out(&format!("{label}{}", text(Some(value))));
        }
    }
    // `$candidates['scopes'] ?? []`, rendered unless it is exactly [].
    let scopes = php::get(candidates, "scopes").filter(|value| !value.is_null());
    if let Some(scopes) =
        scopes.filter(|value| !php::is_array(value) || php::non_empty_array(value))
    {
        if !php::is_array(scopes) {
            return Err(CliError::Failure(format!(
                "array_map(): Argument #2 ($array) must be of type array, {} given",
                value_name(scopes)
            )));
        }
        out("");
        out("<info>Scopes</info>");
        let mut rows = Vec::new();
        for scope in php::values(scopes) {
            if !php::is_array(scope) {
                return Err(CliError::Failure(format!(
                    "DurableWorkflow\\Cli\\Commands\\SystemCommand\\RepairStatusCommand::{{closure}}(): Argument #1 ($scope) must be of type array, {} given",
                    value_name(scope)
                )));
            }
            let cell = |key: &str, default: &str| text_or(php::get(scope, key), default);
            rows.push(vec![
                cell("scope_key", ""),
                cell("existing_task_candidates", "0"),
                cell("missing_task_candidates", "0"),
                cell("total_candidates", "0"),
                cell("selected_total_candidates", "0"),
                yes_no(php::get(scope, "scan_limited_by_global_policy")).to_string(),
            ]);
        }
        out(&output::render_table(
            &[
                "Scope",
                "Existing",
                "Missing",
                "Total",
                "Selected",
                "Scan Limited",
            ],
            &rows,
        ));
    }
    Ok(0)
}

pub fn repair_pass(context: &Context, input: &Input) -> Result<i32, CliError> {
    let mut body = Map::new();
    let run_ids = input.opt_all("run-id");
    if !run_ids.is_empty() {
        body.insert("run_ids".into(), json!(run_ids));
    }
    if let Some(instance_id) = input
        .opt("instance-id")
        .flatten()
        .filter(|id| !id.is_empty())
    {
        body.insert("instance_id".into(), json!(instance_id));
    }
    let result = pass(context, &["system", "repair", "pass"], pass_body(body))?;
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let out = formatter::writeln_out;
    if truthy(result.get("throttled")) {
        out("<comment>Repair pass skipped (watchdog throttle held)</comment>");
        return Ok(0);
    }
    let field = |key: &str| int(result.get(key));
    out("<info>Repair pass completed</info>");
    out(&format!(
        "  Candidates:   {} existing task, {} missing task",
        field("selected_existing_task_candidates"),
        field("selected_missing_task_candidates"),
    ));
    out(&format!(
        "  Repaired:     {} existing, {} missing, {} dispatched",
        field("repaired_existing_tasks"),
        field("repaired_missing_tasks"),
        field("dispatched_tasks"),
    ));
    out(&format!(
        "  Contracts:    {} selected, {} backfilled, {} unavailable",
        field("selected_command_contract_candidates"),
        field("backfilled_command_contracts"),
        field("command_contract_backfill_unavailable"),
    ));
    let mut failed = error_lines(
        result.get("existing_task_failures"),
        "candidate_id",
        Some("Task"),
        "message",
    );
    failed |= error_lines(
        result.get("missing_run_failures"),
        "run_id",
        Some("Run"),
        "message",
    );
    failed |= error_lines(
        result.get("command_contract_failures"),
        "run_id",
        Some("Contract"),
        "message",
    );
    Ok(i32::from(failed))
}

pub fn retention_status(context: &Context, _input: &Input) -> Result<i32, CliError> {
    let result = get(context, &["system", "retention"])?;
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let out = formatter::writeln_out;
    let count = result.get("expired_run_count");
    let tag = if greater_than_zero(count) {
        "comment"
    } else {
        "info"
    };
    out("<info>History Retention</info>");
    out(&format!(
        "  Namespace:      {}",
        text_or(result.get("namespace"), "unknown")
    ));
    out(&format!(
        "  Retention days: {}",
        int(result.get("retention_days"))
    ));
    out(&format!(
        "  Cutoff:         {}",
        text_or(result.get("cutoff"), "unknown")
    ));
    out(&format!("<{tag}>  Expired runs:   {}</{tag}>", int(count)));
    out(&format!(
        "  Scan limit:     {}",
        int(result.get("scan_limit"))
    ));
    out(&format!(
        "  Scan pressure:  {}",
        yes_no(result.get("scan_pressure"))
    ));
    id_list(result.get("expired_run_ids"), "Expired Run IDs");
    Ok(0)
}

/// A non-empty ID list under its heading.
fn id_list(ids: Option<&Value>, heading: &str) {
    let ids = members(ids);
    if ids.is_empty() {
        return;
    }
    formatter::writeln_out("");
    formatter::writeln_out(&format!("<info>{heading}</info>"));
    for id in ids {
        formatter::writeln_out(&format!("  {}", text(Some(id))));
    }
}

pub fn retention_pass(context: &Context, input: &Input) -> Result<i32, CliError> {
    let mut body = Map::new();
    let run_ids = input.opt_all("run-id");
    if !run_ids.is_empty() {
        body.insert("run_ids".into(), json!(run_ids));
    }
    if let Some(limit) = input.opt("limit").flatten() {
        body.insert("limit".into(), json!(php::string_to_int(limit)));
    }
    let result = pass(context, &["system", "retention", "pass"], pass_body(body))?;
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let out = formatter::writeln_out;
    out("<info>Retention enforcement pass completed</info>");
    out(&format!("  Processed:  {}", int(result.get("processed"))));
    out(&format!("  Pruned:     {}", int(result.get("pruned"))));
    out(&format!("  Skipped:    {}", int(result.get("skipped"))));
    out(&format!("  Failed:     {}", int(result.get("failed"))));
    let failed = error_lines(result.get("results"), "run_id", None, "reason");
    Ok(i32::from(failed))
}

pub fn activity_timeout_status(context: &Context, _input: &Input) -> Result<i32, CliError> {
    let result = get(context, &["system", "activity-timeouts"])?;
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let out = formatter::writeln_out;
    let count = result.get("expired_count");
    let tag = if greater_than_zero(count) {
        "comment"
    } else {
        "info"
    };
    out(&format!(
        "<{tag}>Expired Activity Executions: {}</{tag}>",
        int(count)
    ));
    out(&format!(
        "  Scan limit:     {}",
        int(result.get("scan_limit"))
    ));
    out(&format!(
        "  Scan pressure:  {}",
        yes_no(result.get("scan_pressure"))
    ));
    id_list(result.get("expired_execution_ids"), "Expired Execution IDs");
    Ok(0)
}

pub fn activity_timeout_pass(context: &Context, input: &Input) -> Result<i32, CliError> {
    let mut body = Map::new();
    let execution_ids = input.opt_all("execution-id");
    if !execution_ids.is_empty() {
        body.insert("execution_ids".into(), json!(execution_ids));
    }
    if let Some(limit) = input.opt("limit").flatten() {
        body.insert("limit".into(), json!(php::string_to_int(limit)));
    }
    let result = pass(
        context,
        &["system", "activity-timeouts", "pass"],
        pass_body(body),
    )?;
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let out = formatter::writeln_out;
    out("<info>Activity timeout enforcement pass completed</info>");
    out(&format!("  Processed:  {}", int(result.get("processed"))));
    out(&format!("  Enforced:   {}", int(result.get("enforced"))));
    out(&format!("  Skipped:    {}", int(result.get("skipped"))));
    out(&format!("  Failed:     {}", int(result.get("failed"))));
    let failed = error_lines(result.get("results"), "execution_id", None, "reason");
    Ok(i32::from(failed))
}

pub fn operator_metrics(context: &Context, _input: &Input) -> Result<i32, CliError> {
    let result = get(context, &["system", "operator-metrics"])?;
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    crate::commands::operator_metrics::render(&result);
    Ok(0)
}

pub fn service_call_describe(context: &Context, input: &Input) -> Result<i32, CliError> {
    let segments = ["endpoint", "service", "operation", "call-id"]
        .map(|name| input.arg(name).unwrap_or_default());
    let result = with_namespace(
        get(
            context,
            &[
                "service-endpoints",
                segments[0],
                "services",
                segments[1],
                "operations",
                segments[2],
                "service-calls",
                segments[3],
            ],
        )?,
        &context.connection.namespace,
        None,
    );
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    formatter::writeln_out("<info>Nexus Service Call</info>");
    for (label, field) in [
        ("Namespace", "namespace"),
        ("Call ID", "id"),
        ("Status", "status"),
        ("Outcome", "outcome"),
        ("Deadline", "call_deadline_at"),
        ("Caller Namespace", "caller_namespace"),
        ("Caller Workflow", "caller_workflow_instance_id"),
        ("Caller Run", "caller_workflow_run_id"),
        ("Target Namespace", "target_namespace"),
        ("Target Workflow", "linked_workflow_instance_id"),
        ("Target Run", "linked_workflow_run_id"),
        ("Target Update", "linked_workflow_update_id"),
        ("Retry Attempts", "retry_attempt_count"),
        ("Failure Type", "failure_type"),
        ("Failure Message", "message"),
        ("Caller Failure Type", "caller_observed_error_type"),
        ("Result Envelope", "result_envelope"),
        ("Output Reference", "output_payload_reference"),
        ("Failure Reference", "failure_payload_reference"),
        ("Outcome Metadata", "outcome_metadata"),
        ("Attempts", "service_call_attempts"),
    ] {
        let text = match php::raw_get(&result, field) {
            None => "-".to_string(),
            Some(Value::String(value)) => value.clone(),
            Some(value) => json_encode_unescaped_unicode(value),
        };
        formatter::writeln_out(&format!("  {label}: {}", formatter::display_text(&text)));
    }
    Ok(0)
}

/// PHP `json_encode($value, JSON_UNESCAPED_UNICODE)`: slashes escaped,
/// non-ASCII text kept, U+2028 and U+2029 still escaped.
pub(crate) fn json_encode_unescaped_unicode(value: &Value) -> String {
    let encoded = php::json_encode(value);
    let mut out = String::with_capacity(encoded.len());
    let mut chars = encoded.chars().peekable();
    let mut pending_high: Option<u16> = None;
    while let Some(character) = chars.next() {
        if character == '/' {
            out.push_str("\\/");
            continue;
        }
        if character != '\\' {
            out.push(character);
            continue;
        }
        match chars.next() {
            Some('u') => {
                let hex: String = chars.by_ref().take(4).collect();
                let unit = u16::from_str_radix(&hex, 16).unwrap_or(0);
                if let Some(high) = pending_high.take() {
                    let code =
                        0x10000 + ((u32::from(high) - 0xD800) << 10) + (u32::from(unit) - 0xDC00);
                    out.push(char::from_u32(code).unwrap_or('\u{fffd}'));
                } else if (0xD800..0xDC00).contains(&unit) {
                    pending_high = Some(unit);
                } else if unit >= 0x80 && unit != 0x2028 && unit != 0x2029 {
                    out.push(char::from_u32(u32::from(unit)).unwrap_or('\u{fffd}'));
                } else {
                    out.push_str("\\u");
                    out.push_str(&hex);
                }
            }
            Some(other) => {
                out.push('\\');
                out.push(other);
            }
            None => out.push('\\'),
        }
    }
    out
}

pub fn bridge_webhook(context: &Context, input: &Input) -> Result<i32, CliError> {
    let adapter = php::trim(input.arg("adapter").unwrap_or_default()).to_string();
    let action = required_option(input, "action")?;
    let idempotency_key = required_option(input, "idempotency-key")?;
    let target = input.opt("target").flatten();
    if target.is_none_or(|target| php::trim(target).is_empty()) {
        return Err(CliError::Invalid(
            "--target is required and must be a JSON object.".into(),
        ));
    }
    let target = json_object(target, "target")?;
    let correlation = match input.opt("correlation").flatten() {
        Some(value) if !php::trim(value).is_empty() => {
            Some(json_object(Some(value), "correlation")?)
        }
        _ => None,
    };
    if adapter.is_empty() {
        return Err(CliError::Invalid("adapter is required.".into()));
    }
    let mut body = Map::new();
    body.insert("action".into(), json!(action));
    body.insert("idempotency_key".into(), json!(idempotency_key));
    body.insert("target".into(), target.value.clone());
    let input_payload = payload::parse(input)?;
    if let Some(input_payload) = &input_payload {
        body.insert("input".into(), input_payload.value.clone());
    }
    if let Some(correlation) = &correlation {
        body.insert("correlation".into(), correlation.value.clone());
    }
    context.preflight(false)?;
    let body = payload::request_body(
        Value::Object(body),
        &[Some(&target), input_payload.as_ref(), correlation.as_ref()],
    )?;
    let result = context.resource_request(
        RawRequest::post(["bridge-adapters", "webhook", adapter.as_str()]).json(body),
    )?;
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let out = formatter::writeln_out;
    let accepted = if result.get("accepted") == Some(&Value::Bool(true)) {
        "yes"
    } else {
        "no"
    };
    out("<info>Bridge adapter outcome</info>");
    out(&format!(
        "  Adapter: {}",
        text_or(result.get("adapter"), "-")
    ));
    out(&format!("  Action: {}", text_or(result.get("action"), "-")));
    out(&format!("  Accepted: {accepted}"));
    out(&format!(
        "  Outcome: {}",
        text_or(result.get("outcome"), "-")
    ));
    for (field, label) in [
        ("reason", "Reason"),
        ("control_plane_outcome", "Control Plane Outcome"),
        ("idempotency_key", "Idempotency Key"),
        ("workflow_id", "Workflow ID"),
        ("run_id", "Run ID"),
        ("command_id", "Command ID"),
    ] {
        let value = result.get(field).filter(|value| !value.is_null());
        if php::is_scalar(value) && !php::to_string(value).is_empty() {
            out(&format!("  {label}: {}", text(value)));
        }
    }
    if let Some(target) = result.get("target").filter(|value| php::is_array(value)) {
        out(&format!(
            "  Target: {}",
            formatter::display_text(&php::json_encode(target))
        ));
    }
    Ok(0)
}

fn required_option(input: &Input, name: &str) -> Result<String, CliError> {
    match input.opt(name).flatten() {
        Some(value) if !php::trim(value).is_empty() => Ok(php::trim(value).to_string()),
        _ => Err(CliError::Invalid(format!("--{name} is required."))),
    }
}

/// 2.x `requiredJsonObject()` / `optionalJsonObject()` after the presence
/// check: a JSON document that decodes to a non-list array.
fn json_object(value: Option<&str>, option: &str) -> Result<payload::Payload, CliError> {
    match payload::parse_json_option(value, option)? {
        Some(decoded) if php::is_array(&decoded.value) && !php::is_list(&decoded.value) => {
            Ok(decoded)
        }
        _ => Err(CliError::Invalid(format!(
            "--{option} must be a JSON object."
        ))),
    }
}
