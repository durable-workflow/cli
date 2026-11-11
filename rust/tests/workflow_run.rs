//! workflow:describe, workflow:show-run, workflow:history and
//! workflow:history-export against a loopback Server that answers each
//! request from a queue, so paging and --follow are observable.

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
            "dw-workflow-run-{}-{now}-{}",
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

struct Reply {
    status: u16,
    control_plane: bool,
    body: Value,
}

fn ok(body: Value) -> Reply {
    Reply {
        status: 200,
        control_plane: true,
        body,
    }
}

/// Cluster info is always the published PHP 2.11.8 document. Every other
/// request takes the next queued reply; the last one repeats.
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
                let mut bytes = Vec::new();
                let mut buffer = [0; 2048];
                while !bytes.windows(4).any(|part| part == b"\r\n\r\n") {
                    match stream.read(&mut buffer) {
                        Ok(0) | Err(_) => break,
                        Ok(size) => bytes.extend_from_slice(&buffer[..size]),
                    }
                }
                let request = String::from_utf8_lossy(&bytes).into_owned();
                let (status, control_plane, body) =
                    if request.starts_with("GET /prefix/api/cluster/info ") {
                        (200, true, info.clone())
                    } else {
                        observed.lock().unwrap().push(request);
                        let mut queue = queue.lock().unwrap();
                        let reply = if queue.len() > 1 {
                            queue.pop_front().unwrap()
                        } else {
                            let last = queue.front().unwrap();
                            Reply {
                                status: last.status,
                                control_plane: last.control_plane,
                                body: last.body.clone(),
                            }
                        };
                        (reply.status, reply.control_plane, reply.body)
                    };
                let payload = serde_json::to_vec(&body).unwrap();
                let version = if control_plane {
                    "X-Durable-Workflow-Control-Plane-Version: 2\r\n"
                } else {
                    ""
                };
                let head = format!("HTTP/1.1 {status} Test\r\n{version}Content-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n", payload.len());
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

fn run_body(status: &str) -> Value {
    json!({
        "workflow_id": "chk-42",
        "run_id": "run-7",
        "status": status,
        "control_plane": contract("describe"),
    })
}

fn json_stdout(output: &Output) -> Value {
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    serde_json::from_slice(&output.stdout).unwrap()
}

#[test]
fn describe_encodes_identifiers_as_single_path_segments() {
    let scratch = Scratch::new();
    let server = Server::new(info(), vec![ok(run_body("running"))]);
    let result = json_stdout(&scratch.run(
        &server.url,
        &["workflow:describe", "a/b?c#d", "--run-id=r/1", "--json"],
    ));
    assert_eq!(result["namespace"], "orders");
    let requests = server.requests();
    assert_eq!(requests.len(), 1);
    assert!(requests[0]
        .to_lowercase()
        .starts_with("get /prefix/api/workflows/a%2fb%3fc%23d/runs/r%2f1 http/1.1"));
    assert!(requests[0].contains("Bearer fixture-token"));
}

#[test]
fn describe_without_a_truthy_run_id_reads_the_current_run() {
    let scratch = Scratch::new();
    for run_id in ["--run-id=0", "--run-id="] {
        let server = Server::new(info(), vec![ok(run_body("running"))]);
        json_stdout(&scratch.run(
            &server.url,
            &["workflow:describe", "chk-42", run_id, "--json"],
        ));
        assert_eq!(
            server.request_lines(),
            ["GET /prefix/api/workflows/chk-42 HTTP/1.1"]
        );
    }
}

#[test]
fn empty_and_dot_identifiers_are_refused_before_any_workflow_request() {
    let scratch = Scratch::new();
    for args in [
        vec!["workflow:describe", ".."],
        vec!["workflow:show-run", "chk-42", "."],
        vec!["workflow:history", "", "run-7"],
        vec!["workflow:history-export", "chk-42", ".."],
    ] {
        let server = Server::new(info(), vec![ok(run_body("running"))]);
        let mut args = args.clone();
        args.push("--output=json");
        let output = scratch.run(&server.url, &args);
        assert_eq!(output.status.code(), Some(2), "{args:?}: {output:?}");
        assert!(server.requests().is_empty(), "{args:?}");
    }
}

#[test]
fn follow_polls_until_the_run_is_terminal() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        vec![ok(run_body("running")), ok(run_body("completed"))],
    );
    let output = scratch.run(
        &server.url,
        &["workflow:show-run", "chk-42", "run-7", "--follow"],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    let stdout = String::from_utf8(output.stdout).unwrap();
    assert!(stdout.starts_with("Following run (status: running)...\n  Status changed: running -> completed\n\nWorkflow Run\n"), "{stdout}");
    assert_eq!(server.requests().len(), 2);
}

#[test]
fn compatibility_refusal_sends_no_workflow_request() {
    let scratch = Scratch::new();
    let mut incompatible = info();
    incompatible["control_plane"]["version"] = json!("99");
    let server = Server::new(incompatible, vec![ok(run_body("running"))]);
    for args in [
        vec!["workflow:describe", "chk-42"],
        vec!["workflow:show-run", "chk-42", "run-7"],
        vec!["workflow:history", "chk-42", "run-7"],
        vec!["workflow:history-export", "chk-42", "run-7"],
    ] {
        assert_eq!(scratch.run(&server.url, &args).status.code(), Some(8));
    }
    assert!(server.requests().is_empty());
}

#[test]
fn redirects_are_refused_without_following() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        vec![Reply {
            status: 302,
            control_plane: true,
            body: json!({"message": "moved"}),
        }],
    );
    let output = scratch.run(&server.url, &["workflow:describe", "chk-42", "--json"]);
    assert_ne!(output.status.code(), Some(0));
    assert_eq!(server.requests().len(), 1);
}

fn history_page(events: Value, token: Option<&str>) -> Reply {
    ok(json!({
        "workflow_id": "chk-42",
        "run_id": "run-7",
        "events": events,
        "next_page_token": token,
        "control_plane": contract("history"),
    }))
}

#[test]
fn history_follows_every_page_and_returns_one_envelope() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        vec![
            history_page(json!([{"sequence": 1}, {"sequence": 2}]), Some("page/2")),
            history_page(json!([{"sequence": 3, "namespace": "other"}]), None),
        ],
    );
    let result = json_stdout(&scratch.run(
        &server.url,
        &[
            "workflow:history",
            "chk-42",
            "run-7",
            "--page-size=2",
            "--json",
        ],
    ));
    assert_eq!(
        result["events"],
        json!([
            {"sequence": 1, "namespace": "orders"},
            {"sequence": 2, "namespace": "orders"},
            {"sequence": 3, "namespace": "other"},
        ])
    );
    assert!(result.get("next_page_token").is_none());
    assert_eq!(result["namespace"], "orders");
    assert_eq!(
        server.request_lines(),
        [
            "GET /prefix/api/workflows/chk-42/runs/run-7/history?wait_new_event=0&page_size=2 HTTP/1.1",
            "GET /prefix/api/workflows/chk-42/runs/run-7/history?wait_new_event=0&page_size=2&next_page_token=page%2F2 HTTP/1.1",
        ]
    );
}

#[test]
fn history_jsonl_streams_each_event_and_follow_asks_for_new_events() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        vec![
            history_page(json!([{"sequence": 1}]), Some("next")),
            history_page(json!([{"sequence": 2}]), None),
        ],
    );
    let output = scratch.run(
        &server.url,
        &[
            "workflow:history",
            "chk-42",
            "run-7",
            "--follow",
            "--output=jsonl",
        ],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(
        String::from_utf8(output.stdout).unwrap(),
        "{\"sequence\":1,\"namespace\":\"orders\"}\n{\"sequence\":2,\"namespace\":\"orders\"}\n"
    );
    assert!(server.request_lines()[0].contains("?wait_new_event=1 "));
}

#[test]
fn history_export_writes_the_bundle_to_a_file_and_confirms_on_stderr() {
    let scratch = Scratch::new();
    let bundle = json!({"schema": "durable-workflow.v2.history-export", "history_events": [], "payloads": {}});
    let server = Server::new(
        info(),
        vec![Reply {
            status: 200,
            control_plane: false,
            body: bundle,
        }],
    );
    let file = scratch.0.join("bundle.json");
    let output = scratch.run(
        &server.url,
        &[
            "workflow:history-export",
            "chk-42",
            "run-7",
            "-o",
            file.to_str().unwrap(),
        ],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert!(output.stdout.is_empty());
    assert_eq!(
        String::from_utf8(output.stderr).unwrap(),
        format!("Exported namespace orders to {}\n", file.display())
    );
    assert_eq!(
        fs::read_to_string(&file).unwrap(),
        "{\n    \"schema\": \"durable-workflow.v2.history-export\",\n    \"history_events\": [],\n    \"payloads\": [],\n    \"namespace\": \"orders\"\n}\n"
    );
    assert_eq!(
        server.request_lines(),
        ["GET /prefix/api/workflows/chk-42/runs/run-7/history/export HTTP/1.1"]
    );
}

#[test]
fn server_error_text_is_not_console_markup() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        vec![Reply {
            status: 404,
            control_plane: true,
            body: json!({"message": "<error>FORGED</error>\u{1b}[2J"}),
        }],
    );
    let output = scratch.run(&server.url, &["workflow:describe", "chk-42"]);
    assert_eq!(output.status.code(), Some(5), "{output:?}");
    let stderr = String::from_utf8(output.stderr).unwrap();
    assert!(
        stderr.contains("<error>FORGED</error>\\u{001b}[2J"),
        "{stderr}"
    );
}

#[test]
fn responses_nested_deeper_than_128_levels_decode_like_php() {
    // 2.x's json_decode reads up to 512 levels; serde_json alone stops at 128.
    let scratch = Scratch::new();
    let mut memo = json!(1);
    for _ in 0..300 {
        memo = json!([memo]);
    }
    let mut body = run_body("running");
    body["memo"] = memo;
    let server = Server::new(info(), vec![ok(body)]);
    let output = scratch.run(&server.url, &["workflow:describe", "chk-42", "--json"]);
    let stdout = String::from_utf8(output.stdout.clone()).unwrap();
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert!(
        stdout.contains(&format!("\"memo\":{}1{}", "[".repeat(300), "]".repeat(300))),
        "{}",
        &stdout[..stdout.len().min(200)]
    );
}
