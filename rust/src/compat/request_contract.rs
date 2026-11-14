//! `ControlPlaneRequestContract`: the Server-published request contract
//! (`control_plane.request_contract`) that names canonical option values and
//! the aliases the Server rejects.

use crate::php;
use serde_json::Value;

pub const SCHEMA: &str = "durable-workflow.v2.control-plane-request.contract";
pub const VERSION: i64 = 1;

/// A validated `control_plane.request_contract` manifest.
#[derive(Debug, Clone)]
pub struct RequestContract {
    operations: Value,
    schema: String,
    version: i64,
}

impl RequestContract {
    /// `ControlPlaneRequestContract::fromClusterInfo`; the error is the text
    /// of `compatibilityErrorFromClusterInfo`.
    pub fn from_cluster_info(info: &Value) -> Result<Self, String> {
        php::get(info, "control_plane")
            .filter(|control_plane| php::is_array(control_plane))
            .and_then(|control_plane| php::get(control_plane, "request_contract"))
            .and_then(Self::from_published_manifest)
            .ok_or_else(|| compatibility_error_from_cluster_info(info))
    }

    fn from_published_manifest(contract: &Value) -> Option<Self> {
        if !php::is_array(contract) {
            return None;
        }
        let schema =
            php::as_str(php::get(contract, "schema")).filter(|schema| *schema == SCHEMA)?;
        let version = php::int_cast(php::get(contract, "version"));
        let operations = php::get(contract, "operations").filter(|value| php::is_array(value))?;
        (version == VERSION).then(|| Self {
            operations: operations.clone(),
            schema: schema.to_string(),
            version,
        })
    }

    pub fn schema(&self) -> &str {
        &self.schema
    }

    pub fn version(&self) -> i64 {
        self.version
    }

    /// The `operations` map, as `manifest()` and `operations()` return it.
    pub fn operations(&self) -> &Value {
        &self.operations
    }

    /// `assertCanonicalValue`: rejects a declared alias or a value outside a
    /// declared canonical set. `option_name` (such as `--duplicate-policy`)
    /// replaces the field name in the message.
    pub fn assert_canonical_value(
        &self,
        operation: &str,
        field: &str,
        value: Option<&str>,
        option_name: Option<&str>,
    ) -> Result<(), String> {
        let Some(value) = value.filter(|value| !php::trim(value).is_empty()) else {
            return Ok(());
        };
        let Some(definition) = self.field_definition(operation, field) else {
            return Ok(());
        };
        let display = option_name.unwrap_or(field);

        if let Some(replacement) = rejected_alias(php::get(definition, "rejected_aliases"), value) {
            return Err(format!(
                "Server contract rejects {display} value [{value}]; use [{replacement}]."
            ));
        }

        let canonical = string_list(php::get(definition, "canonical_values"));
        if canonical.is_empty() || canonical.contains(&value) {
            return Ok(());
        }
        Err(format!(
            "Server contract expects {display} to be one of [{}]; got [{value}].",
            canonical.join(", ")
        ))
    }

    fn field_definition(&self, operation: &str, field: &str) -> Option<&Value> {
        let operation =
            php::get(&self.operations, operation).filter(|value| php::is_array(value))?;
        let fields = php::get(operation, "fields").filter(|value| php::is_array(value))?;
        php::get(fields, field).filter(|value| php::is_array(value))
    }
}

/// `stringMap()` lookup: string keys and values, both trimmed; integer-like
/// keys are integers in PHP and are skipped. The last duplicate wins.
fn rejected_alias<'a>(aliases: Option<&'a Value>, value: &str) -> Option<&'a str> {
    let Some(Value::Object(map)) = aliases else {
        return None;
    };
    map.iter()
        .filter(|(key, _)| php::int_key(key).is_none() && !php::trim(key).is_empty())
        .filter_map(|(key, item)| {
            let item = item
                .as_str()
                .map(php::trim)
                .filter(|item| !item.is_empty())?;
            Some((php::trim(key), item))
        })
        .rfind(|(key, _)| *key == value)
        .map(|(_, item)| item)
}

/// `stringList()`: trimmed non-empty strings.
fn string_list(value: Option<&Value>) -> Vec<&str> {
    value
        .filter(|value| php::is_array(value))
        .map(|value| {
            php::values(value)
                .into_iter()
                .filter_map(Value::as_str)
                .map(php::trim)
                .filter(|item| !item.is_empty())
                .collect()
        })
        .unwrap_or_default()
}

/// `ControlPlaneRequestContract::compatibilityErrorFromClusterInfo`.
pub fn compatibility_error_from_cluster_info(info: &Value) -> String {
    let contract = php::array_get(php::get(info, "control_plane"), "request_contract");
    compatibility_error(contract)
}

fn compatibility_error(contract: Option<&Value>) -> String {
    let expected = format!("{SCHEMA} v{VERSION}");
    let Some(contract) = contract.filter(|value| php::is_array(value)) else {
        return format!(
            "Server compatibility error: missing control_plane.request_contract; expected {expected}."
        );
    };
    let schema = php::as_str(php::get(contract, "schema"));
    let version = php::get(contract, "version");

    let version_is_digits = php::is_int(version) || php::ctype_digit(&php::to_string(version));
    let Some(schema) = schema.filter(|schema| !php::trim(schema).is_empty() && version_is_digits)
    else {
        return format!(
            "Server compatibility error: invalid control_plane.request_contract metadata; expected {expected}."
        );
    };
    if schema != SCHEMA || php::int_cast(version) != VERSION {
        return format!(
            "Server compatibility error: unsupported control_plane.request_contract schema/version [{schema} v{}]; expected {expected}.",
            php::to_string(version)
        );
    }
    if !php::is_array_opt(php::get(contract, "operations")) {
        return format!(
            "Server compatibility error: invalid control_plane.request_contract.operations payload; expected {expected}."
        );
    }
    format!("Server compatibility error: invalid control_plane.request_contract payload; expected {expected}.")
}
