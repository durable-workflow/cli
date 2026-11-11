//! search-attribute:*, storage:test, namespace:set-storage-driver,
//! task-queue:* and worker:* against a loopback Server that answers each
//! request from a queue, so request counts, paths and bodies are observable.

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
            "dw-queues-workers-{}-{now}-{}",
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

#[test]
fn names_are_single_encoded_path_segments() {
    let scratch = Scratch::new();
    for (args, line) in [
        (
            vec!["task-queue:describe", "a/b?c#d"],
            "GET /prefix/api/task-queues/a%2Fb%3Fc%23d HTTP/1.1",
        ),
        (
            vec!["task-queue:build-ids", "a/b"],
            "GET /prefix/api/task-queues/a%2Fb/build-ids HTTP/1.1",
        ),
        (
            vec!["task-queue:drain", "a/b", "--build-id=x/y"],
            "POST /prefix/api/task-queues/a%2Fb/build-ids/drain HTTP/1.1",
        ),
        (
            vec!["worker:describe", "w/1"],
            "GET /prefix/api/workers/w%2F1 HTTP/1.1",
        ),
        (
            vec!["worker:deregister", "w?1"],
            "DELETE /prefix/api/workers/w%3F1 HTTP/1.1",
        ),
        (
            vec!["search-attribute:delete", "Order/Status"],
            "DELETE /prefix/api/search-attributes/Order%2FStatus HTTP/1.1",
        ),
        (
            vec!["namespace:set-storage-driver", "bill/ing", "s3"],
            "PUT /prefix/api/namespaces/bill%2Fing/external-storage HTTP/1.1",
        ),
    ] {
        let server = Server::new(info(), vec![ok(json!({"name": "x"}))]);
        let mut args = args.clone();
        args.push("--json");
        json_stdout(&scratch.run(&server.url, &args));
        let lines = server.request_lines();
        assert_eq!(lines.len(), 1, "{args:?}");
        assert_eq!(lines[0], line, "{args:?}");
        assert!(server.requests()[0].contains("Bearer fixture-token"));
    }
}

#[test]
fn empty_and_dot_names_are_refused_before_any_request() {
    let scratch = Scratch::new();
    for args in [
        vec!["task-queue:describe", ".."],
        vec!["task-queue:build-ids", "."],
        vec!["task-queue:promote", "", "--build-id=b"],
        vec!["worker:describe", ".."],
        vec!["worker:deregister", "."],
        vec!["search-attribute:delete", ".."],
        vec!["namespace:set-storage-driver", "..", "s3"],
    ] {
        let server = Server::new(info(), vec![ok(json!({}))]);
        let mut args = args.clone();
        args.push("--json");
        let output = scratch.run(&server.url, &args);
        assert_eq!(output.status.code(), Some(2), "{args:?}: {output:?}");
        assert!(server.requests().is_empty(), "{args:?}");
    }
}

#[test]
fn a_lost_mutation_response_is_never_resent() {
    let scratch = Scratch::new();
    for args in [
        vec!["search-attribute:create", "OrderStatus", "keyword"],
        vec!["search-attribute:delete", "OrderStatus"],
        vec!["storage:test"],
        vec!["namespace:set-storage-driver", "billing", "s3"],
        vec!["task-queue:drain", "orders", "--build-id=b"],
        vec!["task-queue:promote", "orders", "--unversioned"],
        vec!["task-queue:resume", "orders", "--build-id=b"],
        vec!["worker:register", "w-1"],
        vec!["worker:deregister", "w-1"],
    ] {
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
        vec!["task-queue:list"],
        vec!["task-queue:drain", "orders", "--build-id=b"],
        vec!["worker:register"],
    ] {
        let server = Server::new(
            info(),
            vec![Reply {
                status: 302,
                headers: vec![("Location", "/elsewhere")],
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
fn invalid_options_are_refused_before_any_request() {
    let scratch = Scratch::new();
    for (args, code) in [
        // 2.x raises these as console usage errors: exit 1.
        (vec!["task-queue:drain", "orders"], 1),
        (
            vec![
                "task-queue:resume",
                "orders",
                "--unversioned",
                "--build-id=b",
            ],
            1,
        ),
        (vec!["storage:test", "--small-bytes=0"], 1),
        (vec!["storage:test", "--driver=ftp"], 1),
        (vec!["namespace:set-storage-driver", "billing", "ftp"], 1),
        (
            vec![
                "namespace:set-storage-driver",
                "billing",
                "s3",
                "--threshold-bytes=1.5",
            ],
            1,
        ),
        (vec!["worker:register", "--max-activity-tasks=0"], 2),
    ] {
        let server = Server::new(info(), vec![ok(json!({}))]);
        let mut args = args.clone();
        args.push("--json");
        let output = scratch.run(&server.url, &args);
        assert_eq!(output.status.code(), Some(code), "{args:?}: {output:?}");
        assert!(server.requests().is_empty(), "{args:?}");
    }
}

#[test]
fn mutation_bodies_match_2x() {
    let scratch = Scratch::new();
    let server = Server::new(info(), vec![ok(json!({}))]);
    json_stdout(&scratch.run(
        &server.url,
        &["task-queue:drain", "orders", "--unversioned", "--json"],
    ));
    assert_eq!(server.body(0), json!({"build_id": null}));

    let server = Server::new(info(), vec![ok(json!({}))]);
    json_stdout(&scratch.run(
        &server.url,
        &[
            "storage:test",
            "--driver= gcs ",
            "--small-bytes=7",
            "--large-bytes=9000",
            "--json",
        ],
    ));
    assert_eq!(
        server.body(0),
        json!({"small_payload_bytes": 7, "large_payload_bytes": 9000, "driver": "gcs"})
    );

    let server = Server::new(info(), vec![ok(json!({}))]);
    json_stdout(&scratch.run(
        &server.url,
        &[
            "namespace:set-storage-driver",
            "billing",
            " s3 ",
            "--threshold-bytes=4096",
            "--bucket= dw ",
            "--auth-profile=prod",
            "--region=",
            "--disable",
            "--json",
        ],
    ));
    assert_eq!(
        serde_json::to_string(&server.body(0)).unwrap(),
        r#"{"driver":"s3","enabled":false,"threshold_bytes":4096,"config":{"bucket":"dw","auth_profile":"prod"}}"#
    );
}

#[test]
fn worker_register_sends_the_2x_body_and_checks_the_worker_protocol() {
    let scratch = Scratch::new();
    let registered = Reply {
        status: 200,
        headers: vec![("X-Durable-Workflow-Protocol-Version", "1.20")],
        body: Some(json!({"registered": true, "worker_id": "w-1", "protocol_version": "1.20"})),
    };
    let server = Server::new(info(), vec![registered.clone()]);
    let result = json_stdout(&scratch.run(
        &server.url,
        &[
            "worker:register",
            "0",
            "--runtime= ",
            "--workflow-type= a ",
            "--workflow-type=",
            "--activity-type=b",
            "--max-workflow-tasks=3",
            "--json",
        ],
    ));
    assert_eq!(result["worker_id"], "w-1");
    assert_eq!(
        server.request_lines(),
        ["POST /prefix/api/worker/register HTTP/1.1"]
    );
    let body = server.body(0);
    assert_eq!(
        body.as_object().unwrap().keys().collect::<Vec<_>>(),
        [
            "task_queue",
            "runtime",
            "sdk_version",
            "capability_manifest",
            "supported_workflow_types",
            "supported_activity_types",
            "max_concurrent_workflow_tasks",
        ]
    );
    assert_eq!(body["task_queue"], "default");
    assert_eq!(body["runtime"], "external");
    assert_eq!(body["sdk_version"], "dw-cli");
    assert_eq!(body["supported_workflow_types"], json!(["a"]));
    assert_eq!(
        body["capability_manifest"]["sticky_execution"]["reason"],
        "external_diagnostic_worker_uses_complete_durable_history_replay"
    );

    for (headers, body, message) in [
        (
            vec![],
            json!({"registered": true, "protocol_version": "1.20"}),
            "invalid worker-protocol response version for [/worker/register]; expected same-major server minor compatible with [1.0], got [missing].",
        ),
        (
            vec![("X-Durable-Workflow-Protocol-Version", "1.20")],
            json!({"registered": true, "protocol_version": "1.2"}),
            "invalid worker-protocol response body for [/worker/register]",
        ),
        (
            vec![("X-Durable-Workflow-Protocol-Version", "1.20")],
            json!({"registered": 1, "protocol_version": "1.20"}),
            "Worker registration was not confirmed by the server",
        ),
    ] {
        let server = Server::new(
            info(),
            vec![Reply {
                status: 200,
                headers,
                body: Some(body),
            }],
        );
        let output = scratch.run(&server.url, &["worker:register", "w"]);
        assert_eq!(output.status.code(), Some(1), "{output:?}");
        let stderr = String::from_utf8(output.stderr).unwrap();
        assert!(stderr.contains(message), "{stderr}");
        assert_eq!(server.requests().len(), 1);
    }
}

#[test]
fn worker_commands_refuse_a_server_without_the_worker_protocol() {
    let scratch = Scratch::new();
    let mut info = info();
    info.as_object_mut().unwrap().remove("worker_protocol");
    let server = Server::new(info, vec![ok(json!({"workers": []}))]);
    for args in [
        vec!["worker:list"],
        vec!["worker:describe", "w-1"],
        vec!["worker:register"],
        vec!["worker:deregister", "w-1"],
    ] {
        let output = scratch.run(&server.url, &args);
        assert_eq!(output.status.code(), Some(8), "{args:?}: {output:?}");
    }
    assert!(server.requests().is_empty());
}

#[test]
fn worker_list_sends_only_the_given_filters() {
    let scratch = Scratch::new();
    let server = Server::new(info(), vec![ok(json!({"workers": []}))]);
    json_stdout(&scratch.run(
        &server.url,
        &["worker:list", "--task-queue=orders", "--status", "--json"],
    ));
    assert_eq!(
        server.request_lines(),
        ["GET /prefix/api/workers?task_queue=orders HTTP/1.1"]
    );
}

#[test]
fn server_text_in_human_output_is_not_console_markup() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        vec![ok(json!({
            "task_queues": [{"name": "<error>FORGED</error>\u{1b}[2J"}]
        }))],
    );
    let output = scratch.run(&server.url, &["task-queue:list"]);
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    let stdout = String::from_utf8(output.stdout).unwrap();
    assert!(
        stdout.contains("<error>FORGED</error>\\u{001b}[2J"),
        "{stdout}"
    );

    let server = Server::new(
        info(),
        vec![Reply {
            status: 404,
            headers: Vec::new(),
            body: Some(json!({"message": "<error>FORGED</error>"})),
        }],
    );
    let output = scratch.run(&server.url, &["worker:describe", "w-1"]);
    assert_eq!(output.status.code(), Some(5), "{output:?}");
    let stderr = String::from_utf8(output.stderr).unwrap();
    assert!(stderr.contains("<error>FORGED</error>"), "{stderr}");
}
