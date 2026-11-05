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
            if php::is_array(item)
                && nonempty_scalar(php::get(item, "name"))
                && !nonempty_scalar(php::get(item, "namespace"))
            {
                let mut fields = php::array_map(item.take());
                fields.insert(
                    "namespace".into(),
                    json!(php::to_string(fields.get("name"))),
                );
                *item = Value::Object(fields);
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
                .map(|field| {
                    let value = php::get(item, field);
                    let escaped = value
                        .map(|value| json!(formatter::display_text(&php::to_string(Some(value)))));
                    if field == "status" {
                        let scalar = value.filter(|value| php::is_scalar(Some(value)));
                        let escaped = scalar.map(|value| {
                            json!(formatter::display_text(&php::to_string(Some(value))))
                        });
                        output::format_status(escaped.as_ref())
                    } else {
                        escaped
                            .as_ref()
                            .map(|value| php::to_string(Some(value)))
                            .unwrap_or_else(|| if field == "name" { "" } else { "-" }.into())
                    }
                })
                .collect()
        })
        .collect::<Vec<Vec<String>>>();
    formatter::writeln_out(&output::render_table(
        &["Namespace", "Description", "Retention (days)", "Status"],
        &rows,
    ));
    Ok(0)
}

fn nonempty_scalar(value: Option<&Value>) -> bool {
    php::is_scalar(value) && !php::trim(&php::to_string(value)).is_empty()
}
