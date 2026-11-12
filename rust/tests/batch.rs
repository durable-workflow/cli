//! batch:cancel, batch:signal, batch:show and batch:resume (cli#60) against
//! a loopback Server that answers by request line and records every
//! request, with the manifest inspected between and during runs.

use serde_json::{json, Value};
use std::fs;
use std::io::{Read, Write};
use std::net::TcpListener;
use std::path::{Path, PathBuf};
use std::process::{Command, Output, Stdio};
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

struct Scratch(PathBuf);

impl Scratch {
    fn new() -> Self {
        static NEXT: AtomicU64 = AtomicU64::new(0);
        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        let path = std::env::temp_dir().join(format!(
            "dw-batch-{}-{now}-{}",
            std::process::id(),
            NEXT.fetch_add(1, Ordering::Relaxed)
        ));
        fs::create_dir(&path).unwrap();
        Self(path)
    }

    fn manifest(&self) -> PathBuf {
        self.0.join("batch.jsonl")
    }

    fn plain(&self, args: &[&str]) -> Command {
        let mut command = Command::new(env!("CARGO_BIN_EXE_dw"));
        command
            .args(args)
            .env_clear()
            .env("DW_CONFIG_HOME", &self.0)
            .env("HOME", &self.0)
            .stdin(Stdio::null());
        command
    }

    fn command(&self, url: &str, namespace: &str, args: &[&str]) -> Command {
        let mut command = self.plain(args);
        command.args([
            "--server",
            url,
            &format!("--namespace={namespace}"),
            "--token=fixture-token",
        ]);
        command
    }

    fn run(&self, url: &str, args: &[&str]) -> Output {
        self.command(url, "orders", args).output().unwrap()
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
    /// A response with a `Retry-After` header, in seconds.
    Retry(u16, u64),
    /// Close the connection without answering: a lost response.
    Drop,
    /// Answer after a delay.
    Slow(Duration, Value),
}

type Route = dyn Fn(&str, usize) -> Reply + Send + Sync;

struct Server {
    url: String,
    requests: Arc<Mutex<Vec<String>>>,
}

impl Server {
    fn new(route: Box<Route>) -> Self {
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
                let request = read_request(&mut stream);
                let line = request.lines().next().unwrap_or_default().to_owned();
                let reply = if line.starts_with("GET /prefix/api/cluster/info ") {
                    Reply::Json(200, info())
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
                let (status, body, retry_after) = match reply {
                    Reply::Drop => continue,
                    Reply::Json(status, body) => (status, body, None),
                    Reply::Retry(status, seconds) => {
                        (status, json!({"message": "Slow down."}), Some(seconds))
                    }
                    Reply::Slow(delay, body) => {
                        thread::sleep(delay);
                        (200, body, None)
                    }
                };
                let payload = serde_json::to_vec(&body).unwrap();
                let retry = retry_after
                    .map(|seconds| format!("Retry-After: {seconds}\r\n"))
                    .unwrap_or_default();
                let head = format!("HTTP/1.1 {status} Test\r\nX-Durable-Workflow-Control-Plane-Version: 2\r\n{retry}Content-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n", payload.len());
                let _ = stream.write_all(head.as_bytes());
                let _ = stream.write_all(&payload);
            }
        });
        Self { url, requests }
    }

    fn posts(&self) -> Vec<String> {
        self.requests
            .lock()
            .unwrap()
            .iter()
            .map(|request| request.lines().next().unwrap_or_default().to_owned())
            .filter(|line| line.starts_with("POST "))
            .collect()
    }

    fn post_bodies(&self) -> Vec<Value> {
        self.requests
            .lock()
            .unwrap()
            .iter()
            .filter(|request| request.starts_with("POST "))
            .map(|request| {
                serde_json::from_str(&request[request.find("\r\n\r\n").unwrap() + 4..]).unwrap()
            })
            .collect()
    }
}

fn read_request(stream: &mut std::net::TcpStream) -> String {
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
    String::from_utf8_lossy(&bytes).into_owned()
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

fn receipt(line: &str) -> Value {
    let operation = if line.contains("/signal/") {
        "signal"
    } else {
        "cancel"
    };
    json!({"outcome": "accepted", "command_id": "cmd-1", "control_plane": contract(operation)})
}

fn three() -> Value {
    json!([
        {"workflow_id": "wf-a", "run_id": "run-a"},
        {"workflow_id": "wf-b", "run_id": "run-b"},
        {"workflow_id": "wf-c", "run_id": "run-c"},
    ])
}

/// One list page of `workflows`; every mutation goes to `mutation`.
fn server(
    workflows: Value,
    mutation: impl Fn(&str, usize) -> Reply + Send + Sync + 'static,
) -> Server {
    Server::new(Box::new(move |line, earlier| {
        if line.starts_with("GET /prefix/api/workflows?") {
            page(workflows.clone(), None)
        } else {
            mutation(line, earlier)
        }
    }))
}

fn lines(path: &Path) -> Vec<Value> {
    fs::read_to_string(path)
        .unwrap()
        .lines()
        .map(|line| serde_json::from_str(line).unwrap())
        .collect()
}

fn json_stdout(output: &Output) -> Value {
    serde_json::from_slice(&output.stdout).unwrap_or_else(|_| panic!("{output:?}"))
}

fn start_cancel(scratch: &Scratch, url: &str) -> Output {
    scratch.run(
        url,
        &[
            "batch:cancel",
            "--query=customer-42",
            &format!("--manifest={}", scratch.manifest().display()),
            "--rate=100",
            "--yes",
            "--json",
        ],
    )
}

fn resume(scratch: &Scratch, url: &str) -> Output {
    scratch.run(
        url,
        &[
            "batch:resume",
            scratch.manifest().to_str().unwrap(),
            "--rate=100",
            "--yes",
            "--json",
        ],
    )
}

#[test]
fn cancel_records_the_selection_before_any_mutation_and_each_outcome() {
    let scratch = Scratch::new();
    let manifest = scratch.manifest();
    let seen_at_first_post = Arc::new(Mutex::new(None));
    let seen = Arc::clone(&seen_at_first_post);
    let path = manifest.clone();
    let server = server(three(), move |line, _| {
        let mut seen = seen.lock().unwrap();
        if seen.is_none() {
            *seen = Some(lines(&path));
        }
        Reply::Json(200, receipt(line))
    });
    let output = start_cancel(&scratch, &server.url);
    assert_eq!(output.status.code(), Some(0), "{output:?}");

    // When the first cancel arrived, the header held every target and the
    // first dispatch was already recorded.
    let early = seen_at_first_post.lock().unwrap().clone().unwrap();
    assert_eq!(early.len(), 2);
    assert_eq!(early[0]["schema"], "durable-workflow.cli.batch-manifest/v1");
    assert_eq!(early[0]["targets"].as_array().unwrap().len(), 3);
    assert_eq!(early[1]["event"], "dispatching");
    assert!(early[0].get("token").is_none());
    assert!(!fs::read_to_string(&manifest)
        .unwrap()
        .contains("fixture-token"));

    let header = &lines(&manifest)[0];
    assert_eq!(header["namespace"], "orders");
    assert_eq!(header["operation"], "cancel");
    assert_eq!(header["targets"][1]["run_id"], "run-b");
    let id = header["manifest_id"].as_str().unwrap();
    assert_eq!(id.len(), 32);
    assert_eq!(header["targets"][2]["command_key"], format!("batch-{id}-2"));
    let events: Vec<(String, u64)> = lines(&manifest)[1..]
        .iter()
        .map(|event| {
            (
                event["event"].as_str().unwrap().to_owned(),
                event["index"].as_u64().unwrap(),
            )
        })
        .collect();
    assert_eq!(
        events,
        [
            ("dispatching".into(), 0),
            ("outcome".into(), 0),
            ("dispatching".into(), 1),
            ("outcome".into(), 1),
            ("dispatching".into(), 2),
            ("outcome".into(), 2)
        ]
    );
    assert_eq!(
        server.posts(),
        [
            "POST /prefix/api/workflows/wf-a/runs/run-a/cancel HTTP/1.1",
            "POST /prefix/api/workflows/wf-b/runs/run-b/cancel HTTP/1.1",
            "POST /prefix/api/workflows/wf-c/runs/run-c/cancel HTTP/1.1",
        ]
    );
    let summary = json_stdout(&output);
    assert_eq!(summary["counts"]["acknowledged"], 3);
    assert_eq!(summary["complete"], true);

    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        let mode = fs::metadata(&manifest).unwrap().permissions().mode();
        assert_eq!(mode & 0o777, 0o600);
    }
}

#[test]
fn an_existing_manifest_is_refused_before_any_request() {
    let scratch = Scratch::new();
    fs::write(scratch.manifest(), "keep me\n").unwrap();
    let server = server(three(), |line, _| Reply::Json(200, receipt(line)));
    let output = start_cancel(&scratch, &server.url);
    assert_eq!(output.status.code(), Some(2), "{output:?}");
    assert!(server.requests.lock().unwrap().is_empty());
    assert_eq!(fs::read_to_string(scratch.manifest()).unwrap(), "keep me\n");
}

#[test]
fn an_unknown_outcome_stops_the_batch_and_resume_never_resends_it() {
    let scratch = Scratch::new();
    let server = server(three(), |line, _| {
        if line.contains("/wf-b/") && !line.contains("resumed") {
            Reply::Drop
        } else {
            Reply::Json(200, receipt(line))
        }
    });
    let first = start_cancel(&scratch, &server.url);
    assert_eq!(first.status.code(), Some(1), "{first:?}");
    let summary = json_stdout(&first);
    assert_eq!(summary["stopped"]["reason"], "unknown");
    let states: Vec<&str> = summary["targets"]
        .as_array()
        .unwrap()
        .iter()
        .map(|target| target["state"].as_str().unwrap())
        .collect();
    assert_eq!(states, ["acknowledged", "unknown", "pending"]);

    // A cold process continues with wf-c only.
    let second = resume(&scratch, &server.url);
    assert_eq!(second.status.code(), Some(1), "{second:?}");
    let summary = json_stdout(&second);
    assert_eq!(summary["counts"]["acknowledged"], 2);
    assert_eq!(summary["counts"]["unknown"], 1);
    let posts = server.posts();
    assert_eq!(
        posts.iter().filter(|post| post.contains("/wf-b/")).count(),
        1
    );
    assert_eq!(
        posts.iter().filter(|post| post.contains("/wf-c/")).count(),
        1
    );
    assert_eq!(posts.len(), 3);

    // Nothing left to send: resume makes no requests at all.
    let third = resume(&scratch, &server.url);
    assert_eq!(third.status.code(), Some(1), "{third:?}");
    assert_eq!(server.posts().len(), 3);
}

#[test]
fn pressure_is_retried_with_the_same_key_and_bounded() {
    let scratch = Scratch::new();
    let server = server(three(), |line, earlier| {
        if line.contains("/wf-a/") && earlier < 2 {
            Reply::Retry(429, 0)
        } else if line.contains("/wf-b/") {
            Reply::Retry(503, 0)
        } else {
            Reply::Json(200, receipt(line))
        }
    });
    let output = scratch.run(
        &server.url,
        &[
            "batch:signal",
            "approve",
            "--query=queue-7",
            "-i",
            r#"{"amount":1.5,"note":"a/b"}"#,
            &format!("--manifest={}", scratch.manifest().display()),
            "--rate=100",
            "--yes",
            "--json",
        ],
    );
    assert_eq!(output.status.code(), Some(1), "{output:?}");
    let summary = json_stdout(&output);
    assert_eq!(summary["stopped"]["reason"], "pressure");
    assert_eq!(summary["targets"][0]["state"], "acknowledged");
    assert_eq!(summary["targets"][0]["attempts"], 3);
    assert_eq!(summary["targets"][1]["state"], "pressure");
    assert_eq!(summary["targets"][1]["attempts"], 3);
    assert_eq!(summary["targets"][2]["state"], "pending");

    let header = &lines(&scratch.manifest())[0];
    let id = header["manifest_id"].as_str().unwrap();
    assert_eq!(
        header["parameters"]["input"],
        json!({"amount": 1.5, "note": "a/b"})
    );
    let bodies = server.post_bodies();
    assert_eq!(bodies.len(), 6);
    for body in &bodies[..3] {
        assert_eq!(body["request_id"], format!("batch-{id}-0"));
    }
    for body in &bodies[3..] {
        assert_eq!(body["request_id"], format!("batch-{id}-1"));
        assert_eq!(body["input"], json!({"amount": 1.5, "note": "a/b"}));
    }

    // Resume sends wf-c with its own key and the recorded input, and does
    // not send wf-b again: it has used its attempts.
    let resumed = resume(&scratch, &server.url);
    assert_eq!(resumed.status.code(), Some(1), "{resumed:?}");
    let bodies = server.post_bodies();
    assert_eq!(bodies.len(), 7);
    assert_eq!(bodies[6]["request_id"], format!("batch-{id}-2"));
    assert_eq!(bodies[6]["input"], json!({"amount": 1.5, "note": "a/b"}));
    assert!(server.posts()[6].contains("/workflows/wf-c/runs/run-c/signal/approve "));
}

#[test]
fn an_authentication_refusal_leaves_the_target_pending_for_resume() {
    let scratch = Scratch::new();
    let refused = Arc::new(Mutex::new(true));
    let gate = Arc::clone(&refused);
    let server = server(three(), move |line, _| {
        if *gate.lock().unwrap() && line.contains("/wf-b/") {
            Reply::Json(401, json!({"message": "Unauthenticated."}))
        } else {
            Reply::Json(200, receipt(line))
        }
    });
    let first = start_cancel(&scratch, &server.url);
    let summary = json_stdout(&first);
    assert_eq!(summary["stopped"]["reason"], "authentication");
    assert_eq!(summary["counts"]["pending"], 2);
    *refused.lock().unwrap() = false;
    let second = resume(&scratch, &server.url);
    assert_eq!(second.status.code(), Some(0), "{second:?}");
    assert_eq!(json_stdout(&second)["counts"]["acknowledged"], 3);
    assert_eq!(
        server
            .posts()
            .iter()
            .filter(|post| post.contains("/wf-b/"))
            .count(),
        2
    );
}

#[test]
fn a_process_killed_mid_request_leaves_an_unknown_target_that_resume_does_not_resend() {
    let scratch = Scratch::new();
    let server = server(three(), |line, earlier| {
        if line.contains("/wf-a/") && earlier == 0 {
            Reply::Slow(Duration::from_secs(3), receipt(line))
        } else {
            Reply::Json(200, receipt(line))
        }
    });
    let mut child = scratch
        .command(
            &server.url,
            "orders",
            &[
                "batch:cancel",
                "--query=customer-42",
                &format!("--manifest={}", scratch.manifest().display()),
                "--yes",
                "--json",
            ],
        )
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .spawn()
        .unwrap();
    let deadline = Instant::now() + Duration::from_secs(10);
    while server.posts().is_empty() {
        assert!(Instant::now() < deadline, "the batch never dispatched");
        thread::sleep(Duration::from_millis(20));
    }
    child.kill().unwrap();
    child.wait().unwrap();
    let recorded = lines(&scratch.manifest());
    assert_eq!(recorded.last().unwrap()["event"], "dispatching");

    let show = scratch
        .plain(&["batch:show", scratch.manifest().to_str().unwrap(), "--json"])
        .output()
        .unwrap();
    assert_eq!(json_stdout(&show)["targets"][0]["state"], "unknown");

    let resumed = resume(&scratch, &server.url);
    let summary = json_stdout(&resumed);
    assert_eq!(summary["targets"][0]["state"], "unknown");
    assert_eq!(summary["targets"][0]["reason"], "interrupted");
    assert_eq!(summary["counts"]["acknowledged"], 2);
    assert_eq!(
        server
            .posts()
            .iter()
            .filter(|post| post.contains("/wf-a/"))
            .count(),
        1
    );
}

#[test]
fn a_successor_seen_on_a_later_page_keeps_the_first_run() {
    let scratch = Scratch::new();
    let server = Server::new(Box::new(|line, _| {
        if line.starts_with("GET /prefix/api/workflows?") {
            if line.contains("next_page_token=page-1") {
                page(
                    json!([{"workflow_id": "wf-a", "run_id": "run-a2"}, {"workflow_id": "wf-b", "run_id": "run-b"}]),
                    None,
                )
            } else {
                page(
                    json!([{"workflow_id": "wf-a", "run_id": "run-a1"}]),
                    Some("page-1"),
                )
            }
        } else {
            Reply::Json(200, receipt(line))
        }
    }));
    let output = start_cancel(&scratch, &server.url);
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    let header = &lines(&scratch.manifest())[0];
    assert_eq!(header["targets"][0]["run_id"], "run-a1");
    assert_eq!(header["targets"][1]["workflow_id"], "wf-b");
    assert_eq!(header["targets"].as_array().unwrap().len(), 2);
    assert!(server.posts()[0].contains("/wf-a/runs/run-a1/cancel"));
}

#[test]
fn resume_refuses_another_server_or_namespace_without_sending() {
    let scratch = Scratch::new();
    let server = server(three(), |line, _| {
        if line.contains("/wf-c/") {
            Reply::Json(500, json!({"message": "boom"}))
        } else {
            Reply::Json(200, receipt(line))
        }
    });
    start_cancel(&scratch, &server.url);
    let posts = server.posts().len();
    let other_namespace = scratch
        .command(
            &server.url,
            "billing",
            &[
                "batch:resume",
                scratch.manifest().to_str().unwrap(),
                "--yes",
            ],
        )
        .output()
        .unwrap();
    assert_eq!(
        other_namespace.status.code(),
        Some(2),
        "{other_namespace:?}"
    );
    let other_server = scratch.run(
        "http://127.0.0.1:9/prefix",
        &[
            "batch:resume",
            scratch.manifest().to_str().unwrap(),
            "--yes",
        ],
    );
    assert_eq!(other_server.status.code(), Some(2), "{other_server:?}");
    assert_eq!(server.posts().len(), posts);
}

#[test]
fn show_reads_the_manifest_without_contacting_anyone() {
    let scratch = Scratch::new();
    let server = server(three(), |line, _| Reply::Json(200, receipt(line)));
    start_cancel(&scratch, &server.url);
    let requests = server.requests.lock().unwrap().len();
    let show = scratch
        .plain(&["batch:show", scratch.manifest().to_str().unwrap(), "--json"])
        .output()
        .unwrap();
    assert_eq!(show.status.code(), Some(0), "{show:?}");
    let summary = json_stdout(&show);
    assert_eq!(summary["counts"]["acknowledged"], 3);
    assert_eq!(summary["operation"], "cancel");
    let human = scratch
        .plain(&["batch:show", scratch.manifest().to_str().unwrap()])
        .output()
        .unwrap();
    assert!(String::from_utf8_lossy(&human.stdout).contains("Acknowledged: 3"));
    assert_eq!(server.requests.lock().unwrap().len(), requests);
}

#[test]
fn bounds_and_json_confirmation_are_checked_before_anything_is_written() {
    let scratch = Scratch::new();
    let server = server(three(), |line, _| Reply::Json(200, receipt(line)));
    let manifest = format!("--manifest={}", scratch.manifest().display());
    for args in [
        vec!["batch:cancel", "--query=q", manifest.as_str(), "--json"],
        vec![
            "batch:cancel",
            "--query=q",
            manifest.as_str(),
            "--limit=1001",
            "--yes",
        ],
        vec![
            "batch:cancel",
            "--query=q",
            manifest.as_str(),
            "--rate=0",
            "--yes",
        ],
        vec!["batch:cancel", "--query= ", manifest.as_str(), "--yes"],
        vec!["batch:cancel", "--query=q", "--yes"],
        vec!["batch:signal", " ", "--query=q", manifest.as_str(), "--yes"],
    ] {
        let output = scratch.run(&server.url, &args);
        assert_eq!(output.status.code(), Some(2), "{args:?}: {output:?}");
    }
    assert!(!scratch.manifest().exists());
    assert!(server.posts().is_empty());
}

#[test]
fn declining_the_prompt_writes_nothing() {
    let scratch = Scratch::new();
    let server = server(three(), |line, _| Reply::Json(200, receipt(line)));
    let output = scratch.run(
        &server.url,
        &[
            "batch:cancel",
            "--query=customer-42",
            &format!("--manifest={}", scratch.manifest().display()),
        ],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert!(String::from_utf8_lossy(&output.stdout).contains("No manifest was written"));
    assert!(!scratch.manifest().exists());
    assert!(server.posts().is_empty());
}
