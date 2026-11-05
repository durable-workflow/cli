//! The connection for commands that talk to a Server, built on the published
//! durable-workflow SDK client, and the 2.x compatibility preflight.

use crate::cli::Input;
use crate::config::{self, Connection, ConnectionFlags, ProfileStore};
use crate::error::CliError;
use crate::output::{resolve_mode, Mode};
use crate::{build_info, compat};
use durable_workflow::{Client, Error as SdkError, RawRequest};
use serde_json::Value;
use std::time::Duration;

mod workflow_response;

/// How long the compatibility preflight waits for cluster info, as in 2.x.
pub const PREFLIGHT_TIMEOUT: Duration = Duration::from_secs(5);

pub struct Context {
    pub connection: Connection,
    pub mode: Mode,
    runtime: tokio::runtime::Runtime,
}

impl Context {
    pub fn new(input: &Input) -> Result<Self, CliError> {
        let store = ProfileStore::new()?;
        let connection = config::resolve(
            &store,
            &ConnectionFlags {
                env: input.opt_str("env"),
                server: input.opt_str("server"),
                namespace: input.opt_str("namespace"),
                token: input.opt_str("token"),
                tls_verify: input.opt_str("tls-verify"),
            },
        )?;
        let profile_output = connection
            .profile
            .as_ref()
            .and_then(|profile| profile.output.clone());
        let mode = resolve_mode(input, || profile_output)?;
        Self::with_connection(connection, mode)
    }

    pub fn with_connection(connection: Connection, mode: Mode) -> Result<Self, CliError> {
        let runtime = tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()
            .map_err(|error| {
                CliError::Failure(format!("Unable to start the async runtime: {error}"))
            })?;
        Ok(Self {
            connection,
            mode,
            runtime,
        })
    }

    pub fn runtime(&self) -> &tokio::runtime::Runtime {
        &self.runtime
    }

    pub fn sdk(&self) -> Result<Client, CliError> {
        self.sdk_with_timeout(None)
    }

    fn sdk_with_timeout(&self, timeout: Option<Duration>) -> Result<Client, CliError> {
        let connection = &self.connection;
        // The SDK cannot disable certificate verification yet
        // (durable-workflow/sdk-rust#180). Refuse instead of verifying
        // against the caller's explicit choice.
        if !connection.tls_verify && connection.server.to_lowercase().starts_with("https://") {
            return Err(CliError::Invalid(
                "This dw build cannot disable TLS certificate verification yet. Use a trusted certificate or the 2.x CLI for this Server."
                    .into(),
            ));
        }
        let mut builder = Client::builder(connection.server.trim_end_matches('/'))
            .token(connection.token.clone())
            .namespace(connection.namespace.clone());
        if let Some(timeout) = timeout {
            builder = builder.timeout(timeout);
        }
        builder.build().map_err(map_error)
    }

    /// `GET /api/cluster/info`, unchecked.
    pub fn cluster_info(&self, timeout: Option<Duration>) -> Result<Value, CliError> {
        let client = self.sdk_with_timeout(timeout)?;
        self.runtime
            .block_on(async { client.cluster_info().await.map_err(map_error) })
    }

    /// One namespace visibility request over the SDK's configured transport.
    pub fn namespace_list(&self) -> Result<Value, CliError> {
        self.namespace_request(RawRequest::get(["namespaces"]))
    }

    pub fn namespace_describe(&self, name: &str) -> Result<Value, CliError> {
        self.namespace_request(RawRequest::get(["namespaces", name]))
    }

    pub fn namespace_create(&self, body: Value) -> Result<Value, CliError> {
        self.namespace_request(RawRequest::post(["namespaces"]).json(body))
    }

    pub fn namespace_update(&self, name: &str, body: Value) -> Result<Value, CliError> {
        self.namespace_request(RawRequest::put(["namespaces", name]).json(body))
    }

    pub fn namespace_delete(&self, name: &str) -> Result<Value, CliError> {
        self.namespace_request(RawRequest::delete(["namespaces", name]))
    }

    fn namespace_request(&self, request: RawRequest) -> Result<Value, CliError> {
        let request = request
            .protocol_version(compat::worker_protocol_version())
            .control_plane_version(compat::control_plane_version());
        let response = self.runtime.block_on(async {
            self.sdk()?
                .control_plane_request(request)
                .await
                .map_err(map_error)
        })?;
        // The PHP Server client decodes non-array or invalid JSON as an empty array.
        let body = Value::Object(crate::php::array_map(
            response.json().unwrap_or(Value::Null),
        ));
        if !response.is_success() {
            return Err(self.http_error(response.status(), &body));
        }
        Ok(body)
    }

    /// One SDK request, with the PHP CLI's workflow response contract.
    pub fn workflow_list(&self, query: &[(String, String)]) -> Result<Value, CliError> {
        let mut request = RawRequest::get(["workflows"]);
        for (name, value) in query {
            request = request.query(name, value);
        }
        self.workflow_read(request, "/workflows")
    }

    /// Read one workflow's runs. The SDK encodes the identifier as one segment.
    pub fn workflow_runs(&self, workflow_id: &str) -> Result<Value, CliError> {
        let result = self.workflow_read(
            RawRequest::get(["workflows", workflow_id, "runs"]),
            &format!("/workflows/{workflow_id}/runs"),
        )?;
        workflow_response::bind_runs(result, workflow_id).map_err(CliError::Failure)
    }

    fn workflow_read(&self, request: RawRequest, path: &str) -> Result<Value, CliError> {
        let request = request
            .protocol_version(compat::worker_protocol_version())
            .control_plane_version(compat::control_plane_version());
        let response = self.runtime.block_on(async {
            self.sdk()?
                .control_plane_request(request)
                .await
                .map_err(map_error)
        })?;
        let status = response.status();
        let body = response.json().unwrap_or(Value::Null);
        let normalized = workflow_response::normalize(
            body.clone(),
            response.control_plane_version(),
            status,
            path,
        );
        if status >= 400 {
            let payload = if status == 401 || status == 403 {
                body
            } else {
                normalized.unwrap_or(body)
            };
            return Err(self.http_error(status, &payload));
        }
        normalized.map_err(CliError::Failure)
    }

    fn http_error(&self, status: u16, body: &Value) -> CliError {
        let mut error = CliError::from_http(status, &crate::php::json_encode(body));
        if status == 403 && self.managed_namespace() {
            if let CliError::Http { message, .. } = &mut error {
                message.push_str(" This client/control-plane operation requires a client runtime credential; worker runtime credentials are limited to worker-protocol operations.");
            }
        }
        error
    }

    fn managed_namespace(&self) -> bool {
        reqwest::Url::parse(&self.connection.server)
            .ok()
            .is_some_and(|url| {
                url.path()
                    .rsplit_once("/api/runtime/v1/namespaces/")
                    .is_some_and(|(_, namespace)| !namespace.is_empty() && !namespace.contains('/'))
            })
    }

    /// The 2.x preflight every server command except `doctor`,
    /// `server:health` and `server:start-dev` runs before its first request:
    /// refuse with exit 8 when the Server is outside what this CLI supports.
    pub fn preflight(&self, include_worker_protocol: bool) -> Result<Value, CliError> {
        let info = self.cluster_info(Some(PREFLIGHT_TIMEOUT))?;
        compat::assert_compatible(&info, build_info::version(), include_worker_protocol)
            .map_err(CliError::Compatibility)?;
        Ok(info)
    }

    /// The 2.x session warning: the first compatibility warning, on stderr,
    /// before the command runs. Any failure to fetch cluster info is ignored.
    pub fn warn_if_incompatible(&self, include_worker_protocol: bool, literal: bool) {
        if let Ok(info) = self.cluster_info(Some(PREFLIGHT_TIMEOUT)) {
            write_warning(&info, include_worker_protocol, literal);
        }
    }
}

pub fn warn_first(info: &Value, include_worker_protocol: bool) {
    write_warning(info, include_worker_protocol, false);
}

fn write_warning(info: &Value, include_worker_protocol: bool, literal: bool) {
    if let Some(warning) =
        compat::warnings(info, build_info::version(), include_worker_protocol).first()
    {
        let warning = if literal {
            crate::formatter::display_text(warning)
        } else {
            warning.clone()
        };
        crate::formatter::writeln_err(&format!(
            "<comment>{}{}</comment>",
            warning,
            compat::WARNING_SUFFIX
        ));
    }
}

/// Commands whose compatibility checks include the worker protocol.
pub fn uses_worker_protocol(command: &str) -> bool {
    command.starts_with("worker:")
        || command.starts_with("workflow-task:")
        || command.starts_with("query-task:")
        || matches!(command, "activity:complete" | "activity:fail")
}

/// Maps SDK failures onto the CLI's exit categories.
pub fn map_error(error: SdkError) -> CliError {
    match error {
        SdkError::Transport(error) if error.is_timeout() => CliError::Timeout(format!("Request timed out: {error}")),
        SdkError::Transport(error) => CliError::Network(format!("Server unreachable: {error}")),
        SdkError::Http { status, body } => CliError::from_http(status.as_u16(), &body),
        SdkError::InvalidBaseUrl => CliError::Invalid(
            "Invalid server URL: pass the Server or Cloud runtime base URL without the /api suffix.".into(),
        ),
        SdkError::InvalidRawRequest(message) => CliError::Invalid(message),
        other => CliError::Failure(other.to_string()),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn worker_protocol_commands_match_2x() {
        assert!(uses_worker_protocol("worker:list"));
        assert!(uses_worker_protocol("workflow-task:poll"));
        assert!(uses_worker_protocol("activity:fail"));
        assert!(!uses_worker_protocol("activity:list"));
        assert!(!uses_worker_protocol("server:info"));
    }
}
