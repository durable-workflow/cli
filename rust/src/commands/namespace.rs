//! Namespace visibility through the published SDK transport.

use crate::cli::Input;
use crate::client::Context;
use crate::error::CliError;
use crate::{formatter, output, php};
use serde_json::{json, Value};

pub fn list(context: &Context, _input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let mut result = context.namespace_list()?;
    if let Some(items) = result
        .get_mut("namespaces")
        .filter(|value| php::is_array(value))
    {
        let enrich = |item: &mut Value| {
            if php::is_array(item) {
                let fallback = if php::is_scalar(php::get(item, "name")) {
                    php::to_string(php::get(item, "name"))
                } else {
                    String::new()
                };
                *item = with_resource_context(item.take(), &fallback);
            }
        };
        match items {
            Value::Array(items) => items.iter_mut().for_each(enrich),
            Value::Object(items) => items.values_mut().for_each(enrich),
            _ => {}
        }
    }
    if context.mode.machine_readable() {
        output::print_json_list(context.mode, &result, "namespaces");
        return Ok(0);
    }
    if let Some(value) = result
        .get("namespaces")
        .filter(|value| !php::is_array(value))
    {
        let kind = match value {
            Value::String(text) if !text.is_empty() && text != "0" => Some("string"),
            Value::Number(number) if number.as_f64() != Some(0.0) => {
                Some(if number.is_f64() { "float" } else { "int" })
            }
            Value::Bool(true) => Some("true"),
            _ => None,
        };
        if let Some(kind) = kind {
            return Err(CliError::Failure(format!(
                "array_map(): Argument #2 ($array) must be of type array, {kind} given"
            )));
        }
    }
    let items = result
        .get("namespaces")
        .map(php::values)
        .unwrap_or_default();
    if items.is_empty() {
        formatter::writeln_out("<comment>No namespaces found.</comment>");
        return Ok(0);
    }
    let rows = items
        .into_iter()
        .map(|item| {
            ["name", "description", "retention_days", "status"]
                .into_iter()
                .map(|field| cell(item, field))
                .collect()
        })
        .collect::<Vec<Vec<String>>>();
    formatter::writeln_out(&output::render_table(
        &["Namespace", "Description", "Retention (days)", "Status"],
        &rows,
    ));
    Ok(0)
}

pub fn describe(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let name = input.arg("name").unwrap_or_default();
    let result = with_resource_context(context.namespace_describe(name)?, name);
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    formatter::writeln_out(&format!(
        "<info>Namespace: {}</info>",
        cell(&result, "namespace")
    ));
    for (label, key, suffix) in [
        ("Description", "description", ""),
        ("Retention", "retention_days", " days"),
        ("Status", "status", ""),
        ("Created", "created_at", ""),
        ("Updated", "updated_at", ""),
    ] {
        formatter::writeln_out(&format!("  {label}: {}{suffix}", cell(&result, key)));
    }
    Ok(0)
}

pub fn create(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let name = input.arg("name").unwrap_or_default();
    let body = json!({
        "name": name,
        "description": input.opt("description").flatten(),
        "retention_days": php::string_to_int(input.opt("retention").flatten().unwrap_or_default()),
    });
    resource_result(context, input, context.namespace_create(body)?, "created")
}

pub fn update(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let name = input.arg("name").unwrap_or_default();
    let mut fields = serde_json::Map::new();
    if let Some(description) = input.opt("description").flatten() {
        fields.insert("description".into(), json!(description));
    }
    if let Some(retention) = input.opt_str("retention").filter(|value| *value != "0") {
        fields.insert(
            "retention_days".into(),
            json!(php::string_to_int(retention)),
        );
    }
    // PHP's empty associative array is encoded as [], not {}.
    let body = if fields.is_empty() {
        json!([])
    } else {
        Value::Object(fields)
    };
    resource_result(
        context,
        input,
        context.namespace_update(name, body)?,
        "updated",
    )
}

pub fn delete(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let name = input.arg("name").unwrap_or_default();
    resource_result(context, input, context.namespace_delete(name)?, "deleted")
}

fn resource_result(
    context: &Context,
    input: &Input,
    result: Value,
    action: &str,
) -> Result<i32, CliError> {
    let name = input.arg("name").unwrap_or_default();
    let result = with_resource_context(result, name);
    if context.mode.machine_readable() {
        output::print_json(&result);
    } else {
        formatter::writeln_out(&format!(
            "<info>Namespace {action}: {}</info>",
            cell(&result, "namespace")
        ));
    }
    Ok(0)
}

fn with_resource_context(value: Value, fallback: &str) -> Value {
    let name = if nonempty_scalar(php::get(&value, "name")) {
        php::to_string(php::get(&value, "name"))
    } else {
        fallback.to_owned()
    };
    if php::trim(&name).is_empty() {
        return value;
    }
    let mut fields = php::array_map(value);
    if !nonempty_scalar(fields.get("name")) {
        fields.insert("name".into(), json!(name));
    }
    if !nonempty_scalar(fields.get("namespace")) {
        fields.insert("namespace".into(), json!(name));
    }
    Value::Object(fields)
}

fn cell(item: &Value, field: &str) -> String {
    let value = php::get(item, field);
    if field == "status" {
        let escaped = value
            .filter(|value| php::is_scalar(Some(value)))
            .map(|value| json!(formatter::display_text(&php::to_string(Some(value)))));
        output::format_status(escaped.as_ref())
    } else {
        value
            .map(|value| formatter::display_text(&php::to_string(Some(value))))
            .unwrap_or_else(|| if field == "name" { "" } else { "-" }.into())
    }
}

fn nonempty_scalar(value: Option<&Value>) -> bool {
    php::is_scalar(value) && !php::trim(&php::to_string(value)).is_empty()
}
