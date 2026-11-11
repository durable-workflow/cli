//! Read-only workflow visibility through the published SDK transport.

use crate::cli::Input;
use crate::client::Context;
use crate::compat::RequestContract;
use crate::error::CliError;
use crate::{formatter, output, php};
use serde_json::{json, Value};

pub fn list(context: &Context, input: &Input) -> Result<i32, CliError> {
    let info = context.preflight(false)?;
    if let Some(status) = input.opt("status").flatten() {
        RequestContract::from_cluster_info(&info)
            .map_err(CliError::Failure)?
            .assert_canonical_value("list", "status", Some(status), Some("--status"))
            .map_err(CliError::Invalid)?;
    }
    let mut query = Vec::new();
    for (option, name) in [
        ("type", "workflow_type"),
        ("status", "status"),
        ("query", "query"),
    ] {
        if let Some(value) = input.opt(option).flatten() {
            query.push((name.to_string(), value.to_string()));
        }
    }
    let limit = input.opt("limit").flatten().map(|value| json!(value));
    query.push((
        "page_size".into(),
        php::int_cast(limit.as_ref()).to_string(),
    ));
    let result = with_namespace(
        context.workflow_list(&query)?,
        &context.connection.namespace,
        Some("workflows"),
    );
    if context.mode.machine_readable() {
        output::print_json_list(context.mode, &result, "workflows");
        return Ok(0);
    }
    let namespace = formatter::display_text(&php::to_string(result.get("namespace")));
    let workflows = result.get("workflows").map(php::values).unwrap_or_default();
    if workflows.is_empty() {
        formatter::writeln_out(&format!(
            "<comment>No workflows found in namespace {namespace}.</comment>"
        ));
        return Ok(0);
    }
    formatter::writeln_out(&format!("Namespace: {namespace}"));
    let rows = workflows
        .into_iter()
        .map(|workflow| {
            [
                "workflow_id",
                "workflow_type",
                "business_key",
                "status",
                "started_at",
                "closed_at",
            ]
            .into_iter()
            .map(|name| cell(workflow, name))
            .collect()
        })
        .collect::<Vec<Vec<String>>>();
    formatter::writeln_out(&output::render_table(
        &[
            "Workflow ID",
            "Type",
            "Business Key",
            "Status",
            "Started",
            "Closed",
        ],
        &rows,
    ));
    Ok(0)
}

fn nonempty_scalar(value: Option<&Value>) -> bool {
    php::is_scalar(value) && !php::trim(&php::to_string(value)).is_empty()
}

pub fn list_runs(context: &Context, input: &Input) -> Result<i32, CliError> {
    context.preflight(false)?;
    let workflow_id = input.arg("workflow-id").unwrap_or_default();
    let result = with_namespace(
        context.workflow_runs(workflow_id)?,
        &context.connection.namespace,
        Some("runs"),
    );
    if context.mode.machine_readable() {
        output::print_json_list(context.mode, &result, "runs");
        return Ok(0);
    }
    let namespace = formatter::display_text(&php::to_string(result.get("namespace")));
    let runs = result.get("runs").map(php::values).unwrap_or_default();
    if runs.is_empty() {
        formatter::writeln_out("<comment>No runs found.</comment>");
        formatter::writeln_out(&format!("Namespace: {namespace}"));
        return Ok(0);
    }
    formatter::writeln_out(&format!(
        "<info>Workflow: {}</info>",
        formatter::display_text(workflow_id),
    ));
    formatter::writeln_out(&format!("Namespace: {namespace}"));
    let count = result
        .get("run_count")
        .filter(|value| !value.is_null())
        .map(|value| php::to_string(Some(value)))
        .unwrap_or_else(|| runs.len().to_string());
    formatter::writeln_out(&format!("Run Count: {}", formatter::display_text(&count)));
    formatter::writeln_out("");
    let rows = runs
        .into_iter()
        .map(|run| {
            [
                "run_id",
                "run_number",
                "workflow_type",
                "status",
                "task_queue",
                "started_at",
                "closed_at",
            ]
            .into_iter()
            .map(|name| cell(run, name))
            .collect()
        })
        .collect::<Vec<Vec<String>>>();
    formatter::writeln_out(&output::render_table(
        &[
            "Run ID",
            "#",
            "Type",
            "Status",
            "Task Queue",
            "Started",
            "Closed",
        ],
        &rows,
    ));
    Ok(0)
}

/// 2.x `withNamespaceContext()`: the envelope, and each item under
/// `items_key`, gets the namespace when it has none.
pub fn with_namespace(value: Value, fallback: &str, items_key: Option<&str>) -> Value {
    let mut body = php::array_map(value);
    if !nonempty_scalar(body.get("namespace")) {
        body.insert("namespace".into(), json!(fallback));
    }
    let namespace = php::to_string(body.get("namespace"));
    if let Some(workflows) = items_key
        .and_then(|key| body.get_mut(key))
        .filter(|value| php::is_array(value))
    {
        let enrich = |workflow: &mut Value| {
            if php::is_array(workflow) && !nonempty_scalar(php::get(workflow, "namespace")) {
                let mut fields = php::array_map(workflow.take());
                fields.insert("namespace".into(), json!(namespace));
                *workflow = Value::Object(fields);
            }
        };
        match workflows {
            Value::Array(items) => items.iter_mut().for_each(enrich),
            Value::Object(items) => items.values_mut().for_each(enrich),
            _ => {}
        }
    }
    Value::Object(body)
}

fn cell(workflow: &Value, name: &str) -> String {
    let value = php::get(workflow, name);
    if name == "status" {
        // Escape the remote value before adding the CLI's trusted style.
        let escaped =
            value.map(|value| json!(formatter::display_text(&php::to_string(Some(value)))));
        output::format_status(escaped.as_ref())
    } else {
        formatter::display_text(
            &value
                .map(|value| php::to_string(Some(value)))
                .unwrap_or_else(|| "-".into()),
        )
    }
}
