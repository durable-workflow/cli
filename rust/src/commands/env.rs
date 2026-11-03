//! `env:*`: local profile management. These commands never contact a Server.

use crate::cli::{output_option, ArgSpec, CommandSpec, Input, OptSpec};
use crate::config::{Profile, ProfileStore, TokenSource, OUTPUT_MODES};
use crate::error::CliError;
use crate::output::{print_json, print_json_list, render_table, resolve_mode, Mode};
use serde_json::{json, Map, Value};

fn local_mode(input: &Input) -> Result<Mode, CliError> {
    // Profile commands do not apply a profile's default output mode.
    resolve_mode(input, || None)
}

fn describe_token_source(source: &Value) -> String {
    match source.get("type").and_then(Value::as_str) {
        Some("env") => format!(
            "env:{}",
            source.get("env").and_then(Value::as_str).unwrap_or("?")
        ),
        Some("literal") => format!(
            "literal({})",
            source
                .get("value")
                .and_then(Value::as_str)
                .unwrap_or("redacted")
        ),
        _ => "-".into(),
    }
}

fn or_dash(value: &Value) -> String {
    value
        .as_str()
        .map(str::to_string)
        .unwrap_or_else(|| "-".into())
}

fn yes_no(flag: bool) -> String {
    if flag { "yes" } else { "no" }.into()
}

fn with(mut data: Map<String, Value>, extra: &[(&str, Value)]) -> Value {
    for (key, value) in extra {
        data.insert((*key).to_string(), value.clone());
    }
    Value::Object(data)
}

pub fn list_spec() -> CommandSpec {
    CommandSpec {
        name: "env:list",
        description: "List configured dw environment profiles",
        args: vec![],
        opts: vec![
            output_option(),
            OptSpec::flag("json"),
            OptSpec::flag("show-token"),
        ],
    }
}

pub fn list(input: &Input) -> Result<i32, CliError> {
    let mode = local_mode(input)?;
    let store = ProfileStore::new()?;
    let config = store.load()?;
    let show_token = input.flag("show-token");
    let envs: Vec<Value> = config
        .envs
        .values()
        .map(|profile| {
            with(
                profile.describe(show_token),
                &[(
                    "current",
                    json!(config.current_env.as_deref() == Some(profile.name.as_str())),
                )],
            )
        })
        .collect();

    if mode.machine_readable() {
        let envelope = json!({"current_env": config.current_env, "config_path": store.path_string(), "envs": envs});
        print_json_list(mode, &envelope, "envs");
        return Ok(0);
    }

    if envs.is_empty() {
        println!("No dw environments configured.");
        println!("Create one with `dw env:set <name> --server=https://... --namespace=<ns>`.");
        return Ok(0);
    }

    let rows: Vec<Vec<String>> = envs
        .iter()
        .map(|env| {
            vec![
                if env["current"] == json!(true) {
                    "*".into()
                } else {
                    String::new()
                },
                or_dash(&env["name"]),
                or_dash(&env["server"]),
                or_dash(&env["namespace"]),
                describe_token_source(&env["token_source"]),
                yes_no(env["tls_verify"] != json!(false)),
                or_dash(&env["output"]),
            ]
        })
        .collect();
    println!(
        "{}",
        render_table(
            &[
                "Current",
                "Name",
                "Server",
                "Namespace",
                "Token",
                "TLS Verify",
                "Output"
            ],
            &rows
        )
    );
    Ok(0)
}

pub fn show_spec() -> CommandSpec {
    CommandSpec {
        name: "env:show",
        description: "Show details for a dw environment profile",
        args: vec![ArgSpec {
            name: "name",
            required: false,
        }],
        opts: vec![
            output_option(),
            OptSpec::flag("show-token"),
            OptSpec::flag("json"),
        ],
    }
}

pub fn show(input: &Input) -> Result<i32, CliError> {
    let mode = local_mode(input)?;
    let store = ProfileStore::new()?;
    let current = store.load()?.current_env;
    let name = match input.arg("name").filter(|name| !name.is_empty()) {
        Some(name) => name.to_string(),
        None => current.clone().ok_or_else(|| {
            CliError::Invalid(
                "No default dw env is set. Pass a profile name or run `dw env:use <name>`.".into(),
            )
        })?,
    };
    let profile = store.require(&name, "env:show")?;
    let envelope = with(
        profile.describe(input.flag("show-token")),
        &[
            (
                "current",
                json!(current.as_deref() == Some(profile.name.as_str())),
            ),
            ("config_path", json!(store.path_string())),
        ],
    );

    if mode.machine_readable() {
        print_json(&envelope);
        return Ok(0);
    }

    let rows = vec![
        vec!["name".into(), or_dash(&envelope["name"])],
        vec!["current".into(), yes_no(envelope["current"] == json!(true))],
        vec!["server".into(), or_dash(&envelope["server"])],
        vec!["namespace".into(), or_dash(&envelope["namespace"])],
        vec![
            "tls_verify".into(),
            yes_no(envelope["tls_verify"] != json!(false)),
        ],
        vec!["output".into(), or_dash(&envelope["output"])],
        vec![
            "token_source".into(),
            describe_token_source(&envelope["token_source"]),
        ],
        vec!["config_path".into(), or_dash(&envelope["config_path"])],
    ];
    println!("{}", render_table(&["Field", "Value"], &rows));
    Ok(0)
}

pub fn use_spec() -> CommandSpec {
    CommandSpec {
        name: "env:use",
        description: "Set the default dw environment profile",
        args: vec![ArgSpec {
            name: "name",
            required: true,
        }],
        opts: vec![output_option(), OptSpec::flag("json")],
    }
}

pub fn use_env(input: &Input) -> Result<i32, CliError> {
    let mode = local_mode(input)?;
    let store = ProfileStore::new()?;
    let name = input.arg("name").unwrap_or_default();
    let profile = store.set_current(name)?;

    if mode.machine_readable() {
        print_json(&with(
            profile.describe(false),
            &[
                ("current", json!(true)),
                ("config_path", json!(store.path_string())),
            ],
        ));
        return Ok(0);
    }

    println!("Using dw env [{name}].");
    if let Some(server) = &profile.server {
        println!("  server: {server}");
    }
    if let Some(namespace) = &profile.namespace {
        println!("  namespace: {namespace}");
    }
    Ok(0)
}

pub fn delete_spec() -> CommandSpec {
    CommandSpec {
        name: "env:delete",
        description: "Delete a dw environment profile",
        args: vec![ArgSpec {
            name: "name",
            required: true,
        }],
        opts: vec![output_option(), OptSpec::flag("json")],
    }
}

pub fn delete(input: &Input) -> Result<i32, CliError> {
    let mode = local_mode(input)?;
    let store = ProfileStore::new()?;
    let name = input.arg("name").unwrap_or_default();
    let previous = store.load()?.current_env;
    let removed = store.delete(name)?;
    let cleared = previous.as_deref() == Some(name);

    if mode.machine_readable() {
        print_json(&json!({
            "name": removed.name,
            "deleted": true,
            "cleared_current_env": cleared,
            "config_path": store.path_string(),
        }));
        return Ok(0);
    }

    println!("Deleted dw env [{name}].");
    if cleared {
        println!("Cleared the current dw env.");
    }
    Ok(0)
}

pub fn set_spec() -> CommandSpec {
    CommandSpec {
        name: "env:set",
        description: "Create or update a dw environment profile",
        args: vec![ArgSpec {
            name: "name",
            required: true,
        }],
        opts: vec![
            output_option(),
            OptSpec::required("server"),
            OptSpec::required("namespace"),
            OptSpec::required("token-env"),
            OptSpec::required("token"),
            OptSpec::required("tls-verify"),
            OptSpec::required("profile-output"),
            OptSpec::flag("make-default"),
            OptSpec::flag("json"),
        ],
    }
}

fn valid_profile_name(name: &str) -> bool {
    let mut chars = name.chars();
    chars
        .next()
        .is_some_and(|first| first.is_ascii_alphanumeric())
        && chars.all(|c| c.is_ascii_alphanumeric() || matches!(c, '.' | '_' | '-'))
}

fn valid_env_var_name(name: &str) -> bool {
    let mut chars = name.chars();
    chars
        .next()
        .is_some_and(|first| first.is_ascii_uppercase() || first == '_')
        && chars.all(|c| c.is_ascii_uppercase() || c.is_ascii_digit() || c == '_')
}

/// A written option replaces the stored value; an empty value clears it.
fn merge(input: &Input, option: &str, existing: Option<String>) -> Option<String> {
    if input.provided(option) {
        input.opt_str(option).map(str::to_string)
    } else {
        existing
    }
}

pub fn set(input: &Input) -> Result<i32, CliError> {
    let mode = local_mode(input)?;
    let name = input.arg("name").unwrap_or_default();
    if name.is_empty() {
        return Err(CliError::Invalid("Profile name must not be empty.".into()));
    }
    if !valid_profile_name(name) {
        return Err(CliError::Invalid(format!(
            "Invalid profile name [{name}]: must start with a letter or digit and contain only letters, digits, dots, dashes, and underscores."
        )));
    }

    let store = ProfileStore::new()?;
    let existing = store.get(name)?;

    let server = merge(
        input,
        "server",
        existing.as_ref().and_then(|p| p.server.clone()),
    );
    let namespace = merge(
        input,
        "namespace",
        existing.as_ref().and_then(|p| p.namespace.clone()),
    );

    let token_env_given = input.provided("token-env");
    let token_given = input.provided("token");
    if token_env_given
        && token_given
        && input.opt_str("token-env").is_some()
        && input.opt_str("token").is_some()
    {
        return Err(CliError::Invalid(
            "Pass either --token or --token-env, not both.".into(),
        ));
    }
    let token_source = if token_env_given {
        match input.opt_str("token-env") {
            None => None,
            Some(variable) if valid_env_var_name(variable) => Some(TokenSource::Env(variable.to_string())),
            Some(variable) => {
                return Err(CliError::Invalid(format!(
                    "Invalid --token-env value [{variable}]: environment variable names must match /[A-Z_][A-Z0-9_]*/."
                )))
            }
        }
    } else if token_given {
        input
            .opt_str("token")
            .map(|token| TokenSource::Literal(token.to_string()))
    } else {
        existing.as_ref().and_then(|p| p.token_source.clone())
    };

    let tls_verify = if input.provided("tls-verify") {
        crate::config::parse_boolean(
            input.opt_str("tls-verify").unwrap_or_default(),
            "--tls-verify",
        )?
    } else {
        existing.as_ref().map(|p| p.tls_verify).unwrap_or(true)
    };

    let output = if input.provided("profile-output") {
        match input.opt_str("profile-output") {
            None => None,
            Some(value) if OUTPUT_MODES.contains(&value) => Some(value.to_string()),
            Some(_) => {
                return Err(CliError::Invalid(format!(
                    "--profile-output must be one of: {}.",
                    OUTPUT_MODES.join(", ")
                )))
            }
        }
    } else {
        existing.as_ref().and_then(|p| p.output.clone())
    };

    let profile = Profile {
        name: name.to_string(),
        server,
        namespace,
        token_source,
        tls_verify,
        output,
    };
    store.put(profile.clone())?;
    let make_default = input.flag("make-default");
    if make_default {
        store.set_current(name)?;
    }

    if mode.machine_readable() {
        let current = store.load()?.current_env;
        print_json(&with(
            profile.describe(false),
            &[
                ("current", json!(current.as_deref() == Some(name))),
                ("config_path", json!(store.path_string())),
            ],
        ));
        return Ok(0);
    }

    println!("Saved dw env [{name}] to {}.", store.path_string());
    if make_default {
        println!("Set [{name}] as the current dw env.");
    }
    Ok(0)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn profile_and_env_var_names_follow_the_2x_rules() {
        assert!(valid_profile_name("prod-eu.1_a"));
        assert!(!valid_profile_name("-prod"));
        assert!(!valid_profile_name("bad name"));
        assert!(valid_env_var_name("_DW_TOKEN2"));
        assert!(!valid_env_var_name("dw_token"));
        assert!(!valid_env_var_name("2TOKEN"));
    }
}
