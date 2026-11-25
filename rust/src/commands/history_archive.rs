//! `workflow:history-archive` and `workflow:history-archive-verify`
//! (durable-workflow/server#520): a self-contained, verified archive of one
//! run's history and the exact bytes of every external payload it
//! references, copied under the Server's external payload backup hold.
//!
//! The copy acquires the hold with a new owner, reads the export under it,
//! refuses an export that cannot be a complete archive, fetches each object
//! once through the runtime transport and checks its length and digest,
//! writes the manifest, verifies the whole directory from disk, confirms the
//! hold is still its own, and only then writes the receipt. The hold is
//! released on every path; a crash leaves it to expire, and the next run in
//! the same directory releases the owner it recorded.

use crate::cli::Input;
use crate::client::{map_error, Context};
use crate::error::{exit, CliError};
use crate::history_archive::{
    self as archive, Plan, HISTORY_FILE, MANIFEST_FILE, OBJECTS_DIR, RECEIPT_FILE,
};
use crate::{batch_manifest, build_info, compat, formatter, output};
use durable_workflow::RawRequest;
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use std::path::{Path, PathBuf};
use std::time::{Duration, Instant};

/// The record of a copy in progress: the hold owner a crashed run leaves
/// behind for the next run to release.
const PROGRESS_FILE: &str = ".dw-history-archive.json";
const PROGRESS_SCHEMA: &str = "dw.history-archive.in-progress.v1";
const RESULT_SCHEMA: &str = "dw.history-archive.result.v1";
const DEFAULT_HOLD_TTL: u64 = 900;
const HOLD_SEGMENTS: [&str; 2] = ["system", "external-payload-backup-hold"];

/// The outcome's exit status: 0 verified, 1 incomplete, 2 refused.
fn exit_for(outcome: &str) -> i32 {
    match outcome {
        "verified" => exit::SUCCESS,
        "refused" => exit::INVALID,
        _ => exit::FAILURE,
    }
}

pub fn verify(input: &Input) -> Result<i32, CliError> {
    let mode = output::resolve_mode(input, || None)?;
    let dir = PathBuf::from(input.arg("directory").unwrap_or_default());
    let mut report = archive::verify(&dir);
    let outcome = report["outcome"].as_str().unwrap_or("refused").to_owned();
    if mode.machine_readable() {
        report["directory"] = json!(dir.display().to_string());
        output::print_json(&report);
    } else {
        for line in report_lines(&report) {
            formatter::writeln_out(&line);
        }
    }
    Ok(exit_for(&outcome))
}

/// Human lines for a verification report.
fn report_lines(report: &Value) -> Vec<String> {
    let text = |value: &Value| {
        value
            .as_str()
            .map(formatter::display_text)
            .unwrap_or_else(|| "-".into())
    };
    let identity = &report["identity"];
    let mut lines = vec![format!(
        "Outcome: <info>{}</info>",
        text(&report["outcome"])
    )];
    if identity.is_object() {
        lines.push(format!(
            "Run: {} / {} (namespace {})",
            text(&identity["workflow_id"]),
            text(&identity["run_id"]),
            text(&identity["namespace"])
        ));
    }
    if report.get("object_count").is_some() {
        lines.push(format!(
            "Objects: {} of {} verified ({} bytes)",
            report["verified_objects"], report["object_count"], report["object_bytes"]
        ));
    }
    if report["receipt"]["present"] == true {
        lines.push(format!(
            "Receipt: verified at {}",
            text(&report["receipt"]["verified_at"])
        ));
    }
    for finding in report["findings"].as_array().into_iter().flatten() {
        lines.push(format!(
            "  - {}: {}",
            text(&finding["code"]),
            text(&finding["message"])
        ));
    }
    lines
}

/// A random version 4 UUID, the hold's single-use owner.
fn new_owner() -> Result<String, CliError> {
    let mut bytes = [0u8; 16];
    getrandom::getrandom(&mut bytes)
        .map_err(|error| CliError::Failure(format!("Unable to generate a hold owner: {error}")))?;
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    let hex: String = bytes.iter().map(|byte| format!("{byte:02x}")).collect();
    Ok(format!(
        "{}-{}-{}-{}-{}",
        &hex[..8],
        &hex[8..12],
        &hex[12..16],
        &hex[16..20],
        &hex[20..]
    ))
}

fn io_failure(path: &Path, error: std::io::Error) -> CliError {
    CliError::Failure(format!("Unable to write {}: {error}", path.display()))
}

/// Write `bytes` to `path` through a sibling temporary file, so a crash
/// never leaves a partly written file under the final name.
fn write_atomic(path: &Path, bytes: &[u8]) -> Result<(), CliError> {
    let partial = path.with_extension("partial");
    let write = || -> std::io::Result<()> {
        use std::io::Write;
        let mut file = std::fs::File::create(&partial)?;
        file.write_all(bytes)?;
        file.sync_all()?;
        std::fs::rename(&partial, path)
    };
    write().map_err(|error| io_failure(path, error))
}

/// What the target directory already holds.
struct Directory {
    created: bool,
    /// The hold owner a crashed earlier copy recorded.
    stale_owner: Option<String>,
}

/// Create the directory, or accept an empty one or an unfinished archive of
/// the same run. A completed archive and any other directory are refused
/// before the Server is contacted.
fn prepare(dir: &Path, workflow_id: &str, run_id: &str) -> Result<Directory, CliError> {
    if !dir.exists() {
        std::fs::create_dir_all(dir).map_err(|error| io_failure(dir, error))?;
        return Ok(Directory {
            created: true,
            stale_owner: None,
        });
    }
    let metadata = std::fs::symlink_metadata(dir).map_err(|error| io_failure(dir, error))?;
    if !metadata.is_dir() {
        return Err(CliError::Invalid(format!(
            "{} is not a directory.",
            dir.display()
        )));
    }
    let empty = std::fs::read_dir(dir)
        .map_err(|error| io_failure(dir, error))?
        .next()
        .is_none();
    if empty {
        return Ok(Directory {
            created: false,
            stale_owner: None,
        });
    }
    if dir.join(RECEIPT_FILE).exists() {
        return Err(CliError::Invalid(format!(
            "{} already holds a completed archive. Verify it with dw workflow:history-archive-verify, or choose a new directory.",
            dir.display()
        )));
    }
    let read = |name: &str| {
        std::fs::read(dir.join(name))
            .ok()
            .and_then(|bytes| serde_json::from_slice::<Value>(&bytes).ok())
    };
    let progress = read(PROGRESS_FILE).filter(|progress| progress["schema"] == PROGRESS_SCHEMA);
    let manifest =
        read(MANIFEST_FILE).filter(|manifest| manifest["schema"] == archive::MANIFEST_SCHEMA);
    let (archived_workflow, archived_run) = match (&progress, &manifest) {
        (Some(progress), _) => (progress["workflow_id"].clone(), progress["run_id"].clone()),
        (None, Some(manifest)) => (manifest["identity"]["workflow_id"].clone(), manifest["identity"]["run_id"].clone()),
        (None, None) => {
            return Err(CliError::Invalid(format!(
                "{} is not empty and is not an unfinished history archive. Choose an empty or new directory.",
                dir.display()
            )))
        }
    };
    if archived_workflow != workflow_id || archived_run != run_id {
        return Err(CliError::Invalid(format!(
            "{} holds an unfinished archive of another run ({} / {}). Choose another directory.",
            dir.display(),
            archived_workflow.as_str().unwrap_or("-"),
            archived_run.as_str().unwrap_or("-")
        )));
    }
    Ok(Directory {
        created: false,
        stale_owner: progress.and_then(|progress| progress["owner"].as_str().map(str::to_owned)),
    })
}

/// The Server's answer to one control-plane request, as JSON.
async fn send(context: &Context, request: RawRequest) -> Result<(u16, Value), CliError> {
    let request = request
        .protocol_version(compat::worker_protocol_version())
        .control_plane_version(compat::control_plane_version());
    let response = context
        .sdk()?
        .control_plane_request(request)
        .await
        .map_err(map_error)?;
    let body = serde_json::from_slice(response.body_bytes()).unwrap_or(Value::Null);
    Ok((response.status(), body))
}

fn http_error(status: u16, body: &Value) -> CliError {
    CliError::from_http(status, &body.to_string())
}

async fn hold(
    context: &Context,
    action: &str,
    owner: &str,
    ttl: u64,
) -> Result<(u16, Value), CliError> {
    let segments = [HOLD_SEGMENTS[0], HOLD_SEGMENTS[1], action];
    let body = if action == "release" {
        json!({"owner": owner})
    } else {
        json!({"owner": owner, "ttl": ttl})
    };
    send(context, RawRequest::post(segments).json(body)).await
}

/// The hold as this owner holds it, or the reason it does not.
async fn hold_status(context: &Context, owner: &str) -> Result<(u16, Value), CliError> {
    send(
        context,
        RawRequest::get(HOLD_SEGMENTS).query("owner", owner),
    )
    .await
}

fn hold_record(owner: &str, report: &Value) -> Value {
    json!({"kind": archive::HOLD_KIND, "owner": owner, "acquired_at": report["acquired_at"], "expires_at": report["expires_at"]})
}

/// One copy's progress for the result document.
#[derive(Default)]
struct Copied {
    copied: usize,
    resumed: usize,
    failures: Vec<Value>,
    hold_lost: Option<Value>,
}

/// The renewing hold of one copy.
struct Hold<'a> {
    context: &'a Context,
    owner: String,
    ttl: u64,
    renewed: Instant,
    report: Value,
}

impl Hold<'_> {
    /// Renew once a third of the lease has passed. A refused renewal means
    /// the hold lapsed or was taken: the copy cannot complete.
    async fn keep(&mut self) -> Result<Result<(), Value>, CliError> {
        if self.renewed.elapsed() < Duration::from_secs(self.ttl / 3) {
            return Ok(Ok(()));
        }
        let (status, body) = hold(self.context, "renew", &self.owner, self.ttl).await?;
        if status != 200 || body["active"] != true {
            return Ok(Err(body));
        }
        self.renewed = Instant::now();
        self.report = body;
        Ok(Ok(()))
    }
}

/// The length and SHA-256 of a regular file, when it has the expected ones.
fn has_bytes(path: &Path, size: i64, sha256: &str) -> bool {
    let Ok(metadata) = std::fs::symlink_metadata(path) else {
        return false;
    };
    if !metadata.is_file() || metadata.len() != size as u64 {
        return false;
    }
    std::fs::read(path).is_ok_and(|bytes| archive::sha256_hex(&bytes) == sha256)
}

/// Fetch one object into `path`, checking its length and digest before the
/// rename. Answers the refusal when the Server or the bytes fail.
async fn fetch_object(
    http: &reqwest::Client,
    context: &Context,
    object: &archive::RequiredObject,
    path: &Path,
    timeout: Duration,
) -> Result<Result<(), Value>, CliError> {
    let reference = &object.reference;
    let connection = &context.connection;
    let url = format!(
        "{}/api/external-payloads/v1/{}",
        connection.server.trim_end_matches('/'),
        reference.reference_id
    );
    let mut request = http
        .get(url)
        .timeout(timeout)
        .header("Accept", "application/octet-stream")
        .header("X-Namespace", &connection.namespace)
        .header(
            "X-Durable-Workflow-Control-Plane-Version",
            compat::control_plane_version(),
        )
        .header("X-Durable-Workflow-Payload-Codec", reference.codec)
        .header(
            "X-Durable-Workflow-Payload-Size",
            reference.size_bytes.to_string(),
        )
        .header("X-Durable-Workflow-Payload-SHA256", &reference.sha256);
    if let Some(token) = connection
        .token
        .as_deref()
        .filter(|token| !token.is_empty())
    {
        request = request.bearer_auth(token);
    }
    let failure = |reason: &str, status: Option<u16>| -> Result<Result<(), Value>, CliError> {
        Ok(Err(
            json!({"reference_id": reference.reference_id, "status": status, "reason": reason}),
        ))
    };
    let mut response = match request.send().await {
        Ok(response) => response,
        Err(error) if error.is_timeout() => return failure("timeout", None),
        Err(_) => return failure("unreachable", None),
    };
    let status = response.status().as_u16();
    if status != 200 {
        let body: Value = match response.bytes().await {
            Ok(bytes) => serde_json::from_slice(&bytes).unwrap_or(Value::Null),
            Err(_) => Value::Null,
        };
        let reason = body["reason"]
            .as_str()
            .unwrap_or("external_payload_unavailable")
            .to_owned();
        return failure(&reason, Some(status));
    }
    let partial = path.with_extension("partial");
    let mut file = std::fs::File::create(&partial).map_err(|error| io_failure(&partial, error))?;
    let mut hasher = Sha256::new();
    let mut length: u64 = 0;
    loop {
        let chunk = match response.chunk().await {
            Ok(Some(chunk)) => chunk,
            Ok(None) => break,
            Err(_) => {
                let _ = std::fs::remove_file(&partial);
                return failure("transfer_interrupted", Some(status));
            }
        };
        length += chunk.len() as u64;
        if length > reference.size_bytes as u64 {
            let _ = std::fs::remove_file(&partial);
            return failure("external_payload_integrity_mismatch", Some(status));
        }
        hasher.update(&chunk);
        use std::io::Write;
        file.write_all(&chunk)
            .map_err(|error| io_failure(&partial, error))?;
    }
    let digest: String = hasher
        .finalize()
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect();
    if length != reference.size_bytes as u64 || digest != reference.sha256 {
        let _ = std::fs::remove_file(&partial);
        return failure("external_payload_integrity_mismatch", Some(status));
    }
    file.sync_all()
        .map_err(|error| io_failure(&partial, error))?;
    drop(file);
    std::fs::rename(&partial, path).map_err(|error| io_failure(path, error))?;
    Ok(Ok(()))
}

/// Remove temporary files and objects the export no longer references from
/// `objects/`. Only names this command writes are touched.
fn prune_objects(dir: &Path, plan: &Plan) -> Result<(), CliError> {
    let objects = dir.join(OBJECTS_DIR);
    std::fs::create_dir_all(&objects).map_err(|error| io_failure(&objects, error))?;
    for entry in std::fs::read_dir(&objects).map_err(|error| io_failure(&objects, error))? {
        let entry = entry.map_err(|error| io_failure(&objects, error))?;
        let name = entry.file_name().to_string_lossy().into_owned();
        let required = plan
            .objects
            .iter()
            .any(|object| object.reference.reference_id == name);
        if name.starts_with("ep_") && !required {
            let _ = std::fs::remove_file(entry.path());
        }
    }
    Ok(())
}

/// Copy under an acquired hold. Answers the result document's fields.
async fn copy(
    context: &Context,
    hold: &mut Hold<'_>,
    dir: &Path,
    workflow_id: &str,
    run_id: &str,
) -> Result<(Value, Plan, Copied), CliError> {
    let export = RawRequest::get([
        "workflows",
        workflow_id,
        "runs",
        run_id,
        "history",
        "export",
    ])
    .protocol_version(compat::worker_protocol_version())
    .control_plane_version(compat::control_plane_version());
    let response = context
        .sdk()?
        .control_plane_request(export)
        .await
        .map_err(map_error)?;
    if !response.is_success() {
        let body = serde_json::from_slice(response.body_bytes()).unwrap_or(Value::Null);
        return Err(http_error(response.status(), &body));
    }
    let export_bytes = response.body_bytes().to_vec();
    let export: Value = serde_json::from_slice(&export_bytes)
        .map_err(|_| CliError::Failure("The Server's history export is not valid JSON.".into()))?;
    let plan = archive::plan(&export);
    let mut copied = Copied::default();
    if !plan.blockers.is_empty() {
        return Ok((export, plan, copied));
    }
    prune_objects(dir, &plan)?;
    write_atomic(&dir.join(HISTORY_FILE), &export_bytes)?;
    let http = reqwest::Client::builder()
        .danger_accept_invalid_certs(!context.connection.tls_verify)
        .build()
        .map_err(|error| CliError::Failure(format!("Unable to build the HTTP client: {error}")))?;
    let timeout = Duration::from_secs((hold.ttl / 2).max(30));
    for object in &plan.objects {
        if let Err(lost) = hold.keep().await? {
            copied.hold_lost = Some(lost);
            break;
        }
        let path = dir.join(object.path());
        if has_bytes(&path, object.reference.size_bytes, &object.reference.sha256) {
            copied.resumed += 1;
            continue;
        }
        match fetch_object(&http, context, object, &path, timeout).await? {
            Ok(()) => copied.copied += 1,
            Err(failure) => copied.failures.push(failure),
        }
    }
    let manifest = archive::manifest(
        &export,
        &export_bytes,
        &plan.objects,
        &context.connection.namespace,
        &batch_manifest::utc_now(),
        &format!("dw {}", build_info::version()),
        hold_record(&hold.owner, &hold.report),
    );
    write_atomic(
        &dir.join(MANIFEST_FILE),
        output::encode_pretty(&manifest).as_bytes(),
    )?;
    Ok((export, plan, copied))
}

pub fn archive(context: &Context, input: &Input) -> Result<i32, CliError> {
    let workflow_id = input.arg("workflow-id").unwrap_or_default().to_owned();
    let run_id = input.arg("run-id").unwrap_or_default().to_owned();
    let dir = PathBuf::from(input.arg("directory").unwrap_or_default());
    let ttl = match input.opt_str("hold-ttl") {
        None => DEFAULT_HOLD_TTL,
        Some(value) => match value.parse::<u64>() {
            Ok(ttl) if (60..=3600).contains(&ttl) => ttl,
            _ => {
                return Ok(crate::command_usage_error(
                    &input.command,
                    "--hold-ttl must be a whole number of seconds from 60 to 3600.",
                ))
            }
        },
    };
    context.preflight(false)?;
    let directory = prepare(&dir, &workflow_id, &run_id)?;
    let runtime = context.runtime();
    if let Some(stale) = &directory.stale_owner {
        // A crashed earlier copy's hold: released if it is still recorded.
        let _ = runtime.block_on(hold(context, "release", stale, ttl));
    }
    let owner = new_owner()?;
    let (status, acquired) = runtime.block_on(hold(context, "acquire", &owner, ttl))?;
    if status == 404 {
        return Err(CliError::Failure(
            "The Server does not serve the external payload backup hold (/api/system/external-payload-backup-hold), which a history archive copies under. Use native Server 3.x or later.".into(),
        ));
    }
    if status != 200 {
        cleanup_created(&dir, &directory);
        return Err(http_error(status, &acquired));
    }
    let progress = json!({"schema": PROGRESS_SCHEMA, "owner": owner, "workflow_id": workflow_id, "run_id": run_id,
        "namespace": context.connection.namespace, "started_at": batch_manifest::utc_now()});
    write_atomic(
        &dir.join(PROGRESS_FILE),
        output::encode_pretty(&progress).as_bytes(),
    )?;
    let mut held = Hold {
        context,
        owner: owner.clone(),
        ttl,
        renewed: Instant::now(),
        report: acquired,
    };

    let copied = runtime.block_on(async {
        tokio::select! {
            result = copy(context, &mut held, &dir, &workflow_id, &run_id) => Some(result),
            _ = tokio::signal::ctrl_c() => None,
        }
    });
    let finish = |held: &Hold| {
        let released = runtime.block_on(hold(context, "release", &held.owner, ttl));
        if matches!(released, Ok((200, _))) {
            let _ = std::fs::remove_file(dir.join(PROGRESS_FILE));
        }
    };
    let (export, plan, copied) = match copied {
        None => {
            finish(&held);
            formatter::writeln_err("<error>Cancelled. The archive is incomplete; run the same command again to resume.</error>");
            return Ok(130);
        }
        Some(Err(error)) => {
            finish(&held);
            return Err(error);
        }
        Some(Ok(result)) => result,
    };

    let mut result = json!({
        "schema": RESULT_SCHEMA,
        "directory": dir.display().to_string(),
        "namespace": context.connection.namespace,
        "workflow_id": workflow_id,
        "run_id": run_id,
        "objects": plan.objects.len(),
        "object_bytes": plan.object_bytes(),
        "copied": copied.copied,
        "resumed": copied.resumed,
        "failures": copied.failures,
        "hold": {"owner": owner, "acquired_at": held.report["acquired_at"]},
    });
    let outcome = if !plan.blockers.is_empty() {
        result["blockers"] = Value::Array(
            plan.blockers
                .iter()
                .map(archive::Finding::to_json)
                .collect(),
        );
        "refused"
    } else if let Some(lost) = &copied.hold_lost {
        result["hold_lost"] = lost.clone();
        "incomplete"
    } else {
        // Cold verification: every byte read back from the directory.
        let report = archive::verify_contents(&dir);
        let confirmed = runtime.block_on(hold_status(context, &owner));
        let confirmed = match confirmed {
            Ok((200, body)) if body["active"] == true => Ok(body),
            Ok((_, body)) => Err(body),
            Err(error) => Err(json!({"reason": error.message()})),
        };
        let outcome = match (&report["outcome"], confirmed) {
            (verified, Ok(confirmed)) if verified == "verified" => {
                let mut record = hold_record(&owner, &held.report);
                record["confirmed_at"] = confirmed["database_time"].clone();
                record["expires_at"] = confirmed["expires_at"].clone();
                let manifest_bytes = std::fs::read(dir.join(MANIFEST_FILE))
                    .map_err(|error| io_failure(&dir, error))?;
                let receipt =
                    archive::receipt(&manifest_bytes, &report, &batch_manifest::utc_now(), record);
                write_atomic(
                    &dir.join(RECEIPT_FILE),
                    output::encode_pretty(&receipt).as_bytes(),
                )?;
                result["receipt"] = json!(dir.join(RECEIPT_FILE).display().to_string());
                "verified"
            }
            (verified, Err(lost)) if verified == "verified" => {
                result["hold_lost"] = lost;
                "incomplete"
            }
            _ => "incomplete",
        };
        result["verification"] = report;
        outcome
    };
    finish(&held);
    if outcome == "refused" {
        // Nothing was copied; leave the directory as it was found.
        cleanup_created(&dir, &directory);
    }
    result["outcome"] = json!(outcome);
    if context.mode.machine_readable() {
        output::print_json(&result);
    } else {
        for line in result_lines(&result, &export) {
            formatter::writeln_out(&line);
        }
    }
    Ok(exit_for(outcome))
}

/// Remove the directory if this run created it and left it empty.
fn cleanup_created(dir: &Path, directory: &Directory) {
    let _ = std::fs::remove_file(dir.join(PROGRESS_FILE));
    if directory.created {
        let _ = std::fs::remove_dir(dir.join(OBJECTS_DIR));
        let _ = std::fs::remove_dir(dir);
    }
}

fn result_lines(result: &Value, export: &Value) -> Vec<String> {
    let text = |value: &Value| {
        value
            .as_str()
            .map(formatter::display_text)
            .unwrap_or_else(|| "-".into())
    };
    let mut lines = vec![
        format!("Outcome: <info>{}</info>", text(&result["outcome"])),
        format!(
            "Run: {} / {} (namespace {})",
            text(&result["workflow_id"]),
            text(&result["run_id"]),
            text(&result["namespace"])
        ),
        format!("Directory: {}", text(&result["directory"])),
    ];
    if result.get("blockers").is_some() {
        lines.push("Nothing was copied: this export cannot be a complete archive.".into());
        for blocker in result["blockers"].as_array().into_iter().flatten() {
            lines.push(format!(
                "  - {}: {}",
                text(&blocker["code"]),
                text(&blocker["message"])
            ));
        }
        if export["history_complete"] != true {
            lines.push("Archive a closed run whose history has not been pruned.".into());
        }
        return lines;
    }
    lines.push(format!(
        "Objects: {} ({} bytes): {} copied, {} already present",
        result["objects"], result["object_bytes"], result["copied"], result["resumed"]
    ));
    for failure in result["failures"].as_array().into_iter().flatten() {
        lines.push(format!(
            "  - {} not copied: {}",
            text(&failure["reference_id"]),
            text(&failure["reason"])
        ));
    }
    if result.get("hold_lost").is_some() {
        lines.push(format!(
            "The backup hold was lost during the copy ({}); no receipt was written.",
            text(&result["hold_lost"]["reason"])
        ));
    }
    for finding in result["verification"]["findings"]
        .as_array()
        .into_iter()
        .flatten()
    {
        lines.push(format!(
            "  - {}: {}",
            text(&finding["code"]),
            text(&finding["message"])
        ));
    }
    match result["outcome"].as_str() {
        Some("verified") => lines.push(format!("Receipt: {}", text(&result["receipt"]))),
        _ => lines
            .push("No receipt was written. Run the same command again to resume the copy.".into()),
    }
    lines
}
