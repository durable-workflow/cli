//! `ExternalTaskInputContract::parseEnvelope`, used to check that a
//! published input fixture's embedded example is a valid envelope. Only the
//! task kind and the first error message are needed.

use crate::php;
use serde_json::Value;

const SCHEMA: &str = "durable-workflow.v2.external-task-input";
const VERSION: i64 = 1;

type Parsed<T> = Result<T, String>;

/// Validates an envelope and returns its task kind, or the 2.x error text.
pub fn parse(envelope: &Value) -> Parsed<&str> {
    require_string_value(envelope, "schema", SCHEMA)?;
    require_int_value(envelope, "version", VERSION)?;

    let task = require_object(envelope, "task")?;
    let kind = require_kind(task)?;
    validate_task(task, kind)?;

    let workflow = require_object(envelope, "workflow")?;
    validate_workflow(workflow, kind)?;

    let lease = require_object(envelope, "lease")?;
    for field in ["owner", "expires_at", "heartbeat_endpoint"] {
        require_string(lease, field)?;
    }

    let payloads = require_object(envelope, "payloads")?;
    require_nullable_object(payloads, "arguments", true)?;
    require_object(envelope, "headers")?;

    if kind == "activity_task" {
        let deadlines = require_object(envelope, "deadlines")?;
        for field in [
            "schedule_to_start",
            "start_to_close",
            "schedule_to_close",
            "heartbeat",
        ] {
            require_optional_string(deadlines, field)?;
        }
        return Ok(kind);
    }

    let history = require_object(envelope, "history")?;
    require_list(history, "events")?;
    require_int(history, "last_sequence")?;
    require_optional_string(history, "next_page_token")?;
    require_optional_string(history, "encoding")?;
    Ok(kind)
}

fn validate_task(task: &Value, kind: &str) -> Parsed<()> {
    require_string(task, "id")?;
    if require_int(task, "attempt")? < 1 {
        return Err("External task input task.attempt must be >= 1.".to_string());
    }
    require_string(task, "task_queue")?;
    require_optional_string(task, "connection")?;
    require_string(task, "idempotency_key")?;

    if kind == "activity_task" {
        require_string(task, "activity_attempt_id")?;
        require_string(task, "handler")?;
        return require_nullable_object(task, "external_executor", false);
    }

    require_optional_string(task, "handler")?;
    require_optional_string(task, "compatibility")
}

fn validate_workflow(workflow: &Value, kind: &str) -> Parsed<()> {
    require_string(workflow, "id")?;
    require_string(workflow, "run_id")?;
    if kind != "workflow_task" {
        return Ok(());
    }
    require_optional_string(workflow, "status")?;
    require_object(workflow, "resume").map(|_| ())
}

fn require_kind(task: &Value) -> Parsed<&str> {
    let kind = require_string(task, "kind")?;
    match kind {
        "activity_task" | "workflow_task" => Ok(kind),
        _ => Err(format!("Unsupported external task input kind [{kind}].")),
    }
}

fn missing(field: &str) -> String {
    format!("External task input is missing required field [{field}].")
}

fn present<'a>(value: &'a Value, field: &str) -> Parsed<&'a Value> {
    php::raw_get(value, field).ok_or_else(|| missing(field))
}

/// A non-list array (a JSON object with non-sequential keys).
fn is_object(value: &Value) -> bool {
    php::is_array(value) && !php::is_list(value)
}

fn require_object<'a>(value: &'a Value, field: &str) -> Parsed<&'a Value> {
    let item = present(value, field)?;
    if is_object(item) {
        Ok(item)
    } else {
        Err(format!(
            "External task input field [{field}] must be an object."
        ))
    }
}

fn require_nullable_object(value: &Value, field: &str, required: bool) -> Parsed<()> {
    let Some(item) = php::raw_get(value, field) else {
        return if required {
            Err(missing(field))
        } else {
            Ok(())
        };
    };
    if item.is_null() || is_object(item) {
        Ok(())
    } else {
        Err(format!(
            "External task input field [{field}] must be an object or null."
        ))
    }
}

fn require_list(value: &Value, field: &str) -> Parsed<()> {
    let item = present(value, field)?;
    if php::is_array(item) && php::is_list(item) {
        Ok(())
    } else {
        Err(format!(
            "External task input field [{field}] must be an array."
        ))
    }
}

fn require_string<'a>(value: &'a Value, field: &str) -> Parsed<&'a str> {
    present(value, field)?
        .as_str()
        .ok_or_else(|| format!("External task input field [{field}] must be a string."))
}

fn require_optional_string(value: &Value, field: &str) -> Parsed<()> {
    match php::raw_get(value, field) {
        None | Some(Value::Null) | Some(Value::String(_)) => Ok(()),
        Some(_) => Err(format!(
            "External task input field [{field}] must be a string or null."
        )),
    }
}

fn require_int(value: &Value, field: &str) -> Parsed<i64> {
    present(value, field)?
        .as_i64()
        .ok_or_else(|| format!("External task input field [{field}] must be an integer."))
}

fn require_string_value(value: &Value, field: &str, expected: &str) -> Parsed<()> {
    if present(value, field)?.as_str() == Some(expected) {
        Ok(())
    } else {
        Err(must_be(field, expected))
    }
}

fn require_int_value(value: &Value, field: &str, expected: i64) -> Parsed<()> {
    if present(value, field)?.as_i64() == Some(expected) {
        Ok(())
    } else {
        Err(must_be(field, &expected.to_string()))
    }
}

fn must_be(field: &str, expected: &str) -> String {
    format!("External task input field [{field}] must be [{expected}].")
}
