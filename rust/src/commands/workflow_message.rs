//! Commands that send a workflow a message: `workflow:query`,
//! `workflow:start`, `workflow:signal` and `workflow:update`.
//!
//! Each command makes exactly one request and never resends it. When the
//! response is lost the outcome is unknown: signals and updates report the
//! command key they sent so the operator can inspect before retrying.

use crate::cli::Input;
use crate::client::Context;
use crate::commands::workflow::with_namespace;
use crate::commands::workflow_run::{is_terminal, status};
use crate::compat::RequestContract;
use crate::error::{human_output_text, CliError};
use crate::payload::{self, Payload};
use crate::{formatter, output, php};
use serde_json::{json, Map, Value};
use std::sync::Mutex;
use std::time::{Duration, Instant};

/// The longest caller command key, in Unicode code points.
const REQUEST_ID_MAX_CHARACTERS: usize = 255;

/// The key this invocation chose (2.x `$commandRequestId`), for its errors.
static REQUEST_ID: Mutex<Option<String>> = Mutex::new(None);

/// The command key this invocation sent or was about to send, once chosen.
pub fn command_request_id() -> Option<String> {
    REQUEST_ID.lock().ok().and_then(|id| id.clone())
}

/// 2.x `commandRequestId()`: the caller's `--request-id`, validated, or one
/// key generated for this invocation (`cli-<kind>-<32 hex digits>`).
fn choose_request_id(input: &Input, kind: &str) -> Result<String, CliError> {
    let id = if input.provided("request-id") {
        let Some(explicit) = input.opt("request-id").flatten() else {
            return Err(CliError::Invalid(
                "--request-id must not be empty or whitespace-only.".into(),
            ));
        };
        if is_blank(explicit) {
            return Err(CliError::Invalid(
                "--request-id must not be empty or whitespace-only.".into(),
            ));
        }
        let characters = explicit.chars().count();
        if characters > REQUEST_ID_MAX_CHARACTERS {
            return Err(CliError::Invalid(format!(
                "--request-id must be 1 to {REQUEST_ID_MAX_CHARACTERS} Unicode characters; got {characters} characters."
            )));
        }
        explicit.to_string()
    } else {
        let mut bytes = [0u8; 16];
        getrandom::getrandom(&mut bytes).map_err(|error| {
            CliError::Failure(format!("Unable to generate a request ID: {error}"))
        })?;
        let hex: String = bytes.iter().map(|byte| format!("{byte:02x}")).collect();
        format!("cli-{kind}-{hex}")
    };
    if let Ok(mut current) = REQUEST_ID.lock() {
        *current = Some(id.clone());
    }
    Ok(id)
}

/// 2.x `isBlankCommandRequestId()`: PHP `trim()` leaves nothing, or every
/// character is ASCII whitespace or a Unicode separator.
fn is_blank(value: &str) -> bool {
    php::trim(value).is_empty()
        || value.chars().all(|character| {
            matches!(character, ' ' | '\t' | '\n' | '\u{0b}' | '\u{0c}' | '\r')
                || matches!(
                    character,
                    '\u{a0}' | '\u{1680}' | '\u{2000}'
                        ..='\u{200a}'
                            | '\u{2028}'
                            | '\u{2029}'
                            | '\u{202f}'
                            | '\u{205f}'
                            | '\u{3000}'
                )
        })
}

/// 2.x `errorRequestIdContext()`: the chosen key, else the caller's
/// non-blank `--request-id`.
pub fn error_request_id(input: &Input) -> Option<String> {
    command_request_id().or_else(|| {
        input
            .opt("request-id")
            .flatten()
            .filter(|value| !is_blank(value))
            .map(str::to_owned)
    })
}

/// A non-empty trimmed scalar, as 2.x `hasNonEmptyString()` tests it.
fn nonempty_scalar(value: Option<&Value>) -> bool {
    php::is_scalar(value) && !php::trim(&php::to_string(value)).is_empty()
}

/// 2.x `writeNamespaceLine()`.
fn namespace_line(result: &Value) {
    let namespace = result
        .get("namespace")
        .filter(|value| nonempty_scalar(Some(value)))
        .map(|value| php::to_string(Some(value)))
        .unwrap_or_else(|| "-".into());
    formatter::writeln_out(&format!(
        "  Namespace: {}",
        formatter::display_text(&namespace)
    ));
}

/// `$result[$key]` concatenated into a line, as data.
fn field(result: &Value, key: &str) -> String {
    formatter::display_text(&php::to_string(result.get(key)))
}

/// `if (isset($result[$key])) writeln("  Label: ".$result[$key])`.
fn optional_line(result: &Value, label: &str, key: &str) {
    if result.get(key).is_some_and(|value| !value.is_null()) {
        formatter::writeln_out(&format!("  {label}: {}", field(result, key)));
    }
}

/// 2.x `writeCommandReceiptLines()`.
fn receipt_lines(result: &Value) {
    if nonempty_scalar(result.get("request_id")) {
        formatter::writeln_out(&format!(
            "  Request ID: {}",
            human_output_text(&php::to_string(result.get("request_id")))
        ));
    }
    if let Some(Value::Bool(retained)) = result.get("retained") {
        formatter::writeln_out(&format!(
            "  Retained: {}",
            if *retained {
                "yes (original receipt returned for this request id)"
            } else {
                "no"
            }
        ));
    }
    if let Some(Value::Bool(verified)) = result.get("intent_verified") {
        formatter::writeln_out(&format!(
            "  Intent Verified: {}",
            if *verified { "yes" } else { "no" }
        ));
    }
}

/// 2.x `optionalString()`: a trimmed non-empty string option.
fn optional_string<'a>(input: &'a Input, name: &str) -> Option<&'a str> {
    input
        .opt(name)
        .flatten()
        .map(php::trim)
        .filter(|value| !value.is_empty())
}

/// The path a workflow message targets, as segments and as 2.x names it.
fn message_path<'a>(
    workflow_id: &'a str,
    run_id: Option<&'a str>,
    kind: &'a str,
    name: &'a str,
) -> (Vec<&'a str>, String) {
    match run_id {
        Some(run_id) => (
            vec!["workflows", workflow_id, "runs", run_id, kind, name],
            format!("/workflows/{workflow_id}/runs/{run_id}/{kind}/{name}"),
        ),
        None => (
            vec!["workflows", workflow_id, kind, name],
            format!("/workflows/{workflow_id}/{kind}/{name}"),
        ),
    }
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
    let arguments = payload::parse_arguments(input)?;
    let mut body = Map::new();
    if let Some(arguments) = &arguments {
        body.insert("input".into(), arguments.value.clone());
    }
    let info = context.preflight(false)?;
    let (segments, path) = message_path(
        workflow_id,
        input.opt("run-id").flatten(),
        "query",
        query_name,
    );
    let body = payload::request_body(Value::Object(body), &[arguments.as_ref()])?;
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
    namespace_line(&result);
    optional_line(&result, "Query", "query_name");
    // Pretty JSON keeps its line breaks; only console markup is escaped.
    formatter::writeln_out(&formatter::escape(&php::json_encode_pretty(
        result.get("result").unwrap_or(&Value::Null),
    )));
    Ok(0)
}

/// 2.x `queryHttpTimeoutSeconds()`: at least 35 s, or the Server's query
/// task timeout plus 10 s, at most 300 s.
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

pub fn signal(context: &Context, input: &Input) -> Result<i32, CliError> {
    let workflow_id = input.arg("workflow-id").unwrap_or_default();
    let signal_name = input.arg("signal-name").unwrap_or_default();
    let request_id = choose_request_id(input, "signal")?;
    let mut body = Map::new();
    body.insert("request_id".into(), json!(request_id));
    let arguments = payload::parse_arguments(input)?;
    if let Some(arguments) = &arguments {
        body.insert("input".into(), arguments.value.clone());
    }
    let (segments, path) = message_path(
        workflow_id,
        input.opt("run-id").flatten(),
        "signal",
        signal_name,
    );
    context.preflight(false)?;
    let body = payload::request_body(Value::Object(body), &[arguments.as_ref()])?;
    let mut result = php::array_map(with_namespace(
        context.workflow_post(&segments, body, &path, None)?,
        &context.connection.namespace,
        None,
    ));
    // Servers that predate keyed signals do not echo the key; the operator
    // still needs it to retry after a lost response.
    if !matches!(result.get("request_id"), Some(Value::String(id)) if !id.is_empty()) {
        result.insert("request_id".into(), json!(request_id));
    }
    let result = Value::Object(result);
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    formatter::writeln_out("<info>Signal sent</info>");
    formatter::writeln_out(&format!("  Workflow ID: {}", field(&result, "workflow_id")));
    namespace_line(&result);
    formatter::writeln_out(&format!("  Signal: {}", field(&result, "signal_name")));
    formatter::writeln_out(&format!("  Outcome: {}", field(&result, "outcome")));
    optional_line(&result, "Command Status", "command_status");
    optional_line(&result, "Command ID", "command_id");
    receipt_lines(&result);
    Ok(0)
}

pub fn update(context: &Context, input: &Input) -> Result<i32, CliError> {
    let workflow_id = input.arg("workflow-id").unwrap_or_default();
    let update_name = input.arg("update-name").unwrap_or_default();
    let run_id = input.opt("run-id").flatten();
    let info = context.preflight(false)?;
    let wait_for = optional_string(input, "wait").unwrap_or("accepted");
    RequestContract::from_cluster_info(&info)
        .map_err(CliError::Invalid)?
        .assert_canonical_value("update", "wait_for", Some(wait_for), Some("--wait"))
        .map_err(CliError::Invalid)?;
    let mut request = Map::new();
    request.insert("wait_for".into(), json!(wait_for));
    request.insert(
        "request_id".into(),
        json!(choose_request_id(input, "update")?),
    );
    let arguments = payload::parse_arguments(input)?;
    if let Some(arguments) = &arguments {
        request.insert("input".into(), arguments.value.clone());
    }
    let (segments, path) = message_path(workflow_id, run_id, "update", update_name);
    let body = payload::request_body(Value::Object(request.clone()), &[arguments.as_ref()])?;
    let result = with_namespace(
        context.workflow_post(&segments, body, &path, None)?,
        &context.connection.namespace,
        None,
    );
    let result = Value::Object(with_update_diagnostics(
        php::array_map(result),
        workflow_id,
        update_name,
        run_id.filter(|run_id| !run_id.is_empty()),
        &php_shaped(request),
        None,
    ));
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    formatter::writeln_out("<info>Update sent</info>");
    formatter::writeln_out(&format!("  Workflow ID: {}", field(&result, "workflow_id")));
    namespace_line(&result);
    formatter::writeln_out(&format!("  Update: {}", field(&result, "update_name")));
    formatter::writeln_out(&format!("  Update ID: {}", field(&result, "update_id")));
    formatter::writeln_out(&format!("  Outcome: {}", field(&result, "outcome")));
    optional_line(&result, "Command Status", "command_status");
    optional_line(&result, "Update Status", "update_status");
    optional_line(&result, "Wait For", "wait_for");
    receipt_lines(&result);
    Ok(0)
}

/// The request as PHP holds it: an empty JSON object is an empty array.
fn php_shaped(request: Map<String, Value>) -> Map<String, Value> {
    php::array_map(crate::php_json::php_array_shape(Value::Object(request)))
}

/// What a failed `workflow:update` was sending, read from the command line
/// as 2.x `withWorkflowUpdateErrorDiagnostics()` reads it.
#[derive(Debug)]
pub struct UpdateErrorRequest {
    workflow_id: Option<String>,
    update_name: Option<String>,
    run_id: Option<String>,
    wait_for: Option<String>,
    request_id: Option<String>,
    input: Option<Value>,
}

impl UpdateErrorRequest {
    pub fn from_input(input: &Input) -> Self {
        let argument = |name: &str| {
            input
                .arg(name)
                .map(php::trim)
                .filter(|value| !value.is_empty())
                .map(str::to_owned)
        };
        let option = |name: &str| optional_string(input, name).map(str::to_owned);
        // 2.x does not reread --input-file here, so stdin stays untouched.
        let input_value = if option("input-file").is_some() {
            None
        } else {
            payload::parse_arguments(input)
                .ok()
                .flatten()
                .map(|payload: Payload| crate::php_json::php_array_shape(payload.value))
        };
        UpdateErrorRequest {
            workflow_id: argument("workflow-id"),
            update_name: argument("update-name"),
            run_id: option("run-id"),
            wait_for: option("wait"),
            request_id: error_request_id(input),
            input: input_value,
        }
    }
}

fn scalar_string(value: Option<&Value>) -> Option<String> {
    value
        .filter(|value| php::is_scalar(Some(value)))
        .map(|value| php::to_string(Some(value)))
}

/// 2.x `withWorkflowUpdateErrorDiagnostics()`: the error envelope with the
/// update request and its diagnostics.
pub fn update_error_diagnostics(
    envelope: Map<String, Value>,
    error: &CliError,
    request: &UpdateErrorRequest,
) -> Map<String, Value> {
    let workflow_id = request
        .workflow_id
        .clone()
        .or_else(|| scalar_string(envelope.get("workflow_id")))
        .unwrap_or_default();
    let update_name = request
        .update_name
        .clone()
        .or_else(|| scalar_string(envelope.get("update_name")))
        .unwrap_or_default();
    if workflow_id.is_empty() || update_name.is_empty() {
        return envelope;
    }
    let run_id = request
        .run_id
        .clone()
        .or_else(|| scalar_string(envelope.get("run_id")));
    let mut sent = Map::new();
    sent.insert(
        "wait_for".into(),
        json!(request
            .wait_for
            .clone()
            .or_else(|| scalar_string(envelope.get("wait_for")))
            .unwrap_or_else(|| "accepted".into())),
    );
    if let Some(request_id) = request
        .request_id
        .clone()
        .or_else(|| scalar_string(envelope.get("request_id")))
    {
        sent.insert("request_id".into(), json!(request_id));
    }
    if let Some(input) = &request.input {
        sent.insert("input".into(), input.clone());
    }
    let mut details = Map::new();
    match error {
        CliError::Http { status, body, .. } => {
            details.insert("status_code".into(), json!(status));
            details.insert(
                "message".into(),
                body.as_ref()
                    .and_then(|body| body.get("message"))
                    .filter(|message| !message.is_null())
                    .cloned()
                    .unwrap_or_else(|| json!(error.message())),
            );
            details.insert(
                "reason".into(),
                body.as_ref()
                    .and_then(|body| body.get("reason"))
                    .and_then(Value::as_str)
                    .filter(|reason| !reason.is_empty())
                    .map(|reason| json!(reason))
                    .unwrap_or(Value::Null),
            );
        }
        other => {
            details.insert("message".into(), json!(other.message()));
        }
    }
    with_update_diagnostics(
        envelope,
        &workflow_id,
        &update_name,
        run_id.as_deref(),
        &sent,
        Some(&details),
    )
}

/// PHP `$value ?? null` for a key: missing and null are both absent.
fn present<'a>(map: &'a Map<String, Value>, key: &str) -> Option<&'a Value> {
    map.get(key).filter(|value| !value.is_null())
}

/// PHP `$payload[$key] ??= $value`.
fn default_to(map: &mut Map<String, Value>, key: &str, value: Value) {
    if present(map, key).is_none() {
        map.insert(key.into(), value);
    }
}

/// 2.x `withWorkflowUpdateDiagnostics()`.
fn with_update_diagnostics(
    mut payload: Map<String, Value>,
    workflow_id: &str,
    update_name: &str,
    run_id: Option<&str>,
    request: &Map<String, Value>,
    error: Option<&Map<String, Value>>,
) -> Map<String, Value> {
    default_to(&mut payload, "workflow_id", json!(workflow_id));
    default_to(&mut payload, "update_name", json!(update_name));
    if let Some(run_id) = run_id {
        default_to(&mut payload, "run_id", json!(run_id));
    }
    for key in ["request_id", "wait_for"] {
        if !payload.contains_key(key) {
            if let Some(value) = present(request, key) {
                payload.insert(key.into(), value.clone());
            }
        }
    }

    let mut envelope = Map::new();
    envelope.insert("workflow_id".into(), json!(workflow_id));
    if let Some(run_id) = run_id {
        envelope.insert("run_id".into(), json!(run_id));
    }
    envelope.insert("update_name".into(), json!(update_name));
    for key in ["request_id", "wait_for"] {
        if let Some(value) = present(request, key).or_else(|| present(&payload, key)) {
            envelope.insert(key.into(), value.clone());
        }
    }
    if let Some(input) = present(request, "input") {
        envelope.insert("input".into(), input.clone());
    }
    // array_replace(): the CLI's request, then any Server request fields.
    if let Some(existing) = payload.get("request").filter(|value| php::is_array(value)) {
        for (key, value) in php::array_map(existing.clone()) {
            envelope.insert(key, value);
        }
    }
    payload.insert("request".into(), Value::Object(envelope));

    let state = diagnostic_state(&payload, error);
    if let Some(state) = &state {
        if state == "refused" {
            payload.insert("update_state".into(), json!(state));
            payload.insert("state".into(), json!(state));
        } else {
            default_to(&mut payload, "update_state", json!(state));
            default_to(&mut payload, "state", json!(state));
        }
    }
    if !nonempty_scalar(payload.get("outcome")) {
        let outcome = match state.as_deref() {
            Some("accepted") => Some("update_accepted"),
            Some("completed") => Some("update_completed"),
            Some("failed") => Some("update_failed"),
            Some("refused") => Some("update_refused"),
            _ => error.map(|_| "update_refused"),
        };
        if let Some(outcome) = outcome {
            payload.insert("outcome".into(), json!(outcome));
        }
    }
    if let Some(error) = error {
        if !payload.contains_key("outcome") {
            payload.insert("outcome".into(), json!("update_refused"));
        }
        let mut details = Map::new();
        for (key, value) in [
            ("status_code", present(error, "status_code")),
            (
                "message",
                present(&payload, "message").or_else(|| present(error, "message")),
            ),
            (
                "reason",
                present(&payload, "reason").or_else(|| present(error, "reason")),
            ),
            ("outcome", present(&payload, "outcome")),
            ("validation_errors", present(&payload, "validation_errors")),
            ("failure_id", present(&payload, "failure_id")),
            ("failure_message", present(&payload, "failure_message")),
        ] {
            if let Some(value) = value {
                details.insert(key.into(), value.clone());
            }
        }
        default_to(&mut payload, "error_details", Value::Object(details));
    }
    let references: Map<String, Value> = [
        "workflow_sequence",
        "command_sequence",
        "accepted_at",
        "applied_at",
        "rejected_at",
        "closed_at",
    ]
    .into_iter()
    .filter_map(|key| {
        payload
            .get(key)
            .map(|value| (key.to_string(), value.clone()))
    })
    .collect();
    if !references.is_empty() && !payload.contains_key("history_references") {
        payload.insert("history_references".into(), Value::Object(references));
    }
    for key in [
        "update_id",
        "request_id",
        "reason",
        "rejection_reason",
        "rejection_category",
        "result",
        "result_envelope",
        "error_details",
        "history_references",
    ] {
        default_to(&mut payload, key, Value::Null);
    }
    if !payload.contains_key("payload") {
        if let Some(input) = payload
            .get("request")
            .and_then(|request| request.get("input"))
            .cloned()
        {
            payload.insert("payload".into(), input);
        }
    }
    if present(&payload, "update_diagnostics").is_none() {
        let diagnostics = diagnostic_envelope(&payload);
        payload.insert("update_diagnostics".into(), Value::Object(diagnostics));
    }
    if present(&payload, "cli_fields").is_none() {
        let fields = cli_fields(&payload);
        payload.insert("cli_fields".into(), fields);
    }
    payload
}

/// 2.x `workflowUpdateDiagnosticState()`.
fn diagnostic_state(
    payload: &Map<String, Value>,
    error: Option<&Map<String, Value>>,
) -> Option<String> {
    let normalized = |value: Option<&Value>| {
        nonempty_scalar(value).then(|| php::trim(&php::to_string(value)).to_ascii_lowercase())
    };
    let command_status = normalized(payload.get("command_status")).map(|status| {
        if matches!(status.as_str(), "rejected" | "refused") {
            "refused".to_string()
        } else {
            status
        }
    });
    if command_status.as_deref() == Some("refused") {
        return command_status;
    }
    let outcome = normalized(payload.get("outcome")).and_then(|outcome| {
        if outcome.starts_with("rejected") || outcome.contains("refused") {
            Some("refused")
        } else if outcome.contains("failed") {
            Some("failed")
        } else if outcome.contains("completed") {
            Some("completed")
        } else if outcome.contains("accepted") {
            Some("accepted")
        } else {
            None
        }
    });
    if outcome == Some("refused") {
        return Some("refused".into());
    }
    for key in ["update_status", "update_state", "state"] {
        if let Some(state) = normalized(payload.get(key)) {
            return Some(state);
        }
    }
    command_status
        .or_else(|| outcome.map(str::to_owned))
        .or_else(|| error.map(|_| "refused".to_string()))
}

/// 2.x `workflowUpdateDiagnosticEnvelope()`.
fn diagnostic_envelope(payload: &Map<String, Value>) -> Map<String, Value> {
    let mut diagnostics = Map::new();
    for key in [
        "state",
        "update_state",
        "namespace",
        "workflow_id",
        "run_id",
        "requested_run_id",
        "resolved_run_id",
        "update_name",
        "update_id",
        "request_id",
        "retained",
        "intent_verified",
        "outcome",
        "reason",
        "rejection_reason",
        "rejection_category",
        "command_status",
        "command_source",
        "command_id",
        "command_sequence",
        "update_status",
        "workflow_sequence",
        "wait_for",
        "wait_timed_out",
        "wait_timeout_seconds",
        "failure_id",
        "failure_message",
        "principal",
        "request",
        "payload",
        "history_references",
        "result_envelope",
    ] {
        if let Some(value) = payload.get(key) {
            diagnostics.insert(key.into(), value.clone());
        }
    }
    if let Some(result) = payload.get("result") {
        diagnostics.insert("result".into(), result.clone());
    }
    if let Some(input) = payload
        .get("request")
        .filter(|request| php::is_array(request))
        .and_then(|request| php::get(request, "input"))
    {
        diagnostics.insert("payload".into(), input.clone());
    }
    if let Some(details) = payload.get("error_details") {
        diagnostics.insert("error".into(), details.clone());
    }
    diagnostics
}

/// 2.x `workflowUpdateCliFields()`.
fn cli_fields(payload: &Map<String, Value>) -> Value {
    let present_fields: Vec<String> = [
        "workflow_id",
        "run_id",
        "requested_run_id",
        "resolved_run_id",
        "update_name",
        "update_id",
        "request_id",
        "retained",
        "intent_verified",
        "state",
        "update_state",
        "outcome",
        "reason",
        "rejection_reason",
        "rejection_category",
        "command_status",
        "command_id",
        "workflow_command_id",
        "command_sequence",
        "update_status",
        "workflow_sequence",
        "wait_for",
        "wait_timed_out",
        "wait_timeout_seconds",
        "payload",
        "result",
        "result_envelope",
        "error_details",
        "failure_id",
        "failure_message",
        "principal",
        "history_references",
        "update_diagnostics",
    ]
    .into_iter()
    .filter(|key| payload.contains_key(*key))
    .map(|key| format!("workflow:update.{key}"))
    .collect();
    let either = |first: &str, second: &str| {
        present(payload, first)
            .or_else(|| present(payload, second))
            .cloned()
            .unwrap_or(Value::Null)
    };
    let one = |key: &str| present(payload, key).cloned().unwrap_or(Value::Null);
    json!({
        "surface": "workflow:update --json",
        "fields_present": present_fields,
        "state": either("state", "update_state"),
        "request_id": one("request_id"),
        "update_id": one("update_id"),
        "outcome": one("outcome"),
        "reason": either("reason", "rejection_reason"),
        "payload": one("payload"),
        "result": one("result"),
        "error": one("error_details"),
        "history_references": one("history_references"),
    })
}

/// How often `--wait` polls, and how often JSON callers get a diagnostic.
const WAIT_POLL_INTERVAL: Duration = Duration::from_secs(2);
const WAIT_DIAGNOSTIC_INTERVAL: Duration = Duration::from_secs(30);

pub fn start(context: &Context, input: &Input) -> Result<i32, CliError> {
    let info = context.preflight(false)?;
    let duplicate_policy = optional_string(input, "duplicate-policy");
    if let Some(policy) = duplicate_policy {
        RequestContract::from_cluster_info(&info)
            .map_err(CliError::Invalid)?
            .assert_canonical_value(
                "start",
                "duplicate_policy",
                Some(policy),
                Some("--duplicate-policy"),
            )
            .map_err(CliError::Invalid)?;
    }
    let int_option = |name: &str| {
        input
            .opt(name)
            .flatten()
            .map(|value| json!(php::string_to_int(value)))
    };
    let text_option = |name: &str| input.opt(name).flatten().map(|value| json!(value));
    let arguments = payload::parse_arguments(input)?;
    let memo = payload::parse_json_option(input.opt("memo").flatten(), "memo")?;
    let mut body = Map::new();
    for (key, value) in [
        ("workflow_type", text_option("type")),
        ("workflow_id", text_option("workflow-id")),
        ("business_key", text_option("business-key")),
        ("task_queue", text_option("task-queue")),
        (
            "duplicate_policy",
            duplicate_policy.map(|policy| json!(policy)),
        ),
        (
            "input",
            arguments.as_ref().map(|arguments| arguments.value.clone()),
        ),
        ("memo", memo.as_ref().map(|memo| memo.value.clone())),
        ("execution_timeout_seconds", int_option("execution-timeout")),
        ("run_timeout_seconds", int_option("run-timeout")),
        ("priority", int_option("priority")),
        (
            "fairness_key",
            optional_string(input, "fairness-key").map(|key| json!(key)),
        ),
        ("fairness_weight", int_option("fairness-weight")),
    ] {
        if let Some(value) = value {
            body.insert(key.into(), value);
        }
    }
    let attributes = input.opt_all("search-attr");
    if !attributes.is_empty() {
        let mut map = Map::new();
        for attribute in attributes {
            let (key, value) = match attribute.split_once('=') {
                Some((key, value)) => (key, json!(value)),
                None => (attribute, Value::Null),
            };
            map.insert(key.into(), value);
        }
        body.insert("search_attributes".into(), Value::Object(map));
    }
    let body = payload::request_body(Value::Object(body), &[arguments.as_ref(), memo.as_ref()])?;
    let result = with_namespace(
        context.workflow_post(&["workflows"], body, "/workflows", None)?,
        &context.connection.namespace,
        None,
    );
    let json_mode = context.mode.machine_readable();
    if input.flag("wait") {
        return wait_for_terminal(context, &result, json_mode);
    }
    if json_mode {
        output::print_json(&result);
        return Ok(0);
    }
    start_banner(&result);
    Ok(0)
}

fn start_banner(result: &Value) {
    formatter::writeln_out("<info>Workflow started</info>");
    formatter::writeln_out(&format!("  Workflow ID: {}", field(result, "workflow_id")));
    namespace_line(result);
    formatter::writeln_out(&format!("  Run ID: {}", field(result, "run_id")));
    optional_line(result, "Business Key", "business_key");
    optional_line(result, "Payload Codec", "payload_codec");
    formatter::writeln_out(&format!("  Outcome: {}", status(result.get("outcome"), "")));
}

/// 2.x `waitAndEmit()`: poll the execution until it is terminal. Exit 0
/// only when it completed.
fn wait_for_terminal(context: &Context, started: &Value, json_mode: bool) -> Result<i32, CliError> {
    if !json_mode {
        start_banner(started);
        formatter::writeln_out("");
        formatter::writeln_out("<comment>Waiting for workflow to complete...</comment>");
    }
    let namespace = php::to_string(started.get("namespace"));
    let workflow_id = php::to_string(started.get("workflow_id"));
    let path = format!("/workflows/{workflow_id}");
    let mut last_diagnostic: Option<Instant> = None;
    let describe = loop {
        let describe = context.workflow_get(&["workflows", &workflow_id], &[], &path)?;
        if is_terminal(&describe) {
            break describe;
        }
        if json_mode {
            if last_diagnostic.is_none_or(|at| at.elapsed() >= WAIT_DIAGNOSTIC_INTERVAL) {
                wait_diagnostic(context, &workflow_id, &describe);
                last_diagnostic = Some(Instant::now());
            }
        } else {
            formatter::write_out(".");
        }
        std::thread::sleep(WAIT_POLL_INTERVAL);
    };
    let describe = with_namespace(describe, &namespace, None);
    let exit = if describe.get("status_bucket").and_then(Value::as_str) == Some("completed") {
        0
    } else {
        1
    };
    if json_mode {
        if formatter::verbosity() == formatter::Verbosity::Normal {
            formatter::emit(
                formatter::Stream::Stdout,
                &(php::json_encode_pretty(&describe) + "\n"),
            );
        }
        return Ok(exit);
    }
    formatter::writeln_out("");
    formatter::writeln_out("<info>Workflow reached terminal state</info>");
    namespace_line(&describe);
    formatter::writeln_out(&format!("  Status: {}", status(describe.get("status"), "")));
    formatter::writeln_out(&format!(
        "  Closed Reason: {}",
        formatter::display_text(&php::or(describe.get("closed_reason"), "-"))
    ));
    formatter::writeln_out(&format!(
        "  Closed At: {}",
        formatter::display_text(&php::or(describe.get("closed_at"), "-"))
    ));
    if let Some(value) = describe.get("output").filter(|value| !value.is_null()) {
        formatter::writeln_out(&format!(
            "  Output: {}",
            formatter::display_text(&php::json_encode(value))
        ));
    }
    Ok(exit)
}

/// 2.x `emitWaitDiagnostic()`: one JSON line on stderr describing why the
/// workflow is still waiting.
fn wait_diagnostic(context: &Context, workflow_id: &str, describe: &Value) {
    let path = format!("/workflows/{workflow_id}/debug");
    let (debug, debug_error) =
        match context.workflow_get(&["workflows", workflow_id, "debug"], &[], &path) {
            Ok(debug) => (Some(debug), None),
            Err(error) => (None, Some(error.message().to_string())),
        };
    let diagnostic = build_wait_diagnostic(workflow_id, describe, debug.as_ref(), debug_error);
    if formatter::verbosity() == formatter::Verbosity::Normal {
        formatter::emit(
            formatter::Stream::Stderr,
            &(php::json_encode(&diagnostic) + "\n"),
        );
    }
}

/// 2.x `compactDiagnostic()`: drop nulls and empty arrays.
fn compact(values: Vec<(&str, Option<Value>)>) -> Value {
    Value::Object(
        values
            .into_iter()
            .filter_map(|(key, value)| {
                let value = value?;
                let empty = match &value {
                    Value::Null => true,
                    Value::Array(items) => items.is_empty(),
                    Value::Object(map) => map.is_empty(),
                    _ => false,
                };
                (!empty).then(|| (key.to_string(), value))
            })
            .collect(),
    )
}

/// `$array[$key] ?? null` on any value.
fn lookup(value: Option<&Value>, key: &str) -> Option<Value> {
    value
        .and_then(|value| php::get(value, key))
        .filter(|value| !value.is_null())
        .cloned()
}

/// 2.x `mapDiagnosticList()`: array items only, at most `limit`.
fn diagnostic_list(items: Option<&Value>, limit: usize, mapper: impl Fn(&Value) -> Value) -> Value {
    Value::Array(
        items
            .filter(|items| php::is_array(items))
            .map(|items| {
                php::values(items)
                    .into_iter()
                    .filter(|item| php::is_array(item))
                    .take(limit)
                    .map(mapper)
                    .collect()
            })
            .unwrap_or_default(),
    )
}

fn pick(item: &Value, keys: &[&'static str]) -> Value {
    compact(
        keys.iter()
            .map(|key| (*key, lookup(Some(item), key)))
            .collect(),
    )
}

/// 2.x `waitDiagnostic()`.
fn build_wait_diagnostic(
    workflow_id: &str,
    describe: &Value,
    debug: Option<&Value>,
    debug_error: Option<String>,
) -> Value {
    let execution = debug
        .and_then(|debug| php::get(debug, "execution"))
        .filter(|execution| php::is_array(execution));
    let first = |sources: &[(Option<&Value>, &str)]| {
        sources
            .iter()
            .find_map(|(source, key)| lookup(*source, key))
    };
    let findings = diagnostic_list(lookup(debug, "findings").as_ref(), 5, |finding| {
        pick(
            finding,
            &[
                "severity",
                "code",
                "message",
                "task_id",
                "task_queue",
                "workflow_type",
                "workflow_type_match",
                "activity_type",
                "activity_execution_id",
                "active_worker_count",
                "active_worker_limit",
                "active_workers_truncated",
                "active_workers",
            ],
        )
    });
    let tasks = diagnostic_list(
        lookup(debug, "pending_workflow_tasks").as_ref(),
        5,
        |task| {
            pick(
                task,
                &[
                    "task_id",
                    "task_type",
                    "status",
                    "transport_state",
                    "summary",
                    "queue",
                    "lease_owner",
                    "lease_expired",
                    "compatibility_supported",
                    "compatibility_reason",
                ],
            )
        },
    );
    let activities = diagnostic_list(
        lookup(debug, "pending_activities").as_ref(),
        5,
        |activity| {
            let attempt = php::get(activity, "current_attempt")
                .filter(|attempt| php::is_array(attempt))
                .map(|attempt| {
                    pick(
                        attempt,
                        &[
                            "activity_attempt_id",
                            "attempt_number",
                            "status",
                            "lease_owner",
                            "lease_expires_at",
                            "lease_expired",
                        ],
                    )
                });
            let mut fields: Vec<(&str, Option<Value>)> = [
                "activity_execution_id",
                "activity_type",
                "status",
                "queue",
                "attempt_count",
            ]
            .into_iter()
            .map(|key| (key, lookup(Some(activity), key)))
            .collect();
            fields.push(("current_attempt", attempt));
            compact(fields)
        },
    );
    let queues = lookup(debug, "activity_task_queues")
        .filter(php::is_array)
        .map(|queues| {
            Value::Object(
                php::entries(&queues)
                    .into_iter()
                    .filter(|(_, queue)| php::is_array(queue))
                    .map(|(name, queue)| {
                        let stats = php::get(queue, "stats")
                            .and_then(|stats| php::get(stats, "pollers"))
                            .filter(|pollers| php::is_array(pollers));
                        let pollers = diagnostic_list(php::get(queue, "pollers"), 5, |poller| {
                            pick(
                                poller,
                                &[
                                    "worker_id",
                                    "runtime",
                                    "sdk_version",
                                    "build_id",
                                    "status",
                                    "is_stale",
                                    "supported_activity_types",
                                ],
                            )
                        });
                        (
                            name.text(),
                            compact(vec![
                                ("active_pollers", lookup(stats, "active_count")),
                                ("stale_pollers", lookup(stats, "stale_count")),
                                ("pollers", Some(pollers)),
                            ]),
                        )
                    })
                    .collect(),
            )
        });
    let failures = diagnostic_list(lookup(debug, "recent_failures").as_ref(), 3, |failure| {
        pick(
            failure,
            &[
                "failure_id",
                "source_kind",
                "source_id",
                "failure_category",
                "exception_class",
                "message",
            ],
        )
    });
    let describe = Some(describe);
    compact(vec![
        ("event", Some(json!("workflow_wait_diagnostic"))),
        ("workflow_id", Some(json!(workflow_id))),
        ("run_id", first(&[(debug, "run_id"), (describe, "run_id")])),
        (
            "status",
            first(&[(execution, "status"), (describe, "status")]),
        ),
        (
            "status_bucket",
            first(&[(describe, "status_bucket"), (execution, "status_bucket")]),
        ),
        (
            "wait_kind",
            first(&[(describe, "wait_kind"), (execution, "wait_kind")]),
        ),
        (
            "wait_reason",
            first(&[(describe, "wait_reason"), (execution, "wait_reason")]),
        ),
        ("diagnostic_status", lookup(debug, "diagnostic_status")),
        ("findings", Some(findings)),
        ("pending_workflow_tasks", Some(tasks)),
        ("pending_activities", Some(activities)),
        ("activity_task_queues", queues),
        ("recent_failures", Some(failures)),
        ("diagnostic_error", debug_error.map(|error| json!(error))),
    ])
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn blank_request_ids_follow_2x() {
        for blank in [
            "",
            " ",
            "\t\n",
            "\u{0b}\u{0c}",
            "\u{a0}",
            "\u{3000} \u{2028}",
            "\0",
        ] {
            assert!(is_blank(blank), "{blank:?}");
        }
        for key in ["a", " a ", "\u{200b}", "-"] {
            assert!(!is_blank(key), "{key:?}");
        }
    }

    #[test]
    fn update_diagnostics_follow_2x_for_an_accepted_update() {
        let payload = php::array_map(json!({
            "workflow_id": "chk-42",
            "update_name": "approve",
            "update_id": "upd-1",
            "outcome": "update_accepted",
            "command_status": "accepted",
            "workflow_sequence": 7,
            "namespace": "default",
        }));
        let request =
            php::array_map(json!({"wait_for": "accepted", "request_id": "req-1", "input": [1]}));
        let result = Value::Object(with_update_diagnostics(
            payload, "chk-42", "approve", None, &request, None,
        ));
        assert_eq!(result["state"], "accepted");
        assert_eq!(result["update_state"], "accepted");
        assert_eq!(result["request_id"], "req-1");
        assert_eq!(result["payload"], json!([1]));
        assert_eq!(
            result["request"],
            json!({"workflow_id": "chk-42", "update_name": "approve", "request_id": "req-1", "wait_for": "accepted", "input": [1]})
        );
        assert_eq!(
            result["history_references"],
            json!({"workflow_sequence": 7})
        );
        assert_eq!(result["cli_fields"]["state"], "accepted");
        assert!(result["cli_fields"]["fields_present"]
            .as_array()
            .unwrap()
            .contains(&json!("workflow:update.update_diagnostics")));
        assert_eq!(result["update_diagnostics"]["payload"], json!([1]));
    }

    #[test]
    fn wait_diagnostic_is_compact_and_bounded() {
        let debug = json!({
            "run_id": "run-1",
            "execution": {"status": "waiting"},
            "findings": [{"code": "no_workers", "extra": 1}, "skip", {}, {}, {}, {}, {}],
            "activity_task_queues": {"default": {"stats": {"pollers": {"active_count": 0}}, "pollers": []}},
            "recent_failures": [{"failure_id": "f1"}, {"failure_id": "f2"}, {"failure_id": "f3"}, {"failure_id": "f4"}],
        });
        let describe = json!({"status": "running", "status_bucket": "running"});
        let diagnostic = build_wait_diagnostic("chk-42", &describe, Some(&debug), None);
        assert_eq!(diagnostic["status"], "waiting");
        assert_eq!(diagnostic["status_bucket"], "running");
        assert_eq!(diagnostic["findings"].as_array().unwrap().len(), 5);
        assert_eq!(diagnostic["findings"][0], json!({"code": "no_workers"}));
        assert_eq!(
            diagnostic["activity_task_queues"],
            json!({"default": {"active_pollers": 0}})
        );
        assert_eq!(diagnostic["recent_failures"].as_array().unwrap().len(), 3);
        assert!(diagnostic.get("pending_activities").is_none());
        let unreachable =
            build_wait_diagnostic("chk-42", &describe, None, Some("Server unreachable".into()));
        assert_eq!(unreachable["diagnostic_error"], "Server unreachable");
        assert_eq!(unreachable["status"], "running");
    }
}
