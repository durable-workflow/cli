//! Exit codes, error kinds and the error envelope. These are part of the
//! published automation contract and match the 2.x CLI.

use serde_json::{json, Map, Value};

pub mod exit {
    pub const SUCCESS: i32 = 0;
    pub const FAILURE: i32 = 1;
    pub const INVALID: i32 = 2;
    pub const NETWORK: i32 = 3;
    pub const AUTH: i32 = 4;
    pub const NOT_FOUND: i32 = 5;
    pub const SERVER: i32 = 6;
    pub const TIMEOUT: i32 = 7;
    pub const COMPATIBILITY: i32 = 8;

    pub fn from_http_status(status: u16) -> i32 {
        match status {
            401 | 403 => AUTH,
            404 => NOT_FOUND,
            408 => TIMEOUT,
            500..=599 => SERVER,
            400..=499 => INVALID,
            _ => FAILURE,
        }
    }
}

#[derive(Debug)]
pub enum CliError {
    /// Local validation of user input.
    Invalid(String),
    /// The Server could not be reached.
    Network(String),
    Timeout(String),
    /// The Server answered with an HTTP error.
    Http {
        status: u16,
        message: String,
        body: Option<Map<String, Value>>,
    },
    /// The Server is outside what this CLI supports.
    Compatibility(crate::compat::CompatibilityFailure),
    /// Anything else, such as an unreadable config file.
    Failure(String),
}

impl CliError {
    pub fn message(&self) -> &str {
        match self {
            CliError::Invalid(message)
            | CliError::Network(message)
            | CliError::Timeout(message)
            | CliError::Failure(message)
            | CliError::Http { message, .. } => message,
            CliError::Compatibility(failure) => &failure.message,
        }
    }

    pub fn exit_code(&self) -> i32 {
        match self {
            CliError::Invalid(_) => exit::INVALID,
            CliError::Network(_) => exit::NETWORK,
            CliError::Timeout(_) => exit::TIMEOUT,
            CliError::Http { status, .. } => exit::from_http_status(*status),
            CliError::Compatibility(_) => exit::COMPATIBILITY,
            CliError::Failure(_) => exit::FAILURE,
        }
    }

    /// Builds the HTTP error the way the 2.x client does: the Server's
    /// message, error or first validation message, else the rejection
    /// reason, else the status.
    pub fn from_http(status: u16, raw_body: &str) -> Self {
        let body = serde_json::from_str::<Value>(raw_body)
            .ok()
            .and_then(|value| match value {
                Value::Object(map) => Some(map),
                _ => None,
            });
        let text = |key: &str| {
            body.as_ref()
                .and_then(|body| body.get(key))
                .and_then(Value::as_str)
                .filter(|value| !value.is_empty())
                .map(str::to_string)
        };
        let mut message = match text("rejection_reason") {
            Some(reason) => format!("Rejected: {reason}"),
            None => format!("HTTP {status}"),
        };
        let first_validation = body.as_ref().and_then(first_validation_message);
        if let Some(candidate) = text("message")
            .or_else(|| text("error"))
            .or(first_validation)
        {
            message = candidate;
        }
        CliError::Http {
            status,
            message: format!("Server error: {message}"),
            body: body.filter(|b| !b.is_empty()),
        }
    }

    fn body(&self) -> Option<&Map<String, Value>> {
        match self {
            CliError::Http { body, .. } => body.as_ref(),
            _ => None,
        }
    }

    fn reason(&self) -> Option<&str> {
        self.body()
            .and_then(|body| body.get("reason"))
            .and_then(Value::as_str)
            .filter(|reason| !reason.is_empty())
    }

    fn validation_errors(&self) -> Option<&Value> {
        let body = self.body()?;
        body.get("validation_errors")
            .or_else(|| body.get("errors"))
            .filter(|errors| errors.is_object() || errors.is_array())
    }
}

fn first_validation_message(body: &Map<String, Value>) -> Option<String> {
    let errors = body
        .get("validation_errors")
        .or_else(|| body.get("errors"))?;
    let fields: Vec<&Value> = match errors {
        Value::Object(map) => map.values().collect(),
        Value::Array(items) => items.iter().collect(),
        _ => return None,
    };
    fields
        .into_iter()
        .find_map(|messages| match messages {
            Value::Array(items) => items
                .iter()
                .find_map(|item| item.as_str().filter(|text| !text.is_empty())),
            Value::String(text) if !text.is_empty() => Some(text.as_str()),
            _ => None,
        })
        .map(str::to_string)
}

/// What the error envelope and next steps need to know about the command.
pub struct ErrorContext<'a> {
    pub command: &'a str,
    pub namespace: Option<String>,
    pub workflow_id: Option<String>,
    pub server_flag: Option<String>,
    pub env_flag: Option<String>,
}

impl ErrorContext<'_> {
    fn doctor_command(&self) -> String {
        if let Some(server) = &self.server_flag {
            return format!("dw doctor --server={server} --output=json");
        }
        if let Some(env) = &self.env_flag {
            return format!("dw doctor --env={env} --output=json");
        }
        "dw doctor --output=json".to_string()
    }
}

pub fn recommendations(error: &CliError, context: &ErrorContext) -> Vec<Value> {
    let item = |id: &str, message: &str, command: String| json!({"id": id, "severity": "error", "message": message, "command": command});
    let help = format!("dw {} --help", context.command);
    match error {
        CliError::Network(_) => vec![item(
            "server.unreachable",
            "Check the server URL, DNS, port, and TLS settings for the selected environment.",
            context.doctor_command(),
        )],
        CliError::Timeout(_) => vec![item(
            "server.timeout",
            "Check server load and network reachability, then retry with a smaller request or narrower query.",
            context.doctor_command(),
        )],
        CliError::Invalid(_) => vec![item(
            "input.invalid",
            "Review the command usage and option values before retrying.",
            help,
        )],
        CliError::Http { status, .. } => match status {
            401 | 403 => vec![item(
                "auth.failed",
                "Check the selected environment, auth token source, and namespace permissions.",
                context.doctor_command(),
            )],
            404 => vec![item(
                "resource.not_found",
                "Check the namespace, workflow/run identifiers, and selected environment.",
                help,
            )],
            400..=499 => vec![item(
                "request.invalid",
                "Inspect the request options and retry in JSON mode if support needs the exact error envelope.",
                format!("dw {} --output=json", context.command),
            )],
            500..=599 => vec![item(
                "server.error",
                "Check server health and logs before retrying the command.",
                "dw server:health --output=json".to_string(),
            )],
            _ => vec![],
        },
        CliError::Compatibility(failure) => vec![item(
            "compatibility.unsupported",
            &failure.next_step,
            context.doctor_command(),
        )],
        CliError::Failure(_) => vec![],
    }
}

/// Fields copied from a Server refusal into the error envelope, in order.
const SERVER_DIAGNOSTIC_FIELDS: &[&str] = &[
    "workflow_id",
    "run_id",
    "requested_run_id",
    "resolved_run_id",
    "workflow_type",
    "command_id",
    "workflow_command_id",
    "command_sequence",
    "target_scope",
    "command_status",
    "command_source",
    "command_reason",
    "update_name",
    "update_id",
    "update_status",
    "outcome",
    "reason",
    "rejection_reason",
    "rejection_category",
    "request_id",
    "workflow_sequence",
    "result",
    "result_envelope",
    "failure_id",
    "failure_message",
    "principal",
    "accepted_at",
    "applied_at",
    "rejected_at",
    "closed_at",
    "wait_for",
    "wait_timed_out",
    "wait_timeout_seconds",
    "accepted",
    "message",
    "state",
    "update_state",
    "request",
    "error_details",
    "update_diagnostics",
];

const HISTORY_REFERENCE_FIELDS: &[&str] = &[
    "workflow_sequence",
    "command_sequence",
    "accepted_at",
    "applied_at",
    "rejected_at",
    "closed_at",
];

/// The Server's non-empty error code fields, in envelope order.
fn body_codes(body: &Map<String, Value>) -> impl Iterator<Item = (&'static str, &str)> + '_ {
    ["code", "error_code", "type", "rejection_reason"]
        .into_iter()
        .filter_map(|key| {
            let value = body
                .get(key)
                .and_then(Value::as_str)
                .filter(|value| !value.is_empty())?;
            Some((key, value))
        })
}

/// Copies Server refusal fields into the envelope without replacing any
/// field the CLI already set, and groups the history references.
fn copy_server_diagnostics(envelope: &mut Map<String, Value>, body: &Map<String, Value>) {
    for key in SERVER_DIAGNOSTIC_FIELDS {
        if let Some(value) = body.get(*key) {
            envelope
                .entry(key.to_string())
                .or_insert_with(|| value.clone());
        }
    }
    let references: Map<String, Value> = HISTORY_REFERENCE_FIELDS
        .iter()
        .filter_map(|key| body.get(*key).map(|value| (key.to_string(), value.clone())))
        .collect();
    if !references.is_empty() {
        envelope
            .entry("history_references")
            .or_insert(Value::Object(references));
    }
}

fn add_http_details(
    envelope: &mut Map<String, Value>,
    error: &CliError,
    status: u16,
    body: Option<&Map<String, Value>>,
) {
    envelope.insert("status_code".into(), json!(status));
    if let Some(reason) = error.reason() {
        envelope.insert("reason".into(), json!(reason));
    }
    if let Some(body) = body {
        for (key, value) in body_codes(body) {
            envelope.insert(key.into(), json!(value));
        }
        copy_server_diagnostics(envelope, body);
    }
    if let Some(errors) = error.validation_errors() {
        envelope.insert("validation_errors".into(), errors.clone());
    }
    if let Some(body) = body {
        envelope.insert("server_response".into(), Value::Object(body.clone()));
    }
}

pub fn envelope(error: &CliError, context: &ErrorContext) -> Value {
    let mut envelope = Map::new();
    envelope.insert("error".into(), json!(error.message()));
    envelope.insert("exit_code".into(), json!(error.exit_code()));
    envelope.insert("command".into(), json!(context.command));
    if let Some(namespace) = &context.namespace {
        envelope.insert("namespace".into(), json!(namespace));
    }
    if let CliError::Http { status, body, .. } = error {
        add_http_details(&mut envelope, error, *status, body.as_ref());
    }
    if let Some(workflow_id) = &context.workflow_id {
        envelope
            .entry("workflow_id")
            .or_insert_with(|| json!(workflow_id));
    }
    if let CliError::Compatibility(failure) = error {
        envelope.insert(
            "compatibility".into(),
            Value::Object(failure.diagnostic.clone()),
        );
    }
    let next = recommendations(error, context);
    if !next.is_empty() {
        envelope.insert("recommendations".into(), Value::Array(next));
    }
    Value::Object(envelope)
}

/// The Server's error codes, as `Code: value` lines.
fn body_code_lines(body: &Map<String, Value>) -> Vec<String> {
    body_codes(body)
        .map(|(key, value)| {
            let mut label = key.replace('_', " ");
            label[..1].make_ascii_uppercase();
            format!("{label}: {value}")
        })
        .collect()
}

/// `Validation: field: message` lines from a validation error object.
fn validation_lines(errors: &Value) -> Vec<String> {
    let Value::Object(fields) = errors else {
        return vec![];
    };
    fields
        .iter()
        .flat_map(|(field, messages)| {
            let texts: Vec<&str> = match messages {
                Value::Array(items) => items.iter().filter_map(Value::as_str).collect(),
                Value::String(message) => vec![message.as_str()],
                _ => vec![],
            };
            texts
                .into_iter()
                .filter(|text| !text.is_empty())
                .map(move |text| format!("Validation: {field}: {text}"))
        })
        .collect()
}

fn next_step_lines(next: &[Value]) -> Vec<String> {
    if next.is_empty() {
        return vec![];
    }
    let mut lines = vec!["Next steps:".to_string()];
    for item in next {
        lines.push(format!(
            "  - {}",
            item["message"].as_str().unwrap_or_default()
        ));
        if let Some(command) = item["command"].as_str() {
            lines.push(format!("    Try: {command}"));
        }
    }
    lines
}

/// Human error text for stderr.
pub fn human(error: &CliError, context: &ErrorContext) -> String {
    let display = |text: &str| {
        if matches!(
            context.command,
            "workflow:list"
                | "workflow:list-runs"
                | "workflow:describe"
                | "workflow:show-run"
                | "workflow:history"
                | "workflow:history-export"
                | "namespace:list"
                | "namespace:describe"
                | "namespace:create"
                | "namespace:update"
                | "namespace:delete"
        ) || ["schedule:", "search-attribute:", "task-queue:", "worker:"]
            .iter()
            .any(|group| context.command.starts_with(group))
            || matches!(
                context.command,
                "storage:test" | "namespace:set-storage-driver"
            )
        {
            crate::formatter::display_text(text)
        } else {
            text.to_owned()
        }
    };
    let mut lines = vec![format!("<error>{}</error>", display(error.message()))];
    if let Some(namespace) = &context.namespace {
        lines.push(format!("Namespace: {}", display(namespace)));
    }
    if let Some(reason) = error.reason() {
        lines.push(format!("Reason: {}", display(reason)));
    }
    if let Some(body) = error.body() {
        lines.extend(body_code_lines(body).into_iter().map(|line| display(&line)));
    }
    if let Some(errors) = error.validation_errors() {
        lines.extend(
            validation_lines(errors)
                .into_iter()
                .map(|line| display(&line)),
        );
    }
    lines.extend(
        next_step_lines(&recommendations(error, context))
            .into_iter()
            .map(|line| display(&line)),
    );
    lines.join("\n")
}

#[cfg(test)]
mod tests {
    use super::*;

    fn context() -> ErrorContext<'static> {
        ErrorContext {
            command: "workflow:cancel",
            namespace: Some("default".into()),
            workflow_id: None,
            server_flag: None,
            env_flag: None,
        }
    }

    #[test]
    fn http_status_maps_to_exit_categories() {
        assert_eq!(exit::from_http_status(401), exit::AUTH);
        assert_eq!(exit::from_http_status(404), exit::NOT_FOUND);
        assert_eq!(exit::from_http_status(408), exit::TIMEOUT);
        assert_eq!(exit::from_http_status(409), exit::INVALID);
        assert_eq!(exit::from_http_status(503), exit::SERVER);
    }

    #[test]
    fn refusal_without_message_keeps_reason_and_server_response() {
        let error = CliError::from_http(
            409,
            r#"{"workflow_id":"wf","run_id":"run-a","reason":"historical_run_command_rejected","target_scope":"run"}"#,
        );
        assert_eq!(error.message(), "Server error: HTTP 409");
        let envelope = envelope(&error, &context());
        assert_eq!(envelope["exit_code"], 2);
        assert_eq!(envelope["status_code"], 409);
        assert_eq!(envelope["reason"], "historical_run_command_rejected");
        assert_eq!(envelope["run_id"], "run-a");
        assert_eq!(envelope["server_response"]["target_scope"], "run");
        assert_eq!(envelope["recommendations"][0]["id"], "request.invalid");
    }

    #[test]
    fn message_wins_over_rejection_reason() {
        let error = CliError::from_http(422, r#"{"rejection_reason":"x","message":"Bad input."}"#);
        assert_eq!(error.message(), "Server error: Bad input.");
        let error = CliError::from_http(422, r#"{"rejection_reason":"x"}"#);
        assert_eq!(error.message(), "Server error: Rejected: x");
        let error = CliError::from_http(422, r#"{"errors":{"name":["Name is required."]}}"#);
        assert_eq!(error.message(), "Server error: Name is required.");
    }

    #[test]
    fn human_error_lists_reason_codes_validation_and_next_steps() {
        let error = CliError::from_http(
            422,
            r#"{"message":"Invalid.","reason":"bad_input","error_code":"E1","errors":{"name":["Required.",""],"type":"Unknown."}}"#,
        );
        assert_eq!(
            crate::formatter::format(&human(&error, &context()), false),
            "Server error: Invalid.\nNamespace: default\nReason: bad_input\nError code: E1\nValidation: name: Required.\nValidation: type: Unknown.\nNext steps:\n  - Inspect the request options and retry in JSON mode if support needs the exact error envelope.\n    Try: dw workflow:cancel --output=json"
        );
    }

    #[test]
    fn existing_commands_keep_their_ansi_placeholder_boundaries() {
        let error = CliError::Invalid("Use <name>.".into());
        let rendered = crate::formatter::format(&human(&error, &context()), true);
        assert!(rendered.contains("\u{1b}[39;49m\u{1b}[37;41m<name>\u{1b}[39;49m\u{1b}[37;41m"));
    }

    #[test]
    fn network_errors_point_to_doctor_with_the_selected_target() {
        let context = ErrorContext {
            command: "server:health",
            namespace: None,
            workflow_id: None,
            server_flag: Some("http://127.0.0.1:1".into()),
            env_flag: None,
        };
        let envelope = envelope(
            &CliError::Network("Server unreachable: refused".into()),
            &context,
        );
        assert_eq!(envelope["exit_code"], 3);
        assert_eq!(
            envelope["recommendations"][0]["command"],
            "dw doctor --server=http://127.0.0.1:1 --output=json"
        );
        assert!(envelope.get("namespace").is_none());
    }
}
