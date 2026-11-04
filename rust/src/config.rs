//! Named environment profiles. The file format, location, permissions and
//! resolution order match the 2.x CLI, so existing profiles keep working.

use crate::error::CliError;
use serde_json::{json, Map, Value};
use std::collections::BTreeMap;
use std::path::{Path, PathBuf};

pub const SCHEMA: &str = "durable-workflow.cli.config";
pub const VERSION: i64 = 1;
pub const OUTPUT_MODES: [&str; 3] = ["table", "json", "jsonl"];

#[derive(Clone, Debug, PartialEq, Eq)]
pub enum TokenSource {
    Literal(String),
    Env(String),
}

#[derive(Clone, Debug)]
pub struct Profile {
    pub name: String,
    pub server: Option<String>,
    pub namespace: Option<String>,
    pub token_source: Option<TokenSource>,
    pub tls_verify: bool,
    pub output: Option<String>,
}

fn non_empty(value: Option<&Value>) -> Option<String> {
    value
        .and_then(Value::as_str)
        .filter(|text| !text.is_empty())
        .map(str::to_string)
}

/// PHP casts the stored value with `(bool)`, so mirror its truthiness.
fn php_truthy(value: &Value) -> bool {
    match value {
        Value::Null => false,
        Value::Bool(flag) => *flag,
        Value::Number(number) => number.as_f64().is_some_and(|n| n != 0.0),
        Value::String(text) => !(text.is_empty() || text == "0"),
        Value::Array(items) => !items.is_empty(),
        Value::Object(map) => !map.is_empty(),
    }
}

impl Profile {
    pub fn from_value(name: &str, data: &Map<String, Value>) -> Result<Self, CliError> {
        let token_source = match data.get("token_source").and_then(Value::as_object) {
            Some(source) => match (
                source.get("type").and_then(Value::as_str),
                non_empty(source.get("value")),
            ) {
                (Some("literal"), Some(value)) => Some(TokenSource::Literal(value)),
                (Some("env"), Some(value)) => Some(TokenSource::Env(value)),
                (Some(other), Some(_)) => {
                    return Err(CliError::Failure(format!(
                        "Profile token source type must be [literal] or [env], got [{other}]."
                    )))
                }
                _ => None,
            },
            None => None,
        };
        let output = non_empty(data.get("output"));
        if let Some(mode) = &output {
            if !OUTPUT_MODES.contains(&mode.as_str()) {
                return Err(CliError::Failure(format!(
                    "Profile output must be one of [{}], got [{mode}].",
                    OUTPUT_MODES.join(", ")
                )));
            }
        }
        Ok(Self {
            name: name.to_string(),
            server: non_empty(data.get("server")),
            namespace: non_empty(data.get("namespace")),
            token_source,
            tls_verify: data.get("tls_verify").map(php_truthy).unwrap_or(true),
            output,
        })
    }

    fn token_source_value(&self) -> Option<Value> {
        self.token_source.as_ref().map(|source| match source {
            TokenSource::Literal(value) => json!({"type": "literal", "value": value}),
            TokenSource::Env(value) => json!({"type": "env", "value": value}),
        })
    }

    /// The stored form, omitting unset fields.
    pub fn to_value(&self) -> Value {
        let mut data = Map::new();
        if let Some(server) = &self.server {
            data.insert("server".into(), json!(server));
        }
        if let Some(namespace) = &self.namespace {
            data.insert("namespace".into(), json!(namespace));
        }
        if let Some(source) = self.token_source_value() {
            data.insert("token_source".into(), source);
        }
        data.insert("tls_verify".into(), json!(self.tls_verify));
        if let Some(output) = &self.output {
            data.insert("output".into(), json!(output));
        }
        Value::Object(data)
    }

    /// The public description. Literal tokens are redacted unless asked for.
    pub fn describe(&self, show_token: bool) -> Map<String, Value> {
        let mut data = Map::new();
        data.insert("name".into(), json!(self.name));
        data.insert("server".into(), json!(self.server));
        data.insert("namespace".into(), json!(self.namespace));
        data.insert("tls_verify".into(), json!(self.tls_verify));
        data.insert("output".into(), json!(self.output));
        let source = match &self.token_source {
            None => Value::Null,
            Some(TokenSource::Env(name)) => json!({"type": "env", "env": name}),
            Some(TokenSource::Literal(value)) => {
                json!({"type": "literal", "value": if show_token { value.as_str() } else { "redacted" }})
            }
        };
        data.insert("token_source".into(), source);
        data
    }

    pub fn resolve_token(&self) -> Option<String> {
        match &self.token_source {
            None => None,
            Some(TokenSource::Literal(value)) => Some(value.clone()),
            Some(TokenSource::Env(name)) => env_string(name),
        }
    }
}

pub fn env_string(name: &str) -> Option<String> {
    std::env::var(name).ok().filter(|value| !value.is_empty())
}

pub struct Config {
    pub current_env: Option<String>,
    /// Sorted by name, as the 2.x CLI lists and writes them.
    pub envs: BTreeMap<String, Profile>,
}

pub struct ProfileStore {
    path: PathBuf,
}

impl ProfileStore {
    pub fn new() -> Result<Self, CliError> {
        Ok(Self {
            path: default_path()?,
        })
    }

    pub fn path_string(&self) -> String {
        self.path.to_string_lossy().into_owned()
    }

    pub fn load(&self) -> Result<Config, CliError> {
        let display = self.path_string();
        let contents = match std::fs::read_to_string(&self.path) {
            Ok(contents) => contents,
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => {
                return Ok(Config {
                    current_env: None,
                    envs: BTreeMap::new(),
                })
            }
            Err(_) => {
                return Err(CliError::Failure(format!(
                    "Unable to read dw config file [{display}]."
                )))
            }
        };
        let decoded: Value = serde_json::from_str(&contents).map_err(|error| {
            CliError::Failure(format!(
                "dw config file [{display}] is not valid JSON: {error}"
            ))
        })?;
        let Value::Object(document) = decoded else {
            return Err(CliError::Failure(format!(
                "dw config file [{display}] must decode to an object."
            )));
        };
        let mut envs = BTreeMap::new();
        match document.get("envs") {
            None | Some(Value::Null) => {}
            // An empty PHP array is written as [].
            Some(Value::Array(items)) if items.is_empty() => {}
            Some(Value::Object(entries)) => {
                for (name, entry) in entries {
                    if let Value::Object(entry) = entry {
                        envs.insert(name.clone(), Profile::from_value(name, entry)?);
                    }
                }
            }
            Some(_) => {
                return Err(CliError::Failure(format!(
                    "dw config file [{display}] has a non-object envs value."
                )))
            }
        }
        let current_env = document
            .get("current_env")
            .and_then(Value::as_str)
            .map(str::to_string);
        Ok(Config { current_env, envs })
    }

    pub fn get(&self, name: &str) -> Result<Option<Profile>, CliError> {
        Ok(self.load()?.envs.remove(name))
    }

    pub fn require(&self, name: &str, source: &str) -> Result<Profile, CliError> {
        let config = self.load()?;
        if let Some(profile) = config.envs.get(name) {
            return Ok(profile.clone());
        }
        let available: Vec<&str> = config.envs.keys().map(String::as_str).collect();
        let hint = if available.is_empty() {
            " No environments are configured — run `dw env:set <name> --server=...`.".to_string()
        } else {
            format!(
                " Available environments: [{}]. Run `dw env:set {name} --server=...` to create it.",
                available.join(", ")
            )
        };
        Err(CliError::Invalid(format!(
            "Unknown dw environment [{name}] (requested via {source}).{hint}"
        )))
    }

    pub fn put(&self, profile: Profile) -> Result<(), CliError> {
        let mut config = self.load()?;
        config.envs.insert(profile.name.clone(), profile);
        self.write(&config)
    }

    pub fn set_current(&self, name: &str) -> Result<Profile, CliError> {
        let mut config = self.load()?;
        let Some(profile) = config.envs.get(name).cloned() else {
            let available: Vec<&str> = config.envs.keys().map(String::as_str).collect();
            let hint = if available.is_empty() {
                " No environments are configured.".to_string()
            } else {
                format!(" Available environments: [{}].", available.join(", "))
            };
            return Err(CliError::Invalid(format!(
                "Cannot set current env to [{name}]: profile does not exist.{hint}"
            )));
        };
        config.current_env = Some(name.to_string());
        self.write(&config)?;
        Ok(profile)
    }

    pub fn delete(&self, name: &str) -> Result<Profile, CliError> {
        let mut config = self.load()?;
        let Some(removed) = config.envs.remove(name) else {
            return Err(CliError::Invalid(format!(
                "Cannot delete env [{name}]: profile does not exist."
            )));
        };
        if config.current_env.as_deref() == Some(name) {
            config.current_env = None;
        }
        self.write(&config)?;
        Ok(removed)
    }

    /// Writes atomically. A new directory is 0700 and the file 0600, because
    /// it can hold a literal token.
    fn write(&self, config: &Config) -> Result<(), CliError> {
        let display = self.path_string();
        if let Some(dir) = self.path.parent() {
            if !dir.is_dir() {
                std::fs::create_dir_all(dir).map_err(|_| {
                    CliError::Failure(format!(
                        "Unable to create dw config directory [{}].",
                        dir.to_string_lossy()
                    ))
                })?;
                set_mode(dir, 0o700);
            }
        }
        let envs = if config.envs.is_empty() {
            Value::Array(vec![])
        } else {
            Value::Object(
                config
                    .envs
                    .iter()
                    .map(|(name, profile)| (name.clone(), profile.to_value()))
                    .collect(),
            )
        };
        let document = json!({
            "schema": SCHEMA,
            "version": VERSION,
            "current_env": config.current_env,
            "envs": envs,
        });
        let encoded = crate::output::encode_pretty(&document);
        let temp = PathBuf::from(format!("{display}.tmp"));
        std::fs::write(&temp, format!("{encoded}\n")).map_err(|_| {
            CliError::Failure(format!(
                "Unable to write dw config file [{}].",
                temp.to_string_lossy()
            ))
        })?;
        set_mode(&temp, 0o600);
        std::fs::rename(&temp, &self.path).map_err(|_| {
            let _ = std::fs::remove_file(&temp);
            CliError::Failure(format!("Unable to commit dw config file [{display}]."))
        })
    }
}

#[cfg(unix)]
fn set_mode(path: &Path, mode: u32) {
    use std::os::unix::fs::PermissionsExt;
    let _ = std::fs::set_permissions(path, std::fs::Permissions::from_mode(mode));
}

#[cfg(not(unix))]
fn set_mode(_path: &Path, _mode: u32) {}

/// `$DW_CONFIG_HOME/config.json`, then `$XDG_CONFIG_HOME/dw/config.json`,
/// then `%APPDATA%\dw\config.json` on Windows, then `~/.config/dw/config.json`.
pub fn default_path() -> Result<PathBuf, CliError> {
    if let Some(home) = env_string("DW_CONFIG_HOME") {
        return Ok(
            PathBuf::from(home.trim_end_matches(std::path::MAIN_SEPARATOR)).join("config.json"),
        );
    }
    if let Some(xdg) = env_string("XDG_CONFIG_HOME") {
        return Ok(
            PathBuf::from(xdg.trim_end_matches(std::path::MAIN_SEPARATOR))
                .join("dw")
                .join("config.json"),
        );
    }
    if cfg!(windows) {
        if let Some(app_data) = env_string("APPDATA") {
            return Ok(PathBuf::from(app_data).join("dw").join("config.json"));
        }
    }
    match env_string("HOME") {
        Some(home) => Ok(PathBuf::from(home.trim_end_matches(std::path::MAIN_SEPARATOR))
            .join(".config")
            .join("dw")
            .join("config.json")),
        None => Err(CliError::Failure(
            "Unable to resolve dw config path: neither DW_CONFIG_HOME, XDG_CONFIG_HOME, APPDATA, nor HOME is set."
                .into(),
        )),
    }
}

/// A connection resolved from flags, environment variables, the selected
/// profile and defaults, in that order.
#[derive(Debug)]
pub struct Connection {
    pub server: String,
    pub namespace: String,
    pub token: Option<String>,
    pub tls_verify: bool,
    pub profile: Option<Profile>,
    sources: ConnectionSources,
}

#[derive(Debug)]
struct ConnectionSources {
    server: &'static str,
    namespace: &'static str,
    profile: Option<&'static str>,
    token: &'static str,
    token_env: Option<String>,
    tls: &'static str,
}

impl Connection {
    /// Redacted provenance captured while resolving the connection. Never
    /// include the bearer token in an operator diagnostic.
    pub fn effective_config(&self, config_path: &str) -> Value {
        let sources = &self.sources;
        let profile_name = self.profile.as_ref().map(|profile| &profile.name);
        let server = json!({"value": self.server, "source": sources.server,
            "contract_source": contract_source(sources.server)});
        json!({
            "contract": {"schema": "durable-workflow.v2.auth-composition.contract", "version": 1},
            "server": server, "server_url": server,
            "namespace": {"value": self.namespace, "source": sources.namespace,
                "contract_source": contract_source(sources.namespace)},
            "profile": {"name": profile_name, "source": sources.profile,
                "contract_source": sources.profile.map(|source| match source {
                    "flag" => "flag_env", "current_env" => "current_profile", other => other,
                }), "config_path": config_path},
            "auth": {"type": if self.token.is_some() {"token"} else {"none"},
                "transport": self.token.as_ref().map(|_| "bearer_authorization_header"),
                "present": self.token.is_some(), "source": sources.token,
                "contract_source": match sources.token {
                    "DURABLE_WORKFLOW_AUTH_TOKEN" => "environment",
                    "profile_literal" => "selected_profile", other => other,
                },
                "env": sources.token_env,
                "profile": if sources.token.starts_with("profile_") {profile_name} else {None},
                "value": self.token.as_ref().map(|_| "redacted")},
            "tls": {"verify": self.tls_verify, "source": sources.tls,
                "contract_source": contract_source(sources.tls)},
            "identity": {"subject": null, "roles": [], "source": "server", "asserted": false},
        })
    }
}

fn contract_source(source: &str) -> &str {
    match source {
        "profile" => "selected_profile",
        name if name.starts_with("DURABLE_WORKFLOW_") => "environment",
        other => other,
    }
}

/// Flag values that were written with a non-empty value. An empty or
/// valueless flag falls through to the environment, as in 2.x.
pub struct ConnectionFlags<'a> {
    pub env: Option<&'a str>,
    pub server: Option<&'a str>,
    pub namespace: Option<&'a str>,
    pub token: Option<&'a str>,
    pub tls_verify: Option<&'a str>,
}

/// Runtime URLs are connection locations, not an implicit credential source.
/// Refuse before client construction and never echo the rejected value.
fn validate_runtime_url(server: &str) -> Result<(), CliError> {
    let invalid = || {
        CliError::Invalid(
        "Invalid server URL: use an absolute HTTP(S) runtime base URL without user information, a query, a fragment, or whitespace. Supply authentication through a token option or configured token source."
            .into(),
    )
    };
    if server
        .chars()
        .any(|character| character.is_control() || character.is_whitespace())
    {
        return Err(invalid());
    }
    let url = reqwest::Url::parse(server).map_err(|_| invalid())?;
    if !matches!(url.scheme(), "http" | "https")
        || url.host_str().is_none()
        || !url.username().is_empty()
        || url.password().is_some()
        || url.query().is_some()
        || url.fragment().is_some()
    {
        return Err(invalid());
    }
    Ok(())
}

pub fn resolve(store: &ProfileStore, flags: &ConnectionFlags) -> Result<Connection, CliError> {
    let (profile, profile_source) = if let Some(env) = flags.env {
        (Some(store.require(env, "--env")?), Some("flag"))
    } else if let Some(env) = env_string("DW_ENV") {
        (Some(store.require(&env, "DW_ENV")?), Some("DW_ENV"))
    } else if let Some(current) = store.load()?.current_env {
        (
            Some(store.require(&current, "dw env:use (current_env in config)")?),
            Some("current_env"),
        )
    } else {
        (None, None)
    };

    let pick =
        |flag: Option<&str>, env: &'static str, profile_value: Option<&String>, default: &str| {
            if let Some(value) = flag {
                (value.to_string(), "flag")
            } else if let Some(value) = env_string(env) {
                (value, env)
            } else if let Some(value) = profile_value {
                (value.clone(), "profile")
            } else {
                (default.to_string(), "default")
            }
        };

    let (server, server_source) = pick(
        flags.server,
        "DURABLE_WORKFLOW_SERVER_URL",
        profile.as_ref().and_then(|p| p.server.as_ref()),
        "http://localhost:8080",
    );
    validate_runtime_url(&server)?;
    let (namespace, namespace_source) = pick(
        flags.namespace,
        "DURABLE_WORKFLOW_NAMESPACE",
        profile.as_ref().and_then(|p| p.namespace.as_ref()),
        "default",
    );
    let (token, token_source, token_env) = if let Some(value) = flags.token {
        (Some(value.to_string()), "flag", None)
    } else if let Some(value) = env_string("DURABLE_WORKFLOW_AUTH_TOKEN") {
        (
            Some(value),
            "DURABLE_WORKFLOW_AUTH_TOKEN",
            Some("DURABLE_WORKFLOW_AUTH_TOKEN".into()),
        )
    } else {
        let resolved = profile.as_ref().and_then(Profile::resolve_token);
        match profile
            .as_ref()
            .and_then(|profile| profile.token_source.as_ref())
        {
            Some(TokenSource::Env(name)) => (resolved, "profile_env", Some(name.clone())),
            Some(TokenSource::Literal(_)) => (resolved, "profile_literal", None),
            None => (None, "none", None),
        }
    };
    let (tls_verify, tls_source) = match flags.tls_verify {
        Some(value) => (parse_boolean(value, "--tls-verify")?, "flag"),
        None => match env_string("DURABLE_WORKFLOW_TLS_VERIFY") {
            Some(value) => (
                parse_boolean(&value, "DURABLE_WORKFLOW_TLS_VERIFY")?,
                "DURABLE_WORKFLOW_TLS_VERIFY",
            ),
            None => profile
                .as_ref()
                .map(|p| (p.tls_verify, "profile"))
                .unwrap_or((true, "default")),
        },
    };

    Ok(Connection {
        server,
        namespace,
        token,
        tls_verify,
        profile,
        sources: ConnectionSources {
            server: server_source,
            namespace: namespace_source,
            profile: profile_source,
            token: token_source,
            token_env,
            tls: tls_source,
        },
    })
}

pub fn parse_boolean(value: &str, source: &str) -> Result<bool, CliError> {
    match value.trim().to_lowercase().as_str() {
        "1" | "true" | "yes" | "on" => Ok(true),
        "0" | "false" | "no" | "off" => Ok(false),
        _ => Err(CliError::Invalid(format!(
            "{source} must be one of: true, false, yes, no, on, off, 1, 0."
        ))),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn runtime_urls_keep_encoded_paths_but_refuse_implicit_credentials() {
        for server in [
            "http://127.0.0.1:8080",
            "https://example.test/api/runtime/v1/namespaces/tenant-1",
            "http://[::1]:8080/runtime/%3Fname%23value",
        ] {
            assert!(validate_runtime_url(server).is_ok());
        }
        for server in [
            "https://fixture-user:fixture-password@example.test",
            "https://fixture-user@example.test",
            "https://:fixture-password@example.test",
            "https://example.test?token=fixture-query",
            "https://example.test#fixture-fragment",
            "https://example.test?",
            "https://example.test#",
            "https://example.test/has space",
            "https://example.test/\nvalue",
            "https://example.test/\u{9b}value",
            "relative/runtime",
            "ftp://example.test",
        ] {
            let error = validate_runtime_url(server).unwrap_err();
            assert_eq!(error.exit_code(), crate::error::exit::INVALID);
            assert!(!error.message().contains("fixture-"));
        }
    }

    #[test]
    fn describe_redacts_literal_tokens() {
        let profile = Profile {
            name: "prod".into(),
            server: Some("https://x".into()),
            namespace: None,
            token_source: Some(TokenSource::Literal("s3cret".into())),
            tls_verify: true,
            output: Some("json".into()),
        };
        let described = Value::Object(profile.describe(false));
        assert_eq!(
            described["token_source"],
            json!({"type": "literal", "value": "redacted"})
        );
        assert_eq!(
            Value::Object(profile.describe(true))["token_source"]["value"],
            "s3cret"
        );
    }

    #[test]
    fn stored_form_omits_unset_fields() {
        let profile = Profile {
            name: "dev".into(),
            server: Some("http://localhost:8080".into()),
            namespace: None,
            token_source: Some(TokenSource::Env("DEV_TOKEN".into())),
            tls_verify: false,
            output: None,
        };
        assert_eq!(
            profile.to_value(),
            json!({"server": "http://localhost:8080", "token_source": {"type": "env", "value": "DEV_TOKEN"}, "tls_verify": false})
        );
    }

    #[test]
    fn booleans_accept_the_2x_spellings() {
        assert!(parse_boolean(" Yes ", "x").unwrap());
        assert!(!parse_boolean("off", "x").unwrap());
        assert!(parse_boolean("maybe", "x").is_err());
    }

    #[test]
    fn effective_config_redacts_auth_and_preserves_source_contracts() {
        let connection = Connection {
            server: "https://example.test".into(),
            namespace: "tenant".into(),
            token: Some("synthetic-private-value".into()),
            tls_verify: false,
            profile: Some(Profile {
                name: "prod".into(),
                server: None,
                namespace: None,
                token_source: None,
                tls_verify: false,
                output: None,
            }),
            sources: ConnectionSources {
                server: "profile",
                namespace: "DURABLE_WORKFLOW_NAMESPACE",
                profile: Some("current_env"),
                token: "profile_env",
                token_env: Some("TEST_TOKEN".into()),
                tls: "flag",
            },
        };
        let effective = connection.effective_config("config.json");
        assert!(!effective.to_string().contains("synthetic-private-value"));
        assert_eq!(effective["server"], effective["server_url"]);
        assert_eq!(effective["server"]["contract_source"], "selected_profile");
        assert_eq!(effective["namespace"]["contract_source"], "environment");
        assert_eq!(effective["profile"]["contract_source"], "current_profile");
        assert_eq!(
            effective["auth"],
            json!({"type": "token", "transport": "bearer_authorization_header",
            "present": true, "source": "profile_env", "contract_source": "profile_env",
            "env": "TEST_TOKEN", "profile": "prod", "value": "redacted"})
        );
        assert_eq!(effective["tls"]["source"], "flag");
        assert_eq!(effective["identity"]["asserted"], false);
    }
}
