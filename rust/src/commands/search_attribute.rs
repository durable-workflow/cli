//! `search-attribute:*`, `storage:test` and `namespace:set-storage-driver`
//! through the published SDK transport. Each mutation is one request; a
//! redirect is refused and a lost response is never submitted again.

use crate::cli::Input;
use crate::client::Context;
use crate::commands::namespace::with_resource_context;
use crate::commands::schedule::{text, text_or, with_namespace, write_namespace_line};
use crate::error::CliError;
use crate::{formatter, output, php};
use durable_workflow::RawRequest;
use serde_json::{json, Map, Value};

/// `CompletionValues::EXTERNAL_STORAGE_DRIVERS`.
const STORAGE_DRIVERS: [&str; 4] = ["local", "s3", "gcs", "azure"];

pub fn list(context: &Context, _input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let result = with_namespace(
        context.resource_request(RawRequest::get(["search-attributes"]))?,
        &context.connection.namespace,
        None,
    );
    if context.mode.machine_readable() {
        // The attribute maps are name => type, so JSONL has no item list.
        output::print_json(&result);
        return Ok(0);
    }
    let mut rows = Vec::new();
    for (key, source) in [
        ("system_attributes", "system"),
        ("custom_attributes", "custom"),
    ] {
        if let Some(attributes) = php::get(&result, key).filter(|value| php::is_array(value)) {
            for (name, kind) in php::entries(attributes) {
                rows.push(vec![
                    formatter::display_text(&name.text()),
                    text(Some(kind)),
                    source.to_string(),
                ]);
            }
        }
    }
    let namespace = text(result.get("namespace"));
    if rows.is_empty() {
        formatter::writeln_out(&format!(
            "<comment>No search attributes found in namespace {namespace}.</comment>"
        ));
        return Ok(0);
    }
    formatter::writeln_out(&format!("Namespace: {namespace}"));
    formatter::writeln_out(&output::render_table(&["Name", "Type", "Source"], &rows));
    Ok(0)
}

pub fn create(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let body = json!({
        "name": input.arg("name").unwrap_or_default(),
        "type": input.arg("type").unwrap_or_default(),
    });
    let result = with_namespace(
        context.resource_request(RawRequest::post(["search-attributes"]).json(body))?,
        &context.connection.namespace,
        None,
    );
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    formatter::writeln_out(&format!(
        "<info>Search attribute created: {} ({})</info>",
        text(php::get(&result, "name")),
        text(php::get(&result, "type")),
    ));
    write_namespace_line(&result);
    Ok(0)
}

pub fn delete(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let name = input.arg("name").unwrap_or_default();
    let result = with_namespace(
        context.resource_request(RawRequest::delete(["search-attributes", name]))?,
        &context.connection.namespace,
        None,
    );
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    formatter::writeln_out(&format!(
        "<info>Search attribute deleted: {}</info>",
        formatter::display_text(name)
    ));
    write_namespace_line(&result);
    Ok(0)
}

pub fn storage_test(context: &Context, input: &Input) -> Result<i32, CliError> {
    let mut body = Map::new();
    body.insert(
        "small_payload_bytes".into(),
        json!(positive_integer(
            input.opt("small-bytes").flatten(),
            "--small-bytes"
        )?),
    );
    body.insert(
        "large_payload_bytes".into(),
        json!(positive_integer(
            input.opt("large-bytes").flatten(),
            "--large-bytes"
        )?),
    );
    let driver = string_option(input, "driver");
    if let Some(driver) = &driver {
        if !STORAGE_DRIVERS.contains(&driver.as_str()) {
            return Err(driver_error());
        }
        body.insert("driver".into(), json!(driver));
    }
    context.preflight(false)?;
    let result = context
        .resource_request(RawRequest::post(["storage", "test"]).json(Value::Object(body)))?;
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    formatter::writeln_out(&format!(
        "<info>External storage round trip: {}</info>",
        text_or(php::get(&result, "status"), "unknown")
    ));
    let driver_line = match php::get(&result, "driver").filter(|value| !value.is_null()) {
        Some(value) => text(Some(value)),
        None => formatter::display_text(driver.as_deref().unwrap_or("configured")),
    };
    formatter::writeln_out(&format!("  Driver: {driver_line}"));
    for (key, label) in [
        ("small_payload", "Small payload"),
        ("large_payload", "Large payload"),
    ] {
        let payload = php::get(&result, key).filter(|value| php::is_array(value));
        let field = |name: &str| payload.and_then(|payload| php::get(payload, name));
        let mut details = vec![format!("status={}", php::or(field("status"), "unknown"))];
        for (name, prefix) in [
            ("bytes", "bytes="),
            ("sha256", "sha256="),
            ("reference_uri", "reference="),
        ] {
            if let Some(value) = field(name).filter(|value| !value.is_null()) {
                details.push(format!("{prefix}{}", php::to_string(Some(value))));
            }
        }
        formatter::writeln_out(&format!(
            "  {label}: {}",
            formatter::display_text(&details.join(" "))
        ));
    }
    Ok(0)
}

pub fn set_storage_driver(context: &Context, input: &Input) -> Result<i32, CliError> {
    let name = input.arg("name").unwrap_or_default();
    let driver = php::trim(input.arg("driver").unwrap_or_default()).to_string();
    if !STORAGE_DRIVERS.contains(&driver.as_str()) {
        return Err(driver_error());
    }
    let enabled = !input.flag("disable");
    let mut body = Map::new();
    body.insert("driver".into(), json!(driver));
    body.insert("enabled".into(), json!(enabled));
    // A bare --threshold-bytes is null in 2.x, which leaves the threshold out.
    if let Some(threshold) = input.opt("threshold-bytes").flatten() {
        body.insert(
            "threshold_bytes".into(),
            json!(positive_integer(Some(threshold), "--threshold-bytes")?),
        );
    }
    let mut config = Map::new();
    for (option, field) in [
        ("uri", "uri"),
        ("disk", "disk"),
        ("bucket", "bucket"),
        ("prefix", "prefix"),
        ("region", "region"),
        ("endpoint", "endpoint"),
        ("auth-profile", "auth_profile"),
    ] {
        if let Some(value) = string_option(input, option) {
            config.insert(field.into(), json!(value));
        }
    }
    if !config.is_empty() {
        body.insert("config".into(), Value::Object(config.clone()));
    }
    context.preflight(false)?;
    let result = with_resource_context(
        context.resource_request(
            RawRequest::put(["namespaces", name, "external-storage"]).json(Value::Object(body)),
        )?,
        name,
    );
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let policy = php::get(&result, "external_payload_storage")
        .filter(|value| php::is_array(value))
        .unwrap_or(&result);
    let shown_name = match php::get(&result, "name").filter(|value| !value.is_null()) {
        Some(value) => text(Some(value)),
        None => formatter::display_text(name),
    };
    formatter::writeln_out(&format!(
        "<info>External storage updated for namespace: {shown_name}</info>"
    ));
    let shown_driver = match php::get(policy, "driver").filter(|value| !value.is_null()) {
        Some(value) => text(Some(value)),
        None => formatter::display_text(&driver),
    };
    formatter::writeln_out(&format!("  Driver: {shown_driver}"));
    let shown_enabled = match php::get(policy, "enabled").filter(|value| !value.is_null()) {
        Some(value) => validate_boolean(value),
        None => enabled,
    };
    formatter::writeln_out(&format!(
        "  Enabled: {}",
        if shown_enabled { "yes" } else { "no" }
    ));
    if let Some(threshold) = php::get(policy, "threshold_bytes").filter(|value| !value.is_null()) {
        formatter::writeln_out(&format!("  Threshold: {} bytes", text(Some(threshold))));
    }
    let keys = match php::get(policy, "config").filter(|value| php::is_array(value)) {
        Some(policy_config) => php::entries(policy_config)
            .into_iter()
            .map(|(key, _)| key.text())
            .collect::<Vec<_>>(),
        None => config.keys().cloned().collect(),
    };
    if !keys.is_empty() {
        formatter::writeln_out(&format!(
            "  Config keys: {}",
            formatter::display_text(&keys.join(", "))
        ));
    }
    Ok(0)
}

fn driver_error() -> CliError {
    CliError::Failure(format!(
        "driver must be one of: {}.",
        STORAGE_DRIVERS.join(", ")
    ))
}

/// 2.x `positiveInteger()`: `preg_match('/^[1-9][0-9]*$/')`, whose `$` also
/// matches before a final newline, then `(int)`.
fn positive_integer(value: Option<&str>, option: &str) -> Result<i64, CliError> {
    let valid = value.is_some_and(|value| {
        let digits = value.strip_suffix('\n').unwrap_or(value);
        digits.starts_with(|c: char| matches!(c, '1'..='9')) && php::ctype_digit(digits)
    });
    match value {
        Some(value) if valid => Ok(php::string_to_int(value)),
        _ => Err(CliError::Failure(format!(
            "{option} must be a positive integer."
        ))),
    }
}

/// 2.x `stringOption()`: the trimmed value, or nothing when blank.
fn string_option(input: &Input, name: &str) -> Option<String> {
    input
        .opt(name)
        .flatten()
        .map(|value| php::trim(value).to_string())
        .filter(|value| !value.is_empty())
}

/// PHP `filter_var($value, FILTER_VALIDATE_BOOLEAN)`.
fn validate_boolean(value: &Value) -> bool {
    match value {
        Value::Bool(flag) => *flag,
        Value::Array(_) | Value::Object(_) | Value::Null => false,
        scalar => matches!(
            php::trim(&php::to_string(Some(scalar)))
                .to_ascii_lowercase()
                .as_str(),
            "1" | "true" | "on" | "yes"
        ),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn positive_integers_follow_the_2x_pattern() {
        assert_eq!(positive_integer(Some("128"), "--x").unwrap(), 128);
        assert_eq!(positive_integer(Some("7\n"), "--x").unwrap(), 7);
        for bad in [
            None,
            Some(""),
            Some("0"),
            Some("012"),
            Some("-1"),
            Some("1.5"),
            Some(" 1"),
        ] {
            assert!(positive_integer(bad, "--x").is_err(), "{bad:?}");
        }
    }

    #[test]
    fn booleans_follow_filter_var() {
        for (value, expected) in [
            (json!(true), true),
            (json!("yes"), true),
            (json!(" On "), true),
            (json!(1), true),
            (json!("0"), false),
            (json!("off"), false),
            (json!([1]), false),
        ] {
            assert_eq!(validate_boolean(&value), expected, "{value}");
        }
    }
}
