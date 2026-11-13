//! workflow:query, workflow:start, workflow:signal, workflow:update and
//! workflow:update-outcome against a loopback Server that records every
//! request and answers from a queue. A dropped reply stands in for a lost
//! response.

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
            "dw-workflow-message-{}-{now}-{}",
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

#[derive(Clone)]
enum Reply {
    Json {
        status: u16,
        body: Value,
    },
    /// Read the request, then close without answering.
    Drop,
}

fn ok(body: Value) -> Reply {
    Reply::Json { status: 200, body }
}

struct Request {
    line: String,
    body: Option<Value>,
}

struct Server {
    url: String,
    requests: Arc<Mutex<Vec<Request>>>,
}

impl Server {
    fn new(replies: Vec<Reply>) -> Self {
        Self::with_info(php_info(), replies)
    }

    /// A Server that answers cluster info with `info`.
    fn with_info(info: Value, replies: Vec<Reply>) -> Self {
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
                let (head, body) = read_request(&mut stream);
                let line = head.lines().next().unwrap_or_default().to_owned();
                let reply = if line.starts_with("GET /prefix/api/cluster/info ") {
                    ok(info.clone())
                } else {
                    observed.lock().unwrap().push(Request {
                        line,
                        body: serde_json::from_slice(&body).ok(),
                    });
                    let mut queue = queue.lock().unwrap();
                    if queue.len() > 1 {
                        queue.pop_front().unwrap()
                    } else {
                        queue.front().unwrap().clone()
                    }
                };
                let Reply::Json { status, body } = reply else {
                    drop(stream);
                    continue;
                };
                let payload = serde_json::to_vec(&body).unwrap();
                let head = format!("HTTP/1.1 {status} Test\r\nX-Durable-Workflow-Control-Plane-Version: 2\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n", payload.len());
                let _ = stream.write_all(head.as_bytes());
                let _ = stream.write_all(&payload);
            }
        });
        Self { url, requests }
    }

    fn lines(&self) -> Vec<String> {
        self.requests
            .lock()
            .unwrap()
            .iter()
            .map(|request| request.line.clone())
            .collect()
    }

    fn body(&self, index: usize) -> Value {
        self.requests.lock().unwrap()[index]
            .body
            .clone()
            .unwrap_or(Value::Null)
    }
}

/// Published PHP Server 2.11.8 cluster info.
fn php_info() -> Value {
    serde_json::from_str(include_str!(
        "../src/compat/fixtures/cluster-info-php-2.11.8.json"
    ))
    .unwrap()
}

fn read_request(stream: &mut std::net::TcpStream) -> (String, Vec<u8>) {
    let mut bytes = Vec::new();
    let mut buffer = [0; 4096];
    let end = loop {
        if let Some(end) = bytes.windows(4).position(|part| part == b"\r\n\r\n") {
            break end + 4;
        }
        match stream.read(&mut buffer) {
            Ok(0) | Err(_) => return (String::from_utf8_lossy(&bytes).into_owned(), Vec::new()),
            Ok(size) => bytes.extend_from_slice(&buffer[..size]),
        }
    };
    let head = String::from_utf8_lossy(&bytes[..end]).into_owned();
    let length = head
        .lines()
        .find_map(|line| {
            let (name, value) = line.split_once(':')?;
            name.eq_ignore_ascii_case("content-length")
                .then(|| value.trim().parse::<usize>().ok())?
        })
        .unwrap_or(0);
    while bytes.len() < end + length {
        match stream.read(&mut buffer) {
            Ok(0) | Err(_) => break,
            Ok(size) => bytes.extend_from_slice(&buffer[..size]),
        }
    }
    (head, bytes[end..].to_vec())
}

fn contract(operation: &str, extra: Value) -> Value {
    let mut contract = json!({
        "schema": "durable-workflow.v2.control-plane-response",
        "version": 1,
        "operation": operation,
        "contract": {
            "schema": "durable-workflow.v2.control-plane-response.contract",
            "version": 1,
            "legacy_field_policy": "reject_non_canonical",
            "legacy_fields": {},
            "required_fields": [],
            "success_fields": []
        }
    });
    for (key, value) in extra.as_object().unwrap() {
        contract[key] = value.clone();
    }
    contract
}

fn signal_reply() -> Reply {
    ok(json!({
        "workflow_id": "chk-42",
        "signal_name": "approve",
        "outcome": "signal_received",
        "control_plane": contract("signal", json!({})),
    }))
}

fn stdout_json(output: &Output) -> Value {
    serde_json::from_slice(&output.stdout)
        .unwrap_or_else(|_| panic!("stdout is not JSON: {output:?}"))
}

fn is_generated(key: &str, kind: &str) -> bool {
    key.strip_prefix(&format!("cli-{kind}-"))
        .is_some_and(|hex| {
            hex.len() == 32
                && hex
                    .chars()
                    .all(|c| c.is_ascii_hexdigit() && !c.is_ascii_uppercase())
        })
}

#[test]
fn signal_sends_one_request_with_a_generated_key_and_reports_it() {
    let scratch = Scratch::new();
    let server = Server::new(vec![signal_reply()]);
    let output = scratch.run(
        &server.url,
        &[
            "workflow:signal",
            "a/b",
            "pay?now",
            "-i",
            "{\"amount\":99.95}",
            "--json",
        ],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(
        server.lines(),
        ["POST /prefix/api/workflows/a%2Fb/signal/pay%3Fnow HTTP/1.1"]
    );
    let body = server.body(0);
    let key = body["request_id"].as_str().unwrap().to_owned();
    assert!(is_generated(&key, "signal"), "{key}");
    assert_eq!(body["input"], json!({"amount": 99.95}));
    let result = stdout_json(&output);
    assert_eq!(
        result["request_id"], key,
        "a legacy Server's missing key is filled in"
    );
    assert_eq!(result["namespace"], "orders");

    let second = scratch.run(
        &server.url,
        &["workflow:signal", "chk-42", "approve", "--json"],
    );
    assert_ne!(
        stdout_json(&second)["request_id"],
        key,
        "each invocation gets its own key"
    );
}

#[test]
fn signal_lost_response_reports_the_key_and_is_not_resent() {
    let scratch = Scratch::new();
    let server = Server::new(vec![Reply::Drop]);
    let output = scratch.run(
        &server.url,
        &[
            "workflow:signal",
            "chk-42",
            "approve",
            "--request-id=approve-1",
            "--json",
        ],
    );
    assert_eq!(output.status.code(), Some(3), "{output:?}");
    assert_eq!(server.lines().len(), 1, "a lost response is never resent");
    let error = stdout_json(&output);
    assert_eq!(error["request_id"], "approve-1");
    assert_eq!(error["workflow_id"], "chk-42");
    assert_eq!(error["signal_name"], "approve");
    let ids: Vec<&str> = error["recommendations"]
        .as_array()
        .unwrap()
        .iter()
        .map(|item| item["id"].as_str().unwrap())
        .collect();
    assert_eq!(ids, ["server.unreachable", "request_id.retry"]);

    let human = scratch.run(&server.url, &["workflow:signal", "chk-42", "approve"]);
    assert_eq!(human.status.code(), Some(3));
    let stderr = String::from_utf8(human.stderr).unwrap();
    let generated = stderr
        .lines()
        .find_map(|line| line.strip_prefix("Request ID: "))
        .unwrap_or_else(|| panic!("{stderr}"));
    assert!(is_generated(generated, "signal"), "{stderr}");
    assert_eq!(server.body(1)["request_id"], generated);
    assert!(
        stderr.contains("The outcome of this command is unknown."),
        "{stderr}"
    );
    assert_eq!(server.lines().len(), 2);
}

#[test]
fn invalid_keys_and_identifiers_send_no_message() {
    let scratch = Scratch::new();
    let server = Server::new(vec![signal_reply()]);
    for args in [
        vec![
            "workflow:signal",
            "chk-42",
            "approve",
            "--request-id= \u{3000}",
        ],
        vec!["workflow:signal", "chk-42", "approve", "--request-id"],
        vec!["workflow:update", "chk-42", "approve", "--request-id=\t"],
        vec!["workflow:signal", "..", "approve", "--request-id=k"],
        vec!["workflow:update", "chk-42", ".", "--request-id=k"],
        vec!["workflow:query", "", "status"],
        vec!["workflow:signal", "chk-42", "approve", "--input={"],
    ] {
        let output = scratch.run(&server.url, &args);
        assert_eq!(output.status.code(), Some(2), "{args:?}: {output:?}");
    }
    let long = format!("--request-id={}", "é".repeat(256));
    let output = scratch.run(
        &server.url,
        &["workflow:signal", "chk-42", "approve", &long, "--json"],
    );
    assert_eq!(output.status.code(), Some(2));
    assert_eq!(
        stdout_json(&output)["error"],
        "--request-id must be 1 to 255 Unicode characters; got 256 characters."
    );
    assert!(server.lines().is_empty(), "{:?}", server.lines());
}

#[test]
fn redirects_are_refused_without_following() {
    let scratch = Scratch::new();
    let server = Server::new(vec![Reply::Json {
        status: 307,
        body: json!({"message": "moved"}),
    }]);
    let output = scratch.run(
        &server.url,
        &[
            "workflow:signal",
            "chk-42",
            "approve",
            "--request-id=k",
            "--json",
        ],
    );
    assert_ne!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(server.lines().len(), 1);
}

#[test]
fn update_sends_wait_policy_key_and_input_and_reports_diagnostics() {
    let scratch = Scratch::new();
    let server = Server::new(vec![ok(json!({
        "workflow_id": "chk-42",
        "update_id": "upd-1",
        "update_status": "completed",
        "result": {"quota": 50},
        "control_plane": contract("update", json!({"update_id": "upd-1"})),
    }))]);
    let output = scratch.run(
        &server.url,
        &[
            "workflow:update",
            "chk-42",
            "quota.adjust",
            "--run-id=run-7",
            "--wait=completed",
            "-i",
            "{\"by\":10,\"meta\":{}}",
            "--json",
        ],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(
        server.lines(),
        ["POST /prefix/api/workflows/chk-42/runs/run-7/update/quota.adjust HTTP/1.1"]
    );
    let body = server.body(0);
    let key = body["request_id"].as_str().unwrap().to_owned();
    assert!(is_generated(&key, "update"), "{key}");
    assert_eq!(body["wait_for"], "completed");
    assert_eq!(body["input"], json!({"by": 10, "meta": []}));
    let result = stdout_json(&output);
    assert_eq!(result["request_id"], key);
    assert_eq!(result["state"], "completed");
    assert_eq!(result["outcome"], "update_completed");
    assert_eq!(result["request"]["run_id"], "run-7");
    assert_eq!(result["cli_fields"]["surface"], "workflow:update --json");
}

#[test]
fn update_lost_response_keeps_the_request_in_the_error() {
    let scratch = Scratch::new();
    let server = Server::new(vec![Reply::Drop]);
    let output = scratch.run(
        &server.url,
        &[
            "workflow:update",
            "chk-42",
            "quota.adjust",
            "--request-id=quota-1",
            "-i",
            "[1]",
            "--json",
        ],
    );
    assert_eq!(output.status.code(), Some(3), "{output:?}");
    assert_eq!(server.lines().len(), 1);
    let error = stdout_json(&output);
    assert_eq!(error["request_id"], "quota-1");
    assert_eq!(error["outcome"], "update_refused");
    assert_eq!(
        error["request"],
        json!({"workflow_id": "chk-42", "update_name": "quota.adjust", "request_id": "quota-1", "wait_for": "accepted", "input": [1]})
    );
    assert_eq!(error["recommendations"][1]["id"], "request_id.retry");
}

#[test]
fn start_sends_the_2x_body() {
    let scratch = Scratch::new();
    let server = Server::new(vec![ok(json!({
        "workflow_id": "chk-42",
        "run_id": "run-1",
        "outcome": "started_new",
        "control_plane": contract("start", json!({})),
    }))]);
    let output = scratch.run(
        &server.url,
        &[
            "workflow:start",
            "-t",
            "orders.Checkout",
            "-w",
            "chk-42",
            "-i",
            "42",
            "--memo={\"source\":\"cli\",\"tags\":{}}",
            "--search-attr",
            "0=zero",
            "--search-attr",
            "env=prod",
            "--search-attr",
            "env=stage",
            "--execution-timeout=3600x",
            "--priority=3",
            "--fairness-key= tenant-a ",
            "--fairness-weight=5",
            "--json",
        ],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(server.lines(), ["POST /prefix/api/workflows HTTP/1.1"]);
    assert_eq!(
        server.body(0),
        json!({
            "workflow_type": "orders.Checkout",
            "workflow_id": "chk-42",
            "task_queue": "default",
            "input": [42],
            "memo": {"source": "cli", "tags": []},
            "execution_timeout_seconds": 3600,
            "priority": 3,
            "fairness_key": "tenant-a",
            "fairness_weight": 5,
            "search_attributes": {"0": "zero", "env": "stage"}
        })
    );
    assert!(
        server.body(0).get("request_id").is_none(),
        "start has no request ID"
    );

    let output = scratch.run(
        &server.url,
        &[
            "workflow:start",
            "-t",
            "orders.Checkout",
            "--task-queue",
            "--json",
        ],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(server.body(1), json!({"workflow_type": "orders.Checkout"}));
}

#[test]
fn start_wait_polls_until_terminal_and_explains_the_wait_in_json() {
    let scratch = Scratch::new();
    let describe = |status: &str, bucket: &str| {
        ok(json!({
            "workflow_id": "chk-42",
            "status": status,
            "status_bucket": bucket,
            "control_plane": contract("describe", json!({})),
        }))
    };
    let server = Server::new(vec![
        ok(json!({
            "workflow_id": "chk-42",
            "run_id": "run-1",
            "outcome": "started_new",
            "control_plane": contract("start", json!({})),
        })),
        describe("running", "running"),
        ok(json!({
            "run_id": "run-1",
            "diagnostic_status": "waiting_for_worker",
            "findings": [{"code": "no_workers", "message": "No worker polls <orders>."}],
            "control_plane": contract("debug", json!({})),
        })),
        describe("failed", "failed"),
    ]);
    let output = scratch.run(
        &server.url,
        &[
            "workflow:start",
            "-t",
            "orders.Checkout",
            "-w",
            "chk-42",
            "--wait",
            "--json",
        ],
    );
    assert_eq!(
        output.status.code(),
        Some(1),
        "a failed workflow exits 1: {output:?}"
    );
    assert_eq!(
        server.lines(),
        [
            "POST /prefix/api/workflows HTTP/1.1",
            "GET /prefix/api/workflows/chk-42 HTTP/1.1",
            "GET /prefix/api/workflows/chk-42/debug HTTP/1.1",
            "GET /prefix/api/workflows/chk-42 HTTP/1.1",
        ]
    );
    let final_state = stdout_json(&output);
    assert_eq!(final_state["status"], "failed");
    assert_eq!(final_state["namespace"], "orders");
    assert!(
        String::from_utf8_lossy(&output.stdout).starts_with("{\n    "),
        "pretty JSON"
    );
    let diagnostic: Value = serde_json::from_slice(&output.stderr).unwrap();
    assert_eq!(diagnostic["event"], "workflow_wait_diagnostic");
    assert_eq!(diagnostic["diagnostic_status"], "waiting_for_worker");
    assert_eq!(
        diagnostic["findings"][0]["message"],
        "No worker polls <orders>."
    );
}

#[test]
fn query_posts_input_without_a_key_and_prints_the_result() {
    let scratch = Scratch::new();
    let server = Server::new(vec![ok(json!({
        "workflow_id": "chk-42",
        "query_name": "status",
        "result": {"stage": "<b>shipping</b>"},
        "control_plane": contract("query", json!({})),
    }))]);
    let output = scratch.run(
        &server.url,
        &[
            "workflow:query",
            "chk-42",
            "status",
            "--input-encoding=raw",
            "-i",
            "plain",
        ],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(
        server.lines(),
        ["POST /prefix/api/workflows/chk-42/query/status HTTP/1.1"]
    );
    assert_eq!(server.body(0), json!({"input": ["plain"]}));
    let stdout = String::from_utf8(output.stdout).unwrap();
    assert!(
        stdout.contains("\"stage\": \"<b>shipping</b>\""),
        "Server text is printed literally: {stdout}"
    );
}

/// Cluster info of a Server with the update outcome read route (native
/// Server 3.6.0, Server #1131).
fn outcome_info() -> Value {
    let mut info = php_info();
    info["capabilities"] = json!({"update_outcome_reads": true});
    info
}

/// An update receipt as native Server answers a read, with `fields` on top.
fn receipt(fields: Value) -> Value {
    let mut receipt = json!({
        "workflow_id": "chk-42",
        "run_id": "run-8",
        "requested_run_id": null,
        "target_scope": "instance",
        "command_id": "cmd-3",
        "command_status": "accepted",
        "update_id": "upd-1",
        "update_name": "quota.adjust",
        "update_status": "accepted",
        "result": null,
        "result_envelope": null,
        "failure_id": null,
        "failure_message": null,
        "accepted_at": "2026-11-13T10:00:00.000000Z",
        "closed_at": null,
        "wait_for": "accepted",
        "wait_timed_out": false,
        "wait_timeout_seconds": null,
        "control_plane": contract(
            "update",
            json!({"update_id": "upd-1", "operation_name_field": "update_name", "operation_name": "quota.adjust"})
        ),
    });
    for (key, value) in fields.as_object().unwrap() {
        receipt[key] = value.clone();
    }
    receipt
}

fn reply(status: u16, body: Value) -> Reply {
    Reply::Json { status, body }
}

const OUTCOME_READ: &str =
    "GET /prefix/api/workflows/chk-42/updates/upd-1?wait_for=accepted HTTP/1.1";

#[test]
fn update_outcome_reads_a_completed_result_once_without_resubmitting() {
    let scratch = Scratch::new();
    let envelope = json!({"codec": "json", "blob": "{\"quota\":50}"});
    let completed = receipt(json!({
        "update_status": "completed",
        "result_envelope": envelope,
        "closed_at": "2026-11-13T10:00:02.000000Z",
    }));
    let server = Server::with_info(outcome_info(), vec![ok(completed)]);
    let output = scratch.run(
        &server.url,
        &["workflow:update-outcome", "chk-42", "upd-1", "--json"],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(server.lines(), [OUTCOME_READ], "one read, never a POST");
    let result = stdout_json(&output);
    assert_eq!(result["update_status"], "completed");
    assert_eq!(result["update_id"], "upd-1");
    assert_eq!(result["run_id"], "run-8");
    assert_eq!(result["result_envelope"], envelope);
    assert_eq!(result["namespace"], "orders");
    assert_eq!(result["wait_timed_out"], false);

    let output = scratch.run(&server.url, &["workflow:update-outcome", "chk-42", "upd-1"]);
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    let stdout = String::from_utf8(output.stdout).unwrap();
    assert!(stdout.starts_with("Update completed\n"), "{stdout}");
    assert!(stdout.contains("  Update ID: upd-1\n"), "{stdout}");
    assert!(stdout.contains("  Run ID: run-8\n"), "{stdout}");
    assert!(stdout.contains("  Namespace: orders\n"), "{stdout}");
    assert!(stdout.contains("  Result Envelope:\n"), "{stdout}");
    assert!(stdout.contains(r#""blob": "{\"quota\":50}""#), "{stdout}");
    assert!(!stdout.contains("pending"), "{stdout}");
    assert!(server.lines().iter().all(|line| line.starts_with("GET ")));
}

#[test]
fn update_outcome_keeps_a_completed_null_result_distinct_from_pending() {
    let scratch = Scratch::new();
    let null_result = json!({"codec": "json", "blob": "null"});
    let server = Server::with_info(
        outcome_info(),
        vec![ok(receipt(json!({
            "update_status": "completed",
            "result_envelope": null_result,
        })))],
    );
    let output = scratch.run(
        &server.url,
        &["workflow:update-outcome", "chk-42", "upd-1", "--json"],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    let result = stdout_json(&output);
    assert_eq!(result["update_status"], "completed");
    assert_eq!(result["result_envelope"], null_result);

    let output = scratch.run(&server.url, &["workflow:update-outcome", "chk-42", "upd-1"]);
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    let stdout = String::from_utf8(output.stdout).unwrap();
    assert!(stdout.starts_with("Update completed\n"), "{stdout}");
    assert!(stdout.contains(r#""blob": "null""#), "{stdout}");
    assert!(!stdout.contains("pending"), "{stdout}");
}

#[test]
fn update_outcome_reports_a_pending_update_and_how_to_wait_for_it() {
    let scratch = Scratch::new();
    let server = Server::with_info(
        outcome_info(),
        vec![reply(
            202,
            receipt(json!({
                "wait_for": "completed",
                "wait_timed_out": true,
                "wait_timeout_seconds": 5,
            })),
        )],
    );
    let args = [
        "workflow:update-outcome",
        "chk-42",
        "upd-1",
        "--wait=completed",
        "--wait-timeout=5",
    ];
    let output = scratch.run(&server.url, &args);
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(
        server.lines(),
        ["GET /prefix/api/workflows/chk-42/updates/upd-1?wait_for=completed&wait_timeout_seconds=5 HTTP/1.1"]
    );
    let stdout = String::from_utf8(output.stdout).unwrap();
    assert!(
        stdout.starts_with("Update accepted, not finished yet\n"),
        "{stdout}"
    );
    assert!(stdout.contains("  Update Status: accepted\n"), "{stdout}");
    assert!(
        stdout.contains("The completion wait ended after 5 seconds before the update finished."),
        "{stdout}"
    );
    assert!(stdout.contains("not a null result"), "{stdout}");
    assert!(
        stdout.contains("dw workflow:update-outcome chk-42 upd-1 --wait=completed"),
        "{stdout}"
    );
    assert!(!stdout.contains("Result Envelope"), "{stdout}");

    let mut json_args = args.to_vec();
    json_args.push("--json");
    let output = scratch.run(&server.url, &json_args);
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    let result = stdout_json(&output);
    assert_eq!(result["update_status"], "accepted");
    assert_eq!(result["result_envelope"], Value::Null);
    assert_eq!(result["wait_timed_out"], true);
    assert_eq!(result["wait_timeout_seconds"], 5);
    assert_eq!(server.lines().len(), 2);
}

#[test]
fn update_outcome_reports_a_failed_update_as_an_error() {
    let scratch = Scratch::new();
    let server = Server::with_info(
        outcome_info(),
        vec![reply(
            422,
            receipt(json!({
                "update_status": "failed",
                "failure_id": "fail-9",
                "failure_message": "quota <b>exceeded</b>",
                "closed_at": "2026-11-13T10:00:02.000000Z",
            })),
        )],
    );
    let output = scratch.run(&server.url, &["workflow:update-outcome", "chk-42", "upd-1"]);
    assert_eq!(output.status.code(), Some(2), "{output:?}");
    assert!(output.stdout.is_empty(), "{output:?}");
    let stderr = String::from_utf8(output.stderr).unwrap();
    assert!(
        stderr.starts_with("Update [upd-1] failed: quota <b>exceeded</b>\n"),
        "{stderr}"
    );
    assert!(stderr.contains("final outcome"), "{stderr}");
    assert!(!stderr.contains("retry"), "{stderr}");

    let output = scratch.run(
        &server.url,
        &["workflow:update-outcome", "chk-42", "upd-1", "--json"],
    );
    assert_eq!(output.status.code(), Some(2), "{output:?}");
    let error = stdout_json(&output);
    assert_eq!(error["exit_code"], 2);
    assert_eq!(error["status_code"], 422);
    assert_eq!(error["command"], "workflow:update-outcome");
    assert_eq!(error["namespace"], "orders");
    assert_eq!(error["workflow_id"], "chk-42");
    assert_eq!(error["update_id"], "upd-1");
    assert_eq!(error["update_status"], "failed");
    assert_eq!(error["failure_id"], "fail-9");
    assert_eq!(error["failure_message"], "quota <b>exceeded</b>");
    assert_eq!(error["recommendations"][0]["id"], "update.failed");
    assert_eq!(server.lines(), [OUTCOME_READ, OUTCOME_READ]);
}

#[test]
fn update_outcome_not_found_exits_5_and_does_not_claim_the_update_never_existed() {
    let scratch = Scratch::new();
    let server = Server::with_info(
        outcome_info(),
        vec![reply(
            404,
            json!({
                "message": "Update [upd-1] is not retained for workflow [chk-42].",
                "reason": "update_not_found",
                "workflow_id": "chk-42",
                "update_id": "upd-1",
            }),
        )],
    );
    let output = scratch.run(&server.url, &["workflow:update-outcome", "chk-42", "upd-1"]);
    assert_eq!(output.status.code(), Some(5), "{output:?}");
    let stderr = String::from_utf8(output.stderr).unwrap();
    assert!(
        stderr.starts_with(
            "Update [upd-1] is not retained for workflow [chk-42] in namespace [orders]."
        ),
        "{stderr}"
    );
    assert!(
        stderr.contains("does not prove the update was never admitted"),
        "{stderr}"
    );
    assert!(stderr.contains("Reason: update_not_found"), "{stderr}");

    let output = scratch.run(
        &server.url,
        &["workflow:update-outcome", "chk-42", "upd-1", "--json"],
    );
    assert_eq!(output.status.code(), Some(5), "{output:?}");
    let error = stdout_json(&output);
    assert_eq!(error["exit_code"], 5);
    assert_eq!(error["status_code"], 404);
    assert_eq!(error["reason"], "update_not_found");
    assert_eq!(error["update_id"], "upd-1");
    assert_eq!(error["recommendations"][0]["id"], "resource.not_found");
    assert_eq!(server.lines(), [OUTCOME_READ, OUTCOME_READ]);
}

#[test]
fn update_outcome_is_refused_before_any_request_without_the_capability() {
    let scratch = Scratch::new();
    let mut not_advertised = php_info();
    not_advertised["capabilities"] = json!({"update_outcome_reads": false});
    for info in [php_info(), not_advertised] {
        let server = Server::with_info(info.clone(), vec![ok(receipt(json!({})))]);
        let output = scratch.run(
            &server.url,
            &[
                "workflow:update-outcome",
                "chk-42",
                "upd-1",
                "--wait=completed",
            ],
        );
        assert_eq!(output.status.code(), Some(8), "{output:?}");
        let stderr = String::from_utf8(output.stderr).unwrap();
        assert!(
            stderr.contains("does not advertise capabilities.update_outcome_reads"),
            "{stderr}"
        );
        assert!(
            stderr.contains("dw never sends the update again in place of a read"),
            "{stderr}"
        );

        let output = scratch.run(
            &server.url,
            &["workflow:update-outcome", "chk-42", "upd-1", "--json"],
        );
        assert_eq!(output.status.code(), Some(8), "{output:?}");
        let error = stdout_json(&output);
        assert_eq!(error["exit_code"], 8);
        assert_eq!(
            error["compatibility"]["detail"],
            "server did not advertise capabilities.update_outcome_reads."
        );
        assert!(
            server.lines().is_empty(),
            "no read and no update: {:?}",
            server.lines()
        );
    }
}

#[test]
fn update_outcome_refuses_bad_wait_options_and_a_mismatched_receipt() {
    let scratch = Scratch::new();
    let server = Server::with_info(outcome_info(), vec![ok(receipt(json!({})))]);
    for args in [
        vec!["workflow:update-outcome", "chk-42", "upd-1", "--wait=later"],
        vec![
            "workflow:update-outcome",
            "chk-42",
            "upd-1",
            "--wait-timeout=5",
        ],
        vec![
            "workflow:update-outcome",
            "chk-42",
            "upd-1",
            "--wait=completed",
            "--wait-timeout=0",
        ],
        vec![
            "workflow:update-outcome",
            "chk-42",
            "upd-1",
            "--wait=completed",
            "--wait-timeout=1.5",
        ],
    ] {
        let output = scratch.run(&server.url, &args);
        assert_eq!(output.status.code(), Some(2), "{args:?}: {output:?}");
    }
    assert!(server.lines().is_empty(), "{:?}", server.lines());

    let output = scratch.run(&server.url, &["workflow:update-outcome", "chk-42", "upd-2"]);
    assert_eq!(output.status.code(), Some(1), "{output:?}");
    let stderr = String::from_utf8(output.stderr).unwrap();
    assert!(
        stderr.contains("does not match the requested update"),
        "{stderr}"
    );
    assert!(!String::from_utf8(output.stdout).unwrap().contains("upd-1"));
}

#[test]
fn update_points_an_unfinished_update_at_update_outcome_only_when_the_server_can_read_it() {
    let scratch = Scratch::new();
    let accepted = receipt(json!({
        "wait_for": "completed",
        "wait_timed_out": true,
        "wait_timeout_seconds": 10,
    }));
    let args = [
        "workflow:update",
        "chk-42",
        "quota.adjust",
        "--wait=completed",
        "--request-id=quota-1",
    ];
    let server = Server::with_info(outcome_info(), vec![reply(202, accepted.clone())]);
    let output = scratch.run(&server.url, &args);
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    let stdout = String::from_utf8(output.stdout).unwrap();
    assert!(
        stdout.contains("accepted but not finished (the completion wait ran out)"),
        "{stdout}"
    );
    assert!(
        stdout.contains("dw workflow:update-outcome chk-42 upd-1 --wait=completed"),
        "{stdout}"
    );

    let server = Server::with_info(php_info(), vec![reply(202, accepted)]);
    let output = scratch.run(&server.url, &args);
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    let stdout = String::from_utf8(output.stdout).unwrap();
    assert!(!stdout.contains("workflow:update-outcome"), "{stdout}");
}
