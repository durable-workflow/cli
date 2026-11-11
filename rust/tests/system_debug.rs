//! system:*, service-call:describe, bridge:webhook, debug and watch against
//! a loopback Server that answers each request from a queue and records the
//! request line and body.

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
            "dw-system-debug-{}-{now}-{}",
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
        control_plane: bool,
        body: Value,
    },
    /// Read the request, then close without answering.
    Drop,
}

fn ok(body: Value) -> Reply {
    Reply::Json {
        status: 200,
        control_plane: true,
        body,
    }
}

fn status(status: u16, body: Value) -> Reply {
    Reply::Json {
        status,
        control_plane: true,
        body,
    }
}

struct Seen {
    line: String,
    body: String,
}

/// Cluster info is the published PHP 2.11.8 document. Every other request
/// takes the next queued reply; the last one repeats.
struct Server {
    url: String,
    requests: Arc<Mutex<Vec<Seen>>>,
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
                let (head, body) = read_request(&mut stream);
                let line = head.lines().next().unwrap_or_default().to_owned();
                let reply = if line.starts_with("GET /prefix/api/cluster/info ") {
                    ok(info.clone())
                } else {
                    observed.lock().unwrap().push(Seen {
                        line: line.clone(),
                        body,
                    });
                    let mut queue = queue.lock().unwrap();
                    if queue.len() > 1 {
                        queue.pop_front().unwrap()
                    } else {
                        queue.front().unwrap().clone()
                    }
                };
                let Reply::Json {
                    status,
                    control_plane,
                    body,
                } = reply
                else {
                    continue;
                };
                let payload = serde_json::to_vec(&body).unwrap();
                let version = if control_plane {
                    "X-Durable-Workflow-Control-Plane-Version: 2\r\n"
                } else {
                    ""
                };
                let location = if (300..400).contains(&status) {
                    "Location: /prefix/api/elsewhere\r\n"
                } else {
                    ""
                };
                let head = format!("HTTP/1.1 {status} Test\r\n{version}{location}Content-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n", payload.len());
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
            .map(|seen| seen.line.clone())
            .collect()
    }

    fn bodies(&self) -> Vec<Value> {
        self.requests
            .lock()
            .unwrap()
            .iter()
            .map(|seen| serde_json::from_str(&seen.body).unwrap_or(Value::Null))
            .collect()
    }
}

fn read_request(stream: &mut std::net::TcpStream) -> (String, String) {
    let mut bytes = Vec::new();
    let mut buffer = [0; 4096];
    let header_end = loop {
        if let Some(end) = bytes.windows(4).position(|part| part == b"\r\n\r\n") {
            break end + 4;
        }
        match stream.read(&mut buffer) {
            Ok(0) | Err(_) => return (String::from_utf8_lossy(&bytes).into_owned(), String::new()),
            Ok(size) => bytes.extend_from_slice(&buffer[..size]),
        }
    };
    let head = String::from_utf8_lossy(&bytes[..header_end]).into_owned();
    let length = head
        .lines()
        .find_map(|line| {
            let (name, value) = line.split_once(':')?;
            name.eq_ignore_ascii_case("content-length")
                .then(|| value.trim().parse::<usize>().ok())
                .flatten()
        })
        .unwrap_or(0);
    while bytes.len() < header_end + length {
        match stream.read(&mut buffer) {
            Ok(0) | Err(_) => break,
            Ok(size) => bytes.extend_from_slice(&buffer[..size]),
        }
    }
    let body = String::from_utf8_lossy(&bytes[header_end..]).into_owned();
    (head, body)
}

fn info() -> Value {
    serde_json::from_str(include_str!(
        "../src/compat/fixtures/cluster-info-php-2.11.8.json"
    ))
    .unwrap()
}

fn contract(operation: &str) -> Value {
    json!({
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
    })
}

fn workflow(status: &str) -> Value {
    json!({
        "workflow_id": "chk-42",
        "run_id": "run-7",
        "status": status,
        "control_plane": contract("describe"),
    })
}

#[test]
fn pass_commands_send_one_post_with_the_2x_body() {
    let scratch = Scratch::new();
    for (args, path, body) in [
        (
            vec![
                "system:repair-pass",
                "--run-id=a",
                "--run-id=b",
                "--instance-id=chk-42",
            ],
            "/prefix/api/system/repair/pass",
            json!({"run_ids": ["a", "b"], "instance_id": "chk-42"}),
        ),
        (
            vec!["system:retention-pass", "--limit=25x"],
            "/prefix/api/system/retention/pass",
            json!({"limit": 25}),
        ),
        (
            vec!["system:activity-timeout-pass"],
            "/prefix/api/system/activity-timeouts/pass",
            json!([]),
        ),
    ] {
        let server = Server::new(info(), vec![ok(json!({"processed": 0}))]);
        let mut args = args.clone();
        args.push("--json");
        let output = scratch.run(&server.url, &args);
        assert_eq!(output.status.code(), Some(0), "{args:?}: {output:?}");
        assert_eq!(server.lines(), [format!("POST {path} HTTP/1.1")]);
        assert_eq!(server.bodies(), [body], "{args:?}");
    }
}

#[test]
fn pass_failures_exit_1_and_a_lost_response_is_not_resent() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        vec![ok(
            json!({"results": [{"run_id": "run-1", "outcome": "error", "reason": "locked"}]}),
        )],
    );
    let output = scratch.run(&server.url, &["system:retention-pass"]);
    assert_eq!(output.status.code(), Some(1), "{output:?}");
    assert!(String::from_utf8_lossy(&output.stdout).contains("  run-1: locked"));

    for args in [
        vec!["system:repair-pass"],
        vec!["system:retention-pass"],
        vec!["system:activity-timeout-pass"],
        vec![
            "bridge:webhook",
            "stripe",
            "--action=start_workflow",
            "--idempotency-key=evt-1",
            "--target={\"workflow_type\":\"orders\"}",
        ],
    ] {
        let server = Server::new(info(), vec![Reply::Drop]);
        let output = scratch.run(&server.url, &args);
        assert_eq!(output.status.code(), Some(3), "{args:?}: {output:?}");
        assert_eq!(server.lines().len(), 1, "{args:?} must not be resent");
    }
}

#[test]
fn redirects_are_refused_without_following() {
    let scratch = Scratch::new();
    let server = Server::new(info(), vec![status(302, json!({"message": "moved"}))]);
    let output = scratch.run(&server.url, &["system:repair-pass", "--json"]);
    assert_ne!(output.status.code(), Some(0));
    assert_eq!(server.lines().len(), 1);
}

#[test]
fn bridge_webhook_validates_before_any_request() {
    let scratch = Scratch::new();
    let target = "--target={\"workflow_type\":\"orders\"}";
    for (args, message) in [
        (
            vec!["bridge:webhook", "stripe", "--idempotency-key=e", target],
            "--action is required.",
        ),
        (
            vec!["bridge:webhook", "stripe", "--action=a", target],
            "--idempotency-key is required.",
        ),
        (
            vec![
                "bridge:webhook",
                "stripe",
                "--action=a",
                "--idempotency-key=e",
            ],
            "--target is required and must be a JSON object.",
        ),
        (
            vec![
                "bridge:webhook",
                "stripe",
                "--action=a",
                "--idempotency-key=e",
                "--target=[1]",
            ],
            "--target must be a JSON object.",
        ),
        (
            vec![
                "bridge:webhook",
                "stripe",
                "--action=a",
                "--idempotency-key=e",
                target,
                "--correlation={}",
            ],
            "--correlation must be a JSON object.",
        ),
        (
            vec![
                "bridge:webhook",
                " ",
                "--action=a",
                "--idempotency-key=e",
                target,
            ],
            "adapter is required.",
        ),
    ] {
        let server = Server::new(info(), vec![ok(json!({}))]);
        let mut args = args.clone();
        args.push("--json");
        let output = scratch.run(&server.url, &args);
        assert_eq!(output.status.code(), Some(2), "{args:?}: {output:?}");
        let error: Value = serde_json::from_slice(&output.stdout).unwrap();
        assert_eq!(error["error"], message);
        assert!(server.lines().is_empty(), "{args:?}");
    }
}

#[test]
fn bridge_webhook_sends_the_event_once() {
    let scratch = Scratch::new();
    let server = Server::new(info(), vec![ok(json!({"accepted": true}))]);
    let output = scratch.run(
        &server.url,
        &[
            "bridge:webhook",
            " str ipe/x ",
            "--action= start_workflow ",
            "--idempotency-key=evt-1",
            "--target={\"workflow_type\":\"orders\"}",
            "--correlation={\"trace\":\"t\"}",
            "-i",
            "[1,2]",
            "--json",
        ],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(
        server.lines(),
        ["POST /prefix/api/bridge-adapters/webhook/str%20ipe%2Fx HTTP/1.1"]
    );
    assert_eq!(
        server.bodies(),
        [json!({
            "action": "start_workflow",
            "idempotency_key": "evt-1",
            "target": {"workflow_type": "orders"},
            "input": [1, 2],
            "correlation": {"trace": "t"}
        })]
    );
}

#[test]
fn identifiers_are_single_segments_and_dots_are_refused() {
    let scratch = Scratch::new();
    let server = Server::new(info(), vec![ok(json!({"id": "c"}))]);
    let output = scratch.run(
        &server.url,
        &[
            "service-call:describe",
            "a/b",
            "s?x",
            "o#p",
            "c d",
            "--json",
        ],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(
        server.lines(),
        ["GET /prefix/api/service-endpoints/a%2Fb/services/s%3Fx/operations/o%23p/service-calls/c%20d HTTP/1.1"]
    );

    for args in [
        vec!["service-call:describe", "billing", "..", "create", "call"],
        vec!["debug", "workflow", "."],
        vec!["debug", "workflow", "chk-42", "--run-id=.."],
        vec!["watch", "workflow", ".."],
    ] {
        let server = Server::new(info(), vec![ok(workflow("completed"))]);
        let mut args = args.clone();
        args.push("--output=json");
        let output = scratch.run(&server.url, &args);
        assert_eq!(output.status.code(), Some(2), "{args:?}: {output:?}");
        assert!(server.lines().is_empty(), "{args:?}");
    }
}

#[test]
fn debug_reads_the_selected_run() {
    let scratch = Scratch::new();
    let mut body = workflow("running");
    body["control_plane"] = contract("debug");
    let server = Server::new(info(), vec![ok(body)]);
    let output = scratch.run(
        &server.url,
        &["debug", "workflow", "chk/42", "-r", "run 7", "--json"],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(
        server.lines(),
        ["GET /prefix/api/workflows/chk%2F42/runs/run%207/debug HTTP/1.1"]
    );
}

#[test]
fn watch_polls_until_terminal_and_reports_changes() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        vec![
            ok(workflow("running")),
            ok(workflow("running")),
            ok(json!({
                "workflow_id": "chk-42",
                "status": "completed",
                "status_bucket": "completed",
                "output": {"total": 3},
                "control_plane": contract("describe"),
            })),
        ],
    );
    let output = scratch.run(
        &server.url,
        &["watch", "workflow", "chk-42", "--interval=0"],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(
        String::from_utf8(output.stdout).unwrap(),
        "Watching workflow chk-42 (status: running)\nChange #3\n  status: running -> completed\n  status_bucket: - -> completed\n  output: - -> {\"total\":3}\nTerminal state reached: completed\n"
    );
    assert_eq!(server.lines().len(), 3);
}

#[test]
fn watch_stops_at_max_polls_with_exit_7_and_failed_runs_exit_1() {
    let scratch = Scratch::new();
    let server = Server::new(info(), vec![ok(workflow("running"))]);
    let output = scratch.run(
        &server.url,
        &[
            "watch",
            "workflow",
            "chk-42",
            "--interval=0",
            "--max-polls=3",
        ],
    );
    assert_eq!(output.status.code(), Some(7), "{output:?}");
    assert!(String::from_utf8_lossy(&output.stdout)
        .ends_with("Watch stopped after 3 polls before terminal state.\n"));
    assert_eq!(server.lines().len(), 3);

    let server = Server::new(info(), vec![ok(workflow("timed_out"))]);
    let output = scratch.run(&server.url, &["watch", "workflow", "chk-42"]);
    assert_eq!(output.status.code(), Some(1), "{output:?}");
    assert_eq!(server.lines().len(), 1);
}

#[test]
fn watch_and_debug_refuse_bad_options_before_any_request() {
    let scratch = Scratch::new();
    for (args, code) in [
        (vec!["watch", "workflow", "chk-42", "--interval=-1"], 2),
        (vec!["watch", "workflow", "chk-42", "--max-polls=0"], 2),
        (vec!["watch", "schedule", "chk-42"], 2),
        (vec!["debug", "schedule", "chk-42"], 2),
    ] {
        let server = Server::new(info(), vec![ok(workflow("completed"))]);
        let output = scratch.run(&server.url, &args);
        assert_eq!(output.status.code(), Some(code), "{args:?}: {output:?}");
        assert!(server.lines().is_empty(), "{args:?}");
    }
}

#[test]
fn compatibility_refusal_sends_no_request() {
    let scratch = Scratch::new();
    let mut incompatible = info();
    incompatible["control_plane"]["version"] = json!("99");
    let server = Server::new(incompatible, vec![ok(json!({}))]);
    for args in [
        vec!["system:repair-status"],
        vec!["system:repair-pass"],
        vec!["system:operator-metrics"],
        vec!["service-call:describe", "e", "s", "o", "c"],
        vec!["debug", "workflow", "chk-42"],
        vec!["watch", "workflow", "chk-42"],
        vec![
            "bridge:webhook",
            "stripe",
            "--action=a",
            "--idempotency-key=e",
            "--target={\"a\":1}",
        ],
    ] {
        assert_eq!(
            scratch.run(&server.url, &args).status.code(),
            Some(8),
            "{args:?}"
        );
    }
    assert!(server.lines().is_empty());
}

#[test]
fn human_output_prints_server_text_as_data() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        vec![ok(json!({
            "namespace": "<error>X</error>",
            "expired_run_ids": ["run\u{1b}[2J"],
            "expired_run_count": 1
        }))],
    );
    let output = scratch.run(&server.url, &["system:retention-status"]);
    let stdout = String::from_utf8(output.stdout).unwrap();
    assert!(
        stdout.contains("  Namespace:      <error>X</error>"),
        "{stdout}"
    );
    assert!(stdout.contains("  run\\u{001b}[2J"), "{stdout}");
}
