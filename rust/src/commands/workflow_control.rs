//! Workflow run control: `workflow:cancel` (one run, or a bounded batch
//! selected by a visibility query), `workflow:terminate`, `workflow:archive`,
//! `workflow:redrive`, `workflow:repair` and `workflow:migrate-v1`.
//!
//! Every mutation is one request. A redirect is refused rather than
//! followed, and a mutation whose response is lost is reported as a
//! transport failure, never submitted again. Batch cancellation posts to the
//! exact run the visibility list selected, never to the workflow instance,
//! whose current run may be a successor (cli#59).

use crate::cli::Input;
use crate::client::Context;
use crate::commands::workflow::with_namespace;
use crate::compat::RequestContract;
use crate::error::CliError;
use crate::{formatter, output, php, php_json};
use serde_json::{json, Map, Value};
use std::collections::HashSet;
use std::io::{BufRead, Write};

/// Most workflows one visibility page may return to a batch selection.
const BATCH_PAGE_SIZE: i64 = 200;
/// The exit code 2.x records for a target it could not address.
const COMPATIBILITY_EXIT: i64 = 8;

pub fn cancel(context: &Context, input: &Input) -> Result<i32, CliError> {
    let workflow_id = input.arg("workflow-id");
    if let Some(query) = input.opt("all-matching").flatten() {
        if workflow_id.is_some_and(|id| !id.is_empty()) {
            return Err(CliError::Invalid(
                "Pass either workflow-id or --all-matching, not both.".into(),
            ));
        }
        return batch_cancel(context, input, query);
    }
    let workflow_id = workflow_id.filter(|id| !id.is_empty()).ok_or_else(|| {
        CliError::Invalid("workflow-id is required unless --all-matching is used.".into())
    })?;
    run_command(context, input, workflow_id, "cancel", "Workflow cancelled")
}

pub fn terminate(context: &Context, input: &Input) -> Result<i32, CliError> {
    let workflow_id = input.arg("workflow-id").unwrap_or_default();
    run_command(
        context,
        input,
        workflow_id,
        "terminate",
        "Workflow terminated",
    )
}

/// One cancel or terminate: the current run, or `--run-id`'s run.
fn run_command(
    context: &Context,
    input: &Input,
    workflow_id: &str,
    action: &str,
    title: &str,
) -> Result<i32, CliError> {
    context.preflight(false)?;
    let run_id = input.opt("run-id").flatten();
    let (segments, path) = match run_id {
        Some(run_id) => (
            vec!["workflows", workflow_id, "runs", run_id, action],
            format!("/workflows/{workflow_id}/runs/{run_id}/{action}"),
        ),
        None => (
            vec!["workflows", workflow_id, action],
            format!("/workflows/{workflow_id}/{action}"),
        ),
    };
    let result = post(context, &segments, reason_body(input), &path)?;
    command_result(context, title, &result)
}

pub fn archive(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let workflow_id = input.arg("workflow-id").unwrap_or_default();
    let result = post(
        context,
        &["workflows", workflow_id, "archive"],
        reason_body(input),
        &format!("/workflows/{workflow_id}/archive"),
    )?;
    command_result(context, "Archive requested", &result)
}

pub fn repair(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let workflow_id = input.arg("workflow-id").unwrap_or_default();
    let result = post(
        context,
        &["workflows", workflow_id, "repair"],
        empty_body(),
        &format!("/workflows/{workflow_id}/repair"),
    )?;
    command_result(context, "Repair requested", &result)
}

pub fn redrive(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let workflow_id = input.arg("workflow-id").unwrap_or_default();
    let run_id = input.arg("run-id").unwrap_or_default();
    let body = match input.opt("request-id").flatten() {
        Some(request_id) if !request_id.is_empty() => json!({"request_id": request_id}),
        _ => empty_body(),
    };
    let result = post(
        context,
        &["workflows", workflow_id, "runs", run_id, "redrive"],
        body,
        &format!("/workflows/{workflow_id}/runs/{run_id}/redrive"),
    )?;
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let or = |key: &str, default: &str| formatter::display_text(&php::or(result.get(key), default));
    formatter::writeln_out("<info>Redrive requested</info>");
    formatter::writeln_out(&format!(
        "  Workflow ID: {}",
        or("workflow_id", workflow_id)
    ));
    write_namespace_line(&result);
    formatter::writeln_out(&format!(
        "  Source Run ID: {}",
        or("continued_from_run_id", run_id)
    ));
    formatter::writeln_out(&format!("  New Run ID: {}", or("run_id", "-")));
    formatter::writeln_out(&format!(
        "  Resume Step: {}",
        or("resume_step_sequence", "-")
    ));
    Ok(0)
}

pub fn migrate_v1(context: &Context, input: &Input) -> Result<i32, CliError> {
    let source_id = input
        .opt("source-id")
        .flatten()
        .map(php::trim)
        .filter(|source| !source.is_empty())
        .ok_or_else(|| CliError::Invalid("--source-id is required.".into()))?;
    let projection = read_projection(input.arg("projection").unwrap_or_default())?;
    context.preflight(false)?;
    let body = json!({
        "source_id": source_id,
        "workflow": php_json::php_array_shape(projection),
        "dry_run": input.flag("dry-run"),
    });
    let result = post(
        context,
        &["workflows", "import", "waterline-v1"],
        body,
        "/workflows/import/waterline-v1",
    )?;
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let array = |value: Option<&Value>| value.filter(|value| php::is_array(value)).cloned();
    let identity = array(result.get("identity")).unwrap_or(Value::Null);
    let waterline = array(php::get(&identity, "waterline")).unwrap_or(Value::Null);
    let standalone = array(php::get(&identity, "standalone")).unwrap_or(Value::Null);
    let text =
        |value: Option<&Value>, default: &str| formatter::display_text(&php::or(value, default));
    let namespace = match php::get(&standalone, "namespace") {
        Some(value) if !value.is_null() => text(Some(value), "-"),
        _ => text(result.get("namespace"), "-"),
    };
    for line in [
        "<info>Waterline v1 projection</info>".to_string(),
        format!("  Status: {}", text(result.get("status"), "-")),
        format!("  Source: {}", text(php::get(&waterline, "source_id"), "-")),
        format!(
            "  Waterline ID: {}",
            text(php::get(&waterline, "qualified_workflow_id"), "-")
        ),
        format!(
            "  Workflow ID: {}",
            text(php::get(&standalone, "workflow_id"), "-")
        ),
        format!("  Run ID: {}", text(php::get(&standalone, "run_id"), "-")),
        format!("  Namespace: {namespace}"),
        "  Read Only: yes".to_string(),
    ] {
        formatter::writeln_out(&line);
    }
    if let Some(unsupported) = result
        .get("unsupported_fields")
        .filter(|value| php::non_empty_array(value))
    {
        formatter::writeln_out("  Unsupported Fields:");
        for field in php::values(unsupported) {
            if !php::is_array(field) {
                continue;
            }
            formatter::writeln_out(&format!(
                "    - {}: {} ({})",
                text(php::get(field, "field"), "-"),
                text(php::get(field, "reason"), "-"),
                text(php::get(field, "remediation"), "-"),
            ));
        }
    }
    Ok(0)
}

/// 2.x `readProjection()`: a Waterline v1 detail object, from a file or
/// stdin, unwrapping a `data` envelope.
fn read_projection(path: &str) -> Result<Value, CliError> {
    use std::io::Read;
    let contents = if path == "-" {
        let mut contents = Vec::new();
        std::io::stdin()
            .read_to_end(&mut contents)
            .map(|_| contents)
    } else if std::path::Path::new(path).is_dir() {
        // PHP reads a directory as empty text.
        Ok(Vec::new())
    } else {
        std::fs::read(path)
    }
    .map_err(|_| {
        CliError::Invalid(format!(
            "Unable to read Waterline projection from [{path}]."
        ))
    })?;
    let decoded = php_json::decode(&contents).map_err(|error| {
        CliError::Invalid(format!(
            "Waterline projection must be valid JSON: {}",
            error.message()
        ))
    })?;
    let value = decoded.value;
    if !php::is_array(&value) || php::is_list(&value) {
        return Err(CliError::Invalid(
            "Waterline projection must be a JSON object.".into(),
        ));
    }
    match value.get("data") {
        Some(data) if php::is_array(data) && !php::is_list(data) => Ok(data.clone()),
        _ => Ok(value),
    }
}

fn post(context: &Context, segments: &[&str], body: Value, path: &str) -> Result<Value, CliError> {
    Ok(with_namespace(
        context.workflow_post(segments, body, path, None)?,
        &context.connection.namespace,
        None,
    ))
}

/// PHP's empty request array, sent as `[]`.
fn empty_body() -> Value {
    Value::Array(Vec::new())
}

/// `array_filter(['reason' => --reason], fn ($v) => $v !== null)`.
fn reason_body(input: &Input) -> Value {
    match input.opt("reason").flatten() {
        Some(reason) => json!({"reason": reason}),
        None => empty_body(),
    }
}

/// The receipt cancel, terminate, archive and repair print.
fn command_result(context: &Context, title: &str, result: &Value) -> Result<i32, CliError> {
    if context.mode.machine_readable() {
        output::print_json(result);
        return Ok(0);
    }
    let field = |key: &str| formatter::display_text(&php::to_string(result.get(key)));
    formatter::writeln_out(&format!("<info>{title}</info>"));
    formatter::writeln_out(&format!("  Workflow ID: {}", field("workflow_id")));
    write_namespace_line(result);
    formatter::writeln_out(&format!("  Outcome: {}", field("outcome")));
    for (label, key) in [
        ("Command Status", "command_status"),
        ("Command ID", "command_id"),
    ] {
        if result.get(key).is_some_and(|value| !value.is_null()) {
            formatter::writeln_out(&format!("  {label}: {}", field(key)));
        }
    }
    Ok(0)
}

/// 2.x `writeNamespaceLine()`.
fn write_namespace_line(result: &Value) {
    formatter::writeln_out(&format!("  Namespace: {}", namespace_text(result)));
}

fn namespace_text(result: &Value) -> String {
    match result.get("namespace") {
        Some(value)
            if php::is_scalar(Some(value))
                && !php::trim(&php::to_string(Some(value))).is_empty() =>
        {
            formatter::display_text(&php::to_string(Some(value)))
        }
        _ => "-".into(),
    }
}

/// One workflow the batch selected, pinned to the run the list reported.
struct Target {
    workflow_id: String,
    run_id: Option<String>,
}

fn batch_cancel(context: &Context, input: &Input, query: &str) -> Result<i32, CliError> {
    let query = php::trim(query);
    if query.is_empty() {
        return Err(CliError::Invalid(
            "--all-matching must be a non-empty visibility query.".into(),
        ));
    }
    if input.opt("run-id").flatten().is_some() {
        return Err(CliError::Invalid(
            "--run-id cannot be combined with --all-matching.".into(),
        ));
    }
    let json_mode = context.mode.machine_readable();
    let skip_confirmation = input.flag("yes");
    if json_mode && !skip_confirmation {
        return Err(CliError::Invalid(
            "--yes is required when using --all-matching with --json.".into(),
        ));
    }
    let info = context.preflight(false)?;
    let namespace = context.connection.namespace.clone();
    let status = input
        .opt("status")
        .flatten()
        .filter(|value| !value.is_empty());
    if let Some(status) = status {
        RequestContract::from_cluster_info(&info)
            .map_err(CliError::Failure)?
            .assert_canonical_value("list", "status", Some(status), Some("--status"))
            .map_err(CliError::Invalid)?;
    }
    let workflow_type = input
        .opt("type")
        .flatten()
        .filter(|value| !value.is_empty());
    let limit = input
        .opt("limit")
        .flatten()
        .filter(|value| php::ctype_digit(value) && php::string_to_int(value) >= 1)
        .map(php::string_to_int)
        .ok_or_else(|| CliError::Invalid("--limit must be a positive integer.".into()))?;
    let targets = select(context, query, workflow_type, status, limit)?;
    let summary_base = |targets: &[Target], results: Vec<Value>, failures: Vec<Value>| {
        let mut summary = Map::new();
        summary.insert("query".into(), json!(query));
        summary.insert("workflow_type".into(), json!(workflow_type));
        summary.insert("status".into(), json!(status));
        summary.insert("matched".into(), json!(targets.len()));
        summary.insert("cancelled".into(), json!(results.len()));
        summary.insert("failed".into(), json!(failures.len()));
        summary.insert(
            "workflow_ids".into(),
            Value::Array(
                targets
                    .iter()
                    .map(|target| json!(target.workflow_id))
                    .collect(),
            ),
        );
        summary.insert(
            "targets".into(),
            Value::Array(
                targets
                    .iter()
                    .map(|target| {
                        json!({
                            "workflow_id": target.workflow_id,
                            "run_id": target.run_id,
                            "namespace": namespace,
                        })
                    })
                    .collect(),
            ),
        );
        summary.insert("results".into(), Value::Array(results));
        summary.insert("failures".into(), Value::Array(failures));
        summary.insert("namespace".into(), json!(namespace));
        Value::Object(summary)
    };

    if targets.is_empty() {
        if json_mode {
            print_batch_json(&summary_base(&targets, Vec::new(), Vec::new()));
            return Ok(0);
        }
        formatter::writeln_out(&format!(
            "<comment>No workflows matched in namespace {}.</comment>",
            formatter::display_text(&namespace)
        ));
        return Ok(0);
    }

    if !skip_confirmation && !confirm(input, targets.len(), query) {
        formatter::writeln_out("<comment>Batch cancellation aborted.</comment>");
        return Ok(0);
    }

    let body = reason_body(input);
    let mut results = Vec::new();
    let mut failures = Vec::new();
    for target in &targets {
        let failure =
            |run_id: Option<&str>, reason: Option<&str>, message: &str, exit_code: i64| {
                json!({
                    "workflow_id": target.workflow_id,
                    "run_id": run_id,
                    "namespace": namespace,
                    "reason": reason,
                    "message": message,
                    "exit_code": exit_code,
                })
            };
        // Cancel only the selected run. The instance endpoint would cancel
        // whichever run is current at dispatch time.
        let Some(run_id) = target.run_id.as_deref() else {
            failures.push(failure(
                None,
                Some("run_id_unavailable"),
                "The workflow list did not report a run ID, so this workflow was not cancelled.",
                COMPATIBILITY_EXIT,
            ));
            continue;
        };
        let workflow_id = target.workflow_id.as_str();
        let outcome = context.workflow_post(
            &["workflows", workflow_id, "runs", run_id, "cancel"],
            body.clone(),
            &format!("/workflows/{workflow_id}/runs/{run_id}/cancel"),
            None,
        );
        match outcome {
            Ok(result) => results.push(with_namespace(result, &namespace, None)),
            // A malformed success response stops the batch, as in 2.x.
            Err(error @ CliError::Failure(_)) => return Err(error),
            Err(error) => {
                let reason = match &error {
                    CliError::Http { body, .. } => body
                        .as_ref()
                        .and_then(|body| body.get("reason"))
                        .and_then(Value::as_str)
                        .filter(|reason| !reason.is_empty())
                        .map(str::to_owned),
                    _ => None,
                };
                failures.push(failure(
                    Some(run_id),
                    reason.as_deref(),
                    error.message(),
                    i64::from(error.exit_code()),
                ));
            }
        }
    }

    let exit = if failures.is_empty() { 0 } else { 1 };
    let cancelled = results.len();
    let failed = failures.len();
    if json_mode {
        print_batch_json(&summary_base(&targets, results, failures));
        return Ok(exit);
    }
    formatter::writeln_out(&format!(
        "<info>Cancelled {cancelled} workflow{}.</info>",
        if cancelled == 1 { "" } else { "s" }
    ));
    formatter::writeln_out(&format!(
        "  Namespace: {}",
        if php::trim(&namespace).is_empty() {
            "-".to_string()
        } else {
            formatter::display_text(&namespace)
        }
    ));
    formatter::writeln_out(&format!("  Matched: {}", targets.len()));
    formatter::writeln_out(&format!("  Failed: {failed}"));
    for target in &targets {
        formatter::writeln_out(&format!(
            "  - {} (run {})",
            formatter::display_text(&target.workflow_id),
            formatter::display_text(target.run_id.as_deref().unwrap_or("unknown"))
        ));
    }
    for failure in &failures {
        let text = |key: &str| formatter::display_text(&php::to_string(failure.get(key)));
        let run_id = failure
            .get("run_id")
            .and_then(Value::as_str)
            .map(formatter::display_text)
            .unwrap_or_else(|| "unknown".into());
        let reason = failure
            .get("reason")
            .and_then(Value::as_str)
            .map(|reason| format!(" [{}]", formatter::display_text(reason)))
            .unwrap_or_default();
        formatter::writeln_out(&format!(
            "<error>  {} (run {run_id}): {}{reason}</error>",
            text("workflow_id"),
            text("message"),
        ));
    }
    Ok(exit)
}

/// The batch summary as 2.x prints it: pretty JSON through the console
/// writer, so it is silenced by `-q` like other output.
fn print_batch_json(summary: &Value) {
    formatter::writeln_out(&formatter::escape(&php::json_encode_pretty(summary)));
}

/// 2.x `matchingWorkflows()`: at most `limit` workflows, each pinned to the
/// run its first listing reported. Visibility pages are not a snapshot, so a
/// workflow seen again keeps its first run. A page that adds nothing new, or
/// a repeated page token, stops selection before anything is cancelled.
fn select(
    context: &Context,
    query: &str,
    workflow_type: Option<&str>,
    status: Option<&str>,
    limit: i64,
) -> Result<Vec<Target>, CliError> {
    let mut targets: Vec<Target> = Vec::new();
    let mut seen_workflows = HashSet::new();
    let mut seen_tokens = HashSet::new();
    let mut next_page_token: Option<String> = None;
    let limit = usize::try_from(limit).unwrap_or(usize::MAX);
    loop {
        let matched_before = targets.len();
        let remaining = limit - targets.len();
        let page_size = BATCH_PAGE_SIZE.min(i64::try_from(remaining).unwrap_or(i64::MAX));
        let mut parameters = vec![("query".to_string(), query.to_string())];
        if let Some(workflow_type) = workflow_type {
            parameters.push(("workflow_type".into(), workflow_type.into()));
        }
        if let Some(status) = status {
            parameters.push(("status".into(), status.into()));
        }
        parameters.push(("page_size".into(), page_size.to_string()));
        if let Some(token) = &next_page_token {
            parameters.push(("next_page_token".into(), token.clone()));
        }
        let page = context.workflow_get(&["workflows"], &parameters, "/workflows")?;
        if let Some(workflows) = page.get("workflows").filter(|value| php::is_array(value)) {
            for workflow in php::values(workflows) {
                let Some(workflow_id) = php::get(workflow, "workflow_id").and_then(Value::as_str)
                else {
                    continue;
                };
                if !seen_workflows.insert(workflow_id.to_string()) {
                    continue;
                }
                let run_id = php::get(workflow, "run_id")
                    .and_then(Value::as_str)
                    .filter(|run_id| !run_id.is_empty())
                    .map(str::to_owned);
                targets.push(Target {
                    workflow_id: workflow_id.to_string(),
                    run_id,
                });
                if targets.len() >= limit {
                    break;
                }
            }
        }
        next_page_token = page
            .get("next_page_token")
            .and_then(Value::as_str)
            .filter(|token| !token.is_empty())
            .map(str::to_owned);
        let Some(token) = next_page_token.clone().filter(|_| targets.len() < limit) else {
            break;
        };
        if seen_tokens.contains(&token) {
            return Err(CliError::Failure(
                "Stopped selecting workflows: the server returned the same page token twice. No workflows were cancelled.".into(),
            ));
        }
        if targets.len() == matched_before {
            return Err(CliError::Failure(
                "Stopped selecting workflows: a page added no new workflows but the server reported more pages. No workflows were cancelled.".into(),
            ));
        }
        seen_tokens.insert(token);
    }
    Ok(targets)
}

/// 2.x's confirmation: the question on stderr and one answer line from
/// stdin, also when stdin is a pipe. `-n`, `-q` or end of input mean no.
fn confirm(input: &Input, count: usize, query: &str) -> bool {
    if input.flag("no-interaction") || formatter::verbosity() != formatter::Verbosity::Normal {
        return false;
    }
    formatter::emit(
        formatter::Stream::Stderr,
        &formatter::format(
            &format!(
                "Cancel {count} workflow{} matching [{}]? [y/N] ",
                if count == 1 { "" } else { "s" },
                formatter::escape(query)
            ),
            formatter::decorated(formatter::Stream::Stderr),
        ),
    );
    let _ = std::io::stderr().flush();
    let mut answer = String::new();
    if std::io::stdin().lock().read_line(&mut answer).unwrap_or(0) == 0 {
        return false;
    }
    answer
        .trim()
        .chars()
        .next()
        .is_some_and(|first| first.eq_ignore_ascii_case(&'y'))
}
