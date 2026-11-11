//! workflow:cancel (one run and bounded batches), terminate, archive,
//! redrive, repair and migrate-v1 against a loopback Server that answers
//! by request line and records every request.

use serde_json::{json, Value};
use std::fs;
use std::io::{Read, Write};
use std::net::TcpListener;
use std::path::PathBuf;
use std::process::{Command, Output, Stdio};
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
            "dw-workflow-control-{}-{now}-{}",
            std::process::id(),
            NEXT.fetch_add(1, Ordering::Relaxed)
        ));
        fs::create_dir(&path).unwrap();
        Self(path)
    }

    fn command(&self, url: &str, args: &[&str]) -> Command {
        let mut command = Command::new(env!("CARGO_BIN_EXE_dw"));
        command
            .args(args)
            .args([
                "--server",
                url,
                "--namespace=orders",
                "--token=fixture-token",
            ])
            .env_clear()
            .env("DW_CONFIG_HOME", &self.0)
            .env("HOME", &self.0);
        command
    }

    fn run(&self, url: &str, args: &[&str]) -> Output {
        self.command(url, args)
            .stdin(Stdio::null())
            .output()
            .unwrap()
    }

    fn run_with_stdin(&self, url: &str, args: &[&str], stdin: &str) -> Output {
        let mut child = self
            .command(url, args)
            .stdin(Stdio::piped())
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .spawn()
            .unwrap();
        child
            .stdin
            .take()
            .unwrap()
            .write_all(stdin.as_bytes())
            .unwrap();
        child.wait_with_output().unwrap()
    }
}

impl Drop for Scratch {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}

/// What the fixture does with one request.
enum Reply {
    Json(u16, Value),
    /// Close the connection without answering: a lost response.
    Drop,
}

type Route = dyn Fn(&str, usize) -> Reply + Send + Sync;

/// Cluster info is always the published PHP 2.11.8 document. Every other
/// request goes to `route` with its request line and how many requests
/// with that line came before it.
struct Server {
    url: String,
    requests: Arc<Mutex<Vec<String>>>,
}

impl Server {
    fn new(info: Value, route: Box<Route>) -> Self {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let url = format!("http://{}/prefix", listener.local_addr().unwrap());
        let requests = Arc::new(Mutex::new(Vec::new()));
        let observed = Arc::clone(&requests);
        thread::spawn(move || {
            for stream in listener.incoming() {
                let Ok(mut stream) = stream else { continue };
                stream
                    .set_read_timeout(Some(Duration::from_secs(2)))
                    .unwrap();
                let mut bytes = Vec::new();
                let mut buffer = [0; 4096];
                while !bytes.windows(4).any(|part| part == b"\r\n\r\n") {
                    match stream.read(&mut buffer) {
                        Ok(0) | Err(_) => break,
                        Ok(size) => bytes.extend_from_slice(&buffer[..size]),
                    }
                }
                let head = String::from_utf8_lossy(&bytes).into_owned();
                let length = head
                    .lines()
                    .find_map(|line| {
                        line.to_ascii_lowercase()
                            .strip_prefix("content-length:")
                            .map(|value| value.trim().parse::<usize>().unwrap_or(0))
                    })
                    .unwrap_or(0);
                let body_start = head.find("\r\n\r\n").map_or(bytes.len(), |at| at + 4);
                while bytes.len() < body_start + length {
                    match stream.read(&mut buffer) {
                        Ok(0) | Err(_) => break,
                        Ok(size) => bytes.extend_from_slice(&buffer[..size]),
                    }
                }
                let request = String::from_utf8_lossy(&bytes).into_owned();
                let line = request.lines().next().unwrap_or_default().to_owned();
                let reply = if line.starts_with("GET /prefix/api/cluster/info ") {
                    Reply::Json(200, info.clone())
                } else {
                    let mut seen = observed.lock().unwrap();
                    let earlier = seen
                        .iter()
                        .filter(|request: &&String| request.lines().next() == Some(line.as_str()))
                        .count();
                    seen.push(request);
                    drop(seen);
                    route(&line, earlier)
                };
                let Reply::Json(status, body) = reply else {
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
            .map(|request| request.lines().next().unwrap_or_default().to_owned())
            .collect()
    }

    fn posts(&self) -> Vec<String> {
        self.lines()
            .into_iter()
            .filter(|line| line.starts_with("POST "))
            .collect()
    }

    fn body(&self, index: usize) -> Value {
        let requests = self.requests.lock().unwrap();
        let request = &requests[index];
        let body = &request[request.find("\r\n\r\n").unwrap() + 4..];
        serde_json::from_str(body).unwrap()
    }
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

fn page(workflows: Value, token: Option<&str>) -> Reply {
    Reply::Json(
        200,
        json!({"workflows": workflows, "next_page_token": token, "control_plane": contract("list")}),
    )
}

/// Answers every cancel with a receipt for the run in its path.
fn cancelled(line: &str) -> Reply {
    let path = line.split(' ').nth(1).unwrap_or_default();
    let parts: Vec<&str> = path.split('/').collect();
    Reply::Json(
        200,
        json!({
            "workflow_id": parts.get(4).copied().unwrap_or_default(),
            "run_id": parts.get(6).copied().unwrap_or_default(),
            "outcome": "cancelled",
            "control_plane": contract("cancel"),
        }),
    )
}

/// A batch Server: list pages in order, then a receipt for every cancel.
fn batch_server(pages: Vec<(Value, Option<&'static str>)>) -> Server {
    let pages = Arc::new(pages);
    Server::new(
        info(),
        Box::new(move |line, _| {
            if line.starts_with("GET /prefix/api/workflows?") {
                let index = line.matches("next_page_token=").count();
                let page_index = if index == 0 {
                    0
                } else {
                    line.split("next_page_token=page-")
                        .nth(1)
                        .and_then(|rest| rest.split([' ', '&']).next())
                        .and_then(|number| number.parse::<usize>().ok())
                        .unwrap_or(1)
                };
                let (workflows, token) = pages[page_index.min(pages.len() - 1)].clone();
                page(workflows, token)
            } else {
                cancelled(line)
            }
        }),
    )
}

fn json_stdout(output: &Output) -> Value {
    serde_json::from_slice(&output.stdout).unwrap_or_else(|_| panic!("{output:?}"))
}

#[test]
fn batch_cancel_posts_to_each_selected_run_never_the_instance() {
    let scratch = Scratch::new();
    let server = batch_server(vec![(
        json!([{"workflow_id": "order-7", "run_id": "run-a"}, {"workflow_id": "order-8", "run_id": "run-c"}]),
        None,
    )]);
    let output = scratch.run(
        &server.url,
        &[
            "workflow:cancel",
            "--all-matching=customer-42",
            "--type=orders.process",
            "--reason=customer request",
            "--yes",
            "--json",
        ],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    let summary = json_stdout(&output);
    assert_eq!(
        summary["targets"],
        json!([
            {"workflow_id": "order-7", "run_id": "run-a", "namespace": "orders"},
            {"workflow_id": "order-8", "run_id": "run-c", "namespace": "orders"},
        ])
    );
    assert_eq!(summary["cancelled"], 2);
    assert_eq!(summary["results"][0]["run_id"], "run-a");
    assert_eq!(
        server.lines(),
        [
            "GET /prefix/api/workflows?query=customer-42&workflow_type=orders.process&status=running&page_size=100 HTTP/1.1",
            "POST /prefix/api/workflows/order-7/runs/run-a/cancel HTTP/1.1",
            "POST /prefix/api/workflows/order-8/runs/run-c/cancel HTTP/1.1",
        ]
    );
    assert_eq!(server.body(1), json!({"reason": "customer request"}));
}

#[test]
fn a_refused_run_is_reported_and_never_retried_against_its_successor() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        Box::new(|line, _| {
            if line.starts_with("GET ") {
                page(
                    json!([{"workflow_id": "order-7", "run_id": "run-a"}, {"workflow_id": "order-8", "run_id": "run-c"}]),
                    None,
                )
            } else if line.contains("/runs/run-a/") {
                Reply::Json(
                    409,
                    json!({"message": "Commands cannot target historical runs.", "reason": "historical_run_command_rejected", "workflow_id": "order-7", "run_id": "run-a"}),
                )
            } else {
                cancelled(line)
            }
        }),
    );
    let output = scratch.run(
        &server.url,
        &[
            "workflow:cancel",
            "--all-matching=customer-42",
            "--yes",
            "--json",
        ],
    );
    assert_eq!(output.status.code(), Some(1), "{output:?}");
    assert_eq!(
        server.posts(),
        [
            "POST /prefix/api/workflows/order-7/runs/run-a/cancel HTTP/1.1",
            "POST /prefix/api/workflows/order-8/runs/run-c/cancel HTTP/1.1",
        ]
    );
    let summary = json_stdout(&output);
    assert_eq!(summary["matched"], 2);
    assert_eq!(summary["cancelled"], 1);
    assert_eq!(
        summary["failures"],
        json!([{
            "workflow_id": "order-7",
            "run_id": "run-a",
            "namespace": "orders",
            "reason": "historical_run_command_rejected",
            "message": "Server error: Commands cannot target historical runs.",
            "exit_code": 2,
        }])
    );
    assert_eq!(summary["results"][0]["run_id"], "run-c");
}

#[test]
fn a_lost_cancel_response_is_recorded_once_and_the_batch_continues() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        Box::new(|line, _| {
            if line.starts_with("GET ") {
                page(
                    json!([{"workflow_id": "order-7", "run_id": "run-a"}, {"workflow_id": "order-8", "run_id": "run-c"}]),
                    None,
                )
            } else if line.contains("/runs/run-a/") {
                Reply::Drop
            } else {
                cancelled(line)
            }
        }),
    );
    let output = scratch.run(
        &server.url,
        &[
            "workflow:cancel",
            "--all-matching=customer-42",
            "--yes",
            "--json",
        ],
    );
    assert_eq!(output.status.code(), Some(1), "{output:?}");
    assert_eq!(server.posts().len(), 2, "no cancel is submitted twice");
    let summary = json_stdout(&output);
    assert_eq!(summary["failures"][0]["run_id"], "run-a");
    assert_eq!(summary["failures"][0]["exit_code"], 3);
    assert_eq!(summary["cancelled"], 1);
}

#[test]
fn a_lost_single_cancel_response_is_a_transport_failure_sent_once() {
    let scratch = Scratch::new();
    let server = Server::new(info(), Box::new(|_, _| Reply::Drop));
    let output = scratch.run(
        &server.url,
        &["workflow:cancel", "chk-42", "--run-id=run-7", "--json"],
    );
    assert_eq!(output.status.code(), Some(3), "{output:?}");
    assert_eq!(
        server.posts(),
        ["POST /prefix/api/workflows/chk-42/runs/run-7/cancel HTTP/1.1"]
    );
}

#[test]
fn workflows_without_a_listed_run_are_not_cancelled() {
    let scratch = Scratch::new();
    let server = batch_server(vec![(
        json!([{"workflow_id": "order-7"}, {"workflow_id": "order-8", "run_id": ""}]),
        None,
    )]);
    let output = scratch.run(
        &server.url,
        &[
            "workflow:cancel",
            "--all-matching=customer-42",
            "--yes",
            "--json",
        ],
    );
    assert_eq!(output.status.code(), Some(1), "{output:?}");
    assert!(server.posts().is_empty());
    let summary = json_stdout(&output);
    assert_eq!(summary["cancelled"], 0);
    for failure in summary["failures"].as_array().unwrap() {
        assert_eq!(failure["reason"], "run_id_unavailable");
        assert_eq!(failure["run_id"], Value::Null);
        assert_eq!(failure["exit_code"], 8);
    }
}

#[test]
fn a_workflow_seen_again_keeps_its_first_selected_run() {
    let scratch = Scratch::new();
    let server = batch_server(vec![
        (
            json!([{"workflow_id": "order-7", "run_id": "run-a"}]),
            Some("page-1"),
        ),
        (
            json!([
                {"workflow_id": "order-7", "run_id": "run-b"},
                {"workflow_id": "order-8", "run_id": "run-c"},
                {"workflow_id": "order-9", "run_id": "run-d"},
            ]),
            None,
        ),
    ]);
    let output = scratch.run(
        &server.url,
        &[
            "workflow:cancel",
            "--all-matching=customer-42",
            "--yes",
            "--limit=2",
            "--json",
        ],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(
        server.lines(),
        [
            "GET /prefix/api/workflows?query=customer-42&status=running&page_size=2 HTTP/1.1",
            "GET /prefix/api/workflows?query=customer-42&status=running&page_size=1&next_page_token=page-1 HTTP/1.1",
            "POST /prefix/api/workflows/order-7/runs/run-a/cancel HTTP/1.1",
            "POST /prefix/api/workflows/order-8/runs/run-c/cancel HTTP/1.1",
        ]
    );
    assert_eq!(
        json_stdout(&output)["workflow_ids"],
        json!(["order-7", "order-8"])
    );
}

#[test]
fn selection_stops_when_a_page_adds_nothing_new() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        Box::new(|line, _| {
            if line.starts_with("GET ") {
                // A stuck cursor: a fresh token, but the same workflow.
                let token = format!("page-{}", line.matches("page-").count() + 1);
                page(
                    json!([{"workflow_id": "order-7", "run_id": "run-a"}]),
                    Some(Box::leak(token.into_boxed_str())),
                )
            } else {
                cancelled(line)
            }
        }),
    );
    let output = scratch.run(
        &server.url,
        &[
            "workflow:cancel",
            "--all-matching=customer-42",
            "--yes",
            "--limit=2",
            "--json",
        ],
    );
    assert_eq!(output.status.code(), Some(1), "{output:?}");
    assert!(server.posts().is_empty());
    assert_eq!(server.lines().len(), 2);
    assert!(json_stdout(&output)["error"]
        .as_str()
        .unwrap()
        .contains("a page added no new workflows"));
}

#[test]
fn selection_stops_when_a_page_token_repeats() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        Box::new(|line, _| {
            if line.contains("next_page_token=stuck") {
                page(
                    json!([{"workflow_id": "order-8", "run_id": "run-c"}]),
                    Some("stuck"),
                )
            } else if line.starts_with("GET ") {
                page(
                    json!([{"workflow_id": "order-7", "run_id": "run-a"}]),
                    Some("stuck"),
                )
            } else {
                cancelled(line)
            }
        }),
    );
    let output = scratch.run(
        &server.url,
        &[
            "workflow:cancel",
            "--all-matching=customer-42",
            "--yes",
            "--limit=5",
        ],
    );
    assert_eq!(output.status.code(), Some(1), "{output:?}");
    assert!(server.posts().is_empty());
    assert!(String::from_utf8_lossy(&output.stderr)
        .contains("the server returned the same page token twice"));
}

#[test]
fn confirmation_reads_one_answer_from_stdin() {
    let scratch = Scratch::new();
    let selection = || {
        batch_server(vec![(
            json!([{"workflow_id": "order-7", "run_id": "run-a"}]),
            None,
        )])
    };
    for (answer, cancels) in [("yes\n", true), ("Y", true), ("no\n", false), ("", false)] {
        let server = selection();
        let output = scratch.run_with_stdin(
            &server.url,
            &["workflow:cancel", "--all-matching=customer-42"],
            answer,
        );
        assert_eq!(output.status.code(), Some(0), "{answer:?}: {output:?}");
        assert_eq!(
            String::from_utf8_lossy(&output.stderr),
            "Cancel 1 workflow matching [customer-42]? [y/N] "
        );
        assert_eq!(server.posts().len(), usize::from(cancels), "{answer:?}");
        let stdout = String::from_utf8_lossy(&output.stdout);
        assert_eq!(
            stdout.contains("Batch cancellation aborted."),
            !cancels,
            "{stdout}"
        );
    }
    let server = selection();
    let output = scratch.run_with_stdin(
        &server.url,
        &["workflow:cancel", "--all-matching=customer-42", "-n"],
        "yes\n",
    );
    assert!(output.stderr.is_empty(), "-n never asks");
    assert!(server.posts().is_empty());
}

#[test]
fn identifiers_are_single_segments_and_dots_are_refused_before_any_request() {
    let scratch = Scratch::new();
    let server = Server::new(info(), Box::new(cancelled_any));
    let output = scratch.run(
        &server.url,
        &["workflow:terminate", "a/b?c", "--run-id=r/1", "--json"],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(
        server.lines()[0].to_lowercase(),
        "post /prefix/api/workflows/a%2fb%3fc/runs/r%2f1/terminate http/1.1"
    );
    for args in [
        vec!["workflow:cancel", ".."],
        vec!["workflow:cancel", "chk-42", "--run-id=."],
        vec!["workflow:terminate", "."],
        vec!["workflow:archive", ".."],
        vec!["workflow:repair", "."],
        vec!["workflow:redrive", "chk-42", ".."],
    ] {
        let server = Server::new(info(), Box::new(cancelled_any));
        let mut args = args.clone();
        args.push("--json");
        let output = scratch.run(&server.url, &args);
        assert_eq!(output.status.code(), Some(2), "{args:?}: {output:?}");
        assert!(server.lines().is_empty(), "{args:?}");
    }
}

fn cancelled_any(line: &str, _: usize) -> Reply {
    cancelled(line)
}

#[test]
fn compatibility_refusal_sends_no_command() {
    let scratch = Scratch::new();
    let mut incompatible = info();
    incompatible["control_plane"]["version"] = json!("99");
    let server = Server::new(incompatible, Box::new(cancelled_any));
    for args in [
        vec!["workflow:cancel", "chk-42"],
        vec!["workflow:cancel", "--all-matching=q", "--yes"],
        vec!["workflow:terminate", "chk-42"],
        vec!["workflow:archive", "chk-42"],
        vec!["workflow:repair", "chk-42"],
        vec!["workflow:redrive", "chk-42", "run-7"],
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
fn redirects_are_refused_without_following() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        Box::new(|_, _| Reply::Json(302, json!({"message": "moved"}))),
    );
    let output = scratch.run(&server.url, &["workflow:repair", "chk-42", "--json"]);
    assert_ne!(output.status.code(), Some(0));
    assert_eq!(server.lines().len(), 1);
}

#[test]
fn bodies_match_2x() {
    let scratch = Scratch::new();
    let server = Server::new(info(), Box::new(cancelled_any));
    for args in [
        vec!["workflow:archive", "chk-42"],
        vec!["workflow:archive", "chk-42", "--reason="],
        vec!["workflow:repair", "chk-42"],
        vec![
            "workflow:redrive",
            "chk-42",
            "run-7",
            "--request-id=incident-42",
        ],
        vec!["workflow:redrive", "chk-42", "run-7", "--request-id="],
    ] {
        let mut args = args.clone();
        args.push("--json");
        assert_eq!(
            scratch.run(&server.url, &args).status.code(),
            Some(0),
            "{args:?}"
        );
    }
    assert_eq!(server.body(0), json!([]));
    assert_eq!(server.body(1), json!({"reason": ""}));
    assert_eq!(server.body(2), json!([]));
    assert_eq!(server.body(3), json!({"request_id": "incident-42"}));
    assert_eq!(server.body(4), json!([]));
}

#[test]
fn migrate_v1_sends_the_unwrapped_projection_from_stdin() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        Box::new(|_, _| {
            Reply::Json(
                200,
                json!({"status": "dry_run", "control_plane": contract("import")}),
            )
        }),
    );
    let output = scratch.run_with_stdin(
        &server.url,
        &[
            "workflow:migrate-v1",
            "-",
            "--source-id= legacy ",
            "--dry-run",
            "--json",
        ],
        r#"{"data":{"id":42,"logs":{},"0":"kept"}}"#,
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(
        server.lines(),
        ["POST /prefix/api/workflows/import/waterline-v1 HTTP/1.1"]
    );
    assert_eq!(
        server.body(0),
        json!({"source_id": "legacy", "workflow": {"id": 42, "logs": [], "0": "kept"}, "dry_run": true})
    );
}

#[test]
fn migrate_v1_refuses_bad_input_before_any_request() {
    let scratch = Scratch::new();
    let server = Server::new(info(), Box::new(cancelled_any));
    for (stdin, args, message) in [
        (
            "{}",
            vec!["workflow:migrate-v1", "-", "--json"],
            "--source-id is required.",
        ),
        (
            "[1]",
            vec!["workflow:migrate-v1", "-", "--source-id=s", "--json"],
            "Waterline projection must be a JSON object.",
        ),
        (
            "{}",
            vec!["workflow:migrate-v1", "-", "--source-id=s", "--json"],
            "Waterline projection must be a JSON object.",
        ),
        (
            "{",
            vec!["workflow:migrate-v1", "-", "--source-id=s", "--json"],
            "Waterline projection must be valid JSON: Syntax error",
        ),
    ] {
        let output = scratch.run_with_stdin(&server.url, &args, stdin);
        assert_eq!(output.status.code(), Some(2), "{stdin}: {output:?}");
        assert_eq!(json_stdout(&output)["error"], message);
    }
    assert!(server.lines().is_empty());
}

#[test]
fn server_error_text_is_not_console_markup() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        Box::new(|_, _| Reply::Json(409, json!({"message": "<error>FORGED</error>\u{1b}[2J"}))),
    );
    let output = scratch.run(&server.url, &["workflow:terminate", "chk-42"]);
    assert_eq!(output.status.code(), Some(2), "{output:?}");
    let stderr = String::from_utf8(output.stderr).unwrap();
    assert!(
        stderr.contains("<error>FORGED</error>\\u{001b}[2J"),
        "{stderr}"
    );
}
