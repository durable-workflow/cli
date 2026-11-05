//! `server:*` commands.

use crate::client;
use crate::commands::info;
use crate::compat::RequestContract;
use crate::error::CliError;
use crate::formatter::writeln_out;
use crate::output::{format_status, print_json, render_table};
use serde_json::Value;

fn is_healthy(status: &str) -> bool {
    matches!(
        status.to_lowercase().as_str(),
        "ok" | "healthy" | "success" | "succeeded"
    )
}

fn check_action(check: &str) -> &'static str {
    let normalized = check.to_lowercase().replace(['-', ' '], "_");
    if normalized.contains("database") || normalized.contains("mysql") {
        "Check database connectivity and migrations."
    } else if normalized.contains("redis") || normalized.contains("cache") {
        "Check Redis/cache connectivity."
    } else if normalized.contains("queue") || normalized.contains("worker") {
        "Check workers and queue consumers."
    } else if normalized.contains("storage") || normalized.contains("payload") {
        "Check payload storage configuration."
    } else {
        "Inspect the server component named by this check."
    }
}

/// A check status as text. Native Server reports checks as booleans
/// (`{"database": true}`) where PHP Server reports `"ok"`.
fn check_status(value: &Value) -> String {
    match value {
        Value::Bool(true) => "ok".into(),
        Value::Bool(false) => "failed".into(),
        other => scalar_text(other).unwrap_or_else(|| "unknown".into()),
    }
}

fn scalar_text(value: &Value) -> Option<String> {
    match value {
        Value::String(text) => Some(text.clone()),
        Value::Number(number) => Some(number.to_string()),
        Value::Bool(flag) => Some(if *flag { "1".into() } else { String::new() }),
        _ => None,
    }
}

pub fn health(context: &client::Context, _input: &crate::cli::Input) -> Result<i32, CliError> {
    let result = context
        .runtime()
        .block_on(async { context.sdk()?.health().await.map_err(client::map_error) })?;

    if context.mode.machine_readable() {
        print_json(&result);
        return Ok(0);
    }

    writeln_out(&format!(
        "Server is {}",
        format_status(result.get("status"))
    ));
    // Native Server's health response has no timestamp.
    if let Some(timestamp) = result.get("timestamp").and_then(scalar_text) {
        writeln_out(&format!("Timestamp: {timestamp}"));
    }

    let mut unhealthy = Vec::new();
    if let Some(checks) = result
        .get("checks")
        .and_then(Value::as_object)
        .filter(|checks| !checks.is_empty())
    {
        let rows: Vec<Vec<String>> = checks
            .iter()
            .map(|(name, status)| {
                let status = check_status(status);
                let healthy = is_healthy(&status);
                if !healthy {
                    unhealthy.push(name.clone());
                }
                vec![
                    name.clone(),
                    format_status(Some(&Value::String(status))),
                    if healthy {
                        "-".into()
                    } else {
                        check_action(name).into()
                    },
                ]
            })
            .collect();
        writeln_out(&render_table(&["Check", "Status", "Action"], &rows));
    }

    if !unhealthy.is_empty() {
        writeln_out("");
        writeln_out("<comment>Next steps:</comment>");
        writeln_out("  - Run `dw doctor` with the same --env or --server options for connection and auth context.");
        writeln_out(&format!(
            "  - Inspect server logs and dependencies for: {}.",
            unhealthy.join(", ")
        ));
    }
    Ok(0)
}

/// `server:info`: cluster info after the compatibility preflight.
pub fn info(context: &client::Context, _input: &crate::cli::Input) -> Result<i32, CliError> {
    let cluster_info = context.preflight(false)?;
    if context.mode.machine_readable() {
        print_json(&cluster_info);
        return Ok(0);
    }
    let contract = RequestContract::from_cluster_info(&cluster_info).ok();
    writeln_out(&info::render(&cluster_info, contract.as_ref()));
    Ok(0)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn unhealthy_checks_get_a_component_action() {
        assert!(is_healthy("OK"));
        assert!(!is_healthy("degraded"));
        assert_eq!(
            check_action("mysql-primary"),
            "Check database connectivity and migrations."
        );
        assert_eq!(
            check_action("Queue Workers"),
            "Check workers and queue consumers."
        );
        assert_eq!(
            check_action("clock"),
            "Inspect the server component named by this check."
        );
        assert_eq!(check_status(&Value::Bool(true)), "ok");
        assert_eq!(check_status(&Value::Bool(false)), "failed");
    }
}
