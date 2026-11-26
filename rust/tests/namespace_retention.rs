//! namespace:retention-plan and namespace:retention-apply (Server #522)
//! against a loopback Server that answers each request from a queue, so
//! request counts, paths and bodies are observable.

use serde_json::{json, Value};
use std::collections::VecDeque;
use std::fs;
use std::io::{Read, Write};
use std::net::TcpListener;
use std::path::PathBuf;
use std::process::{Command, Output};
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::{Duration, SystemTime, UNIX_EPOCH};

struct Scratch(PathBuf);

impl Scratch {
    fn new() -> Self {
        static NEXT: AtomicU64 = AtomicU64::new(0);
        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        let path = std::env::temp_dir().join(format!(
            "dw-namespace-retention-{}-{now}-{}",
            std::process::id(),
            NEXT.fetch_add(1, Ordering::Relaxed)
        ));
        fs::create_dir(&path).unwrap();
        Self(path)
    }

    fn run(&self, url: &str, args: &[&str]) -> Output {
        Command::new(env!("CARGO_BIN_EXE_dw"))
            .args(args)
            .args([
                "--server",
                url,
                "--namespace=orders",
                "--token=fixture-token",
            ])
            .env_clear()
            .env("DW_CONFIG_HOME", &self.0)
            .env("HOME", &self.0)
            .output()
            .unwrap()
    }
}

impl Drop for Scratch {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}

/// A queued answer. `body: None` closes the connection without a response,
/// as a Server that crashed after receiving the request would.
#[derive(Clone)]
struct Reply {
    status: u16,
    headers: Vec<(&'static str, &'static str)>,
    body: Option<Value>,
}

fn ok(body: Value) -> Reply {
    Reply {
        status: 200,
        headers: Vec::new(),
        body: Some(body),
    }
}

fn lost() -> Reply {
    Reply {
        status: 200,
        headers: Vec::new(),
        body: None,
    }
}

/// Cluster info is the given document. Every other request takes the next
/// queued reply; the last one repeats.
struct Server {
    url: String,
    requests: Arc<Mutex<Vec<String>>>,
}

impl Server {
    fn new(info: Value, replies: Vec<Reply>) -> Self {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let url = format!("http://{}/prefix", listener.local_addr().unwrap());
        let requests = Arc::new(Mutex::new(Vec::new()));
        let observed = Arc::clone(&requests);
        let queue = Arc::new(Mutex::new(VecDeque::from(replies)));
        thread::spawn(move || {
            for stream in listener.incoming() {
                let Ok(mut stream) = stream else { continue };
                stream
                    .set_read_timeout(Some(Duration::from_secs(2)))
                    .unwrap();
                let request = read_request(&mut stream);
                let reply = if request.starts_with("GET /prefix/api/cluster/info ") {
                    ok(info.clone())
                } else {
                    observed.lock().unwrap().push(request);
                    let mut queue = queue.lock().unwrap();
                    if queue.len() > 1 {
                        queue.pop_front().unwrap()
                    } else {
                        queue.front().cloned().unwrap_or_else(|| ok(json!({})))
                    }
                };
                let Some(body) = reply.body else {
                    // Drop the connection: the request was received, the
                    // response is lost.
                    continue;
                };
                let payload = serde_json::to_vec(&body).unwrap();
                let mut head = format!("HTTP/1.1 {} Test\r\n", reply.status);
                for (name, value) in &reply.headers {
                    head.push_str(&format!("{name}: {value}\r\n"));
                }
                head.push_str(&format!(
                    "Content-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",
                    payload.len()
                ));
                let _ = stream.write_all(head.as_bytes());
                let _ = stream.write_all(&payload);
            }
        });
        Self { url, requests }
    }

    fn requests(&self) -> Vec<String> {
        self.requests.lock().unwrap().clone()
    }

    fn request_lines(&self) -> Vec<String> {
        self.requests()
            .iter()
            .map(|request| request.lines().next().unwrap_or_default().to_owned())
            .collect()
    }

    fn body(&self, index: usize) -> Value {
        let request = &self.requests()[index];
        let body = request.split("\r\n\r\n").nth(1).unwrap_or_default();
        serde_json::from_str(body).unwrap()
    }
}

/// The request head, plus its body when Content-Length announces one.
fn read_request(stream: &mut std::net::TcpStream) -> String {
    let mut bytes = Vec::new();
    let mut buffer = [0; 4096];
    loop {
        if let Some(end) = bytes.windows(4).position(|part| part == b"\r\n\r\n") {
            let head = String::from_utf8_lossy(&bytes[..end]).to_lowercase();
            let length = head
                .lines()
                .find_map(|line| line.strip_prefix("content-length:"))
                .and_then(|value| value.trim().parse::<usize>().ok())
                .unwrap_or(0);
            if bytes.len() >= end + 4 + length {
                break;
            }
        }
        match stream.read(&mut buffer) {
            Ok(0) | Err(_) => break,
            Ok(size) => bytes.extend_from_slice(&buffer[..size]),
        }
    }
    String::from_utf8_lossy(&bytes).into_owned()
}

fn info() -> Value {
    serde_json::from_str(include_str!(
        "../src/compat/fixtures/cluster-info-php-2.11.8.json"
    ))
    .unwrap()
}

fn json_stdout(output: &Output) -> Value {
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    serde_json::from_slice(&output.stdout).unwrap()
}

fn stdout_text(output: &Output) -> String {
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    String::from_utf8(output.stdout.clone()).unwrap()
}

fn refusal(status: u16, body: Value) -> Reply {
    Reply {
        status,
        headers: Vec::new(),
        body: Some(body),
    }
}

fn examples(run: &str) -> Value {
    json!([{
        "run_id": run,
        "workflow_id": format!("order-{run}"),
        "workflow_type": "orders.process",
        "status": "completed",
        "closed_at": "2026-11-01T10:00:00.000000Z"
    }])
}

/// A native Server 3.30.0 plan that shortens 30 days to 7, with one count
/// at its scan bound and an incomplete hold inspection.
fn shortening_plan() -> Value {
    json!({
        "schema": "durable-workflow.v2.retention-plan.v1",
        "namespace": "billing",
        "evaluated_at": "2026-11-26T10:00:00.000000Z",
        "read_only": true,
        "scope": "A point-in-time scan of one read snapshot, not an immutable deletion set.",
        "policy_revision": "sha256:policy",
        "plan_revision": "sha256:plan",
        "retroactive": true,
        "current_policy": {
            "retention_mode": "bounded",
            "retention_days": 30,
            "cutoff": "2026-10-27T10:00:00+00:00",
            "updated_at": "2026-11-01T09:00:00.000000Z"
        },
        "proposed_policy": {
            "retention_mode": "bounded",
            "retention_days": 7,
            "cutoff": "2026-11-19T10:00:00+00:00"
        },
        "scan_limit": 1000,
        "hold_scan_limit": 100,
        "example_limit": 5,
        "complete": false,
        "incomplete": [
            {"field": "changes.newly_eligible", "reason": "scan_limit_reached"},
            {"field": "exclusions.backup_hold", "reason": "hold_scan_limit_reached"}
        ],
        "changes": {
            "newly_eligible": {"count": null, "lower_bound": 1000, "complete": false, "examples": examples("run-1")},
            "no_longer_eligible": {"count": 0, "lower_bound": 0, "complete": true, "examples": []},
            "unchanged_eligible": {"count": 4, "lower_bound": 4, "complete": true, "examples": examples("run-0")},
            "unchanged_retained": {"count": 12, "lower_bound": 12, "complete": true, "examples": []}
        },
        "exclusions": {
            "archived": {"count": 3, "lower_bound": 3, "complete": true},
            "forever": {"count": 0, "lower_bound": 0, "complete": true},
            "not_terminal": {"count": 8, "lower_bound": 8, "complete": true},
            "detached_activity_hold": {"count": null, "lower_bound": 0, "complete": false, "examples": []},
            "external_payload_cleanup_unavailable": {"count": null, "lower_bound": 0, "complete": false, "examples": []},
            "backup_hold": {"count": null, "lower_bound": 2, "complete": false, "examples": examples("run-2")}
        },
        "hold_inspection": {
            "scope": "eligible_under_proposed_policy",
            "inspected": 100,
            "complete": false,
            "backup_hold_active": true,
            "not_evaluated": ["external_payload_storage_binding_unavailable"]
        }
    })
}

fn forever_plan() -> Value {
    let mut plan = shortening_plan();
    plan["retroactive"] = json!(false);
    plan["complete"] = json!(true);
    plan["incomplete"] = json!([]);
    plan["proposed_policy"] =
        json!({"retention_mode": "forever", "retention_days": null, "cutoff": null});
    plan
}

fn applied() -> Value {
    json!({
        "schema": "durable-workflow.v2.retention-apply.v1",
        "namespace": "billing",
        "applied_at": "2026-11-26T10:05:00.000000Z",
        "retroactive": true,
        "confirmed_retroactive_pruning": true,
        "previous_policy_revision": "sha256:policy",
        "previous_policy": {
            "retention_mode": "bounded",
            "retention_days": 30,
            "cutoff": "2026-10-27T10:05:00+00:00",
            "updated_at": "2026-11-01T09:00:00.000000Z"
        },
        "policy_revision": "sha256:applied",
        "policy": {
            "retention_mode": "bounded",
            "retention_days": 7,
            "cutoff": "2026-11-19T10:05:00+00:00",
            "updated_at": "2026-11-26T10:05:00.000000Z"
        },
        "scope": "The policy is active from applied_at. The plan's counts were a point-in-time scan, not the set that will be deleted."
    })
}

const APPLY_ARGS: [&str; 5] = [
    "namespace:retention-apply",
    "billing",
    "--retention=7",
    "--policy-revision=sha256:policy",
    "--plan-revision=sha256:plan",
];

#[test]
fn a_plan_is_one_read_only_request_and_shows_every_count() {
    let scratch = Scratch::new();
    let server = Server::new(info(), vec![ok(shortening_plan())]);
    let stdout = stdout_text(&scratch.run(
        &server.url,
        &[
            "namespace:retention-plan",
            "billing",
            "--retention=7",
            "--scan-limit=1000",
            "--hold-scan-limit=100",
        ],
    ));
    assert_eq!(
        server.request_lines(),
        ["POST /prefix/api/namespaces/billing/retention-plan HTTP/1.1"]
    );
    assert_eq!(
        server.body(0),
        json!({"retention_days": 7, "scan_limit": 1000, "hold_scan_limit": 100})
    );
    assert!(server.requests()[0].contains("Bearer fixture-token"));
    for expected in [
        "Retention plan for namespace billing",
        "  Evaluated at: 2026-11-26T10:00:00.000000Z",
        "  Current policy: bounded, 30 days (cutoff 2026-10-27T10:00:00+00:00)",
        "  Proposed policy: bounded, 7 days (cutoff 2026-11-19T10:00:00+00:00)",
        "  Retroactive: yes",
        "  Complete: no",
        "  Policy revision: sha256:policy",
        "  Plan revision: sha256:plan",
        "Changes (closed, unarchived runs):",
        "| newly_eligible     | at least 1000 |",
        "| no_longer_eligible | 0             |",
        "| unchanged_retained | 12            |",
        "Exclusions (runs a prune would not delete):",
        "| archived                             | 3          |",
        "| detached_activity_hold               | at least 0 |",
        "| backup_hold                          | at least 2 |",
        "Incomplete: these counts reached their scan limit and are minimums, not exact figures.",
        "  changes.newly_eligible: scan_limit_reached",
        "  exclusions.backup_hold: hold_scan_limit_reached",
        "Hold inspection: 100 eligible runs inspected, complete: no, backup hold active: yes",
        "  Not evaluated: external_payload_storage_binding_unavailable",
        "Examples (oldest first):",
        "  newly_eligible:\n    - run_id=run-1, workflow_id=order-run-1, workflow_type=orders.process, status=completed, closed_at=2026-11-01T10:00:00.000000Z",
        "  backup_hold:\n    - run_id=run-2,",
        "A point-in-time scan of one read snapshot, not an immutable deletion set.",
        "This policy is retroactive: closed runs the current policy keeps become eligible for irreversible pruning",
        "Apply this plan with dw namespace:retention-apply billing --retention-mode=bounded --retention=7 --policy-revision=sha256:policy --plan-revision=sha256:plan --confirm-retroactive-pruning;",
    ] {
        assert!(stdout.contains(expected), "{expected}: {stdout}");
    }

    let server = Server::new(info(), vec![ok(shortening_plan())]);
    let output = scratch.run(
        &server.url,
        &[
            "namespace:retention-plan",
            "billing",
            "--retention=7",
            "--json",
        ],
    );
    assert_eq!(json_stdout(&output), shortening_plan());
    assert_eq!(server.requests().len(), 1);
}

#[test]
fn a_forever_plan_sends_only_the_mode_and_its_apply_needs_no_confirmation() {
    let scratch = Scratch::new();
    let server = Server::new(info(), vec![ok(forever_plan())]);
    let stdout = stdout_text(&scratch.run(
        &server.url,
        &[
            "namespace:retention-plan",
            "billing",
            "--retention-mode=forever",
        ],
    ));
    assert_eq!(server.body(0), json!({"retention_mode": "forever"}));
    for expected in [
        "  Proposed policy: forever (no cutoff)",
        "  Retroactive: no",
        "  Complete: yes",
        "Apply this plan with dw namespace:retention-apply billing --retention-mode=forever --policy-revision=sha256:policy --plan-revision=sha256:plan;",
    ] {
        assert!(stdout.contains(expected), "{expected}: {stdout}");
    }
    assert!(!stdout.contains("Incomplete:"), "{stdout}");
    assert!(
        !stdout.contains("--confirm-retroactive-pruning"),
        "{stdout}"
    );
    assert!(!stdout.contains("is retroactive"), "{stdout}");

    // A plan with no proposal compares the current policy with itself.
    let server = Server::new(info(), vec![ok(forever_plan())]);
    stdout_text(&scratch.run(&server.url, &["namespace:retention-plan", "billing"]));
    assert_eq!(server.body(0), json!({}));
}

#[test]
fn apply_sends_one_guarded_request_bound_to_both_revisions() {
    let scratch = Scratch::new();
    let server = Server::new(info(), vec![ok(applied())]);
    let mut args = APPLY_ARGS.to_vec();
    args.push("--confirm-retroactive-pruning");
    let stdout = stdout_text(&scratch.run(&server.url, &args));
    assert_eq!(
        server.request_lines(),
        ["POST /prefix/api/namespaces/billing/retention-plan/apply HTTP/1.1"]
    );
    assert_eq!(
        server.body(0),
        json!({
            "retention_days": 7,
            "policy_revision": "sha256:policy",
            "plan_revision": "sha256:plan",
            "confirm_retroactive_pruning": true
        })
    );
    for expected in [
        "Retention policy applied to namespace billing.",
        "  Applied at: 2026-11-26T10:05:00.000000Z",
        "  Previous policy: bounded, 30 days (cutoff 2026-10-27T10:05:00+00:00)",
        "  Policy: bounded, 7 days (cutoff 2026-11-19T10:05:00+00:00)",
        "  Retroactive: yes, pruning confirmed",
        "  Previous policy revision: sha256:policy",
        "  Policy revision: sha256:applied",
        "The policy is active from applied_at.",
    ] {
        assert!(stdout.contains(expected), "{expected}: {stdout}");
    }

    let server = Server::new(info(), vec![ok(applied())]);
    let mut args = APPLY_ARGS.to_vec();
    args.extend(["--retention-mode=bounded", "--json"]);
    assert_eq!(json_stdout(&scratch.run(&server.url, &args)), applied());
    assert_eq!(
        server.body(0),
        json!({
            "retention_mode": "bounded",
            "retention_days": 7,
            "policy_revision": "sha256:policy",
            "plan_revision": "sha256:plan",
            "confirm_retroactive_pruning": false
        })
    );
}

#[test]
fn apply_takes_the_proposal_and_revisions_from_a_saved_plan() {
    let scratch = Scratch::new();
    for (plan, proposal) in [
        (
            shortening_plan(),
            json!({"retention_mode": "bounded", "retention_days": 7}),
        ),
        (forever_plan(), json!({"retention_mode": "forever"})),
    ] {
        let path = scratch.0.join("plan.json");
        fs::write(&path, serde_json::to_vec_pretty(&plan).unwrap()).unwrap();
        let plan_file = format!("--plan-file={}", path.display());
        let server = Server::new(info(), vec![ok(applied())]);
        stdout_text(&scratch.run(
            &server.url,
            &[
                "namespace:retention-apply",
                "BILLING",
                &plan_file,
                "--confirm-retroactive-pruning",
            ],
        ));
        let mut expected = proposal;
        expected["policy_revision"] = json!("sha256:policy");
        expected["plan_revision"] = json!("sha256:plan");
        expected["confirm_retroactive_pruning"] = json!(true);
        assert_eq!(server.body(0), expected);
    }
}

#[test]
fn each_refused_apply_changes_nothing_and_says_what_to_do_next() {
    let scratch = Scratch::new();
    for (status, body, id, expected) in [
        (
            409,
            json!({
                "reason": "retention_policy_revision_stale",
                "namespace": "billing",
                "message": "The namespace changed after the plan. Request a new plan and apply its revisions.",
                "policy_revision": "sha256:newer"
            }),
            "retention_plan.stale",
            vec![
                "Server error: The namespace changed after the plan. Request a new plan and apply its revisions.",
                "Reason: retention_policy_revision_stale",
                "Nothing was applied: the namespace changed after the plan. Its current policy revision is sha256:newer.",
                "    Try: dw namespace:retention-plan billing",
            ],
        ),
        (
            409,
            json!({
                "reason": "retention_plan_revision_mismatch",
                "namespace": "billing",
                "message": "The policy to apply is not the policy that was planned. Request a plan for this policy."
            }),
            "retention_plan.mismatch",
            vec![
                "Reason: retention_plan_revision_mismatch",
                "Nothing was applied: the policy sent is not the policy that was planned.",
                "    Try: dw namespace:retention-plan billing",
            ],
        ),
        (
            422,
            json!({
                "reason": "retention_pruning_confirmation_required",
                "namespace": "billing",
                "retroactive": true,
                "message": "This policy makes closed runs eligible for pruning that the current policy keeps, and pruning is irreversible. Set confirm_retroactive_pruning to true to apply it."
            }),
            "retention_plan.confirmation_required",
            vec![
                "Reason: retention_pruning_confirmation_required",
                "repeat the command with --confirm-retroactive-pruning.",
            ],
        ),
    ] {
        let server = Server::new(info(), vec![refusal(status, body.clone())]);
        let output = scratch.run(&server.url, &APPLY_ARGS);
        assert_eq!(output.status.code(), Some(2), "{id}: {output:?}");
        assert_eq!(server.requests().len(), 1, "{id}");
        assert_eq!(server.body(0)["confirm_retroactive_pruning"], json!(false));
        let stderr = String::from_utf8(output.stderr).unwrap();
        for line in expected {
            assert!(stderr.contains(line), "{id}: {line}: {stderr}");
        }

        let server = Server::new(info(), vec![refusal(status, body.clone())]);
        let mut args = APPLY_ARGS.to_vec();
        args.push("--json");
        let output = scratch.run(&server.url, &args);
        assert_eq!(output.status.code(), Some(2), "{id}: {output:?}");
        assert_eq!(server.requests().len(), 1, "{id}");
        let envelope: Value = serde_json::from_slice(&output.stdout).unwrap();
        assert_eq!(envelope["exit_code"], json!(2), "{envelope}");
        assert_eq!(envelope["status_code"], json!(status), "{envelope}");
        assert_eq!(envelope["reason"], body["reason"], "{envelope}");
        assert_eq!(envelope["server_response"], body, "{envelope}");
        assert_eq!(envelope["recommendations"][0]["id"], json!(id), "{envelope}");
    }
}

#[test]
fn a_server_without_the_plan_routes_points_at_server_3_30() {
    let scratch = Scratch::new();
    for args in [
        vec!["namespace:retention-plan", "billing", "--retention=7"],
        APPLY_ARGS.to_vec(),
    ] {
        let server = Server::new(info(), vec![refusal(404, json!({"message": "Not Found"}))]);
        let mut json_args = args.clone();
        json_args.push("--json");
        let output = scratch.run(&server.url, &json_args);
        assert_eq!(output.status.code(), Some(5), "{args:?}: {output:?}");
        let envelope: Value = serde_json::from_slice(&output.stdout).unwrap();
        assert_eq!(
            envelope["recommendations"][0]["id"],
            json!("retention_plan.unsupported"),
            "{envelope}"
        );
        assert!(envelope["recommendations"][0]["message"]
            .as_str()
            .unwrap()
            .contains("native Durable Workflow Server 3.30.0 or later"));
    }

    // An unknown namespace on a Server that has the route is just not found.
    let server = Server::new(
        info(),
        vec![refusal(
            404,
            json!({"reason": "namespace_not_found", "namespace": "nope", "message": "Namespace [nope] not found."}),
        )],
    );
    let output = scratch.run(&server.url, &["namespace:retention-plan", "nope", "--json"]);
    assert_eq!(output.status.code(), Some(5), "{output:?}");
    let envelope: Value = serde_json::from_slice(&output.stdout).unwrap();
    assert_eq!(
        envelope["recommendations"][0]["id"],
        json!("resource.not_found"),
        "{envelope}"
    );
}

#[test]
fn invalid_options_and_plan_files_are_refused_before_any_request() {
    let scratch = Scratch::new();
    let wrong_namespace = scratch.0.join("other.json");
    let mut other = shortening_plan();
    other["namespace"] = json!("orders");
    fs::write(&wrong_namespace, other.to_string()).unwrap();
    let not_a_plan = scratch.0.join("applied.json");
    fs::write(&not_a_plan, applied().to_string()).unwrap();
    let missing = scratch.0.join("missing.json");
    let file = |path: &PathBuf| format!("--plan-file={}", path.display());
    let (wrong_namespace, not_a_plan, missing) =
        (file(&wrong_namespace), file(&not_a_plan), file(&missing));
    for (args, message) in [
        (
            vec!["namespace:retention-apply", "billing", "--retention=7"],
            "--policy-revision is required.",
        ),
        (
            vec![
                "namespace:retention-apply",
                "billing",
                "--policy-revision=sha256:policy",
            ],
            "--plan-revision is required.",
        ),
        (
            vec!["namespace:retention-plan", "billing", "--retention=seven"],
            "--retention must be a positive integer.",
        ),
        (
            vec!["namespace:retention-plan", "billing", "--retention=0"],
            "--retention must be a positive integer.",
        ),
        (
            vec!["namespace:retention-plan", "billing", "--scan-limit=-1"],
            "--scan-limit must be a positive integer.",
        ),
        (
            vec![
                "namespace:retention-plan",
                "billing",
                "--retention-mode=sometimes",
            ],
            "--retention-mode must be bounded or forever.",
        ),
        (
            vec![
                "namespace:retention-plan",
                "billing",
                "--retention-mode=forever",
                "--retention=7",
            ],
            "a forever policy has no days",
        ),
        (
            vec![
                "namespace:retention-apply",
                "billing",
                &wrong_namespace,
                "--retention=7",
            ],
            "--plan-file cannot be combined with --retention",
        ),
        (
            vec!["namespace:retention-apply", "billing", &wrong_namespace],
            "plans namespace orders, not billing.",
        ),
        (
            vec!["namespace:retention-apply", "billing", &not_a_plan],
            "its schema is not durable-workflow.v2.retention-plan.v1",
        ),
        (
            vec!["namespace:retention-apply", "billing", &missing],
            "Unable to read --plan-file",
        ),
    ] {
        let server = Server::new(info(), vec![ok(json!({}))]);
        let output = scratch.run(&server.url, &args);
        assert_eq!(output.status.code(), Some(2), "{args:?}: {output:?}");
        assert!(server.requests().is_empty(), "{args:?}");
        let stderr = String::from_utf8(output.stderr).unwrap();
        assert!(stderr.contains(message), "{message}: {stderr}");
    }
}

#[test]
fn names_are_one_encoded_segment_and_dot_names_are_refused() {
    let scratch = Scratch::new();
    for (args, line) in [
        (
            vec!["namespace:retention-plan", "bill/ing?x", "--json"],
            "POST /prefix/api/namespaces/bill%2Fing%3Fx/retention-plan HTTP/1.1",
        ),
        (
            vec![
                "namespace:retention-apply",
                "bill/ing",
                "--policy-revision=a",
                "--plan-revision=b",
                "--json",
            ],
            "POST /prefix/api/namespaces/bill%2Fing/retention-plan/apply HTTP/1.1",
        ),
    ] {
        let server = Server::new(info(), vec![ok(json!({}))]);
        json_stdout(&scratch.run(&server.url, &args));
        assert_eq!(server.request_lines(), [line], "{args:?}");
    }
    for name in ["..", "."] {
        let server = Server::new(info(), vec![ok(json!({}))]);
        let output = scratch.run(&server.url, &["namespace:retention-plan", name, "--json"]);
        assert_eq!(output.status.code(), Some(2), "{name}: {output:?}");
        assert!(server.requests().is_empty(), "{name}");
    }
}

#[test]
fn a_lost_apply_response_is_never_resent() {
    let scratch = Scratch::new();
    let server = Server::new(info(), vec![lost()]);
    let mut args = APPLY_ARGS.to_vec();
    args.push("--json");
    let output = scratch.run(&server.url, &args);
    assert_eq!(output.status.code(), Some(3), "{output:?}");
    assert_eq!(server.requests().len(), 1);
}
