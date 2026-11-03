//! The connection for commands that talk to a Server, built on the published
//! durable-workflow SDK client.

use crate::cli::Input;
use crate::config::{self, Connection, ConnectionFlags, ProfileStore};
use crate::error::CliError;
use crate::output::{resolve_mode, Mode};
use durable_workflow::{Client, Error as SdkError};

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
        Client::builder(connection.server.trim_end_matches('/'))
            .token(connection.token.clone())
            .namespace(connection.namespace.clone())
            .build()
            .map_err(map_error)
    }
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
        other => CliError::Failure(other.to_string()),
    }
}
