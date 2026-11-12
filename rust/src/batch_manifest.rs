//! The batch manifest (cli#60): a private, append-only JSONL file that
//! records a batch's selection before any mutation and each target's
//! outcome as it happens, so an interrupted batch can be inspected and
//! resumed without relisting or resending.
//!
//! Line 1 is the header: schema, Server, namespace, operation and its
//! parameters, the selection and every target with its pinned run and
//! command key. Each later line is one event: `dispatching` before a
//! request leaves, `outcome` after its response. Every write is fsync'd.

use crate::error::CliError;
use serde_json::{json, Map, Value};
use std::fs::{File, OpenOptions};
use std::io::{BufRead, BufReader, Seek, SeekFrom, Write};
use std::path::{Path, PathBuf};

pub const SCHEMA: &str = "durable-workflow.cli.batch-manifest/v1";

/// Where one target stands.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum State {
    /// Not dispatched yet, or rejected before admission (authentication).
    Pending,
    /// The Server accepted the command.
    Acknowledged,
    /// A permanent refusal; resending would be refused again.
    Refused,
    /// The Server asked the caller to slow down.
    Pressure,
    /// Not addressable: closed, not found, or listed without a run ID.
    Skipped,
    /// Sent, but whether the Server admitted it is not known.
    Unknown,
}

impl State {
    pub const ALL: [State; 6] = [
        State::Acknowledged,
        State::Skipped,
        State::Refused,
        State::Pressure,
        State::Unknown,
        State::Pending,
    ];

    pub fn name(self) -> &'static str {
        match self {
            State::Pending => "pending",
            State::Acknowledged => "acknowledged",
            State::Refused => "refused",
            State::Pressure => "pressure",
            State::Skipped => "skipped",
            State::Unknown => "unknown",
        }
    }

    fn parse(name: &str) -> Option<State> {
        State::ALL.into_iter().find(|state| state.name() == name)
    }
}

/// One selected workflow, pinned to the run its listing reported.
pub struct Target {
    pub index: usize,
    pub workflow_id: String,
    pub run_id: Option<String>,
    pub command_key: String,
    pub state: State,
    /// Requests dispatched so far.
    pub attempts: u64,
    /// The last outcome event, when there is one.
    pub last_outcome: Option<Map<String, Value>>,
}

impl Target {
    pub fn to_json(&self) -> Value {
        let detail = |key: &str| {
            self.last_outcome
                .as_ref()
                .and_then(|outcome| outcome.get(key))
                .cloned()
                .unwrap_or(Value::Null)
        };
        json!({
            "index": self.index,
            "workflow_id": self.workflow_id,
            "run_id": self.run_id,
            "command_key": self.command_key,
            "state": self.state.name(),
            "attempts": self.attempts,
            "http_status": detail("http_status"),
            "reason": detail("reason"),
            "message": detail("message"),
        })
    }
}

/// An open manifest: its header, every target's current state, and (when
/// opened for writing) an exclusive lock on the file.
pub struct Manifest {
    pub path: PathBuf,
    pub header: Map<String, Value>,
    pub targets: Vec<Target>,
    /// Why earlier runs stopped before sending every target, oldest first.
    pub stops: Vec<Map<String, Value>>,
    file: Option<File>,
}

impl Manifest {
    /// Write a new manifest: refuses an existing path, creates the file
    /// private to the caller, and makes the header durable before returning.
    pub fn create(path: &Path, header: Map<String, Value>) -> Result<Manifest, CliError> {
        let mut options = OpenOptions::new();
        options.write(true).create_new(true);
        #[cfg(unix)]
        {
            use std::os::unix::fs::OpenOptionsExt;
            options.mode(0o600);
        }
        let file = options.open(path).map_err(|error| {
            if error.kind() == std::io::ErrorKind::AlreadyExists {
                CliError::Invalid(format!(
                    "The manifest {} already exists. Choose a new path, or resume it with dw batch:resume.",
                    path.display()
                ))
            } else {
                CliError::Failure(format!(
                    "Unable to create the manifest {}: {error}",
                    path.display()
                ))
            }
        })?;
        lock(&file, path)?;
        let targets = targets_from(&header, path)?;
        let mut manifest = Manifest {
            path: path.to_path_buf(),
            header,
            targets,
            stops: Vec::new(),
            file: Some(file),
        };
        let line = Value::Object(manifest.header.clone());
        manifest.write_line(&line)?;
        Ok(manifest)
    }

    /// Read a manifest. With `writable`, also lock it for this process and
    /// drop a final line a crash left half-written, so appends stay valid.
    pub fn open(path: &Path, writable: bool) -> Result<Manifest, CliError> {
        let unreadable = |error: std::io::Error| {
            CliError::Invalid(format!(
                "Unable to read the manifest {}: {error}",
                path.display()
            ))
        };
        let mut file = OpenOptions::new()
            .read(true)
            .write(writable)
            .open(path)
            .map_err(unreadable)?;
        if writable {
            lock(&file, path)?;
        }
        let mut lines = Vec::new();
        let mut complete_length = 0u64;
        let mut reader = BufReader::new(&file);
        loop {
            let mut line = Vec::new();
            let read = reader.read_until(b'\n', &mut line).map_err(unreadable)?;
            if read == 0 {
                break;
            }
            if line.last() != Some(&b'\n') {
                // An interrupted append: the event was never recorded.
                break;
            }
            complete_length += read as u64;
            lines.push(line);
        }
        drop(reader);
        let invalid = |detail: &str| {
            CliError::Invalid(format!(
                "{} is not a dw batch manifest: {detail}",
                path.display()
            ))
        };
        let parse = |line: &[u8]| -> Option<Map<String, Value>> {
            match serde_json::from_slice::<Value>(line) {
                Ok(Value::Object(map)) => Some(map),
                _ => None,
            }
        };
        let mut lines = lines.into_iter();
        let header = lines
            .next()
            .and_then(|line| parse(&line))
            .ok_or_else(|| invalid("missing header"))?;
        if header.get("schema").and_then(Value::as_str) != Some(SCHEMA) {
            return Err(invalid(&format!("expected schema {SCHEMA}")));
        }
        let mut targets = targets_from(&header, path)?;
        let mut in_flight = vec![false; targets.len()];
        let mut stops = Vec::new();
        for (number, line) in lines.enumerate() {
            let event = parse(&line)
                .ok_or_else(|| invalid(&format!("line {} is not a JSON object", number + 2)))?;
            // A run-level record, such as a deadline, names no target.
            if event.get("event").and_then(Value::as_str) == Some("stopped") {
                stops.push(event);
                continue;
            }
            let target = event
                .get("index")
                .and_then(Value::as_u64)
                .and_then(|index| usize::try_from(index).ok())
                .filter(|index| *index < targets.len())
                .ok_or_else(|| invalid(&format!("line {} names no target", number + 2)))?;
            match event.get("event").and_then(Value::as_str) {
                Some("dispatching") => {
                    targets[target].attempts += 1;
                    in_flight[target] = true;
                }
                Some("outcome") => {
                    let state = event
                        .get("state")
                        .and_then(Value::as_str)
                        .and_then(State::parse)
                        .ok_or_else(|| invalid(&format!("line {} has no state", number + 2)))?;
                    targets[target].state = state;
                    targets[target].last_outcome = Some(event);
                    in_flight[target] = false;
                }
                _ => return Err(invalid(&format!("line {} has no event", number + 2))),
            }
        }
        // A request dispatched with no recorded outcome may have been
        // admitted: its outcome is unknown.
        for (target, sent) in targets.iter_mut().zip(in_flight) {
            if sent {
                target.state = State::Unknown;
                target.last_outcome = None;
            }
        }
        if writable {
            file.set_len(complete_length).map_err(unreadable)?;
            file.seek(SeekFrom::End(0)).map_err(unreadable)?;
        }
        Ok(Manifest {
            path: path.to_path_buf(),
            header,
            targets,
            stops,
            file: writable.then_some(file),
        })
    }

    pub fn header_str(&self, key: &str) -> &str {
        self.header
            .get(key)
            .and_then(Value::as_str)
            .unwrap_or_default()
    }

    /// Record that a request for this target is about to leave.
    pub fn dispatching(&mut self, index: usize) -> Result<(), CliError> {
        let attempt = self.targets[index].attempts + 1;
        self.write_line(&json!({
            "event": "dispatching",
            "index": index,
            "attempt": attempt,
            "at": utc_now(),
        }))?;
        self.targets[index].attempts = attempt;
        self.targets[index].state = State::Unknown;
        self.targets[index].last_outcome = None;
        Ok(())
    }

    /// Record a target's outcome. `details` adds fields such as the HTTP
    /// status, the Server's reason and message.
    pub fn outcome(
        &mut self,
        index: usize,
        state: State,
        details: Map<String, Value>,
    ) -> Result<(), CliError> {
        let mut event = Map::new();
        event.insert("event".into(), json!("outcome"));
        event.insert("index".into(), json!(index));
        event.insert("attempt".into(), json!(self.targets[index].attempts));
        event.insert("state".into(), json!(state.name()));
        event.extend(details);
        event.insert("at".into(), json!(utc_now()));
        self.write_line(&Value::Object(event.clone()))?;
        self.targets[index].state = state;
        self.targets[index].last_outcome = Some(event);
        Ok(())
    }

    /// Record why this run stopped before sending every eligible target.
    pub fn stopped(&mut self, reason: &str, message: &str) -> Result<(), CliError> {
        let mut event = Map::new();
        event.insert("event".into(), json!("stopped"));
        event.insert("reason".into(), json!(reason));
        event.insert("message".into(), json!(message));
        event.insert("at".into(), json!(utc_now()));
        self.write_line(&Value::Object(event.clone()))?;
        self.stops.push(event);
        Ok(())
    }

    /// Counts per state, in a stable order.
    pub fn counts(&self) -> Map<String, Value> {
        State::ALL
            .into_iter()
            .map(|state| {
                let count = self
                    .targets
                    .iter()
                    .filter(|target| target.state == state)
                    .count();
                (state.name().to_string(), json!(count))
            })
            .collect()
    }

    fn write_line(&mut self, value: &Value) -> Result<(), CliError> {
        let path = self.path.clone();
        let file = self
            .file
            .as_mut()
            .expect("only writable manifests record events");
        let mut line = serde_json::to_vec(value).expect("JSON values always serialize");
        line.push(b'\n');
        file.write_all(&line)
            .and_then(|()| file.sync_all())
            .map_err(|error| {
                CliError::Failure(format!(
                    "Unable to record progress in the manifest {}: {error}. Stopped before sending anything further.",
                    path.display()
                ))
            })
    }
}

fn targets_from(header: &Map<String, Value>, path: &Path) -> Result<Vec<Target>, CliError> {
    let invalid = || {
        CliError::Invalid(format!(
            "{} is not a dw batch manifest: its targets are malformed",
            path.display()
        ))
    };
    header
        .get("targets")
        .and_then(Value::as_array)
        .ok_or_else(invalid)?
        .iter()
        .enumerate()
        .map(|(position, target)| {
            let text = |key: &str| target.get(key).and_then(Value::as_str).map(str::to_owned);
            if target.get("index").and_then(Value::as_u64) != Some(position as u64) {
                return Err(invalid());
            }
            Ok(Target {
                index: position,
                workflow_id: text("workflow_id").ok_or_else(invalid)?,
                run_id: text("run_id"),
                command_key: text("command_key").ok_or_else(invalid)?,
                state: State::Pending,
                attempts: 0,
                last_outcome: None,
            })
        })
        .collect()
}

/// One batch at a time per manifest: an exclusive advisory lock that the
/// operating system releases if the process dies.
fn lock(file: &File, path: &Path) -> Result<(), CliError> {
    #[cfg(unix)]
    {
        use std::os::unix::io::AsRawFd;
        // SAFETY: flock only reads the descriptor, which `file` keeps open.
        let locked = unsafe { libc::flock(file.as_raw_fd(), libc::LOCK_EX | libc::LOCK_NB) };
        if locked != 0 {
            return Err(CliError::Invalid(format!(
                "Another dw process is running the batch in {}. Wait for it to finish.",
                path.display()
            )));
        }
    }
    #[cfg(not(unix))]
    {
        let _ = (file, path);
    }
    Ok(())
}

/// A new random manifest identity: 128 bits as lowercase hex.
pub fn new_id() -> Result<String, CliError> {
    let mut bytes = [0u8; 16];
    getrandom::getrandom(&mut bytes)
        .map_err(|error| CliError::Failure(format!("Unable to generate a manifest ID: {error}")))?;
    Ok(bytes.iter().map(|byte| format!("{byte:02x}")).collect())
}

/// The current UTC time as RFC 3339 with whole seconds.
pub fn utc_now() -> String {
    let seconds = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|elapsed| elapsed.as_secs())
        .unwrap_or(0);
    format_utc(seconds)
}

fn format_utc(seconds: u64) -> String {
    let days = (seconds / 86_400) as i64;
    let rest = seconds % 86_400;
    // Howard Hinnant's civil_from_days.
    let shifted = days + 719_468;
    let era = shifted.div_euclid(146_097);
    let day_of_era = shifted.rem_euclid(146_097);
    let year_of_era =
        (day_of_era - day_of_era / 1_460 + day_of_era / 36_524 - day_of_era / 146_096) / 365;
    let day_of_year = day_of_era - (365 * year_of_era + year_of_era / 4 - year_of_era / 100);
    let month_index = (5 * day_of_year + 2) / 153;
    let day = day_of_year - (153 * month_index + 2) / 5 + 1;
    let month = if month_index < 10 {
        month_index + 3
    } else {
        month_index - 9
    };
    let year = year_of_era + era * 400 + i64::from(month <= 2);
    format!(
        "{year:04}-{month:02}-{day:02}T{:02}:{:02}:{:02}Z",
        rest / 3_600,
        rest % 3_600 / 60,
        rest % 60
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn formats_utc_times() {
        assert_eq!(format_utc(0), "1970-01-01T00:00:00Z");
        assert_eq!(format_utc(951_782_400), "2000-02-29T00:00:00Z");
        assert_eq!(format_utc(1_794_447_157), "2026-11-12T01:32:37Z");
    }

    #[test]
    fn state_names_round_trip() {
        for state in State::ALL {
            assert_eq!(State::parse(state.name()), Some(state));
        }
        assert_eq!(State::parse("done"), None);
    }
}
