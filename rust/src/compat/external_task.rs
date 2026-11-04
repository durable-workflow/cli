//! `ExternalTaskInputContract::warning` and `ExternalTaskResultContract::warning`:
//! the worker-protocol fixture manifests a Server publishes for external
//! task carriers, including the embedded example checksums.

use super::{input_envelope, manifest_check, sha256_hex};
use crate::php;
use serde_json::json;
use serde_json::Value;

const VERSION: i64 = 1;

const INPUT_CONTRACT_SCHEMA: &str = "durable-workflow.v2.external-task-input.contract";
const INPUT_ENVELOPE_SCHEMA: &str = "durable-workflow.v2.external-task-input";
const INPUT_MEDIA_TYPE: &str = "application/vnd.durable-workflow.external-task-input+json";
const INPUT_FIXTURES: [(&str, &str); 2] = [
    (
        "workflow_task",
        "durable-workflow.v2.external-task-input.workflow-task.v1",
    ),
    (
        "activity_task",
        "durable-workflow.v2.external-task-input.activity-task.v1",
    ),
];

const RESULT_CONTRACT_SCHEMA: &str = "durable-workflow.v2.external-task-result.contract";
const RESULT_ENVELOPE_SCHEMA: &str = "durable-workflow.v2.external-task-result";
const RESULT_MEDIA_TYPE: &str = "application/vnd.durable-workflow.external-task-result+json";
const RESULT_FIXTURES: [(&str, &str); 8] = [
    (
        "success",
        "durable-workflow.v2.external-task-result.success.v1",
    ),
    (
        "failure",
        "durable-workflow.v2.external-task-result.failure.v1",
    ),
    (
        "malformed_output",
        "durable-workflow.v2.external-task-result.malformed-output.v1",
    ),
    (
        "cancellation",
        "durable-workflow.v2.external-task-result.cancellation.v1",
    ),
    (
        "handler_crash",
        "durable-workflow.v2.external-task-result.handler-crash.v1",
    ),
    (
        "decode_failure",
        "durable-workflow.v2.external-task-result.decode-failure.v1",
    ),
    (
        "unsupported_payload_codec",
        "durable-workflow.v2.external-task-result.unsupported-payload-codec.v1",
    ),
    (
        "unsupported_payload_reference",
        "durable-workflow.v2.external-task-result.unsupported-payload-reference.v1",
    ),
];

const FAILURE_KINDS: [&str; 7] = [
    "application",
    "timeout",
    "cancellation",
    "malformed_output",
    "handler_crash",
    "decode_failure",
    "unsupported_payload",
];

const FAILURE_CLASSIFICATIONS: [&str; 9] = [
    "application_error",
    "timeout",
    "cancelled",
    "deadline_exceeded",
    "handler_crash",
    "decode_failure",
    "malformed_output",
    "unsupported_payload_codec",
    "unsupported_payload_reference",
];

/// One published fixture contract under `worker_protocol`.
struct Contract {
    key: &'static str,
    schema: &'static str,
    validate: fn(&Value) -> Vec<String>,
}

const INPUT: Contract = Contract {
    key: "external_task_input_contract",
    schema: INPUT_CONTRACT_SCHEMA,
    validate: validate_input_manifest,
};

const RESULT: Contract = Contract {
    key: "external_task_result_contract",
    schema: RESULT_CONTRACT_SCHEMA,
    validate: validate_result_manifest,
};

/// `ExternalTaskInputContract::warning`.
pub fn input_warning(cluster_info: &Value) -> Option<String> {
    contract_warning(cluster_info, &INPUT)
}

/// `ExternalTaskResultContract::warning`.
pub fn result_warning(cluster_info: &Value) -> Option<String> {
    contract_warning(cluster_info, &RESULT)
}

/// The same fixture summary `doctor` exposes in 2.x, retaining the
/// Server's fixture order and validating the original manifest.
fn diagnostic(cluster_info: &Value, contract: &Contract, names: &[(&str, &str)]) -> Value {
    let Some(manifest) = php::array_get(php::get(cluster_info, "worker_protocol"), contract.key)
        .filter(|value| php::is_array(value))
    else {
        return Value::Null;
    };
    let fixtures: Vec<_> = php::get(manifest, "fixtures")
        .filter(|value| php::is_array(value))
        .map(php::entries)
        .unwrap_or_default()
        .into_iter()
        .map(|(key, _)| key.text())
        .filter(|key| names.iter().any(|(name, _)| *name == key))
        .collect();
    json!({
        "schema": php::get(manifest, "schema").filter(|value| php::is_scalar(Some(value)))
            .map(|value| php::to_string(Some(value))),
        "version": php::get(manifest, "version").filter(|value| php::is_scalar(Some(value)))
            .map(|value| php::int_cast(Some(value))),
        "fixtures": fixtures,
        "valid": (contract.validate)(manifest).is_empty(),
    })
}

pub fn input_diagnostic(cluster_info: &Value) -> Value {
    let mut summary = diagnostic(cluster_info, &INPUT, &INPUT_FIXTURES);
    if !summary.is_null() {
        let scope = &cluster_info["worker_protocol"][INPUT.key]["scope"];
        // Insert the scope fields before valid, matching the PHP summary.
        let valid = summary
            .as_object_mut()
            .expect("diagnostic object")
            .remove("valid");
        summary["activity_grade_fixture_keys"] = json!(scope_fixture_keys(
            scope,
            "activity_grade_external_execution"
        ));
        summary["worker_protocol_fixture_keys"] =
            json!(scope_fixture_keys(scope, "worker_protocol_runtime"));
        summary["valid"] = valid.unwrap_or(Value::Bool(false));
    }
    summary
}

pub fn result_diagnostic(cluster_info: &Value) -> Value {
    diagnostic(cluster_info, &RESULT, &RESULT_FIXTURES)
}

fn contract_warning(cluster_info: &Value, contract: &Contract) -> Option<String> {
    if !server_requires(cluster_info, contract.key) && !has_published(cluster_info, contract.key) {
        return None;
    }
    let label = format!("worker_protocol.{}", contract.key);
    let manifest = php::array_get(php::get(cluster_info, "worker_protocol"), contract.key);
    let manifest = match manifest_check(manifest, &label, contract.schema, VERSION) {
        Ok(manifest) => manifest,
        Err(warning) => return Some(warning),
    };
    (contract.validate)(manifest).first().map(|error| {
        format!(
            "Compatibility warning: server {label} is missing consumable fixture artifact coverage: {error}."
        )
    })
}

fn has_published(cluster_info: &Value, key: &str) -> bool {
    php::get(cluster_info, "worker_protocol").is_some_and(|worker_protocol| {
        php::is_array(worker_protocol) && php::has_key(worker_protocol, key)
    })
}

/// The Server lists the contract under `required_protocols.worker_protocol`
/// or in the CLI's `requires`.
fn server_requires(cluster_info: &Value, key: &str) -> bool {
    let compatibility = php::get(cluster_info, "client_compatibility");
    if !php::is_array_opt(compatibility) {
        return false;
    }
    let required = php::array_get(compatibility, "required_protocols");
    let worker_protocol = php::array_get(required, "worker_protocol");
    if worker_protocol.is_some_and(|value| php::is_array(value) && php::has_key(value, key)) {
        return true;
    }
    let prefix = format!("worker_protocol.{key}");
    cli_requires_prefix(compatibility, &prefix)
}

/// Whether `client_compatibility.clients.cli.requires` lists a string
/// starting with `prefix`.
pub fn cli_requires_prefix(compatibility: Option<&Value>, prefix: &str) -> bool {
    let cli = php::array_get(php::array_get(compatibility, "clients"), "cli");
    php::array_get(cli, "requires")
        .filter(|requires| php::is_array(requires))
        .is_some_and(|requires| {
            php::values(requires)
                .into_iter()
                .any(|item| item.as_str().is_some_and(|text| text.starts_with(prefix)))
        })
}

/// `ExternalTaskInputContract::validateManifest`.
pub fn validate_input_manifest(manifest: &Value) -> Vec<String> {
    let Some(fixtures) = fixtures_of(manifest) else {
        return vec!["fixtures must be an object keyed by artifact role".to_string()];
    };
    let mut errors = validate_scope(manifest);
    for (name, artifact_name) in INPUT_FIXTURES {
        match php::get(fixtures, name).filter(|artifact| php::is_array(artifact)) {
            Some(artifact) => errors.extend(validate_input_artifact(name, artifact_name, artifact)),
            None => errors.push(format!("missing fixture [{name}]")),
        }
    }
    errors
}

/// `ExternalTaskResultContract::validateManifest`.
pub fn validate_result_manifest(manifest: &Value) -> Vec<String> {
    let Some(fixtures) = fixtures_of(manifest) else {
        return vec!["fixtures must be an object keyed by artifact role".to_string()];
    };
    let mut errors = Vec::new();
    for (name, artifact_name) in RESULT_FIXTURES {
        match php::get(fixtures, name).filter(|artifact| php::is_array(artifact)) {
            Some(artifact) => {
                errors.extend(validate_result_artifact(name, artifact_name, artifact))
            }
            None => errors.push(format!("missing fixture [{name}]")),
        }
    }
    errors
}

fn fixtures_of(manifest: &Value) -> Option<&Value> {
    php::get(manifest, "fixtures").filter(|fixtures| php::is_array(fixtures))
}

fn validate_scope(manifest: &Value) -> Vec<String> {
    let Some(scope) = php::get(manifest, "scope").filter(|scope| php::is_array(scope)) else {
        return vec![
            "scope must describe activity_grade_external_execution and worker_protocol_runtime"
                .to_string(),
        ];
    };
    let mut errors = Vec::new();
    if !scope_fixture_keys(scope, "activity_grade_external_execution").contains(&"activity_task") {
        errors.push(
            "scope.activity_grade_external_execution must include fixture [activity_task]"
                .to_string(),
        );
    }
    if !scope_fixture_keys(scope, "worker_protocol_runtime").contains(&"workflow_task") {
        errors
            .push("scope.worker_protocol_runtime must include fixture [workflow_task]".to_string());
    }
    errors
}

fn scope_fixture_keys<'a>(scope: &'a Value, scope_name: &str) -> Vec<&'a str> {
    php::array_get(php::get(scope, scope_name), "fixture_keys")
        .filter(|keys| php::is_array(keys))
        .map(|keys| {
            php::values(keys)
                .into_iter()
                .filter_map(Value::as_str)
                .collect()
        })
        .unwrap_or_default()
}

/// The envelope a fixture artifact must declare.
struct ArtifactSpec<'a> {
    name: &'a str,
    artifact_name: &'a str,
    media_type: &'a str,
    envelope_schema: &'a str,
    display: fn(Option<&Value>) -> String,
}

/// Checks shared by both contracts; returns the embedded example when there
/// is one.
fn check_artifact<'a>(
    spec: &ArtifactSpec<'_>,
    artifact: &'a Value,
    errors: &mut Vec<String>,
) -> Option<&'a Value> {
    let name = spec.name;
    let field = |key: &str| php::get(artifact, key);
    if php::as_str(field("artifact")) != Some(spec.artifact_name) {
        errors.push(format!(
            "fixture [{name}] has artifact [{}]",
            (spec.display)(field("artifact"))
        ));
    }
    if php::as_str(field("media_type")) != Some(spec.media_type) {
        errors.push(format!(
            "fixture [{name}] has media type [{}]",
            (spec.display)(field("media_type"))
        ));
    }
    if php::as_str(field("schema")) != Some(spec.envelope_schema)
        || !php::version_matches(field("version"), VERSION)
    {
        errors.push(format!(
            "fixture [{name}] has unsupported envelope schema/version"
        ));
    }
    let Some(example) = field("example").filter(|example| php::is_array(example)) else {
        errors.push(format!("fixture [{name}] is missing an embedded example"));
        return None;
    };
    let checksum = sha256_hex(php::json_encode(example).as_bytes());
    if php::as_str(field("sha256")) != Some(checksum.as_str()) {
        errors.push(format!(
            "fixture [{name}] sha256 does not match embedded example"
        ));
    }
    Some(example)
}

fn validate_input_artifact(name: &str, artifact_name: &str, artifact: &Value) -> Vec<String> {
    let spec = ArtifactSpec {
        name,
        artifact_name,
        media_type: INPUT_MEDIA_TYPE,
        envelope_schema: INPUT_ENVELOPE_SCHEMA,
        display: display_input,
    };
    let mut errors = Vec::new();
    let Some(example) = check_artifact(&spec, artifact, &mut errors) else {
        return errors;
    };
    match input_envelope::parse(example) {
        Ok(kind) if kind != name => {
            errors.push(format!("fixture [{name}] example has task kind [{kind}]"))
        }
        Ok(_) => {}
        Err(message) => errors.push(format!("fixture [{name}] example is invalid: {message}")),
    }
    errors
}

/// `ExternalTaskInputContract::display`: non-scalars show their PHP type.
fn display_input(value: Option<&Value>) -> String {
    match value {
        None => "missing".to_string(),
        Some(_) if php::is_scalar(value) => php::to_string(value),
        Some(_) => "array".to_string(),
    }
}

fn validate_result_artifact(name: &str, artifact_name: &str, artifact: &Value) -> Vec<String> {
    let spec = ArtifactSpec {
        name,
        artifact_name,
        media_type: RESULT_MEDIA_TYPE,
        envelope_schema: RESULT_ENVELOPE_SCHEMA,
        display: php::scalar_or_missing,
    };
    let mut errors = Vec::new();
    if let Some(example) = check_artifact(&spec, artifact, &mut errors) {
        errors.extend(validate_result_example(name, example));
    }
    errors
}

fn validate_result_example(name: &str, example: &Value) -> Vec<String> {
    let mut errors = Vec::new();
    let field = |key: &str| php::get(example, key);
    if php::as_str(field("schema")) != Some(RESULT_ENVELOPE_SCHEMA)
        || !php::version_matches(field("version"), VERSION)
    {
        errors.push(format!(
            "fixture [{name}] example has unsupported envelope schema/version"
        ));
    }
    let Some(outcome) = field("outcome").filter(|outcome| php::is_array(outcome)) else {
        errors.push(format!(
            "fixture [{name}] example is missing outcome object"
        ));
        return errors;
    };
    let status = php::as_str(php::get(outcome, "status"));
    if name == "success" {
        if status != Some("succeeded") || !php::has_key(example, "result") {
            errors.push("fixture [success] must be a succeeded envelope with result".to_string());
        }
        return errors;
    }
    let failure = field("failure").filter(|failure| php::is_array(failure));
    match failure {
        Some(failure) if status == Some("failed") => {
            errors.extend(validate_failure(name, failure));
        }
        _ => errors.push(format!(
            "fixture [{name}] must be a failed envelope with failure object"
        )),
    }
    errors
}

fn validate_failure(name: &str, failure: &Value) -> Vec<String> {
    let mut errors = Vec::new();
    let kind = php::get(failure, "kind");
    if !php::as_str(kind).is_some_and(|kind| FAILURE_KINDS.contains(&kind)) {
        errors.push(format!(
            "fixture [{name}] has unsupported failure kind [{}]",
            php::scalar_or_missing(kind)
        ));
    }
    let classification = php::get(failure, "classification");
    if !php::as_str(classification).is_some_and(|value| FAILURE_CLASSIFICATIONS.contains(&value)) {
        errors.push(format!(
            "fixture [{name}] has unsupported failure classification [{}]",
            php::scalar_or_missing(classification)
        ));
    }
    errors
}
