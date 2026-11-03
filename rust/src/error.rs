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
    #[allow(dead_code)]
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
        }
    }

    pub fn exit_code(&self) -> i32 {
        match self {
            CliError::Invalid(_) => exit::INVALID,
            CliError::Network(_) => exit::NETWORK,
            CliError::Timeout(_) => exit::TIMEOUT,
            CliError::Http { status, .. } => exit::from_http_status(*status),
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

pub fn envelope(error: &CliError, context: &ErrorContext) -> Value {
    let mut envelope = Map::new();
    envelope.insert("error".into(), json!(error.message()));
    envelope.insert("exit_code".into(), json!(error.exit_code()));
    envelope.insert("command".into(), json!(context.command));
    if let Some(namespace) = &context.namespace {
        envelope.insert("namespace".into(), json!(namespace));
    }

    if let CliError::Http { status, body, .. } = error {
        envelope.insert("status_code".into(), json!(status));
        if let Some(reason) = error.reason() {
            envelope.insert("reason".into(), json!(reason));
        }
        if let Some(body) = body {
            for key in ["code", "error_code", "type", "rejection_reason"] {
                if let Some(value) = body
                    .get(key)
                    .and_then(Value::as_str)
                    .filter(|value| !value.is_empty())
                {
                    envelope.insert(key.into(), json!(value));
                }
            }
            for key in SERVER_DIAGNOSTIC_FIELDS {
                if let Some(value) = body.get(*key) {
                    envelope
                        .entry(key.to_string())
                        .or_insert_with(|| value.clone());
                }
            }
            let mut references = Map::new();
            for key in HISTORY_REFERENCE_FIELDS {
                if let Some(value) = body.get(*key) {
                    references.insert(key.to_string(), value.clone());
                }
            }
            if !references.is_empty() {
                envelope
                    .entry("history_references")
                    .or_insert(Value::Object(references));
            }
        }
        if let Some(errors) = error.validation_errors() {
            envelope.insert("validation_errors".into(), errors.clone());
        }
        if let Some(body) = body {
            envelope.insert("server_response".into(), Value::Object(body.clone()));
        }
    }

    let next = recommendations(error, context);
    if !next.is_empty() {
        envelope.insert("recommendations".into(), Value::Array(next));
    }
    Value::Object(envelope)
}

/// Human error text for stderr.
pub fn human(error: &CliError, context: &ErrorContext) -> String {
    let mut lines = vec![error.message().to_string()];
    if let Some(namespace) = &context.namespace {
        lines.push(format!("Namespace: {namespace}"));
    }
    if let Some(reason) = error.reason() {
        lines.push(format!("Reason: {reason}"));
    }
    if let Some(body) = error.body() {
        for key in ["code", "error_code", "type", "rejection_reason"] {
            if let Some(value) = body
                .get(key)
                .and_then(Value::as_str)
                .filter(|value| !value.is_empty())
            {
                let mut label = key.replace('_', " ");
                label[..1].make_ascii_uppercase();
                lines.push(format!("{label}: {value}"));
            }
        }
    }
    if let Some(errors) = error.validation_errors() {
        if let Value::Object(fields) = errors {
            for (field, messages) in fields {
                match messages {
                    Value::Array(items) => {
                        for message in items
                            .iter()
                            .filter_map(Value::as_str)
                            .filter(|text| !text.is_empty())
                        {
                            lines.push(format!("Validation: {field}: {message}"));
                        }
                    }
                    Value::String(message) if !message.is_empty() => {
                        lines.push(format!("Validation: {field}: {message}"));
                    }
                    _ => {}
                }
            }
        }
    }
    let next = recommendations(error, context);
    if !next.is_empty() {
        lines.push("Next steps:".into());
        for item in next {
            lines.push(format!(
                "  - {}",
                item["message"].as_str().unwrap_or_default()
            ));
            if let Some(command) = item["command"].as_str() {
                lines.push(format!("    Try: {command}"));
            }
        }
    }
    lines.join("\n")
}

#[cfg(test)]
mod tests {
    use super::*;

    fn context() -> ErrorContext<'static> {
        ErrorContext {
            command: "workflow:cancel",
            namespace: Some("default".into()),
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
    fn network_errors_point_to_doctor_with_the_selected_target() {
        let context = ErrorContext {
            command: "server:health",
            namespace: None,
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
