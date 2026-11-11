//! activity:*, workflow-task:* and query-task:* against a loopback Server
//! that answers each request from a queue, so request counts, paths and
//! bodies are observable.

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
            "dw-activity-tasks-{}-{now}-{}",
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

fn json_error(output: &Output) -> Value {
    serde_json::from_slice(&output.stdout).unwrap()
}

const PROTOCOL: (&str, &str) = ("X-Durable-Workflow-Protocol-Version", "1.20");

/// A successful worker-protocol answer.
fn worker(mut body: Value) -> Reply {
    body["protocol_version"] = json!("1.20");
    Reply {
        status: 200,
        headers: vec![PROTOCOL],
        body: Some(body),
    }
}

/// Each worker-protocol mutation, the request line it must send and the
/// exact body 2.x sends, in 2.x's field order.
fn mutations() -> Vec<(Vec<&'static str>, &'static str, Value)> {
    vec![
        (
            vec!["activity:complete", "act/1", "att-1", "--input={\"ok\":{}}"],
            "POST /prefix/api/worker/activity-tasks/act%2F1/complete HTTP/1.1",
            json!({"activity_attempt_id": "att-1", "lease_owner": "cli", "result": {"ok": []}}),
        ),
        (
            vec![
                "activity:fail",
                "act-1",
                "att-1",
                "-m",
                "boom",
                "--failure-kind=timeout",
                "--retryable",
                "--timeout-type=heartbeat",
                "--cancelled",
                "--malformed-output",
                "--lease-owner=w-9",
            ],
            "POST /prefix/api/worker/activity-tasks/act-1/fail HTTP/1.1",
            json!({
                "activity_attempt_id": "att-1",
                "lease_owner": "w-9",
                "failure": {
                    "message": "boom",
                    "type": null,
                    "non_retryable": false,
                    "kind": "timeout",
                    "retryable": true,
                    "timeout_type": "heartbeat",
                    "cancelled": true,
                    "malformed_output": true
                }
            }),
        ),
        (
            vec![
                "workflow-task:complete",
                "wt?1",
                "02",
                "--complete-result=null",
            ],
            "POST /prefix/api/worker/workflow-tasks/wt%3F1/complete HTTP/1.1",
            json!({
                "lease_owner": "cli",
                "workflow_task_attempt": 2,
                "commands": [{"type": "complete_workflow", "result": null}]
            }),
        ),
        (
            vec![
                "workflow-task:complete",
                "wt-1",
                "1",
                "--command={\"type\":\"fail_workflow\",\"details\":{}}",
                "--command=[1]",
            ],
            "POST /prefix/api/worker/workflow-tasks/wt-1/complete HTTP/1.1",
            json!({
                "lease_owner": "cli",
                "workflow_task_attempt": 1,
                "commands": [{"type": "fail_workflow", "details": []}, [1]]
            }),
        ),
        (
            vec![
                "workflow-task:fail",
                "wt-1",
                "1",
                "--message=boom",
                "--type=",
                "--stack-trace=trace",
            ],
            "POST /prefix/api/worker/workflow-tasks/wt-1/fail HTTP/1.1",
            json!({
                "lease_owner": "cli",
                "workflow_task_attempt": 1,
                "failure": {"message": "boom", "stack_trace": "trace"}
            }),
        ),
        (
            vec!["workflow-task:history", "wt-1", "page/2", "--attempt=3"],
            "POST /prefix/api/worker/workflow-tasks/wt-1/history HTTP/1.1",
            json!({
                "next_history_page_token": "page/2",
                "lease_owner": "cli",
                "workflow_task_attempt": 3
            }),
        ),
        (
            vec!["query-task:complete", "qt-1", "1", "--result= {\"a\":1} "],
            "POST /prefix/api/worker/query-tasks/qt-1/complete HTTP/1.1",
            json!({"lease_owner": "cli", "query_task_attempt": 1, "result": {"a": 1}}),
        ),
        (
            vec![
                "query-task:fail",
                "qt-1",
                "1",
                "-m",
                "unknown",
                "-t",
                "QueryError",
            ],
            "POST /prefix/api/worker/query-tasks/qt-1/fail HTTP/1.1",
            json!({
                "lease_owner": "cli",
                "query_task_attempt": 1,
                "failure": {"message": "unknown", "reason": "query_rejected", "type": "QueryError"}
            }),
        ),
    ]
}

#[test]
fn worker_mutations_send_the_2x_request() {
    let scratch = Scratch::new();
    for (args, line, body) in mutations() {
        let server = Server::new(info(), vec![worker(json!({"outcome": "ok"}))]);
        let mut args = args.clone();
        args.push("--json");
        json_stdout(&scratch.run(&server.url, &args));
        assert_eq!(server.request_lines(), [line], "{args:?}");
        assert_eq!(server.body(0), body, "{args:?}");
        let wire = server.requests()[0]
            .split("\r\n\r\n")
            .nth(1)
            .unwrap()
            .to_owned();
        assert_eq!(wire, serde_json::to_string(&body).unwrap(), "{args:?}");
        let head = server.requests()[0].to_lowercase();
        assert!(
            head.contains("x-durable-workflow-protocol-version: 1.0"),
            "{args:?}"
        );
        assert!(head.contains("bearer fixture-token"), "{args:?}");
    }
}

#[test]
fn polls_send_one_request_with_the_2x_body() {
    let scratch = Scratch::new();
    for (args, line, body) in [
        (
            vec![
                "workflow-task:poll",
                "w-1",
                "--task-queue=orders",
                "--build-id=",
                "--history-page-size=25",
                "--poll-request-id=p-1",
            ],
            "POST /prefix/api/worker/workflow-tasks/poll HTTP/1.1",
            json!({
                "worker_id": "w-1",
                "task_queue": "orders",
                "poll_request_id": "p-1",
                "history_page_size": 25
            }),
        ),
        (
            vec!["query-task:poll", "w-1"],
            "POST /prefix/api/worker/query-tasks/poll HTTP/1.1",
            json!({"worker_id": "w-1", "task_queue": "default"}),
        ),
    ] {
        let server = Server::new(info(), vec![worker(json!({"task": null}))]);
        let mut args = args.clone();
        args.push("--json");
        json_stdout(&scratch.run(&server.url, &args));
        assert_eq!(server.request_lines(), [line], "{args:?}");
        assert_eq!(server.body(0), body, "{args:?}");
    }
}

#[test]
fn activity_reads_use_the_control_plane_and_encode_the_id() {
    let scratch = Scratch::new();
    let server = Server::new(info(), vec![ok(json!({"activity_id": "a/1"}))]);
    let result = json_stdout(&scratch.run(&server.url, &["activity:describe", "a/1?x", "--json"]));
    assert_eq!(result["namespace"], "orders");
    assert_eq!(
        server.request_lines(),
        ["GET /prefix/api/activities/a%2F1%3Fx HTTP/1.1"]
    );
    let server = Server::new(info(), vec![ok(json!({"activities": []}))]);
    json_stdout(&scratch.run(
        &server.url,
        &[
            "activity:list",
            "--status=failed",
            "--next-page-token=t/2",
            "--json",
        ],
    ));
    assert_eq!(
        server.request_lines(),
        ["GET /prefix/api/activities?status=failed&page_size=50&next_page_token=t%2F2 HTTP/1.1"]
    );
}

#[test]
fn empty_and_dot_ids_are_refused_before_any_request() {
    let scratch = Scratch::new();
    for args in [
        vec!["activity:describe", ".."],
        vec!["activity:complete", ".", "att-1"],
        vec!["activity:fail", "", "att-1", "-m", "x"],
        vec!["workflow-task:complete", "..", "1"],
        vec!["workflow-task:fail", ".", "1", "-m", "x"],
        vec!["workflow-task:history", "..", "page", "--attempt=1"],
        vec!["query-task:complete", ".", "1", "--result=1"],
        vec!["query-task:fail", "..", "1", "-m", "x"],
    ] {
        let server = Server::new(info(), vec![worker(json!({}))]);
        let mut args = args.clone();
        args.push("--json");
        let output = scratch.run(&server.url, &args);
        assert_eq!(output.status.code(), Some(2), "{args:?}: {output:?}");
        assert!(server.requests().is_empty(), "{args:?}");
    }
}

#[test]
fn invalid_options_are_refused_before_any_request() {
    let scratch = Scratch::new();
    for (args, message) in [
        (
            vec!["activity:fail", "a", "b", "--retryable", "--non-retryable"],
            "--retryable and --non-retryable cannot both be set.",
        ),
        (
            vec!["activity:complete", "a", "b", "--input=[1,]"],
            "--input must be valid JSON: Syntax error",
        ),
        (
            vec!["workflow-task:poll", "w", "--history-page-size=-1"],
            "--history-page-size must be a positive integer.",
        ),
        (
            vec!["workflow-task:complete", "t", "0"],
            "attempt must be a positive integer.",
        ),
        (
            vec!["workflow-task:complete", "t", "1", "--command=1"],
            "--command must decode to a JSON object.",
        ),
        (
            vec![
                "workflow-task:complete",
                "t",
                "1",
                "--command={}",
                "--complete-result=1",
            ],
            "Use either --command or --complete-result, not both.",
        ),
        (
            vec!["workflow-task:fail", "t", "1", "-m", " "],
            "--message is required.",
        ),
        (
            vec!["workflow-task:history", "t", "p"],
            "--attempt must be a positive integer.",
        ),
        (
            vec!["query-task:complete", "t", "1"],
            "--result is required.",
        ),
        (
            vec!["query-task:fail", "t", "1x"],
            "attempt must be a positive integer.",
        ),
    ] {
        let server = Server::new(info(), vec![worker(json!({}))]);
        let mut args = args.clone();
        args.push("--json");
        let output = scratch.run(&server.url, &args);
        assert_eq!(output.status.code(), Some(2), "{args:?}: {output:?}");
        assert_eq!(json_error(&output)["error"], message, "{args:?}");
        assert!(server.requests().is_empty(), "{args:?}");
    }
}

#[test]
fn a_lost_mutation_response_is_never_resent() {
    let scratch = Scratch::new();
    for (args, _, _) in mutations() {
        let server = Server::new(info(), vec![lost()]);
        let mut args = args.clone();
        args.push("--json");
        let output = scratch.run(&server.url, &args);
        assert_eq!(output.status.code(), Some(3), "{args:?}: {output:?}");
        assert_eq!(server.requests().len(), 1, "{args:?}");
    }
}

#[test]
fn redirects_are_refused_without_following() {
    let scratch = Scratch::new();
    for args in [
        vec!["activity:list"],
        vec!["activity:complete", "a", "b"],
        vec!["workflow-task:poll", "w"],
    ] {
        let server = Server::new(
            info(),
            vec![Reply {
                status: 302,
                headers: vec![("Location", "/elsewhere"), PROTOCOL],
                body: Some(json!({"message": "moved"})),
            }],
        );
        let mut args = args.clone();
        args.push("--json");
        let output = scratch.run(&server.url, &args);
        assert_ne!(output.status.code(), Some(0), "{args:?}");
        assert_eq!(server.requests().len(), 1, "{args:?}");
    }
}

#[test]
fn worker_responses_must_echo_a_compatible_protocol_version() {
    let scratch = Scratch::new();
    let server = Server::new(info(), vec![ok(json!({"task_id": "a"}))]);
    let output = scratch.run(&server.url, &["activity:complete", "a", "b", "--json"]);
    assert_eq!(output.status.code(), Some(1), "{output:?}");
    assert_eq!(
        json_error(&output)["error"],
        "Server error: invalid worker-protocol response version for [/worker/activity-tasks/a/complete]; expected same-major server minor compatible with [1.0], got [missing]."
    );
}

#[test]
fn worker_commands_refuse_a_server_without_the_worker_protocol() {
    let scratch = Scratch::new();
    let mut without = info();
    without.as_object_mut().unwrap().remove("worker_protocol");
    let server = Server::new(without, vec![worker(json!({}))]);
    for args in [
        vec!["activity:complete", "a", "b"],
        vec!["workflow-task:poll", "w"],
        vec!["query-task:poll", "w"],
    ] {
        let mut args = args.clone();
        args.push("--json");
        assert_eq!(
            scratch.run(&server.url, &args).status.code(),
            Some(8),
            "{args:?}"
        );
    }
    assert!(server.requests().is_empty());
    // Activity reads use only the control plane.
    json_stdout(&scratch.run(&server.url, &["activity:list", "--json"]));
}

#[test]
fn server_text_in_human_output_is_not_console_markup() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        vec![worker(
            json!({"task": {"task_id": "<error>X</error>\u{1b}[2J"}}),
        )],
    );
    let output = scratch.run(&server.url, &["workflow-task:poll", "w"]);
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    let stdout = String::from_utf8(output.stdout).unwrap();
    assert!(
        stdout.contains("Task ID: <error>X</error>\\u{001b}[2J"),
        "{stdout}"
    );
    let server = Server::new(
        info(),
        vec![Reply {
            status: 409,
            headers: vec![PROTOCOL],
            body: Some(json!({"message": "<error>FORGED</error>"})),
        }],
    );
    let output = scratch.run(&server.url, &["query-task:fail", "q", "1", "-m", "x"]);
    let stderr = String::from_utf8(output.stderr).unwrap();
    assert!(stderr.contains("<error>FORGED</error>"), "{stderr}");
    assert!(stderr.contains("Namespace: orders"), "{stderr}");
}
