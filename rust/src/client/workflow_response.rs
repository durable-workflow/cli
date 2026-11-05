//! The existing PHP workflow-list response boundary. The SDK owns HTTP;
//! the CLI preserves its published envelope checks and field projection.

use crate::{compat, php};
use serde_json::{Map, Value};

const PATH: &str = "/workflows";

fn text(value: Option<&Value>) -> Option<&str> {
    value
        .and_then(Value::as_str)
        .map(php::trim)
        .filter(|value| !value.is_empty())
}

fn require_text(value: Option<&Value>, message: &str) -> Result<(), String> {
    text(value)
        .map(|_| ())
        .ok_or_else(|| format!("Server error: {message} for [{PATH}]."))
}

fn require_version(value: Option<&Value>, message: &str) -> Result<(), String> {
    if (php::is_int(value) || php::as_str(value).is_some_and(php::ctype_digit))
        && php::int_cast(value) > 0
    {
        Ok(())
    } else {
        Err(format!("Server error: {message} for [{PATH}]."))
    }
}

fn fields(definition: &Value, name: &str) -> Result<Vec<String>, String> {
    let values = php::get(definition, name).filter(|value| php::is_array(value)).ok_or_else(|| {
        format!("Server error: control-plane contract definition for [{PATH}] is missing required field [{name}].")
    })?;
    php::values(values).into_iter().map(|value| {
        text(Some(value)).map(str::to_owned).ok_or_else(|| {
            format!("Server error: control-plane contract definition for [{PATH}] contains an invalid [{name}] entry.")
        })
    }).collect()
}

fn legacy_fields(definition: &Value, body: &Map<String, Value>) -> Result<(), String> {
    let fields = php::get(definition, "legacy_fields").filter(|value| php::is_array(value)).ok_or_else(|| {
        format!("Server error: control-plane contract definition for [{PATH}] is missing required field [legacy_fields].")
    })?;
    let fields = php::array_map(fields.clone());
    // PHP validates the complete mapping before inspecting any response key.
    for (name, value) in &fields {
        if php::int_key(name).is_some() || php::trim(name).is_empty() || text(Some(value)).is_none()
        {
            return Err(format!("Server error: control-plane contract definition for [{PATH}] contains an invalid legacy field mapping."));
        }
    }
    for (name, value) in fields {
        if body.contains_key(&name) {
            return Err(format!("Server error: non-canonical control-plane field [{name}] received for [{PATH}]; expected [{}].", value.as_str().unwrap()));
        }
    }
    Ok(())
}

fn matching_field(
    contract: &Value,
    body: &Map<String, Value>,
    field: &str,
    value: Option<&Value>,
) -> Result<(), String> {
    let contract_value = text(value.or_else(|| php::get(contract, field)));
    let body_value = text(body.get(field));
    if let (Some(expected), Some(actual)) = (contract_value, body_value) {
        if expected != actual {
            return Err(format!("Server error: control-plane contract for [{PATH}] reported [{field}={expected}], but the response body reported [{field}={actual}]."));
        }
    }
    Ok(())
}

fn validate(
    contract: &Value,
    definition: &Value,
    body: &Map<String, Value>,
    status: u16,
) -> Result<(), String> {
    require_text(
        php::get(contract, "schema"),
        "invalid control-plane contract schema",
    )?;
    require_version(
        php::get(contract, "version"),
        "invalid control-plane contract version",
    )?;
    require_text(
        php::get(contract, "operation"),
        "control-plane contract is missing required field [operation]",
    )?;
    for field in fields(definition, "required_fields")? {
        if text(php::get(contract, &field)).is_none() {
            return Err(format!("Server error: control-plane contract for [{PATH}] is missing required field [{field}]."));
        }
    }
    for field in ["workflow_id", "run_id", "update_id"] {
        matching_field(contract, body, field, None)?;
    }
    if let Some(field) = text(php::get(contract, "operation_name_field")) {
        matching_field(
            &Value::Null,
            body,
            field,
            php::get(contract, "operation_name"),
        )?;
    }
    if status < 400 {
        for field in fields(definition, "success_fields")? {
            if !php::has_key(contract, &field) {
                return Err(format!("Server error: control-plane contract for [{PATH}] is missing required field [{field}]."));
            }
        }
    }
    Ok(())
}

pub fn normalize(body: Value, version: Option<&str>, status: u16) -> Result<Value, String> {
    let expected = compat::control_plane_version();
    let version = version.map(php::trim).filter(|value| !value.is_empty());
    if version != Some(expected.as_str()) {
        return Err(format!("Server error: invalid control-plane response version for [{PATH}]; expected [{expected}], got [{}].", version.unwrap_or("missing")));
    }
    let mut body = php::array_map(body);
    let contract = body.get("control_plane").filter(|value| php::is_array(value)).cloned().ok_or_else(|| {
        format!("Server error: control-plane response for [{PATH}] is missing the shared control-plane contract.")
    })?;
    let definition = php::get(&contract, "contract").filter(|value| php::is_array(value)).ok_or_else(|| {
        format!("Server error: control-plane response for [{PATH}] is missing the shared control-plane contract definition.")
    })?;
    require_text(
        php::get(definition, "schema"),
        "invalid nested control-plane contract schema",
    )?;
    require_version(
        php::get(definition, "version"),
        "invalid nested control-plane contract version",
    )?;
    require_text(
        php::get(definition, "legacy_field_policy"),
        "invalid nested control-plane legacy field policy",
    )?;
    legacy_fields(definition, &body)?;
    validate(&contract, definition, &body, status)?;
    for (name, field) in [
        ("control_plane_schema", "schema"),
        ("control_plane_schema_version", "version"),
        ("control_plane_operation", "operation"),
    ] {
        if body.get(name).is_none_or(Value::is_null) {
            body.insert(
                name.into(),
                php::get(&contract, field).cloned().unwrap_or(Value::Null),
            );
        }
    }
    for (name, value) in php::array_map(contract.clone()) {
        if !matches!(name.as_str(), "schema" | "version" | "contract") {
            body.entry(name).or_insert(value);
        }
    }
    if let Some(field) = text(php::get(&contract, "operation_name_field")) {
        if let Some(name) = php::raw_get(&contract, "operation_name") {
            body.entry(field).or_insert_with(|| name.clone());
        }
    }
    if status < 400 && text(php::get(&contract, "operation")) == Some("update") {
        let update_id = text(body.get("update_id")).or_else(|| text(php::get(&contract, "update_id")))
            .ok_or_else(|| format!("Server error: successful workflow update response for [{PATH}] is missing required field [update_id]."))?.to_owned();
        body.insert("update_id".into(), Value::String(update_id));
    }
    if body.get("control_plane_version").is_none_or(Value::is_null) {
        body.insert("control_plane_version".into(), Value::String(expected));
    }
    Ok(Value::Object(body))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    #[ignore = "needs DW_COMPAT_PHP_VECTORS from the PHP vector generator"]
    fn workflow_list_response_matches_php() {
        let path = std::env::var("DW_COMPAT_PHP_VECTORS").expect("DW_COMPAT_PHP_VECTORS");
        let vectors: Value = serde_json::from_slice(&std::fs::read(path).unwrap()).unwrap();
        let cases = vectors["workflow_list_response"].as_array().unwrap();
        assert!(cases.len() >= 250);
        for case in cases {
            let actual = normalize(
                case["body"].clone(),
                Some("2"),
                case["status"].as_u64().unwrap() as u16,
            );
            match actual {
                Ok(body) => {
                    assert!(
                        case["error"].is_null(),
                        "case {}: expected error {}",
                        case["case"],
                        case["error"]
                    );
                    assert_eq!(
                        php::json_encode(&body),
                        case["result_json"].as_str().unwrap(),
                        "case {}",
                        case["case"]
                    );
                }
                Err(error) => assert_eq!(
                    Some(error.as_str()),
                    case["error"].as_str(),
                    "case {}",
                    case["case"]
                ),
            }
        }
    }
}
