//! `namespace:retention-plan` and `namespace:retention-apply`: preview a
//! proposed history-retention policy, then apply it only while that plan
//! still holds (Server #522, native Server 3.30.0).
//!
//! The plan is one read-only POST. The apply is one guarded POST, never
//! resent; a refusal (409 or 422) changed nothing and is reported with what
//! to do next. `namespace:update` keeps activating a policy at once.

use crate::cli::Input;
use crate::client::Context;
use crate::commands::schedule::{nonempty_scalar, text, text_or};
use crate::commands::task_queue::sample_label;
use crate::error::CliError;
use crate::{formatter, output, php};
use durable_workflow::RawRequest;
use serde_json::{json, Map, Value};

/// The schema of a plan answer, which `--plan-file` must carry.
pub const PLAN_SCHEMA: &str = "durable-workflow.v2.retention-plan.v1";

const MODES: [&str; 2] = ["bounded", "forever"];

/// `namespace:retention-plan`: one read-only plan of the proposed policy.
pub fn plan(context: &Context, input: &Input) -> Result<i32, CliError> {
    let name = input.arg("name").unwrap_or_default();
    let mut body = proposal(input)?;
    for option in ["scan-limit", "hold-scan-limit"] {
        if let Some(limit) = positive_option(input, option)? {
            body.insert(option.replace('-', "_"), json!(limit));
        }
    }
    context.preflight(false)?;
    let result = context.resource_request(
        RawRequest::post(["namespaces", name, "retention-plan"]).json(Value::Object(body)),
    )?;
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let namespace = shown_namespace(&result, name);
    formatter::writeln_out(&format!(
        "<info>Retention plan for namespace {namespace}</info>"
    ));
    let field = |key: &str| {
        php::get(&result, key)
            .filter(|value| nonempty_scalar(Some(value)))
            .map(|value| text(Some(value)))
            .unwrap_or_else(|| "-".into())
    };
    formatter::writeln_out(&format!("  Evaluated at: {}", field("evaluated_at")));
    formatter::writeln_out(&format!(
        "  Current policy: {}",
        policy_label(php::get(&result, "current_policy"))
    ));
    formatter::writeln_out(&format!(
        "  Proposed policy: {}",
        policy_label(php::get(&result, "proposed_policy"))
    ));
    formatter::writeln_out(&format!(
        "  Retroactive: {}",
        yes_no(php::get(&result, "retroactive"))
    ));
    formatter::writeln_out(&format!(
        "  Complete: {}",
        yes_no(php::get(&result, "complete"))
    ));
    formatter::writeln_out(&format!("  Policy revision: {}", field("policy_revision")));
    formatter::writeln_out(&format!("  Plan revision: {}", field("plan_revision")));

    let changes = php::get(&result, "changes");
    let exclusions = php::get(&result, "exclusions");
    for (title, heading, section) in [
        ("Changes (closed, unarchived runs):", "Change", changes),
        (
            "Exclusions (runs a prune would not delete):",
            "Exclusion",
            exclusions,
        ),
    ] {
        let rows = entries(section)
            .into_iter()
            .map(|(key, entry)| vec![formatter::display_text(key), count_label(entry)])
            .collect::<Vec<_>>();
        if !rows.is_empty() {
            formatter::writeln_out("");
            formatter::writeln_out(title);
            formatter::writeln_out(&output::render_table(&[heading, "Runs"], &rows));
        }
    }

    let incomplete = php::get(&result, "incomplete")
        .filter(|value| value.is_array())
        .map(php::values)
        .unwrap_or_default();
    if !incomplete.is_empty() {
        formatter::writeln_out("");
        formatter::writeln_out(
            "<comment>Incomplete: these counts reached their scan limit and are minimums, not exact figures.</comment>",
        );
        for item in incomplete {
            let field = text_or(php::get(item, "field"), "-");
            match php::get(item, "reason").filter(|value| nonempty_scalar(Some(value))) {
                Some(reason) => {
                    formatter::writeln_out(&format!("  {field}: {}", text(Some(reason))))
                }
                None => formatter::writeln_out(&format!("  {field}")),
            }
        }
    }

    let holds = php::get(&result, "hold_inspection").filter(|value| value.is_object());
    if let Some(holds) = holds {
        formatter::writeln_out("");
        formatter::writeln_out(&format!(
            "Hold inspection: {} eligible runs inspected, complete: {}, backup hold active: {}",
            text_or(php::get(holds, "inspected"), "-"),
            yes_no(php::get(holds, "complete")),
            yes_no(php::get(holds, "backup_hold_active")),
        ));
        let skipped = php::get(holds, "not_evaluated")
            .filter(|value| value.is_array())
            .map(|value| {
                php::values(value)
                    .into_iter()
                    .map(|item| text(Some(item)))
                    .collect::<Vec<_>>()
            })
            .unwrap_or_default();
        if !skipped.is_empty() {
            formatter::writeln_out(&format!("  Not evaluated: {}", skipped.join(", ")));
        }
    }

    let examples = [changes, exclusions]
        .into_iter()
        .flat_map(entries)
        .filter_map(|(key, entry)| {
            let runs = php::get(entry, "examples")
                .filter(|value| value.is_array())
                .map(php::values)
                .unwrap_or_default();
            (!runs.is_empty()).then_some((key, runs))
        })
        .collect::<Vec<_>>();
    if !examples.is_empty() {
        formatter::writeln_out("");
        formatter::writeln_out("Examples (oldest first):");
        for (key, runs) in examples {
            formatter::writeln_out(&format!("  {}:", formatter::display_text(key)));
            for run in runs {
                formatter::writeln_out(&format!("    - {}", sample_label(run)));
            }
        }
    }

    let mut closing = Vec::new();
    if let Some(scope) = php::get(&result, "scope").filter(|value| nonempty_scalar(Some(value))) {
        closing.push(text(Some(scope)));
    }
    let retroactive = php::get(&result, "retroactive") == Some(&Value::Bool(true));
    if retroactive {
        closing.push("<comment>This policy is retroactive: closed runs the current policy keeps become eligible for irreversible pruning at the next retention pass.</comment>".into());
    }
    if let Some(command) = apply_command(&result, name, retroactive) {
        closing.push(format!(
            "Apply this plan with <info>{command}</info>; the Server refuses it if the namespace changed since this plan."
        ));
    }
    if !closing.is_empty() {
        formatter::writeln_out("");
        for line in closing {
            formatter::writeln_out(&line);
        }
    }
    Ok(0)
}

/// `namespace:retention-apply`: one guarded apply, bound to a plan's
/// revisions and never resent.
pub fn apply(context: &Context, input: &Input) -> Result<i32, CliError> {
    let name = input.arg("name").unwrap_or_default();
    let mut body = match input.opt_str("plan-file") {
        Some(path) => {
            if let Some(option) = [
                "retention",
                "retention-mode",
                "policy-revision",
                "plan-revision",
            ]
            .into_iter()
            .find(|option| input.opt_str(option).is_some())
            {
                return Err(CliError::Invalid(format!(
                    "--plan-file cannot be combined with --{option}; the plan file supplies the proposal and both revisions."
                )));
            }
            plan_file_body(path, name)?
        }
        None => {
            let mut body = proposal(input)?;
            for option in ["policy-revision", "plan-revision"] {
                let value = input
                    .opt_str(option)
                    .map(php::trim)
                    .filter(|value| !value.is_empty())
                    .ok_or_else(|| {
                        CliError::Invalid(format!(
                            "--{option} is required. Take it from dw namespace:retention-plan, or pass --plan-file."
                        ))
                    })?;
                body.insert(option.replace('-', "_"), json!(value));
            }
            body
        }
    };
    body.insert(
        "confirm_retroactive_pruning".into(),
        json!(input.flag("confirm-retroactive-pruning")),
    );
    context.preflight(false)?;
    let result = context.resource_request(
        RawRequest::post(["namespaces", name, "retention-plan", "apply"]).json(Value::Object(body)),
    )?;
    if context.mode.machine_readable() {
        output::print_json(&result);
        return Ok(0);
    }
    let namespace = shown_namespace(&result, name);
    formatter::writeln_out(&format!(
        "<info>Retention policy applied to namespace {namespace}.</info>"
    ));
    let field = |key: &str| {
        php::get(&result, key)
            .filter(|value| nonempty_scalar(Some(value)))
            .map(|value| text(Some(value)))
            .unwrap_or_else(|| "-".into())
    };
    formatter::writeln_out(&format!("  Applied at: {}", field("applied_at")));
    formatter::writeln_out(&format!(
        "  Previous policy: {}",
        policy_label(php::get(&result, "previous_policy"))
    ));
    formatter::writeln_out(&format!(
        "  Policy: {}",
        policy_label(php::get(&result, "policy"))
    ));
    let retroactive = match (
        php::get(&result, "retroactive"),
        php::get(&result, "confirmed_retroactive_pruning"),
    ) {
        (Some(Value::Bool(true)), Some(Value::Bool(true))) => "yes, pruning confirmed",
        (Some(Value::Bool(true)), _) => "yes",
        (Some(Value::Bool(false)), _) => "no",
        _ => "-",
    };
    formatter::writeln_out(&format!("  Retroactive: {retroactive}"));
    formatter::writeln_out(&format!(
        "  Previous policy revision: {}",
        field("previous_policy_revision")
    ));
    formatter::writeln_out(&format!("  Policy revision: {}", field("policy_revision")));
    if let Some(scope) = php::get(&result, "scope").filter(|value| nonempty_scalar(Some(value))) {
        formatter::writeln_out("");
        formatter::writeln_out(&text(Some(scope)));
    }
    Ok(0)
}

/// The `dw namespace:retention-plan` command that plans again what a
/// refused apply tried: its `--retention-mode` and `--retention`, or the
/// proposed policy of its `--plan-file`. Planning with no proposal would
/// compare the current policy with itself.
pub fn replan_command(input: &Input) -> String {
    let name = input.arg("name").unwrap_or_default();
    let mut command = format!("dw namespace:retention-plan {name}");
    let attempted = match input.opt_str("plan-file") {
        Some(path) => plan_file_body(path, name).ok(),
        None => proposal(input).ok(),
    };
    if let Some(body) = attempted {
        if let Some(mode) = body.get("retention_mode").and_then(Value::as_str) {
            command.push_str(&format!(" --retention-mode={mode}"));
        }
        if let Some(days) = body.get("retention_days").and_then(Value::as_i64) {
            command.push_str(&format!(" --retention={days}"));
        }
    }
    command
}

/// The proposed `retention_mode` and `retention_days` from the options; a
/// field left out keeps the namespace's current value on the Server.
fn proposal(input: &Input) -> Result<Map<String, Value>, CliError> {
    let mut body = Map::new();
    let mode = input.opt_str("retention-mode").map(php::trim);
    if let Some(mode) = mode {
        if !MODES.contains(&mode) {
            return Err(CliError::Invalid(
                "--retention-mode must be bounded or forever.".into(),
            ));
        }
        body.insert("retention_mode".into(), json!(mode));
    }
    if let Some(days) = positive_option(input, "retention")? {
        if mode == Some("forever") {
            return Err(CliError::Invalid(
                "--retention cannot be combined with --retention-mode=forever; a forever policy has no days.".into(),
            ));
        }
        body.insert("retention_days".into(), json!(days));
    }
    Ok(body)
}

/// A written option as a positive integer; the Server enforces its range.
fn positive_option(input: &Input, option: &str) -> Result<Option<i64>, CliError> {
    let Some(value) = input.opt(option).flatten().map(php::trim) else {
        return Ok(None);
    };
    match value.parse::<i64>() {
        Ok(number) if number > 0 && php::ctype_digit(value) => Ok(Some(number)),
        _ => Err(CliError::Invalid(format!(
            "--{option} must be a positive integer."
        ))),
    }
}

/// The apply body a saved plan (`namespace:retention-plan --json`) names:
/// its proposed policy, exactly as resolved, and both revisions.
fn plan_file_body(path: &str, name: &str) -> Result<Map<String, Value>, CliError> {
    let invalid = |detail: &str| {
        CliError::Invalid(format!(
            "--plan-file {path} is not a retention plan: {detail}. Save one with dw namespace:retention-plan <name> ... --json."
        ))
    };
    let contents = std::fs::read_to_string(path).map_err(|error| {
        CliError::Invalid(format!("Unable to read --plan-file {path}: {error}"))
    })?;
    let plan: Value =
        serde_json::from_str(&contents).map_err(|_| invalid("it is not valid JSON"))?;
    if plan.get("schema").and_then(Value::as_str) != Some(PLAN_SCHEMA) {
        return Err(invalid(&format!("its schema is not {PLAN_SCHEMA}")));
    }
    let planned = plan
        .get("namespace")
        .and_then(Value::as_str)
        .unwrap_or_default();
    if !planned.eq_ignore_ascii_case(php::trim(name)) {
        return Err(CliError::Invalid(format!(
            "--plan-file {path} plans namespace {}, not {}.",
            formatter::display_text(planned),
            formatter::display_text(name)
        )));
    }
    let proposed = plan.get("proposed_policy");
    let mode = proposed
        .and_then(|policy| policy.get("retention_mode"))
        .and_then(Value::as_str)
        .filter(|mode| MODES.contains(mode))
        .ok_or_else(|| invalid("proposed_policy.retention_mode is missing"))?;
    let mut body = Map::new();
    body.insert("retention_mode".into(), json!(mode));
    if mode == "bounded" {
        let days = proposed
            .and_then(|policy| policy.get("retention_days"))
            .and_then(Value::as_i64)
            .ok_or_else(|| invalid("proposed_policy.retention_days is missing"))?;
        body.insert("retention_days".into(), json!(days));
    }
    for key in ["policy_revision", "plan_revision"] {
        let revision = plan
            .get(key)
            .and_then(Value::as_str)
            .filter(|value| !value.is_empty())
            .ok_or_else(|| invalid(&format!("{key} is missing")))?;
        body.insert(key.into(), json!(revision));
    }
    Ok(body)
}

/// The `dw namespace:retention-apply` command that applies this plan: the
/// resolved proposal, so it matches the plan revision, and both revisions.
fn apply_command(result: &Value, name: &str, retroactive: bool) -> Option<String> {
    let revision = |key: &str| {
        php::get(result, key)
            .filter(|value| nonempty_scalar(Some(value)))
            .map(|value| text(Some(value)))
    };
    let (policy_revision, plan_revision) =
        (revision("policy_revision")?, revision("plan_revision")?);
    let proposed = php::get(result, "proposed_policy")?;
    let mode = php::get(proposed, "retention_mode")
        .and_then(Value::as_str)
        .filter(|mode| MODES.contains(mode))?;
    let mut command = format!(
        "dw namespace:retention-apply {} --retention-mode={mode}",
        formatter::display_text(name)
    );
    if mode == "bounded" {
        let days = php::get(proposed, "retention_days").and_then(Value::as_i64)?;
        command.push_str(&format!(" --retention={days}"));
    }
    command.push_str(&format!(
        " --policy-revision={policy_revision} --plan-revision={plan_revision}"
    ));
    if retroactive {
        command.push_str(" --confirm-retroactive-pruning");
    }
    Some(command)
}

fn shown_namespace(result: &Value, name: &str) -> String {
    match php::get(result, "namespace").filter(|value| nonempty_scalar(Some(value))) {
        Some(value) => text(Some(value)),
        None => formatter::display_text(name),
    }
}

/// `bounded, 7 days (cutoff ...)` or `forever (no cutoff)`.
fn policy_label(policy: Option<&Value>) -> String {
    let Some(policy) = policy.filter(|value| value.is_object()) else {
        return "-".into();
    };
    let mode = text_or(php::get(policy, "retention_mode"), "-");
    if mode == "forever" {
        return "forever (no cutoff)".into();
    }
    let mut label = mode;
    if let Some(days) =
        php::get(policy, "retention_days").filter(|value| nonempty_scalar(Some(value)))
    {
        label.push_str(&format!(", {} days", text(Some(days))));
    }
    if let Some(cutoff) = php::get(policy, "cutoff").filter(|value| nonempty_scalar(Some(value))) {
        label.push_str(&format!(" (cutoff {})", text(Some(cutoff))));
    }
    label
}

/// A plan section's categories, in the Server's order.
fn entries(section: Option<&Value>) -> Vec<(&String, &Value)> {
    section
        .and_then(Value::as_object)
        .map(|fields| {
            fields
                .iter()
                .filter(|(_, entry)| entry.is_object())
                .collect()
        })
        .unwrap_or_default()
}

/// An exact count, or `at least N` for a count that reached its bound.
fn count_label(entry: &Value) -> String {
    let complete = php::get(entry, "complete") == Some(&Value::Bool(true));
    match php::get(entry, "count").filter(|value| value.is_number()) {
        Some(count) if complete => text(Some(count)),
        _ => match php::get(entry, "lower_bound").filter(|value| value.is_number()) {
            Some(bound) => format!("at least {}", text(Some(bound))),
            None => "-".into(),
        },
    }
}

fn yes_no(value: Option<&Value>) -> &'static str {
    match value {
        Some(Value::Bool(true)) => "yes",
        Some(Value::Bool(false)) => "no",
        _ => "-",
    }
}
