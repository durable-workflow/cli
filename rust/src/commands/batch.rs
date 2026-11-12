//! Resumable bulk operations (cli#60): `batch:cancel`, `batch:signal`,
//! `batch:show` and `batch:resume`.
//!
//! A batch writes its whole selection to a private manifest before the
//! first mutation, then records each target's outcome as it happens. Each
//! target is pinned to the run its listing reported (cli#59) and carries a
//! stable command key. Resume never relists: it continues only targets
//! without a definite outcome, with the same key, and never resends a
//! target whose admission is unknown.

use crate::batch_manifest::{self, Manifest, State};
use crate::cli::Input;
use crate::client::Context;
use crate::commands::workflow_control::{confirm, filters, select, Filters};
use crate::commands::workflow_message::message_path;
use crate::error::CliError;
use crate::output::{self, Mode};
use crate::{build_info, formatter, payload, php};
use serde_json::{json, Map, Value};
use std::path::Path;
use std::time::{Duration, Instant};

/// The most targets one batch may select.
const MAX_LIMIT: i64 = 1000;
/// Requests per target, counting the first, while the Server reports pressure.
const MAX_ATTEMPTS: u64 = 3;
/// The longest a batch waits on `Retry-After` before retrying.
const MAX_RETRY_WAIT: Duration = Duration::from_secs(60);
/// The wait under pressure when the Server gives no `Retry-After`.
const DEFAULT_RETRY_WAIT: Duration = Duration::from_secs(1);
const MAX_RATE: i64 = 100;
const SELECTION_NOTE: &str = "Visibility pages are not a transactional snapshot: these are the workflows and runs observed while paging. Resume never relists.";

pub fn cancel(context: &Context, input: &Input) -> Result<i32, CliError> {
    let mut parameters = Map::new();
    parameters.insert(
        "reason".into(),
        json!(input.opt("reason").flatten().map(str::to_owned)),
    );
    start(context, input, "cancel", parameters)
}

pub fn signal(context: &Context, input: &Input) -> Result<i32, CliError> {
    let signal_name = php::trim(input.arg("signal-name").unwrap_or_default());
    if signal_name.is_empty() {
        return Err(CliError::Invalid(
            "The signal name must not be empty.".into(),
        ));
    }
    // The input is decoded once, checked as the request will carry it, and
    // kept in the manifest so resume sends exactly the same intent.
    let arguments = payload::parse_arguments(input)?;
    let mut probe = Map::new();
    if let Some(arguments) = &arguments {
        probe.insert("input".into(), arguments.value.clone());
    }
    payload::request_body(Value::Object(probe), &[arguments.as_ref()])?;
    let mut parameters = Map::new();
    parameters.insert("signal_name".into(), json!(signal_name));
    if let Some(arguments) = arguments {
        parameters.insert("input".into(), arguments.value);
    }
    start(context, input, "signal", parameters)
}

pub fn show(input: &Input) -> Result<i32, CliError> {
    let mode = output::resolve_mode(input, || None)?;
    let manifest = Manifest::open(&manifest_path(input.arg("manifest"))?, false)?;
    report(&manifest, mode, None, "Batch")
}

pub fn resume(context: &Context, input: &Input) -> Result<i32, CliError> {
    let path = manifest_path(input.arg("manifest"))?;
    let rate = rate(input)?;
    let json_mode = context.mode.machine_readable();
    require_yes_for_json(input, json_mode)?;
    let mut manifest = Manifest::open(&path, true)?;
    let server = manifest.header_str("server").to_owned();
    if trim_url(&server) != trim_url(&context.connection.server) {
        return Err(CliError::Invalid(format!(
            "This batch was recorded against {}, not {}. Resume it with --server={} (and the same namespace).",
            server, context.connection.server, server
        )));
    }
    let namespace = manifest.header_str("namespace").to_owned();
    if namespace != context.connection.namespace {
        return Err(CliError::Invalid(format!(
            "This batch was recorded in namespace {namespace}, not {}. Resume it with --namespace={namespace}.",
            context.connection.namespace
        )));
    }
    // Make an interrupted dispatch's unknown outcome a definite record.
    let interrupted: Vec<usize> = manifest
        .targets
        .iter()
        .filter(|target| target.state == State::Unknown && target.last_outcome.is_none())
        .map(|target| target.index)
        .collect();
    for index in interrupted {
        let mut details = Map::new();
        details.insert("reason".into(), json!("interrupted"));
        details.insert(
            "message".into(),
            json!("The request was dispatched but no outcome was recorded before the previous run stopped."),
        );
        manifest.outcome(index, State::Unknown, details)?;
    }
    let eligible = eligible(&manifest).len();
    if eligible == 0 {
        return report(&manifest, context.mode, None, "Nothing to resume");
    }
    let operation = manifest.header_str("operation").to_owned();
    if !input.flag("yes")
        && !confirm(
            input,
            &format!(
                "Resume {} of {} {operation} target{} recorded in {}? [y/N] ",
                eligible,
                manifest.targets.len(),
                if manifest.targets.len() == 1 { "" } else { "s" },
                formatter::escape(&path.display().to_string())
            ),
        )
    {
        formatter::writeln_out("<comment>Batch resume aborted.</comment>");
        return Ok(0);
    }
    context.preflight(false)?;
    let stopped = dispatch(context, &mut manifest, rate)?;
    report(&manifest, context.mode, stopped.as_ref(), "Batch resumed")
}

/// Select, record, confirm and dispatch a new batch.
fn start(
    context: &Context,
    input: &Input,
    operation: &str,
    parameters: Map<String, Value>,
) -> Result<i32, CliError> {
    let query = php::trim(input.opt("query").flatten().unwrap_or_default());
    if query.is_empty() {
        return Err(CliError::Invalid(
            "--query must be a non-empty visibility query.".into(),
        ));
    }
    let path = manifest_path(input.opt("manifest").flatten())?;
    if path.exists() {
        return Err(CliError::Invalid(format!(
            "The manifest {} already exists. Choose a new path, or resume it with dw batch:resume.",
            path.display()
        )));
    }
    let rate = rate(input)?;
    let json_mode = context.mode.machine_readable();
    require_yes_for_json(input, json_mode)?;
    let info = context.preflight(false)?;
    let Filters {
        workflow_type,
        status,
        limit,
    } = filters(input, &info)?;
    if limit > MAX_LIMIT {
        return Err(CliError::Invalid(format!(
            "--limit must be at most {MAX_LIMIT} for a batch."
        )));
    }
    let selected = select(
        context,
        query,
        workflow_type,
        status,
        limit,
        "Nothing was sent and no manifest was written.",
    )?;
    let namespace = context.connection.namespace.clone();
    if selected.is_empty() {
        if json_mode {
            output::print_json(&json!({
                "operation": operation,
                "namespace": namespace,
                "query": query,
                "matched": 0,
                "manifest": null,
            }));
        } else {
            formatter::writeln_out(&format!(
                "<comment>No workflows matched in namespace {}. No manifest was written.</comment>",
                formatter::display_text(&namespace)
            ));
        }
        return Ok(0);
    }
    if !input.flag("yes")
        && !confirm(
            input,
            &format!(
                "{} {} workflow{} matching [{}]? Progress is recorded in {}. [y/N] ",
                php::ucfirst(operation),
                selected.len(),
                if selected.len() == 1 { "" } else { "s" },
                formatter::escape(query),
                formatter::escape(&path.display().to_string())
            ),
        )
    {
        formatter::writeln_out("<comment>Batch aborted. No manifest was written.</comment>");
        return Ok(0);
    }
    let manifest_id = batch_manifest::new_id()?;
    let targets: Vec<Value> = selected
        .iter()
        .enumerate()
        .map(|(index, target)| {
            json!({
                "index": index,
                "workflow_id": target.workflow_id,
                "run_id": target.run_id,
                "command_key": format!("batch-{manifest_id}-{index}"),
            })
        })
        .collect();
    let mut header = Map::new();
    header.insert("schema".into(), json!(batch_manifest::SCHEMA));
    header.insert("manifest_id".into(), json!(manifest_id));
    header.insert("created_at".into(), json!(batch_manifest::utc_now()));
    header.insert("cli_version".into(), json!(build_info::version()));
    header.insert("server".into(), json!(context.connection.server));
    header.insert("namespace".into(), json!(namespace));
    header.insert("operation".into(), json!(operation));
    header.insert("parameters".into(), Value::Object(parameters));
    header.insert(
        "selection".into(),
        json!({
            "query": query,
            "workflow_type": workflow_type,
            "status": status,
            "limit": limit,
            "note": SELECTION_NOTE,
        }),
    );
    header.insert("targets".into(), Value::Array(targets));
    let mut manifest = Manifest::create(&path, header)?;
    let stopped = dispatch(context, &mut manifest, rate)?;
    report(&manifest, context.mode, stopped.as_ref(), "Batch")
}

/// Why a run stopped before every eligible target was sent.
struct Stop {
    reason: &'static str,
    message: String,
}

/// Targets a run may send: never dispatched, or still under pressure with
/// attempts left.
fn eligible(manifest: &Manifest) -> Vec<usize> {
    manifest
        .targets
        .iter()
        .filter(|target| match target.state {
            State::Pending => true,
            State::Pressure => target.attempts < MAX_ATTEMPTS,
            _ => false,
        })
        .map(|target| target.index)
        .collect()
}

/// Send each eligible target in order, one request at a time, recording a
/// `dispatching` event before and an outcome after each request. Stops at
/// the first unknown outcome, authentication refusal or exhausted pressure,
/// so the remaining targets stay pending for a later resume.
fn dispatch(
    context: &Context,
    manifest: &mut Manifest,
    rate: u32,
) -> Result<Option<Stop>, CliError> {
    let operation = manifest.header_str("operation").to_owned();
    let parameters = manifest
        .header
        .get("parameters")
        .and_then(Value::as_object)
        .cloned()
        .unwrap_or_default();
    let interval = Duration::from_secs_f64(1.0 / f64::from(rate));
    let mut last_sent: Option<Instant> = None;
    for index in eligible(manifest) {
        let target = &manifest.targets[index];
        let Some(run_id) = target.run_id.clone() else {
            let mut details = Map::new();
            details.insert("reason".into(), json!("run_id_unavailable"));
            details.insert(
                "message".into(),
                json!("The workflow list did not report a run ID, so this workflow was not addressed."),
            );
            manifest.outcome(index, State::Skipped, details)?;
            continue;
        };
        let workflow_id = target.workflow_id.clone();
        let key = target.command_key.clone();
        let (segments, path, body): (Vec<String>, String, Value) = match operation.as_str() {
            "cancel" => (
                ["workflows", &workflow_id, "runs", &run_id, "cancel"]
                    .map(str::to_owned)
                    .to_vec(),
                format!("/workflows/{workflow_id}/runs/{run_id}/cancel"),
                cancel_body(&parameters),
            ),
            "signal" => {
                let name = parameters
                    .get("signal_name")
                    .and_then(Value::as_str)
                    .unwrap_or_default();
                let (segments, path) = message_path(&workflow_id, Some(&run_id), "signal", name);
                let mut body = Map::new();
                body.insert("request_id".into(), json!(key));
                if let Some(input) = parameters.get("input") {
                    body.insert("input".into(), input.clone());
                }
                (
                    segments.into_iter().map(str::to_owned).collect(),
                    path,
                    Value::Object(body),
                )
            }
            other => {
                return Err(CliError::Invalid(format!(
                    "{} records an unsupported operation [{other}].",
                    manifest.path.display()
                )))
            }
        };
        let segments: Vec<&str> = segments.iter().map(String::as_str).collect();
        loop {
            if let Some(sent) = last_sent {
                std::thread::sleep(interval.saturating_sub(sent.elapsed()));
            }
            manifest.dispatching(index)?;
            last_sent = Some(Instant::now());
            let (result, retry_after) =
                context.workflow_post_observed(&segments, body.clone(), &path);
            let (state, details) = classify(result, retry_after);
            manifest.outcome(index, state, details)?;
            match state {
                State::Pressure if manifest.targets[index].attempts < MAX_ATTEMPTS => {
                    let wait = retry_after
                        .map(Duration::from_secs)
                        .unwrap_or(DEFAULT_RETRY_WAIT)
                        .min(MAX_RETRY_WAIT);
                    std::thread::sleep(wait);
                }
                State::Pressure => {
                    return Ok(Some(Stop {
                        reason: "pressure",
                        message: format!(
                            "The Server kept asking to slow down after {MAX_ATTEMPTS} attempts for {workflow_id}. Resume later; the remaining targets were not sent."
                        ),
                    }))
                }
                State::Unknown => {
                    return Ok(Some(Stop {
                        reason: "unknown",
                        message: format!(
                            "The outcome for {workflow_id} is unknown, so the batch stopped. Check its history and command receipts before deciding anything; resume never resends it."
                        ),
                    }))
                }
                State::Pending => {
                    return Ok(Some(Stop {
                        reason: "authentication",
                        message: "The Server refused the credentials. Fix them and resume; the refused target was not admitted and will be sent again.".into(),
                    }))
                }
                _ => break,
            }
        }
    }
    Ok(None)
}

fn cancel_body(parameters: &Map<String, Value>) -> Value {
    match parameters.get("reason").and_then(Value::as_str) {
        Some(reason) => json!({"reason": reason}),
        None => Value::Array(Vec::new()),
    }
}

/// Map one response to a target state. Anything that may have been admitted
/// without a definite answer is `unknown`.
fn classify(
    result: Result<Value, CliError>,
    retry_after: Option<u64>,
) -> (State, Map<String, Value>) {
    let mut details = Map::new();
    let state = match &result {
        Ok(body) => {
            details.insert("http_status".into(), json!(200));
            for key in ["outcome", "command_id", "command_status"] {
                if let Some(value) = body.get(key).filter(|value| !value.is_null()) {
                    details.insert(key.into(), value.clone());
                }
            }
            State::Acknowledged
        }
        Err(CliError::Http { status, body, .. }) => {
            details.insert("http_status".into(), json!(status));
            let reason = body
                .as_ref()
                .and_then(|body| body.get("reason"))
                .and_then(Value::as_str)
                .filter(|reason| !reason.is_empty());
            if let Some(reason) = reason {
                details.insert("reason".into(), json!(reason));
            }
            match status {
                429 => State::Pressure,
                503 if retry_after.is_some() => State::Pressure,
                401 | 403 => State::Pending,
                404 | 410 => State::Skipped,
                409 if !reason.is_some_and(|reason| reason.contains("request_id")) => {
                    State::Skipped
                }
                400..=499 => State::Refused,
                _ => State::Unknown,
            }
        }
        // An invalid identifier never leaves the client.
        Err(CliError::Invalid(_)) => State::Refused,
        Err(CliError::Compatibility(_)) => State::Pending,
        Err(_) => State::Unknown,
    };
    if let Err(error) = &result {
        details.insert("message".into(), json!(error.message()));
    }
    if let Some(seconds) = retry_after {
        details.insert("retry_after".into(), json!(seconds));
    }
    (state, details)
}

/// Print a batch's state: JSON for machines, a summary for people. Exit 0
/// only when every target ended acknowledged or skipped.
fn report(
    manifest: &Manifest,
    mode: Mode,
    stopped: Option<&Stop>,
    title: &str,
) -> Result<i32, CliError> {
    let complete = manifest
        .targets
        .iter()
        .all(|target| matches!(target.state, State::Acknowledged | State::Skipped));
    let exit = if complete { 0 } else { 1 };
    let unknown: Vec<&batch_manifest::Target> = manifest
        .targets
        .iter()
        .filter(|target| target.state == State::Unknown)
        .collect();
    if mode.machine_readable() {
        output::print_json(&json!({
            "manifest": manifest.path.display().to_string(),
            "manifest_id": manifest.header_str("manifest_id"),
            "schema": batch_manifest::SCHEMA,
            "operation": manifest.header_str("operation"),
            "server": manifest.header_str("server"),
            "namespace": manifest.header_str("namespace"),
            "selection": manifest.header.get("selection"),
            "matched": manifest.targets.len(),
            "counts": manifest.counts(),
            "complete": complete,
            "stopped": stopped.map(|stop| json!({"reason": stop.reason, "message": stop.message})),
            "targets": manifest.targets.iter().map(batch_manifest::Target::to_json).collect::<Vec<_>>(),
        }));
        return Ok(exit);
    }
    let text = formatter::display_text;
    formatter::writeln_out(&format!(
        "<info>{title}: {} {} target{}</info>",
        text(manifest.header_str("operation")),
        manifest.targets.len(),
        if manifest.targets.len() == 1 { "" } else { "s" }
    ));
    formatter::writeln_out(&format!(
        "  Manifest: {}",
        text(&manifest.path.display().to_string())
    ));
    formatter::writeln_out(&format!(
        "  Namespace: {}",
        text(manifest.header_str("namespace"))
    ));
    for (state, count) in manifest.counts() {
        formatter::writeln_out(&format!("  {}: {count}", php::ucfirst(&state)));
    }
    if let Some(stop) = stopped {
        formatter::writeln_out(&format!(
            "<comment>Stopped: {}</comment>",
            text(&stop.message)
        ));
    }
    if !unknown.is_empty() {
        formatter::writeln_out("<comment>Unknown outcomes (never resent automatically):</comment>");
        for target in unknown {
            formatter::writeln_out(&format!(
                "  - {} (run {}) key {}",
                text(&target.workflow_id),
                text(target.run_id.as_deref().unwrap_or("unknown")),
                text(&target.command_key)
            ));
        }
        formatter::writeln_out(
            "  Inspect each workflow's history and command receipts (dw workflow:describe, dw workflow:history) before acting.",
        );
    }
    if !complete {
        formatter::writeln_out(&format!(
            "Next: dw batch:show {0}   |   dw batch:resume {0}",
            text(&manifest.path.display().to_string())
        ));
    }
    Ok(exit)
}

fn manifest_path(value: Option<&str>) -> Result<std::path::PathBuf, CliError> {
    value
        .map(str::trim)
        .filter(|value| !value.is_empty())
        .map(|value| Path::new(value).to_path_buf())
        .ok_or_else(|| CliError::Invalid("A manifest path is required (--manifest=FILE).".into()))
}

fn rate(input: &Input) -> Result<u32, CliError> {
    input
        .opt("rate")
        .flatten()
        .filter(|value| php::ctype_digit(value))
        .map(php::string_to_int)
        .filter(|rate| (1..=MAX_RATE).contains(rate))
        .and_then(|rate| u32::try_from(rate).ok())
        .ok_or_else(|| {
            CliError::Invalid(format!(
                "--rate must be a whole number of requests per second from 1 to {MAX_RATE}."
            ))
        })
}

fn require_yes_for_json(input: &Input, json_mode: bool) -> Result<(), CliError> {
    if json_mode && !input.flag("yes") {
        return Err(CliError::Invalid(
            "--yes is required for a batch with JSON output.".into(),
        ));
    }
    Ok(())
}

fn trim_url(url: &str) -> &str {
    url.trim_end_matches('/')
}

#[cfg(test)]
mod tests {
    use super::*;

    fn http(status: u16, body: Value) -> Result<Value, CliError> {
        Err(CliError::from_http(status, &body.to_string()))
    }

    #[test]
    fn classifies_responses_conservatively() {
        let state = |result, retry| classify(result, retry).0;
        assert_eq!(state(Ok(json!({})), None), State::Acknowledged);
        assert_eq!(state(http(429, json!({})), None), State::Pressure);
        assert_eq!(state(http(503, json!({})), Some(2)), State::Pressure);
        assert_eq!(state(http(503, json!({})), None), State::Unknown);
        assert_eq!(state(http(500, json!({})), None), State::Unknown);
        assert_eq!(state(http(401, json!({})), None), State::Pending);
        assert_eq!(state(http(404, json!({})), None), State::Skipped);
        assert_eq!(
            state(http(409, json!({"reason": "run_closed"})), None),
            State::Skipped
        );
        assert_eq!(
            state(http(409, json!({"reason": "request_id_conflict"})), None),
            State::Refused
        );
        assert_eq!(state(http(422, json!({})), None), State::Refused);
        assert_eq!(
            state(Err(CliError::Network("reset".into())), None),
            State::Unknown
        );
        assert_eq!(
            state(Err(CliError::Failure("malformed".into())), None),
            State::Unknown
        );
    }
}
