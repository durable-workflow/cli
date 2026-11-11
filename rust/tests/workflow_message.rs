//! workflow:query, workflow:start, workflow:signal and workflow:update
//! against a loopback Server that records every request and answers from a
//! queue. A dropped reply stands in for a lost response.

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
        let info: Value = serde_json::from_str(include_str!(
            "../src/compat/fixtures/cluster-info-php-2.11.8.json"
        ))
        .unwrap();
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
