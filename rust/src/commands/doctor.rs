//! Connection and compatibility diagnostics, without the session preflight:
//! `doctor` must still explain a Server this CLI cannot operate against.

use crate::cli::Input;
use crate::config::ProfileStore;
use crate::error::{exit, CliError};
use crate::formatter::writeln_out;
use crate::{build_info, client, compat, formatter, output, php};
use serde_json::{json, Value};
use std::fmt::Write as _;

pub fn run(input: &Input) -> Result<i32, CliError> {
    let context = client::Context::new(input)?;
    let config_path = ProfileStore::new()?.path_string();
    let effective = context.connection.effective_config(&config_path);
    let mut diagnostic = json!({
        "dw": {"version": build_info::version(), "commit": build_info::commit(),
            "build_date": build_info::build_date()},
        "connection": {
            "server": context.connection.server,
            "namespace": context.connection.namespace,
            "profile": {"name": effective["profile"]["name"],
                "source": effective["profile"]["source"], "config_path": config_path},
            "token": {"present": effective["auth"]["present"], "source": effective["auth"]["source"],
                "env": effective["auth"]["env"], "profile": effective["auth"]["profile"]},
            "tls": {"verify": context.connection.tls_verify, "source": effective["tls"]["source"]},
            "effective_config": effective,
        },
        "server": {"reachable": false}, "warnings": [], "recommendations": [],
    });
    let code = match context.cluster_info(None) {
        Ok(info) => {
            diagnostic["server"] = server_diagnostic(&info);
            diagnostic["warnings"] = json!(compat::warnings(&info, build_info::version(), true));
            exit::SUCCESS
        }
        Err(error) => {
            diagnostic["server"]["error"] = json!(error.message());
            // Local client-construction failures are Throwable in PHP;
            // transport and HTTP errors retain their ServerException category.
            match error {
                CliError::Invalid(_) | CliError::Compatibility(_) => exit::FAILURE,
                _ => error.exit_code(),
            }
        }
    };
    diagnostic["recommendations"] = json!(recommendations(&diagnostic, code));
    if context.mode.machine_readable() {
        output::print_json(&diagnostic);
    } else {
        writeln_out(render(&diagnostic).trim_end_matches('\n'));
    }
    Ok(code)
}

fn scalar(value: &Value, key: &str) -> Value {
    php::get(value, key)
        .filter(|value| php::is_scalar(Some(value)))
        .map(|value| json!(php::to_string(Some(value))))
        .unwrap_or(Value::Null)
}

fn integer(value: &Value, key: &str) -> Value {
    php::get(value, key)
        .filter(|value| php::is_scalar(Some(value)))
        .map(|value| json!(php::int_cast(Some(value))))
        .unwrap_or(Value::Null)
}

fn array(value: &Value, key: &str) -> Value {
    php::get(value, key)
        .filter(|value| php::is_array(value))
        .cloned()
        .unwrap_or(Value::Null)
}

fn auth_diagnostic(info: &Value) -> Value {
    let contract = array(info, "auth_composition_contract");
    if contract.is_null() {
        return Value::Null;
    }
    json!({"schema": scalar(&contract, "schema"), "version": integer(&contract, "version"),
        "scope": scalar(&contract, "scope"),
        "canonical_environment": array(&contract, "canonical_environment"),
        "auth_material": array(&contract, "auth_material")})
}

fn control_diagnostic(info: &Value) -> Value {
    let control = array(info, "control_plane");
    if control.is_null() {
        return Value::Null;
    }
    let contract = array(&control, "request_contract");
    json!({"version": scalar(&control, "version"), "header": scalar(&control, "header"),
    "request_contract": if contract.is_null() {Value::Null} else {
        json!({"schema": scalar(&contract, "schema"), "version": scalar(&contract, "version")})
    }})
}

fn invocable_diagnostic(worker: &Value) -> Value {
    let contract = array(worker, "invocable_carrier_contract");
    if contract.is_null() {
        return Value::Null;
    }
    let kinds = &contract["scope"]["task_kinds"];
    json!({
        "schema": scalar(&contract, "schema"), "version": integer(&contract, "version"),
        "carrier_type": scalar(&contract, "carrier_type"),
        "task_kinds": if php::is_array(kinds) {json!(php::values(kinds))} else {json!([])},
        "request_content_type": scalar(&contract["request"], "content_type"),
        "response_content_type": scalar(&contract["response"], "content_type"),
        "idempotency_key_source": scalar(&contract["request"], "idempotency_key_source"),
        "retry_authority": scalar(&contract["rollout_safety"], "retry_authority"),
    })
}

fn worker_diagnostic(info: &Value) -> Value {
    let worker = array(info, "worker_protocol");
    if worker.is_null() {
        return Value::Null;
    }
    json!({"version": scalar(&worker, "version"),
        "server_capabilities": worker.get("server_capabilities"),
        "invocable_carrier_contract": invocable_diagnostic(&worker),
        "external_task_input_contract": compat::input_diagnostic(info),
        "external_task_result_contract": compat::result_diagnostic(info)})
}

fn server_diagnostic(info: &Value) -> Value {
    json!({"reachable": true, "version": scalar(info, "version"),
        "server_id": scalar(info, "server_id"), "default_namespace": scalar(info, "default_namespace"),
        "auth_composition_contract": auth_diagnostic(info),
        "control_plane": control_diagnostic(info), "worker_protocol": worker_diagnostic(info),
        "client_compatibility": info.get("client_compatibility"), "cluster_info": info})
}

fn advice(id: &str, severity: &str, message: &str, command: Option<&str>) -> Value {
    let mut value = json!({"id": id, "severity": severity, "message": message});
    if let Some(command) = command {
        value["command"] = json!(command);
    }
    value
}

fn recommendations(diagnostic: &Value, code: i32) -> Vec<Value> {
    let mut result = Vec::new();
    let connection = &diagnostic["connection"];
    let reachable = diagnostic["server"]["reachable"] == true;
    if !reachable {
        result.push(advice(
            if code == exit::NETWORK {"server.unreachable"} else {"server.error"}, "error",
            if code == exit::NETWORK {
                "Check the server URL, DNS, port, and TLS settings for the selected environment."
            } else {
                "Inspect the server error, then retry with --output=json if support needs the full diagnostic payload."
            },
            Some(&format!("dw doctor --server={} --output=json", text(&connection["server"], "http://localhost:8080"))),
        ));
    }
    if connection["token"]["present"] != true {
        result.push(advice(
            "auth.token_missing",
            "warning",
            "Set an auth token if the target server requires authenticated operator requests.",
            Some("dw env:set <name> --token-env=DURABLE_WORKFLOW_AUTH_TOKEN"),
        ));
    }
    if connection["tls"]["verify"] == false {
        result.push(advice("tls.verification_disabled", "warning",
            "Re-enable TLS verification before using this profile outside a local or disposable test server.",
            Some("dw env:set <name> --tls-verify=true")));
    }
    if diagnostic["warnings"]
        .as_array()
        .is_some_and(|warnings| !warnings.is_empty())
    {
        result.push(advice(
            "compatibility.warning",
            "warning",
            "Review protocol compatibility before running mutating workflow or worker commands.",
            Some("dw server:info --output=json"),
        ));
    }
    if result.is_empty() {
        result.push(advice("doctor.clean", "info",
            "Connection, auth discovery, TLS settings, and advertised protocol metadata look ready for CLI operations.", None));
    }
    if reachable {
        result.push(advice("semantics.execution_contract", "info", concat!(
            "Duplicate activity attempts and replayed workflow tasks are normal v2 behaviour. ",
            "Consult durable-workflow/workflow:docs/architecture/execution-guarantees.md for the at-least-once, ",
            "deterministic-replay, and exactly-once-at-the-durable-state-layer guarantees, and ",
            "use activity_execution_id / workflow_command_id as the framework idempotency surfaces."), None));
        result.push(advice(
            "semantics.worker_compatibility_contract",
            "info",
            concat!(
            "Mixed-version deployments are driven by opaque compatibility markers. ",
            "Consult durable-workflow/workflow:docs/architecture/worker-compatibility.md for the ",
            "run-pinned-at-start, inheritance-through-retry-continue-as-new-and-child-runs, ",
            "claim-time-enforcement, and no-compatible-worker-is-an-explicit-operational-state ",
            "guarantees, plus the operator-driven rollout-and-rollback posture and the ",
            "heartbeat-TTL ceiling that bound fleet staleness."),
            None,
        ));
    }
    result
}

fn text(value: &Value, fallback: &str) -> String {
    php::or(Some(value), fallback)
}

/// Only trusted render templates may create terminal formatting or layout.
/// Keep remote/control text visible as data without changing machine JSON.
fn display(value: &Value, fallback: &str) -> String {
    formatter::display_text(&text(value, fallback))
}

fn line(out: &mut String, label: &str, value: &Value) {
    writeln!(out, "  {label}: {}", display(value, "unknown")).expect("write to string");
}

fn render(diagnostic: &Value) -> String {
    let mut out = String::from("<info>dw doctor</info>\n");
    let dw = &diagnostic["dw"];
    for (label, key) in [
        ("CLI Version", "version"),
        ("Commit", "commit"),
        ("Built", "build_date"),
    ] {
        line(&mut out, label, &dw[key]);
    }
    out.push_str("\nConnection:\n");
    let connection = &diagnostic["connection"];
    line(&mut out, "Server", &connection["server"]);
    line(&mut out, "Namespace", &connection["namespace"]);
    let profile = &connection["profile"];
    let name = if profile["name"].is_null() {
        "none".into()
    } else {
        format!(
            "{} ({})",
            text(&profile["name"], "unknown"),
            text(&profile["source"], "unknown")
        )
    };
    line(&mut out, "Profile", &json!(name));
    writeln!(
        out,
        "  Token: {} via {}",
        if connection["token"]["present"] == true {
            "present"
        } else {
            "not set"
        },
        display(&connection["token"]["source"], "none")
    )
    .expect("write to string");
    writeln!(
        out,
        "  TLS Verify: {}",
        if connection["tls"]["verify"] == false {
            "no"
        } else {
            "yes"
        }
    )
    .expect("write to string");
    out.push_str("\nServer:\n");
    render_server(&mut out, &diagnostic["server"]);
    if let Some(warnings) = diagnostic["warnings"]
        .as_array()
        .filter(|warnings| !warnings.is_empty())
    {
        out.push_str("\nWarnings:\n");
        for warning in warnings {
            writeln!(out, "  {}", display(warning, "")).expect("write to string");
        }
    }
    if let Some(recommendations) = diagnostic["recommendations"]
        .as_array()
        .filter(|items| !items.is_empty())
    {
        out.push_str("\nNext steps:\n");
        for recommendation in recommendations {
            writeln!(out, "  - {}", display(&recommendation["message"], ""))
                .expect("write to string");
            if let Some(command) = recommendation.get("command") {
                writeln!(out, "    Try: {}", display(command, "")).expect("write to string");
            }
        }
    }
    out
}

fn render_server(out: &mut String, server: &Value) {
    if server["reachable"] != true {
        out.push_str("  Reachable: no\n");
        line(out, "Error", &server["error"]);
        return;
    }
    out.push_str("  Reachable: yes\n");
    for (label, key) in [
        ("Server ID", "server_id"),
        ("Version", "version"),
        ("Default Namespace", "default_namespace"),
    ] {
        line(out, label, &server[key]);
    }
    if !server["control_plane"].is_null() {
        line(out, "Control Plane", &server["control_plane"]["version"]);
    }
    let worker = &server["worker_protocol"];
    if !worker.is_null() {
        line(out, "Worker Protocol", &worker["version"]);
        let invocable = &worker["invocable_carrier_contract"];
        if !invocable.is_null() {
            writeln!(
                out,
                "  Invocable Carrier: {} v{} ({})",
                display(&invocable["schema"], "unknown"),
                display(&invocable["version"], "unknown"),
                display(&invocable["carrier_type"], "unknown")
            )
            .expect("write to string");
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn human_connection_fields_cannot_create_markup_or_layout() {
        let value = "<error>untrusted</error>\nForged:\u{1b}]0;title\u{7}\u{9b}31m";
        let diagnostic = json!({
            "dw": {},
            "connection": {"server": value, "namespace": value,
                "profile": {"name": value, "source": value},
                "token": {"source": value}},
            "server": {"reachable": false, "error": value},
            "warnings": [value],
            "recommendations": [{"message": value, "command": value}]
        });
        let human = formatter::format(&render(&diagnostic), false);
        assert!(human.contains("<error>untrusted</error>\\nForged:"));
        assert!(!human.contains("\nForged:"));
        assert!(!human.chars().any(|c| c.is_control() && c != '\n'));
        assert_eq!(diagnostic["connection"]["namespace"], value);
    }

    #[test]
    fn published_metadata_keeps_fixture_summaries_and_order() {
        let info: Value = serde_json::from_str(include_str!(
            "../compat/fixtures/cluster-info-php-2.11.8.json"
        ))
        .unwrap();
        let server = server_diagnostic(&info);
        assert_eq!(server["cluster_info"], info);
        let worker = &server["worker_protocol"];
        assert_eq!(
            worker["external_task_input_contract"]["fixtures"],
            json!(["workflow_task", "activity_task"])
        );
        assert_eq!(worker["external_task_input_contract"]["valid"], true);
        assert_eq!(worker["external_task_result_contract"]["valid"], true);
        assert_eq!(
            worker["invocable_carrier_contract"]["task_kinds"],
            json!(["activity_task"])
        );
    }

    #[test]
    fn malformed_metadata_is_reported_without_refusing_diagnostics() {
        let info = json!({"version": false, "server_id": {}, "control_plane": [],
            "worker_protocol": {"version": 7, "external_task_input_contract": {"version": "2x", "fixtures": {"unknown": {}, "activity_task": {}}}}});
        let server = server_diagnostic(&info);
        assert_eq!(server["reachable"], true);
        assert_eq!(server["version"], "");
        assert_eq!(server["server_id"], Value::Null);
        assert_eq!(server["control_plane"]["version"], Value::Null);
        let input = &server["worker_protocol"]["external_task_input_contract"];
        assert_eq!(input["version"], 2);
        assert_eq!(input["fixtures"], json!(["activity_task"]));
        assert_eq!(input["valid"], false);
    }

    #[test]
    fn failed_connection_keeps_action_order_and_exit_category() {
        let diagnostic = json!({"connection": {"server": "http://127.0.0.1:9", "token": {"present": false}, "tls": {"verify": false}},
            "server": {"reachable": false}, "warnings": []});
        let advice = recommendations(&diagnostic, exit::NETWORK);
        assert_eq!(
            advice
                .iter()
                .map(|item| item["id"].as_str().unwrap())
                .collect::<Vec<_>>(),
            vec![
                "server.unreachable",
                "auth.token_missing",
                "tls.verification_disabled"
            ]
        );
        assert_eq!(
            recommendations(&diagnostic, exit::AUTH)[0]["id"],
            "server.error"
        );
    }
}
