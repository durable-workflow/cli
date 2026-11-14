//! One namespace-scoped, live page of open-run version decisions (shared #220).

use crate::cli::Input;
use crate::client::Context;
use crate::error::CliError;
use crate::{compat, formatter, output};
use durable_workflow::RawRequest;
use serde::Deserialize;
use serde_json::Value;

const CAPABILITY: &str = "workflow_version_usage";
const NEXT_STEP: &str = "Connect to a Server that advertises workflow_version_usage. Missing markers or unavailable history must remain unknown; inspect retained history before retiring a branch.";

#[derive(Clone, Copy, Debug, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
enum State {
    Legacy,
    Current,
    Unknown,
}

impl State {
    fn name(self) -> &'static str {
        match self {
            Self::Legacy => "legacy",
            Self::Current => "current",
            Self::Unknown => "unknown",
        }
    }
}

struct Query<'a> {
    workflow_type: &'a str,
    change_id: &'a str,
    minimum: i32,
    state: Option<State>,
    limit: u16,
    cursor: Option<&'a str>,
}

impl<'a> Query<'a> {
    fn parse(input: &'a Input, namespace: &str) -> Result<Self, CliError> {
        if input.provided("namespace")
            && input
                .opt("namespace")
                .flatten()
                .is_none_or(|value| value.trim().is_empty())
        {
            return Err(CliError::Invalid(
                "--namespace requires a nonempty namespace.".into(),
            ));
        }
        if namespace.trim().is_empty() || namespace.chars().any(char::is_control) {
            return Err(CliError::Invalid(
                "The selected namespace must be nonempty and contain no control characters.".into(),
            ));
        }
        let workflow_type = required_text(input.arg("workflow-type"), "workflow-type", 255)?;
        let change_id = required_text(input.arg("change-id"), "change-id", 1024)?;
        let minimum = input
            .opt("min-version")
            .flatten()
            .and_then(|value| value.parse::<i32>().ok())
            .ok_or_else(|| {
                CliError::Invalid(
                    "--min-version is required and must be a signed 32-bit integer.".into(),
                )
            })?;
        let state = match input.opt("state").flatten() {
            None => None,
            Some("legacy") => Some(State::Legacy),
            Some("current") => Some(State::Current),
            Some("unknown") => Some(State::Unknown),
            Some(_) => {
                return Err(CliError::Invalid(
                    "--state must be legacy, current, or unknown.".into(),
                ))
            }
        };
        let limit = input
            .opt("limit")
            .flatten()
            .and_then(|value| value.parse::<u16>().ok())
            .filter(|limit| (1..=200).contains(limit))
            .ok_or_else(|| CliError::Invalid("--limit must be an integer from 1 to 200.".into()))?;
        let cursor = input.opt("cursor").flatten();
        if cursor == Some("") {
            return Err(CliError::Invalid(
                "--cursor must be the nonempty opaque continuation from this query.".into(),
            ));
        }
        Ok(Self {
            workflow_type,
            change_id,
            minimum,
            state,
            limit,
            cursor,
        })
    }

    fn request(&self) -> RawRequest {
        let mut request = RawRequest::get(["workflow-version-usage"])
            .query("workflow_type", self.workflow_type)
            .query("change_id", self.change_id)
            .query("min_version", self.minimum.to_string())
            .query("limit", self.limit.to_string());
        if let Some(state) = self.state {
            request = request.query("state", state.name());
        }
        if let Some(cursor) = self.cursor {
            request = request.query("cursor", cursor);
        }
        request
    }
}

fn required_text<'a>(
    value: Option<&'a str>,
    name: &str,
    maximum: usize,
) -> Result<&'a str, CliError> {
    value
        .filter(|value| !value.is_empty() && value.len() <= maximum)
        .ok_or_else(|| {
            CliError::Invalid(format!(
                "{name} must be nonempty UTF-8 text of at most {maximum} bytes."
            ))
        })
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Counts {
    total: u64,
    legacy: u64,
    current: u64,
    unknown: u64,
}

impl Counts {
    fn for_state(&self, state: State) -> u64 {
        match state {
            State::Legacy => self.legacy,
            State::Current => self.current,
            State::Unknown => self.unknown,
        }
    }
}

#[derive(Deserialize)]
#[serde(rename_all = "snake_case")]
enum UnknownReason {
    MarkerAbsent,
    HistoryUnavailable,
    HistoryInvalid,
}

impl UnknownReason {
    fn name(&self) -> &'static str {
        match self {
            Self::MarkerAbsent => "marker_absent",
            Self::HistoryUnavailable => "history_unavailable",
            Self::HistoryInvalid => "history_invalid",
        }
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Run {
    instance_id: String,
    run_id: String,
    status: String,
    state: State,
    recorded_version: Option<i32>,
    unknown_reason: Option<UnknownReason>,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Page {
    schema_version: u8,
    namespace: String,
    workflow_type: String,
    change_id: String,
    min_version: i32,
    state: Option<State>,
    consistency: String,
    through_run_id: Option<String>,
    counts: Counts,
    runs: Vec<Run>,
    limit: u16,
    returned_count: usize,
    has_more: bool,
    next_cursor: Option<String>,
}

fn invalid_response(detail: &str) -> CliError {
    CliError::Failure(format!("Workflow version usage is unavailable: invalid Server response ({detail}). Counts have not been established."))
}

impl Page {
    fn parse(body: &Value, query: &Query<'_>, namespace: &str) -> Result<Self, CliError> {
        // Nullable fields are still required by v1. Serde alone treats missing
        // Option fields as null, which would hide incomplete producer data.
        for name in [
            "schema_version",
            "namespace",
            "workflow_type",
            "change_id",
            "min_version",
            "state",
            "consistency",
            "through_run_id",
            "counts",
            "runs",
            "limit",
            "returned_count",
            "has_more",
            "next_cursor",
        ] {
            if body.get(name).is_none() {
                return Err(invalid_response("missing required v1 fields"));
            }
        }
        let page: Self = serde_json::from_value(body.clone())
            .map_err(|_| invalid_response("v1 field types or values"))?;
        if page.schema_version != 1
            || page.namespace != namespace
            || page.workflow_type != query.workflow_type
            || page.change_id != query.change_id
            || page.min_version != query.minimum
            || page.state != query.state
            || page.consistency != "live"
            || page.limit != query.limit
        {
            return Err(invalid_response(
                "query identity or live-page contract mismatch",
            ));
        }
        let total = page
            .counts
            .legacy
            .checked_add(page.counts.current)
            .and_then(|n| n.checked_add(page.counts.unknown));
        if total != Some(page.counts.total)
            || page.returned_count != page.runs.len()
            || page.runs.len() > usize::from(query.limit)
        {
            return Err(invalid_response("counts or page length"));
        }
        let selected = query
            .state
            .map_or(page.counts.total, |state| page.counts.for_state(state));
        if (query.cursor.is_none()
            && !page.has_more
            && u64::try_from(page.returned_count).ok() != Some(selected))
            || (page.has_more && page.returned_count != usize::from(query.limit))
        {
            return Err(invalid_response(
                "incomplete first page or continuation page",
            ));
        }
        let bound = page.through_run_id.as_deref();
        if bound == Some("")
            || (bound.is_none()
                && (page.counts.total != 0 || !page.runs.is_empty() || page.has_more))
            || page.has_more
                != page
                    .next_cursor
                    .as_deref()
                    .is_some_and(|cursor| !cursor.is_empty())
            || (!page.has_more && page.next_cursor.is_some())
            || (page.has_more
                && (page.runs.is_empty() || page.next_cursor.as_deref() == query.cursor))
        {
            return Err(invalid_response("continuation or upper bound"));
        }
        page.validate_runs(body, query, bound)?;
        Ok(page)
    }

    fn validate_runs(
        &self,
        body: &Value,
        query: &Query<'_>,
        bound: Option<&str>,
    ) -> Result<(), CliError> {
        let raw_runs = body["runs"]
            .as_array()
            .ok_or_else(|| invalid_response("run list"))?;
        let mut previous: Option<&str> = None;
        let mut observed = [0u64; 3];
        for (run, raw) in self.runs.iter().zip(raw_runs) {
            if raw.get("recorded_version").is_none()
                || raw.get("unknown_reason").is_none()
                || run.instance_id.is_empty()
                || run.run_id.is_empty()
                || !matches!(run.status.as_str(), "pending" | "running" | "waiting")
                || previous.is_some_and(|id| id >= run.run_id.as_str())
                || bound.is_none_or(|id| run.run_id.as_str() > id)
                || query.state.is_some_and(|state| run.state != state)
            {
                return Err(invalid_response("run identity, ordering or state filter"));
            }
            previous = Some(&run.run_id);
            let valid = match (run.state, run.recorded_version, run.unknown_reason.as_ref()) {
                (State::Legacy, Some(version), None) => version < query.minimum,
                (State::Current, Some(version), None) => version >= query.minimum,
                (State::Unknown, None, Some(_)) => true,
                _ => false,
            };
            if !valid {
                return Err(invalid_response("version classification or unknown reason"));
            }
            observed[match run.state {
                State::Legacy => 0,
                State::Current => 1,
                State::Unknown => 2,
            }] += 1;
        }
        if [State::Legacy, State::Current, State::Unknown]
            .into_iter()
            .zip(observed)
            .any(|(state, count)| count > self.counts.for_state(state))
        {
            return Err(invalid_response("state counts exclude returned runs"));
        }
        Ok(())
    }
}

pub fn run(context: &Context, input: &Input) -> Result<i32, CliError> {
    let query = Query::parse(input, &context.connection.namespace)?;
    let info = context.preflight(false)?;
    compat::require_capability(
        &info,
        crate::build_info::version(),
        CAPABILITY,
        "Version usage is unavailable. No legacy/current/unknown counts have been established.",
        NEXT_STEP,
    )
    .map_err(CliError::Compatibility)?;
    let body = context
        .resource_request(query.request())
        .map_err(|error| match error {
            CliError::Http {
                status,
                message,
                body,
            } if matches!(status, 404 | 405 | 501) => CliError::Http {
                status,
                message: format!(
                    "Workflow version usage is unavailable (HTTP {status}): {message}"
                ),
                body,
            },
            other => other,
        })?;
    let page = Page::parse(&body, &query, &context.connection.namespace)?;
    if context.mode.machine_readable() {
        // One complete page per JSON/JSONL record retains counts even when the
        // selected page is empty. Row-only JSONL would discard unknown counts.
        output::print_json(&body);
    } else {
        render(&page);
    }
    Ok(0)
}

fn render(page: &Page) {
    let text = |value: &str| formatter::display_text(value);
    formatter::writeln_out(&format!("Namespace: {}", text(&page.namespace)));
    formatter::writeln_out(&format!(
        "Workflow type: {} | Change ID: {} | Minimum version: {}",
        text(&page.workflow_type),
        text(&page.change_id),
        page.min_version
    ));
    formatter::writeln_out(&format!(
        "Open runs (all states before filter/page): total={} legacy={} current={} unknown={}",
        page.counts.total, page.counts.legacy, page.counts.current, page.counts.unknown
    ));
    formatter::writeln_out(
        "Live observation. Counts and state membership can change between pages.",
    );
    if page.counts.unknown > 0 {
        formatter::writeln_out("<comment>Unknown runs remain unresolved; missing markers and unavailable history are not current decisions.</comment>");
    }
    if page.runs.is_empty() {
        formatter::writeln_out("No runs on this page.");
    } else {
        let rows = page
            .runs
            .iter()
            .map(|run| {
                vec![
                    text(&run.instance_id),
                    text(&run.run_id),
                    text(&run.status),
                    run.state.name().into(),
                    run.recorded_version
                        .map(|v| v.to_string())
                        .unwrap_or_else(|| "-".into()),
                    run.unknown_reason
                        .as_ref()
                        .map(|r| r.name().to_string())
                        .unwrap_or_else(|| "-".into()),
                ]
            })
            .collect::<Vec<_>>();
        formatter::writeln_out(&output::render_table(
            &[
                "Workflow ID",
                "Run ID",
                "Status",
                "State",
                "Recorded version",
                "Unknown reason",
            ],
            &rows,
        ));
    }
    formatter::writeln_out(&format!(
        "Returned: {} | Has more: {}",
        page.returned_count, page.has_more
    ));
    if let Some(cursor) = &page.next_cursor {
        formatter::writeln_out(&format!("Next cursor: {}", text(cursor)));
    }
    formatter::writeln_out("Keep unknowns visible and restart after draining or fencing admissions before retiring a branch.");
}
