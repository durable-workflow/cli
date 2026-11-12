//! Server compatibility checks of the 2.x CLI: the soft warnings printed
//! before the first server command (`CompatibilityDiagnostics::warnings`),
//! the hard refusal before an operation (`ServerClient::assertServerCompatibility`,
//! surfaced as a `CompatibilityException`), and the Server-published request
//! contract (`ControlPlaneRequestContract`).
//!
//! Every message is byte-identical to 2.x. Cluster info is read with PHP's
//! decoded-JSON semantics (see `php`), because 2.x interpolates raw Server
//! values into its messages.

mod external_task;
mod input_envelope;
mod request_contract;
pub mod version;

pub use external_task::{input_diagnostic, result_diagnostic};
pub use request_contract::RequestContract;

use crate::php;
use serde_json::{Map, Value};

/// Control-plane version this CLI sends.
pub const CONTROL_PLANE_VERSION: &str = "2";
/// Worker-protocol version worker commands send.
pub const WORKER_PROTOCOL_VERSION: &str = "1.0";
/// Overrides the control-plane version the client sends (conformance runs).
pub const CONTROL_PLANE_VERSION_ENV: &str = "DURABLE_WORKFLOW_CONTROL_PLANE_VERSION";
/// Overrides the worker-protocol version the client sends.
pub const WORKER_PROTOCOL_VERSION_ENV: &str = "DURABLE_WORKFLOW_WORKER_PROTOCOL_VERSION";
/// `CompatibilityDiagnostics::NEXT_STEP`.
pub const NEXT_STEP: &str =
    "Upgrade dw, pin dw to a supported release, or connect to a compatible server.";
/// What 2.x appends to the first warning when it prints it.
pub const WARNING_SUFFIX: &str = " Run `dw doctor` for details.";

const AUTH_COMPOSITION_SCHEMA: &str = "durable-workflow.v2.auth-composition.contract";
const AUTH_COMPOSITION_VERSION: i64 = 1;

/// The control-plane version to send: the env override when set and not
/// blank (trimmed), else `CONTROL_PLANE_VERSION`.
pub fn control_plane_version() -> String {
    protocol_version_from_env(CONTROL_PLANE_VERSION_ENV, CONTROL_PLANE_VERSION)
}

/// The worker-protocol version to send, with the same override rules.
pub fn worker_protocol_version() -> String {
    protocol_version_from_env(WORKER_PROTOCOL_VERSION_ENV, WORKER_PROTOCOL_VERSION)
}

fn protocol_version_from_env(name: &str, default: &str) -> String {
    std::env::var(name)
        .ok()
        .map(|value| php::trim(&value).to_string())
        .filter(|value| !value.is_empty())
        .unwrap_or_else(|| default.to_string())
}

/// `ServerClient::isCompatibleWorkerProtocolVersion`: same major and a
/// Server minor at least the client's. Reserved for the worker commands'
/// response checks.
#[allow(dead_code)]
pub fn is_compatible_worker_protocol_version(client: &str, server: &str) -> bool {
    version::is_compatible_worker_protocol(client, server)
}

/// `CompatibilityDiagnostics::warnings`. Callers print only the first, with
/// `WARNING_SUFFIX` appended.
pub fn warnings(
    cluster_info: &Value,
    cli_version: &str,
    include_worker_protocol: bool,
) -> Vec<String> {
    let mut checks = vec![
        control_plane_warning(cluster_info),
        request_contract_warning(cluster_info),
        auth_composition_warning(cluster_info),
        external_task::input_warning(cluster_info),
        external_task::result_warning(cluster_info),
        client_compatibility_authority_warning(cluster_info),
        cli_version_support_warning(cluster_info, cli_version),
    ];
    if include_worker_protocol {
        checks.push(worker_protocol_warning(cluster_info));
    }
    checks.into_iter().flatten().collect()
}

/// Checks a versioned `{schema, version}` manifest. Returns the manifest when
/// it matches, else the 2.x warning naming `label`.
fn manifest_check<'a>(
    manifest: Option<&'a Value>,
    label: &str,
    schema: &str,
    version: i64,
) -> Result<&'a Value, String> {
    let expected = format!("{schema} v{version}");
    let Some(manifest) = manifest.filter(|value| php::is_array(value)) else {
        return Err(format!(
            "Compatibility warning: server did not advertise {label}; dw CLI expects {expected}."
        ));
    };
    let advertised_schema = php::get(manifest, "schema");
    let advertised_version = php::get(manifest, "version");
    if php::as_str(advertised_schema) != Some(schema)
        || !php::version_matches(advertised_version, version)
    {
        return Err(format!(
            "Compatibility warning: server advertises {label} [{} v{}]; dw CLI expects {expected}.",
            php::scalar_or_missing(advertised_schema),
            php::scalar_or_missing(advertised_version),
        ));
    }
    Ok(manifest)
}

fn control_plane_warning(cluster_info: &Value) -> Option<String> {
    let Some(control_plane) = array_field(cluster_info, "control_plane") else {
        return Some(format!(
            "Compatibility warning: server did not advertise control_plane.version; dw CLI expects {CONTROL_PLANE_VERSION}."
        ));
    };
    let version = php::get(control_plane, "version");
    if trimmed_scalar_is(version, CONTROL_PLANE_VERSION) {
        return None;
    }
    Some(format!(
        "Compatibility warning: server advertises control_plane.version [{}]; dw CLI expects {CONTROL_PLANE_VERSION}.",
        php::scalar_or_missing(version)
    ))
}

fn request_contract_warning(cluster_info: &Value) -> Option<String> {
    let control_plane = array_field(cluster_info, "control_plane")?;
    manifest_check(
        php::get(control_plane, "request_contract"),
        "control_plane.request_contract",
        request_contract::SCHEMA,
        request_contract::VERSION,
    )
    .err()
}

fn auth_composition_warning(cluster_info: &Value) -> Option<String> {
    let advertised = php::get(cluster_info, "auth_composition_contract");
    if !server_requires_auth_composition(cluster_info) && advertised.is_none() {
        return None;
    }
    manifest_check(
        advertised,
        "auth_composition_contract",
        AUTH_COMPOSITION_SCHEMA,
        AUTH_COMPOSITION_VERSION,
    )
    .err()
}

fn server_requires_auth_composition(cluster_info: &Value) -> bool {
    let Some(compatibility) = array_field(cluster_info, "client_compatibility") else {
        return false;
    };
    let required = php::get(compatibility, "required_protocols");
    if required.is_some_and(|required| {
        php::is_array(required) && php::has_key(required, "auth_composition")
    }) {
        return true;
    }
    external_task::cli_requires_prefix(Some(compatibility), "auth_composition.")
}

fn worker_protocol_warning(cluster_info: &Value) -> Option<String> {
    let Some(worker_protocol) = array_field(cluster_info, "worker_protocol") else {
        return Some(format!(
            "Compatibility warning: server did not advertise worker_protocol.version; worker commands expect {WORKER_PROTOCOL_VERSION}."
        ));
    };
    let version = php::get(worker_protocol, "version");
    if worker_protocol_supported(version, WORKER_PROTOCOL_VERSION) {
        return None;
    }
    Some(format!(
        "Compatibility warning: server advertises worker_protocol.version [{}]; worker commands support protocol {WORKER_PROTOCOL_VERSION} on compatible same-major server minors.",
        php::scalar_or_missing(version)
    ))
}

fn client_compatibility_authority_warning(cluster_info: &Value) -> Option<String> {
    let compatibility = array_field(cluster_info, "client_compatibility")?;
    let authority = php::get(compatibility, "authority");
    if php::as_str(authority) == Some("protocol_manifests") {
        return None;
    }
    Some(format!(
        "Compatibility warning: server client_compatibility authority is [{}]; dw CLI expects protocol_manifests.",
        php::scalar_or_missing(authority)
    ))
}

fn cli_version_support_warning(cluster_info: &Value, cli_version: &str) -> Option<String> {
    // Unlike `cli_supported_versions`, the warning shows the value untrimmed.
    let supported = php::as_str(cli_client_field(cluster_info, "supported_versions"))
        .filter(|supported| !php::trim(supported).is_empty())?;
    if version::matches_constraint(cli_version, supported) {
        return None;
    }
    Some(format!(
        "Compatibility warning: dw {cli_version} is outside server-advertised cli supported_versions [{supported}] for server {}. Compatibility window: {supported}. Next step: upgrade dw, pin dw to a supported release, or connect to a compatible server.",
        server_version(cluster_info)
    ))
}

/// `client_compatibility.clients.cli.<key>`, each level required to be an array.
fn cli_client_field<'a>(cluster_info: &'a Value, key: &str) -> Option<&'a Value> {
    let compatibility = array_field(cluster_info, "client_compatibility");
    let cli = php::array_get(php::array_get(compatibility, "clients"), "cli");
    php::array_get(cli, key)
}

/// `$value[$key]` when it is an array.
fn array_field<'a>(value: &'a Value, key: &str) -> Option<&'a Value> {
    php::get(value, key).filter(|field| php::is_array(field))
}

/// `is_scalar($v) && trim((string) $v) === $expected`.
fn trimmed_scalar_is(value: Option<&Value>, expected: &str) -> bool {
    php::is_scalar(value) && php::trim(&php::to_string(value)) == expected
}

fn worker_protocol_supported(version: Option<&Value>, client: &str) -> bool {
    php::is_scalar(version)
        && version::is_compatible_worker_protocol(client, php::trim(&php::to_string(version)))
}

/// `CompatibilityDiagnostics::serverVersion`: `version`, `server_version`,
/// then `server.version`, else `unknown`.
pub fn server_version(cluster_info: &Value) -> String {
    let nested = php::array_get(php::get(cluster_info, "server"), "version");
    [
        php::get(cluster_info, "version"),
        php::get(cluster_info, "server_version"),
        nested,
    ]
    .into_iter()
    .find_map(scalar_string)
    .unwrap_or_else(|| "unknown".to_string())
}

/// `CompatibilityDiagnostics::cliSupportedVersions` (trimmed).
pub fn cli_supported_versions(cluster_info: &Value) -> Option<String> {
    php::as_str(cli_client_field(cluster_info, "supported_versions"))
        .map(|supported| php::trim(supported).to_string())
        .filter(|supported| !supported.is_empty())
}

/// `CompatibilityDiagnostics::cliVersionIsSupported`: true when the Server
/// advertises no window.
pub fn cli_version_is_supported(cluster_info: &Value, cli_version: &str) -> bool {
    cli_supported_versions(cluster_info)
        .is_none_or(|supported| version::matches_constraint(cli_version, &supported))
}

/// `scalarString`: a trimmed, non-empty `(string)` of a scalar.
fn scalar_string(value: Option<&Value>) -> Option<String> {
    if !php::is_scalar(value) {
        return None;
    }
    let text = php::to_string(value);
    let trimmed = php::trim(&text);
    (!trimmed.is_empty()).then(|| trimmed.to_string())
}

fn optional_string(value: Option<String>) -> Value {
    value.map_or(Value::Null, Value::String)
}

/// `CompatibilityDiagnostics::failureDiagnostic`: the object 2.x puts under
/// `compatibility` in the JSON error envelope.
pub fn failure_diagnostic(
    cluster_info: &Value,
    cli_version: &str,
    detail: &str,
    client_control_plane_version: Option<&str>,
    client_worker_protocol_version: Option<&str>,
) -> Map<String, Value> {
    let supported = cli_supported_versions(cluster_info);
    let control_plane_server = protocol_server_version(cluster_info, "control_plane");
    let worker_protocol_server = protocol_server_version(cluster_info, "worker_protocol");
    let window = compatibility_window(
        supported.as_deref(),
        control_plane_server.as_deref(),
        worker_protocol_server.as_deref(),
    );
    let protocol = |client: Option<&str>, server: Option<String>| {
        let mut map = Map::new();
        map.insert(
            "client_version".into(),
            optional_string(client.map(str::to_string)),
        );
        map.insert("server_version".into(), optional_string(server));
        Value::Object(map)
    };

    let mut diagnostic = Map::new();
    diagnostic.insert("cli_version".into(), Value::String(cli_version.to_string()));
    diagnostic.insert(
        "server_version".into(),
        Value::String(server_version(cluster_info)),
    );
    diagnostic.insert("compatibility_window".into(), Value::String(window));
    diagnostic.insert(
        "server_supported_cli_versions".into(),
        optional_string(supported),
    );
    diagnostic.insert(
        "control_plane".into(),
        protocol(client_control_plane_version, control_plane_server),
    );
    diagnostic.insert(
        "worker_protocol".into(),
        protocol(client_worker_protocol_version, worker_protocol_server),
    );
    diagnostic.insert("next_step".into(), Value::String(NEXT_STEP.to_string()));
    diagnostic.insert("detail".into(), Value::String(detail.to_string()));
    diagnostic
}

/// `scalarString($clusterInfo[$protocol]['version'])`, the protocol read
/// only when it is an array.
fn protocol_server_version(cluster_info: &Value, protocol: &str) -> Option<String> {
    scalar_string(php::array_get(php::get(cluster_info, protocol), "version"))
}

fn compatibility_window(
    supported: Option<&str>,
    control_plane: Option<&str>,
    worker_protocol: Option<&str>,
) -> String {
    let mut window = vec![match supported {
        Some(supported) => format!("cli {supported}"),
        None => "server did not advertise cli supported_versions".to_string(),
    }];
    if let Some(version) = control_plane {
        window.push(format!("control-plane version {version}"));
    }
    if let Some(version) = worker_protocol {
        window.push(format!("worker protocol same-major <= {version}"));
    }
    window.join("; ")
}

/// `CompatibilityDiagnostics::failureMessage`.
pub fn failure_message(diagnostic: &Map<String, Value>) -> String {
    let field = |key: &str, fallback: &str| {
        scalar_string(diagnostic.get(key)).unwrap_or_else(|| fallback.to_string())
    };
    format!(
        "Server compatibility error: refusing before the requested operation because dw {} cannot safely interoperate with server {}. Compatibility window: {}. Next step: {} Detail: {}",
        field("cli_version", "unknown"),
        field("server_version", "unknown"),
        field("compatibility_window", "unknown"),
        field("next_step", NEXT_STEP),
        field("detail", "compatibility metadata is incomplete"),
    )
}

/// A 2.x `CompatibilityException`: exit code 8, the diagnostic under
/// `compatibility` in the JSON error envelope, and `next_step` as the
/// message of the `compatibility.unsupported` recommendation.
#[derive(Debug, Clone, PartialEq)]
pub struct CompatibilityFailure {
    pub message: String,
    pub diagnostic: Map<String, Value>,
    pub next_step: String,
}

impl CompatibilityFailure {
    fn new(diagnostic: Map<String, Value>) -> Self {
        let next_step = diagnostic
            .get("next_step")
            .and_then(Value::as_str)
            .filter(|next_step| !next_step.is_empty())
            .unwrap_or(NEXT_STEP)
            .to_string();
        Self {
            message: failure_message(&diagnostic),
            diagnostic,
            next_step,
        }
    }
}

/// True when cluster info advertises `capabilities.<name>: true`.
pub fn advertises_capability(cluster_info: &Value, name: &str) -> bool {
    php::array_get(php::get(cluster_info, "capabilities"), name) == Some(&Value::Bool(true))
}

/// Refuses a command whose Server does not advertise the capability it
/// needs: exit 8 like the other compatibility refusals, the same JSON
/// `compatibility` diagnostic, and the command's own message and next step
/// instead of the version-window ones.
pub fn require_capability(
    cluster_info: &Value,
    cli_version: &str,
    name: &str,
    unsupported: &str,
    next_step: &str,
) -> Result<(), CompatibilityFailure> {
    if advertises_capability(cluster_info, name) {
        return Ok(());
    }
    let detail = format!("server did not advertise capabilities.{name}.");
    let mut diagnostic = failure_diagnostic(
        cluster_info,
        cli_version,
        &detail,
        Some(&control_plane_version()),
        Some(&worker_protocol_version()),
    );
    diagnostic.insert("next_step".into(), Value::String(next_step.to_string()));
    Err(CompatibilityFailure {
        message: format!(
            "Server compatibility error: server {} does not advertise capabilities.{name}, so dw refused before sending the request. {unsupported} Next step: {next_step}",
            server_version(cluster_info)
        ),
        diagnostic,
        next_step: next_step.to_string(),
    })
}

/// `ServerClient::assertServerCompatibility`: the control-plane checks, then
/// the worker-protocol checks when asked, using the env-overridable client
/// versions.
pub fn assert_compatible(
    cluster_info: &Value,
    cli_version: &str,
    include_worker_protocol: bool,
) -> Result<(), CompatibilityFailure> {
    let client = ClientVersions {
        cli: cli_version,
        control_plane: &control_plane_version(),
        worker_protocol: &worker_protocol_version(),
    };
    assert_compatible_with(cluster_info, &client, include_worker_protocol)
}

/// The versions this client identifies with.
struct ClientVersions<'a> {
    cli: &'a str,
    control_plane: &'a str,
    worker_protocol: &'a str,
}

fn assert_compatible_with(
    cluster_info: &Value,
    client: &ClientVersions<'_>,
    include_worker_protocol: bool,
) -> Result<(), CompatibilityFailure> {
    let detail = server_refusal(cluster_info, client).or_else(|| {
        include_worker_protocol
            .then(|| worker_refusal(cluster_info, client.worker_protocol))
            .flatten()
    });
    match detail {
        None => Ok(()),
        Some(detail) => Err(CompatibilityFailure::new(failure_diagnostic(
            cluster_info,
            client.cli,
            &detail,
            Some(client.control_plane),
            Some(client.worker_protocol),
        ))),
    }
}

/// `checkServerCompatibility`: the refusal detail, if any.
fn server_refusal(cluster_info: &Value, client: &ClientVersions<'_>) -> Option<String> {
    let Some(control_plane) = array_field(cluster_info, "control_plane") else {
        return Some(format!(
            "missing control_plane manifest; expected control_plane.version {}.",
            client.control_plane
        ));
    };
    let version = php::get(control_plane, "version");
    if !trimmed_scalar_is(version, client.control_plane) {
        return Some(format!(
            "unsupported control_plane.version [{}]; dw {} sends control_plane.version {}.",
            php::scalar_or_missing(version),
            client.cli,
            client.control_plane
        ));
    }
    if !cli_version_is_supported(cluster_info, client.cli) {
        return Some(format!(
            "dw {} is outside server-advertised cli supported_versions [{}].",
            client.cli,
            cli_supported_versions(cluster_info).unwrap_or_else(|| "unknown".to_string())
        ));
    }
    RequestContract::from_cluster_info(cluster_info).err()
}

/// `checkWorkerProtocolCompatibility`: the refusal detail, if any.
fn worker_refusal(cluster_info: &Value, client_version: &str) -> Option<String> {
    let Some(worker_protocol) = array_field(cluster_info, "worker_protocol") else {
        return Some(format!(
            "server did not advertise worker_protocol.version; worker commands send worker_protocol.version {client_version}."
        ));
    };
    let version = php::get(worker_protocol, "version");
    if worker_protocol_supported(version, client_version) {
        return None;
    }
    Some(format!(
        "unsupported worker_protocol.version [{}]; worker commands send worker_protocol.version {client_version}.",
        php::scalar_or_missing(version)
    ))
}

/// Lowercase hex SHA-256, as PHP's `hash('sha256', ...)`.
pub(crate) fn sha256_hex(data: &[u8]) -> String {
    use sha2::{Digest, Sha256};
    format!("{:x}", Sha256::digest(data))
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    const PHP_SERVER: &str = include_str!("compat/fixtures/cluster-info-php-2.11.8.json");
    const NATIVE_SERVER: &str = include_str!("compat/fixtures/cluster-info-native-3.0.5.json");
    const ACTIVITY_TASK: &str =
        include_str!("compat/fixtures/external-task-input-activity-task.v1.json");
    const WORKFLOW_TASK: &str =
        include_str!("compat/fixtures/external-task-input-workflow-task.v1.json");

    fn parse(raw: &str) -> Value {
        serde_json::from_str(raw).expect("fixture is JSON")
    }

    #[test]
    fn a_capability_counts_only_when_advertised_as_true() {
        let mut info = parse(NATIVE_SERVER);
        for value in [json!(null), json!(false), json!("true"), json!(1)] {
            info["capabilities"] = json!({ "task_queue_activity_dispatch_pause": value });
            assert!(!advertises_capability(
                &info,
                "task_queue_activity_dispatch_pause"
            ));
        }
        info["capabilities"] = json!({ "task_queue_activity_dispatch_pause": true });
        assert!(advertises_capability(
            &info,
            "task_queue_activity_dispatch_pause"
        ));
        assert!(
            require_capability(&info, "2.6.0", "task_queue_activity_dispatch_pause", "", "")
                .is_ok()
        );
    }

    #[test]
    fn a_missing_capability_is_a_compatibility_refusal_with_its_own_next_step() {
        let failure = require_capability(
            &parse(PHP_SERVER),
            "2.6.0",
            "task_queue_activity_dispatch_pause",
            "Needs native.",
            "Use drain.",
        )
        .unwrap_err();
        assert_eq!(
            failure.message,
            "Server compatibility error: server 2.11.8 does not advertise capabilities.task_queue_activity_dispatch_pause, so dw refused before sending the request. Needs native. Next step: Use drain."
        );
        assert_eq!(failure.next_step, "Use drain.");
        assert_eq!(failure.diagnostic["next_step"], json!("Use drain."));
        assert_eq!(
            failure.diagnostic["detail"],
            json!("server did not advertise capabilities.task_queue_activity_dispatch_pause.")
        );
        assert_eq!(failure.diagnostic["server_version"], json!("2.11.8"));
    }

    /// `ServerClientTest::clusterInfo`.
    fn cluster_info(server: &str, supported: &str, control_plane: &str, worker: &str) -> Value {
        json!({
            "server_id": "server-1",
            "version": server,
            "control_plane": {
                "version": control_plane,
                "request_contract": {
                    "schema": request_contract::SCHEMA,
                    "version": 1,
                    "operations": [],
                },
            },
            "worker_protocol": {"version": worker},
            "client_compatibility": {
                "authority": "protocol_manifests",
                "top_level_version_role": "informational",
                "clients": {"cli": {"supported_versions": supported}},
            },
        })
    }

    fn default_cluster_info(server: &str, supported: &str) -> Value {
        cluster_info(server, supported, "2", "1.0")
    }

    fn client(cli: &str) -> ClientVersions<'_> {
        ClientVersions {
            cli,
            control_plane: CONTROL_PLANE_VERSION,
            worker_protocol: WORKER_PROTOCOL_VERSION,
        }
    }

    fn refusal(info: &Value, cli: &str, include_worker: bool) -> CompatibilityFailure {
        assert_compatible_with(info, &client(cli), include_worker).expect_err("refused")
    }

    fn first_warning(info: &Value, include_worker: bool) -> String {
        warnings(info, "0.1.5", include_worker)
            .into_iter()
            .next()
            .expect("a warning")
    }

    fn input_artifact(name: &str, example: Value) -> Value {
        json!({
            "artifact": format!("durable-workflow.v2.external-task-input.{name}"),
            "media_type": "application/vnd.durable-workflow.external-task-input+json",
            "schema": "durable-workflow.v2.external-task-input",
            "version": 1,
            "sha256": sha256_hex(php::json_encode(&example).as_bytes()),
            "example": example,
        })
    }

    /// `ExternalTaskInputContractTest::manifest`.
    fn input_manifest() -> Value {
        json!({
            "schema": "durable-workflow.v2.external-task-input.contract",
            "version": 1,
            "scope": {
                "activity_grade_external_execution": {
                    "task_kinds": ["activity_task"],
                    "fixture_keys": ["activity_task"],
                },
                "worker_protocol_runtime": {
                    "task_kinds": ["workflow_task"],
                    "fixture_keys": ["workflow_task"],
                },
            },
            "fixtures": {
                "workflow_task": input_artifact("workflow-task.v1", parse(WORKFLOW_TASK)),
                "activity_task": input_artifact("activity-task.v1", parse(ACTIVITY_TASK)),
            },
        })
    }

    fn result_example(status: &str, failure: Option<(&str, &str)>) -> Value {
        let mut example = json!({
            "schema": "durable-workflow.v2.external-task-result",
            "version": 1,
            "outcome": {"status": status, "recorded": true},
            "task": {"id": "acttask_1", "kind": "activity_task", "attempt": 1},
            "metadata": {"handler": "billing.charge-card", "duration_ms": 10},
        });
        match failure {
            None => example["result"] = json!({"payload": {"codec": "avro", "blob": "QUJD"}}),
            Some((kind, classification)) => {
                example["failure"] = json!({"kind": kind, "classification": classification})
            }
        }
        example
    }

    /// `ExternalTaskResultContractTest::manifest`.
    fn result_manifest() -> Value {
        let fixtures = [
            ("success", "success", None),
            ("failure", "failure", Some(("timeout", "deadline_exceeded"))),
            (
                "malformed_output",
                "malformed-output",
                Some(("malformed_output", "malformed_output")),
            ),
            (
                "cancellation",
                "cancellation",
                Some(("cancellation", "cancelled")),
            ),
            (
                "handler_crash",
                "handler-crash",
                Some(("handler_crash", "handler_crash")),
            ),
            (
                "decode_failure",
                "decode-failure",
                Some(("decode_failure", "decode_failure")),
            ),
            (
                "unsupported_payload_codec",
                "unsupported-payload-codec",
                Some(("unsupported_payload", "unsupported_payload_codec")),
            ),
            (
                "unsupported_payload_reference",
                "unsupported-payload-reference",
                Some(("unsupported_payload", "unsupported_payload_reference")),
            ),
        ];
        let mut map = Map::new();
        for (name, slug, failure) in fixtures {
            let example = result_example(
                if failure.is_some() {
                    "failed"
                } else {
                    "succeeded"
                },
                failure,
            );
            map.insert(
                name.to_string(),
                json!({
                    "artifact": format!("durable-workflow.v2.external-task-result.{slug}.v1"),
                    "media_type": "application/vnd.durable-workflow.external-task-result+json",
                    "schema": "durable-workflow.v2.external-task-result",
                    "version": 1,
                    "sha256": sha256_hex(php::json_encode(&example).as_bytes()),
                    "example": example,
                }),
            );
        }
        json!({
            "schema": "durable-workflow.v2.external-task-result.contract",
            "version": 1,
            "fixtures": map,
        })
    }

    // Live Server responses (trimmed to the compatibility sections).

    #[test]
    fn current_cli_is_compatible_with_published_servers() {
        for raw in [PHP_SERVER, NATIVE_SERVER] {
            let info = parse(raw);
            for include_worker in [false, true] {
                assert_eq!(
                    warnings(&info, "2.5.0-dev", include_worker),
                    Vec::<String>::new()
                );
                assert_eq!(
                    assert_compatible_with(&info, &client("2.5.0-dev"), include_worker),
                    Ok(())
                );
            }
        }
    }

    #[test]
    fn php_server_refuses_cli_outside_its_window() {
        let info = parse(PHP_SERVER);
        assert_eq!(
            warnings(&info, "3.0.0", true),
            vec!["Compatibility warning: dw 3.0.0 is outside server-advertised cli supported_versions [>=2.0.0,<3.0.0] for server 2.11.8. Compatibility window: >=2.0.0,<3.0.0. Next step: upgrade dw, pin dw to a supported release, or connect to a compatible server."]
        );
        let failure = refusal(&info, "3.0.0", false);
        assert_eq!(
            failure.message,
            "Server compatibility error: refusing before the requested operation because dw 3.0.0 cannot safely interoperate with server 2.11.8. Compatibility window: cli >=2.0.0,<3.0.0; control-plane version 2; worker protocol same-major <= 1.20. Next step: Upgrade dw, pin dw to a supported release, or connect to a compatible server. Detail: dw 3.0.0 is outside server-advertised cli supported_versions [>=2.0.0,<3.0.0]."
        );
        assert_eq!(
            serde_json::to_string(&failure.diagnostic).unwrap(),
            r#"{"cli_version":"3.0.0","server_version":"2.11.8","compatibility_window":"cli >=2.0.0,<3.0.0; control-plane version 2; worker protocol same-major <= 1.20","server_supported_cli_versions":">=2.0.0,<3.0.0","control_plane":{"client_version":"2","server_version":"2"},"worker_protocol":{"client_version":"1.0","server_version":"1.20"},"next_step":"Upgrade dw, pin dw to a supported release, or connect to a compatible server.","detail":"dw 3.0.0 is outside server-advertised cli supported_versions [>=2.0.0,<3.0.0]."}"#
        );
        assert_eq!(failure.next_step, NEXT_STEP);
    }

    #[test]
    fn native_server_publishes_no_cli_window() {
        let info = parse(NATIVE_SERVER);
        assert_eq!(warnings(&info, "3.0.0", true), Vec::<String>::new());
        assert_eq!(
            assert_compatible_with(&info, &client("3.0.0"), true),
            Ok(())
        );
    }

    // CompatibilityDiagnosticsProductTrainTest

    #[test]
    fn product_train_prerelease_floor_rejects_earlier_beta() {
        let info = json!({"client_compatibility": {"clients": {"cli": {"supported_versions": ">=2.0.0-rc.1,<2.0.0"}}}});
        assert!(!cli_version_is_supported(&info, "2.0.0-beta.1"));
        assert!(cli_version_is_supported(&info, "2.0.0-rc.1"));
        assert!(!cli_version_is_supported(&info, "2.0.0-beta.7"));
        assert!(!cli_version_is_supported(&info, "2.0.0"));
    }

    // ServerClientTest

    #[test]
    fn rejects_cli_versions_outside_the_server_advertised_window() {
        let failure = refusal(
            &default_cluster_info("0.2.221", ">=0.2,<1.0"),
            "0.1.5",
            false,
        );
        assert!(failure
            .message
            .contains("dw 0.1.5 cannot safely interoperate with server 0.2.221"));
        assert!(failure.message.contains(
            "Compatibility window: cli >=0.2,<1.0; control-plane version 2; worker protocol same-major <= 1.0."
        ));
        assert!(failure.message.contains("Next step:"));
    }

    #[test]
    fn rejects_worker_commands_when_server_worker_protocol_is_outside_the_window() {
        let info = cluster_info("0.2.221", ">=0.1,<1.0", "2", "2.0");
        assert_eq!(
            assert_compatible_with(&info, &client("0.2.0"), false),
            Ok(())
        );
        let failure = refusal(&info, "0.2.0", true);
        assert!(failure.message.contains("worker_protocol.version [2.0]"));
        assert!(failure.message.contains(
            "Compatibility window: cli >=0.1,<1.0; control-plane version 2; worker protocol same-major <= 2.0."
        ));
    }

    #[test]
    fn rejects_cluster_info_without_control_plane_manifest() {
        let failure = refusal(&json!({"version": "2.0.0"}), "2.5.0-dev", false);
        assert_eq!(
            failure.message,
            "Server compatibility error: refusing before the requested operation because dw 2.5.0-dev cannot safely interoperate with server 2.0.0. Compatibility window: server did not advertise cli supported_versions. Next step: Upgrade dw, pin dw to a supported release, or connect to a compatible server. Detail: missing control_plane manifest; expected control_plane.version 2."
        );
        assert_eq!(
            failure.diagnostic["server_supported_cli_versions"],
            Value::Null
        );
        assert_eq!(
            failure.diagnostic["control_plane"],
            json!({"client_version": "2", "server_version": null})
        );
    }

    #[test]
    fn rejects_unsupported_control_plane_version() {
        let info = cluster_info("2.0.0", ">=2.0.0", "3", "1.0");
        let failure = refusal(&info, "2.5.0-dev", false);
        assert!(failure.message.ends_with(
            "Detail: unsupported control_plane.version [3]; dw 2.5.0-dev sends control_plane.version 2."
        ));
    }

    #[test]
    fn uses_protocol_manifest_not_top_level_app_version_for_compatibility() {
        let info = json!({
            "version": "not-semver",
            "control_plane": {
                "version": "2",
                "request_contract": {"schema": request_contract::SCHEMA, "version": 1, "operations": []},
            },
        });
        assert_eq!(
            assert_compatible_with(&info, &client("2.5.0-dev"), false),
            Ok(())
        );
    }

    #[test]
    fn env_overrides_change_the_client_versions() {
        let info = cluster_info("2.0.0", "2.x", "3", "2.4");
        let overridden = ClientVersions {
            cli: "2.5.0-dev",
            control_plane: "3",
            worker_protocol: "2.0",
        };
        assert_eq!(assert_compatible_with(&info, &overridden, true), Ok(()));
        let failure = refusal(&info, "2.5.0-dev", false);
        assert!(failure.message.ends_with("Detail: unsupported control_plane.version [3]; dw 2.5.0-dev sends control_plane.version 2."));

        std::env::set_var("DW_COMPAT_TEST_PROTOCOL_VERSION", "  3  ");
        assert_eq!(
            protocol_version_from_env("DW_COMPAT_TEST_PROTOCOL_VERSION", "2"),
            "3"
        );
        std::env::set_var("DW_COMPAT_TEST_PROTOCOL_VERSION", " \t");
        assert_eq!(
            protocol_version_from_env("DW_COMPAT_TEST_PROTOCOL_VERSION", "2"),
            "2"
        );
        std::env::remove_var("DW_COMPAT_TEST_PROTOCOL_VERSION");
        assert_eq!(
            protocol_version_from_env("DW_COMPAT_TEST_PROTOCOL_VERSION", "1.0"),
            "1.0"
        );
    }

    fn duplicate_policy_info(request_contract: Value) -> Value {
        json!({"control_plane": {"version": "2", "request_contract": request_contract}})
    }

    #[test]
    fn reads_the_server_published_request_contract() {
        let info = duplicate_policy_info(json!({
            "schema": request_contract::SCHEMA,
            "version": 1,
            "operations": {"start": {"fields": {"duplicate_policy": {
                "canonical_values": ["fail", "use-existing"],
                "rejected_aliases": {"use_existing": "use-existing"},
            }}}},
        }));
        let contract = RequestContract::from_cluster_info(&info).expect("contract");
        assert_eq!(contract.schema(), request_contract::SCHEMA);
        assert_eq!(contract.version(), 1);
        assert_eq!(
            contract.operations()["start"]["fields"]["duplicate_policy"]["canonical_values"],
            json!(["fail", "use-existing"])
        );
        assert_eq!(
            contract.assert_canonical_value("start", "duplicate_policy", Some("use_existing"), Some("--duplicate-policy")),
            Err("Server contract rejects --duplicate-policy value [use_existing]; use [use-existing].".to_string())
        );
        assert_eq!(
            contract.assert_canonical_value("start", "duplicate_policy", Some("newest"), None),
            Err("Server contract expects duplicate_policy to be one of [fail, use-existing]; got [newest].".to_string())
        );
        assert_eq!(
            contract.assert_canonical_value("start", "duplicate_policy", Some("fail"), None),
            Ok(())
        );
        assert_eq!(
            contract.assert_canonical_value("start", "duplicate_policy", Some("  "), None),
            Ok(())
        );
        assert_eq!(
            contract.assert_canonical_value("start", "duplicate_policy", None, None),
            Ok(())
        );
        assert_eq!(
            contract.assert_canonical_value("signal", "duplicate_policy", Some("x"), None),
            Ok(())
        );
    }

    #[test]
    fn requires_a_versioned_request_contract() {
        let info = duplicate_policy_info(json!({
            "start": {"fields": {"duplicate_policy": {"canonical_values": ["fail", "use-existing"]}}},
        }));
        let expected = "Server compatibility error: invalid control_plane.request_contract metadata; expected durable-workflow.v2.control-plane-request.contract v1.";
        assert_eq!(
            RequestContract::from_cluster_info(&info).err().as_deref(),
            Some(expected)
        );
        let failure = refusal(&info, "2.5.0-dev", false);
        assert!(failure.message.ends_with(&format!("Detail: {expected}")));
    }

    #[test]
    fn request_contract_errors_name_the_failing_part() {
        let cases = [
            (json!(null), "missing control_plane.request_contract"),
            (
                json!({"schema": "other", "version": "2", "operations": {}}),
                "unsupported control_plane.request_contract schema/version [other v2]",
            ),
            (
                json!({"schema": request_contract::SCHEMA, "version": 1}),
                "invalid control_plane.request_contract.operations payload",
            ),
            (
                json!({"schema": 7, "version": 1, "operations": {}}),
                "invalid control_plane.request_contract metadata",
            ),
            (
                json!({"schema": request_contract::SCHEMA, "operations": {}}),
                "invalid control_plane.request_contract metadata",
            ),
        ];
        for (contract, detail) in cases {
            let error = RequestContract::from_cluster_info(&duplicate_policy_info(contract)).err();
            assert_eq!(
                error,
                Some(format!("Server compatibility error: {detail}; expected durable-workflow.v2.control-plane-request.contract v1."))
            );
        }
        // 2.x accepts any version that casts to 1 once the schema matches.
        for version in [json!("1abc"), json!(1.5), json!(true)] {
            let lenient = duplicate_policy_info(
                json!({"schema": request_contract::SCHEMA, "version": version, "operations": []}),
            );
            assert!(RequestContract::from_cluster_info(&lenient).is_ok());
        }
    }

    // ApplicationCompatibilityWarningTest

    #[test]
    fn warns_from_client_compatibility_metadata() {
        assert_eq!(
            warnings(&default_cluster_info("9.8.7", "0.1.x"), "0.1.5", true),
            Vec::<String>::new()
        );
        assert_eq!(
            first_warning(&default_cluster_info("9.8.7", "0.2.x"), false),
            "Compatibility warning: dw 0.1.5 is outside server-advertised cli supported_versions [0.2.x] for server 9.8.7. Compatibility window: 0.2.x. Next step: upgrade dw, pin dw to a supported release, or connect to a compatible server."
        );
    }

    #[test]
    fn warns_about_auth_composition_contract() {
        let mut info = default_cluster_info("9.8.7", "0.1.x");
        info["client_compatibility"]["required_protocols"] =
            json!({"auth_composition": {"schema": AUTH_COMPOSITION_SCHEMA, "version": 1}});
        assert_eq!(
            first_warning(&info, false),
            "Compatibility warning: server did not advertise auth_composition_contract; dw CLI expects durable-workflow.v2.auth-composition.contract v1."
        );
        let mut info = default_cluster_info("9.8.7", "0.1.x");
        info["auth_composition_contract"] =
            json!({"schema": AUTH_COMPOSITION_SCHEMA, "version": 2});
        assert_eq!(
            first_warning(&info, false),
            "Compatibility warning: server advertises auth_composition_contract [durable-workflow.v2.auth-composition.contract v2]; dw CLI expects durable-workflow.v2.auth-composition.contract v1."
        );
    }

    #[test]
    fn warns_when_required_external_task_contracts_are_missing() {
        for (key, schema) in [
            (
                "external_task_result_contract",
                "durable-workflow.v2.external-task-result.contract",
            ),
            (
                "external_task_input_contract",
                "durable-workflow.v2.external-task-input.contract",
            ),
        ] {
            let mut info = default_cluster_info("9.8.7", "0.1.x");
            info["client_compatibility"]["required_protocols"] =
                json!({"worker_protocol": {key: {"schema": schema, "version": 1}}});
            assert_eq!(
                first_warning(&info, false),
                format!("Compatibility warning: server did not advertise worker_protocol.{key}; dw CLI expects {schema} v1.")
            );
        }
    }

    #[test]
    fn warns_when_external_task_fixture_artifacts_are_incomplete() {
        let mut info = default_cluster_info("9.8.7", "0.1.x");
        let mut manifest = input_manifest();
        manifest["fixtures"]
            .as_object_mut()
            .unwrap()
            .remove("activity_task");
        info["worker_protocol"]["external_task_input_contract"] = manifest;
        assert_eq!(
            first_warning(&info, false),
            "Compatibility warning: server worker_protocol.external_task_input_contract is missing consumable fixture artifact coverage: missing fixture [activity_task]."
        );

        let mut info = default_cluster_info("9.8.7", "0.1.x");
        let mut manifest = result_manifest();
        manifest["fixtures"]
            .as_object_mut()
            .unwrap()
            .remove("handler_crash");
        info["worker_protocol"]["external_task_result_contract"] = manifest;
        assert_eq!(
            first_warning(&info, false),
            "Compatibility warning: server worker_protocol.external_task_result_contract is missing consumable fixture artifact coverage: missing fixture [handler_crash]."
        );
    }

    #[test]
    fn worker_protocol_warnings_only_for_worker_commands() {
        let mut info = default_cluster_info("9.8.7", "0.1.x");
        info.as_object_mut().unwrap().remove("worker_protocol");
        assert_eq!(warnings(&info, "0.1.5", false), Vec::<String>::new());
        assert_eq!(
            first_warning(&info, true),
            "Compatibility warning: server did not advertise worker_protocol.version; worker commands expect 1.0."
        );
        let newer = cluster_info("9.8.7", "0.1.x", "2", "1.8");
        assert_eq!(warnings(&newer, "0.1.5", true), Vec::<String>::new());
        let breaking = cluster_info("9.8.7", "0.1.x", "2", "2.0");
        assert_eq!(
            first_warning(&breaking, true),
            "Compatibility warning: server advertises worker_protocol.version [2.0]; worker commands support protocol 1.0 on compatible same-major server minors."
        );
    }

    #[test]
    fn interpolates_server_values_with_php_string_casts() {
        let info = json!({"control_plane": {"version": 2.0, "request_contract": null}});
        assert_eq!(
            warnings(&info, "2.5.0-dev", false),
            vec!["Compatibility warning: server did not advertise control_plane.request_contract; dw CLI expects durable-workflow.v2.control-plane-request.contract v1."]
        );
        let info = json!({"control_plane": {"version": 1e20}});
        assert_eq!(
            warnings(&info, "2.5.0-dev", false)[0],
            "Compatibility warning: server advertises control_plane.version [1.0E+20]; dw CLI expects 2."
        );
        let info = json!({"control_plane": {"version": true}, "client_compatibility": {"authority": false}});
        assert_eq!(
            warnings(&info, "2.5.0-dev", false),
            vec![
                "Compatibility warning: server advertises control_plane.version [1]; dw CLI expects 2.",
                "Compatibility warning: server did not advertise control_plane.request_contract; dw CLI expects durable-workflow.v2.control-plane-request.contract v1.",
                "Compatibility warning: server client_compatibility authority is []; dw CLI expects protocol_manifests.",
            ]
        );
    }

    // ExternalTaskInputContractTest / ExternalTaskResultContractTest

    #[test]
    fn input_fixtures_parse_as_envelopes() {
        assert_eq!(
            input_envelope::parse(&parse(ACTIVITY_TASK)),
            Ok("activity_task")
        );
        assert_eq!(
            input_envelope::parse(&parse(WORKFLOW_TASK)),
            Ok("workflow_task")
        );

        let mut extra = parse(ACTIVITY_TASK);
        extra["carrier_debug"] = json!({"ignored": true});
        extra["task"]["carrier_debug"] = json!("ignored");
        assert_eq!(input_envelope::parse(&extra), Ok("activity_task"));
    }

    #[test]
    fn input_envelopes_reject_invalid_fields() {
        let mut missing = parse(WORKFLOW_TASK);
        missing["history"]
            .as_object_mut()
            .unwrap()
            .remove("last_sequence");
        assert_eq!(
            input_envelope::parse(&missing),
            Err("External task input is missing required field [last_sequence].".to_string())
        );
        let mut schema = parse(ACTIVITY_TASK);
        schema["schema"] = json!("wrong.schema");
        assert_eq!(
            input_envelope::parse(&schema),
            Err("External task input field [schema] must be [durable-workflow.v2.external-task-input].".to_string())
        );
        let mut version = parse(ACTIVITY_TASK);
        version["version"] = json!(2);
        assert_eq!(
            input_envelope::parse(&version),
            Err("External task input field [version] must be [1].".to_string())
        );
        let mut attempt = parse(ACTIVITY_TASK);
        attempt["task"]["attempt"] = json!(0);
        assert_eq!(
            input_envelope::parse(&attempt),
            Err("External task input task.attempt must be >= 1.".to_string())
        );
        let mut headers = parse(ACTIVITY_TASK);
        headers["headers"] = json!({});
        assert_eq!(
            input_envelope::parse(&headers),
            Err("External task input field [headers] must be an object.".to_string())
        );
    }

    #[test]
    fn validates_input_fixture_manifests() {
        assert_eq!(
            external_task::validate_input_manifest(&input_manifest()),
            Vec::<String>::new()
        );

        let mut manifest = input_manifest();
        manifest["scope"]
            .as_object_mut()
            .unwrap()
            .remove("activity_grade_external_execution");
        manifest["fixtures"]
            .as_object_mut()
            .unwrap()
            .remove("workflow_task");
        manifest["fixtures"]["activity_task"]["path"] =
            json!("tests/fixtures/external-task-input/activity-task.v1.json");
        manifest["fixtures"]["activity_task"]["sha256"] = json!("not-the-example-hash");
        assert_eq!(
            external_task::validate_input_manifest(&manifest),
            vec![
                "scope.activity_grade_external_execution must include fixture [activity_task]",
                "missing fixture [workflow_task]",
                "fixture [activity_task] sha256 does not match embedded example",
            ]
        );

        let info = json!({"worker_protocol": {"version": "1.0", "external_task_input_contract": {
            "schema": "durable-workflow.v2.external-task-input.contract", "version": 1, "fixtures": [],
        }}});
        assert_eq!(
            external_task::input_warning(&info).as_deref(),
            Some("Compatibility warning: server worker_protocol.external_task_input_contract is missing consumable fixture artifact coverage: scope must describe activity_grade_external_execution and worker_protocol_runtime.")
        );
    }

    #[test]
    fn validates_result_fixture_manifests() {
        assert_eq!(
            external_task::validate_result_manifest(&result_manifest()),
            Vec::<String>::new()
        );

        let mut manifest = result_manifest();
        manifest["fixtures"]
            .as_object_mut()
            .unwrap()
            .remove("decode_failure");
        manifest["fixtures"]["success"]["sha256"] = json!("not-the-example-hash");
        assert_eq!(
            external_task::validate_result_manifest(&manifest),
            vec![
                "fixture [success] sha256 does not match embedded example",
                "missing fixture [decode_failure]",
            ]
        );

        let info = json!({"worker_protocol": {"version": "1.0", "external_task_result_contract": {
            "schema": "durable-workflow.v2.external-task-result.contract", "version": 1, "fixtures": [],
        }}});
        assert_eq!(
            external_task::result_warning(&info).as_deref(),
            Some("Compatibility warning: server worker_protocol.external_task_result_contract is missing consumable fixture artifact coverage: missing fixture [success].")
        );
    }

    // PHP semantics the messages depend on (expected values from PHP 8.4).

    #[test]
    fn version_constraints_match_php() {
        let cases = [
            ("2.5.0-dev", ">=2.0.0,<3.0.0", true),
            ("3.0.0", ">=2.0.0,<3.0.0", false),
            ("3.0.0-dev", "<3.0.0", true),
            ("2.5.0-dev", "2.5.*", true),
            ("2.5.0-dev", "=2.5.0", false),
            ("2.5.0", "=2.5.0-dev", false),
            ("v2.1", ">= 2.0", true),
            ("2.0.0-rc.1", ">=2.0.0-rc.1", true),
            ("2.0.0-RC1", ">=2.0.0-rc.1", true),
            ("2.0.0-pl1", ">2.0.0", true),
            ("0.1.5", "1.x || 0.1.*", true),
            ("0.1.5", "^0.1", false),
            ("x", "*", false),
            ("2", "2.x", true),
            ("2.0.0", " , ", false),
        ];
        for (version, constraint, expected) in cases {
            assert_eq!(
                version::matches_constraint(version, constraint),
                expected,
                "{version} against {constraint}"
            );
        }
    }

    #[test]
    fn version_compare_matches_php() {
        let cases = [
            ("2.5.0-dev", "2.5.0", -1),
            ("2.0.0-rc.1", "2.0.0", -1),
            ("2.0.0-beta", "2.0.0-alpha", 1),
            ("2.0.0-pl", "2.0.0", 1),
            ("2.0.0-foo", "2.0.0-dev", -1),
            ("1.0.0", "1.0", 1),
            ("1.0rc1", "1.0", -1),
            ("1_2", "1+2", 0),
            ("", "1", -1),
        ];
        for (left, right, expected) in cases {
            assert_eq!(
                version::php_version_compare(left, right),
                expected,
                "{left} vs {right}"
            );
        }
    }

    #[test]
    fn worker_protocol_compatibility_is_same_major_newer_minor() {
        assert!(is_compatible_worker_protocol_version("1.0", "1.20"));
        assert!(!is_compatible_worker_protocol_version("1.2", "1.1"));
        assert!(!is_compatible_worker_protocol_version("1.0", "2.0"));
        assert!(is_compatible_worker_protocol_version("1", "1"));
        assert!(!is_compatible_worker_protocol_version("1.0", "1.0.0"));
    }

    #[test]
    fn php_casts_and_encoding() {
        let cast = |raw: &str| php::to_string(Some(&parse(raw)));
        assert_eq!(cast("2.0"), "2");
        assert_eq!(cast("1e15"), "1.0E+15");
        assert_eq!(cast("0.30000000000000004"), "0.3");
        assert_eq!(cast("0.00001"), "1.0E-5");
        assert_eq!(cast("true"), "1");
        let int = |raw: &str| php::int_cast(Some(&parse(raw)));
        assert_eq!(int("\"1abc\""), 1);
        assert_eq!(int("\"1e3\""), 1000);
        assert_eq!(int("\"99999999999999999999\""), i64::MAX);
        assert_eq!(int("[1]"), 1);
        assert_eq!(
            php::json_encode(&parse(
                r#"{"a":{},"b":{"0":"x","1":"y"},"c":"/ü😀\u0001","d":1.0,"e":1e20,"f":0.00001}"#
            )),
            r#"{"a":[],"b":["x","y"],"c":"/\u00fc\ud83d\ude00\u0001","d":1,"e":1.0e+20,"f":1.0e-5}"#
        );
    }

    /// Differential check against vectors produced by the 2.x PHP classes.
    /// Point `DW_COMPAT_PHP_VECTORS` at the generator's output to run it.
    #[test]
    #[ignore = "needs DW_COMPAT_PHP_VECTORS from the PHP vector generator"]
    fn matches_php_vectors() {
        let path = std::env::var("DW_COMPAT_PHP_VECTORS").expect("DW_COMPAT_PHP_VECTORS");
        let vectors = parse(&std::fs::read_to_string(path).expect("vectors file"));
        let mut mismatches = Vec::new();
        differential::cluster(&vectors["cluster"], &mut mismatches);
        differential::manifests(&vectors["manifests"], &mut mismatches);
        differential::canonical(&vectors["canonical"], &mut mismatches);
        differential::scalars(&vectors, &mut mismatches);
        assert!(
            mismatches.is_empty(),
            "{} mismatches:\n{}",
            mismatches.len(),
            mismatches
                .iter()
                .take(30)
                .cloned()
                .collect::<Vec<_>>()
                .join("\n")
        );
    }

    mod differential {
        use super::super::*;
        use super::parse;
        use serde_json::json;

        fn check(mismatches: &mut Vec<String>, label: String, actual: Value, expected: &Value) {
            if &actual != expected
                || serde_json::to_string(&actual).ok() != serde_json::to_string(expected).ok()
            {
                mismatches.push(format!("{label}\n  rust: {actual}\n  php:  {expected}"));
            }
        }

        pub fn cluster(cases: &Value, mismatches: &mut Vec<String>) {
            for case in cases.as_array().unwrap() {
                let info = parse(case["cluster_json"].as_str().unwrap());
                let cli = case["cli_version"].as_str().unwrap();
                let include = case["include_worker"].as_bool().unwrap();
                let client = ClientVersions {
                    cli,
                    control_plane: case["client_control_plane"].as_str().unwrap(),
                    worker_protocol: case["client_worker_protocol"].as_str().unwrap(),
                };
                let label = format!(
                    "{} [cli {cli}, worker {include}, cp {}]",
                    case["name"], client.control_plane
                );
                check(
                    mismatches,
                    format!("warnings {label}"),
                    json!(warnings(&info, cli, include)),
                    &case["warnings"],
                );
                let failure = assert_compatible_with(&info, &client, include).err().map(|failure| {
                    json!({"message": failure.message, "diagnostic": failure.diagnostic, "next_step": failure.next_step})
                });
                check(
                    mismatches,
                    format!("failure {label}"),
                    json!(failure),
                    &case["failure"],
                );
                let error = RequestContract::from_cluster_info(&info).err();
                check(
                    mismatches,
                    format!("request contract {label}"),
                    json!(error),
                    &case["request_contract_error"],
                );
            }
        }

        pub fn manifests(cases: &Value, mismatches: &mut Vec<String>) {
            for case in cases.as_array().unwrap() {
                let manifest = parse(case["manifest_json"].as_str().unwrap());
                let name = &case["name"];
                check(
                    mismatches,
                    format!("input manifest {name}"),
                    json!(external_task::validate_input_manifest(&manifest)),
                    &case["input_errors"],
                );
                check(
                    mismatches,
                    format!("result manifest {name}"),
                    json!(external_task::validate_result_manifest(&manifest)),
                    &case["result_errors"],
                );
            }
        }

        pub fn canonical(vectors: &Value, mismatches: &mut Vec<String>) {
            let info = parse(vectors["cluster_json"].as_str().unwrap());
            let contract = RequestContract::from_cluster_info(&info).expect("canonical contract");
            for case in vectors["cases"].as_array().unwrap() {
                let result = contract.assert_canonical_value(
                    case["operation"].as_str().unwrap(),
                    case["field"].as_str().unwrap(),
                    case["value"].as_str(),
                    case["option"].as_str(),
                );
                check(
                    mismatches,
                    format!("canonical {case}"),
                    json!(result.err()),
                    &case["error"],
                );
            }
        }

        pub fn scalars(vectors: &Value, mismatches: &mut Vec<String>) {
            let rows = |key: &str| vectors[key].as_array().unwrap().clone();
            for row in rows("constraints") {
                let actual =
                    version::matches_constraint(row[0].as_str().unwrap(), row[1].as_str().unwrap());
                check(
                    mismatches,
                    format!("constraint {row}"),
                    json!(actual),
                    &row[2],
                );
            }
            for row in rows("version_compare") {
                let actual = version::php_version_compare(
                    row[0].as_str().unwrap(),
                    row[1].as_str().unwrap(),
                );
                check(
                    mismatches,
                    format!("version_compare {row}"),
                    json!(actual),
                    &row[2],
                );
            }
            for row in rows("worker_protocol") {
                let actual = version::is_compatible_worker_protocol(
                    row[0].as_str().unwrap(),
                    row[1].as_str().unwrap(),
                );
                check(
                    mismatches,
                    format!("worker protocol {row}"),
                    json!(actual),
                    &row[2],
                );
            }
            for row in rows("json_encode") {
                let actual = php::json_encode(&parse(row[0].as_str().unwrap()));
                check(
                    mismatches,
                    format!("json_encode {}", row[0]),
                    json!(actual),
                    &row[1],
                );
            }
            for row in rows("float_string") {
                let actual = php::to_string(Some(&parse(row[0].as_str().unwrap())));
                check(
                    mismatches,
                    format!("string cast {}", row[0]),
                    json!(actual),
                    &row[1],
                );
            }
            for row in rows("int_cast") {
                let actual = php::int_cast(Some(&parse(row[0].as_str().unwrap())));
                check(
                    mismatches,
                    format!("int cast {}", row[0]),
                    json!(actual),
                    &row[1],
                );
            }
        }
    }
}
