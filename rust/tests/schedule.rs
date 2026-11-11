//! `schedule:*` against a loopback Server: exact requests, one attempt per
//! page or mutation, the 2.x envelopes and exit codes.

use serde_json::{json, Value};
use std::fs;
use std::io::{Read, Write};
use std::net::TcpListener;
use std::path::PathBuf;
use std::process::{Command, Output, Stdio};
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::thread::{self, JoinHandle};
use std::time::{Duration, SystemTime, UNIX_EPOCH};

struct Scratch(PathBuf);

impl Scratch {
    fn new() -> Self {
        static NEXT: AtomicU64 = AtomicU64::new(0);
        let nonce = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        let path = std::env::temp_dir().join(format!(
            "dw-schedule-{}-{nonce}-{}",
            std::process::id(),
            NEXT.fetch_add(1, Ordering::Relaxed)
        ));
        fs::create_dir(&path).unwrap();
        Self(path)
    }

    fn run(&self, url: &str, args: &[&str]) -> Output {
        self.run_with_stdin(url, args, "")
    }

    fn run_with_stdin(&self, url: &str, args: &[&str], stdin: &str) -> Output {
        let mut child = Command::new(env!("CARGO_BIN_EXE_dw"))
            .args(args)
            .args([
                "--server",
                url,
                "--namespace=orders",
                "--token=fixture-token",
            ])
            .env_clear()
            .env("HOME", &self.0)
            .env("DW_CONFIG_HOME", &self.0)
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

/// One recorded request: request line, headers and body.
#[derive(Clone, Debug)]
struct Request {
    line: String,
    head: String,
    body: String,
}

impl Request {
    fn json(&self) -> Value {
        serde_json::from_str(&self.body).unwrap()
    }
}

/// A loopback Server. Cluster info always answers with `info`; every other
/// request takes the next scripted response, the last one repeating.
/// Status 0 closes the connection without a response.
struct Server {
    url: String,
    requests: Arc<Mutex<Vec<Request>>>,
    stop: Arc<AtomicBool>,
    thread: Option<JoinHandle<()>>,
}

impl Server {
    fn new(info: Value, responses: Vec<(u16, String)>) -> Self {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let url = format!("http://{}/prefix", listener.local_addr().unwrap());
        listener.set_nonblocking(true).unwrap();
        let requests = Arc::new(Mutex::new(Vec::new()));
        let stop = Arc::new(AtomicBool::new(false));
        let observed = requests.clone();
        let stopped = stop.clone();
        let thread = thread::spawn(move || {
            let mut served = 0;
            while !stopped.load(Ordering::Relaxed) {
                match listener.accept() {
                    Ok((mut stream, _)) => {
                        stream.set_nonblocking(false).unwrap();
                        stream
                            .set_read_timeout(Some(Duration::from_secs(2)))
                            .unwrap();
                        let request = read_request(&mut stream);
                        let cluster = request.line.contains("/api/cluster/info ");
                        observed.lock().unwrap().push(request);
                        let (status, payload) = if cluster {
                            (200, info.to_string())
                        } else {
                            let index = served.min(responses.len() - 1);
                            served += 1;
                            responses[index].clone()
                        };
                        if status == 0 {
                            continue;
                        }
                        let location = if (300..400).contains(&status) {
                            "Location: /prefix/api/schedules/elsewhere\r\n"
                        } else {
                            ""
                        };
                        let head = format!("HTTP/1.1 {status} Test\r\n{location}Content-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n", payload.len());
                        let _ = stream.write_all(head.as_bytes());
                        let _ = stream.write_all(payload.as_bytes());
                    }
                    Err(error) if error.kind() == std::io::ErrorKind::WouldBlock => {
                        thread::sleep(Duration::from_millis(2))
                    }
                    Err(error) => panic!("fixture accept failed: {error}"),
                }
            }
        });
        Self {
            url,
            requests,
            stop,
            thread: Some(thread),
        }
    }

    fn json(info: Value, body: Value) -> Self {
        Self::new(info, vec![(200, body.to_string())])
    }

    /// Requests other than the compatibility preflight.
    fn schedule_requests(&self) -> Vec<Request> {
        self.requests
            .lock()
            .unwrap()
            .iter()
            .filter(|request| request.line.contains("/api/schedules"))
            .cloned()
            .collect()
    }

    fn only_request(&self) -> Request {
        let requests = self.schedule_requests();
        assert_eq!(requests.len(), 1, "{requests:?}");
        requests[0].clone()
    }
}

impl Drop for Server {
    fn drop(&mut self) {
        self.stop.store(true, Ordering::Relaxed);
        self.thread.take().unwrap().join().unwrap();
    }
}

fn read_request(stream: &mut std::net::TcpStream) -> Request {
    let mut bytes = Vec::new();
    let mut buffer = [0; 4096];
    let end = loop {
        if let Some(end) = bytes.windows(4).position(|part| part == b"\r\n\r\n") {
            break end;
        }
        match stream.read(&mut buffer) {
            Ok(0) | Err(_) => break bytes.len(),
            Ok(size) => bytes.extend_from_slice(&buffer[..size]),
        }
    };
    let head = String::from_utf8_lossy(&bytes[..end]).into_owned();
    let length = head
        .lines()
        .filter_map(|line| line.split_once(':'))
        .find(|(name, _)| name.eq_ignore_ascii_case("content-length"))
        .and_then(|(_, length)| length.trim().parse::<usize>().ok())
        .unwrap_or(0);
    while bytes.len() < end + 4 + length {
        match stream.read(&mut buffer) {
            Ok(0) | Err(_) => break,
            Ok(size) => bytes.extend_from_slice(&buffer[..size]),
        }
    }
    let body = bytes
        .get(end + 4..)
        .map(|body| String::from_utf8_lossy(body).into_owned())
        .unwrap_or_default();
    Request {
        line: head.lines().next().unwrap_or_default().to_owned(),
        head,
        body,
    }
}

fn info() -> Value {
    serde_json::from_str(include_str!(
        "../src/compat/fixtures/cluster-info-php-2.11.8.json"
    ))
    .unwrap()
}

fn incompatible() -> Value {
    let mut info = info();
    info["control_plane"]["version"] = json!("99");
    info
}

fn success(output: &Output) -> Value {
    assert_eq!(
        output.status.code(),
        Some(0),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    serde_json::from_slice(&output.stdout).unwrap()
}

fn stdout(output: &Output) -> String {
    String::from_utf8(output.stdout.clone()).unwrap()
}

fn stderr(output: &Output) -> String {
    String::from_utf8(output.stderr.clone()).unwrap()
}

fn schedules_page() -> Value {
    json!({
        "schedules": [
            {"schedule_id": "daily", "workflow_type": "reports.Daily", "status": "active", "next_fire": "2026-04-22T06:00:00Z"},
            {"schedule_id": "nightly", "workflow_type": "reports.Nightly", "paused": true, "namespace": "other"}
        ],
        "next_page_token": "page-2"
    })
}

#[test]
fn list_sends_one_filtered_request_and_preserves_the_envelope() {
    let scratch = Scratch::new();
    let server = Server::json(info(), schedules_page());
    let result = success(&scratch.run(
        &server.url,
        &[
            "schedule:list",
            "--status=paused",
            "--type=reports.Daily",
            "--query=Region = \"eu\"",
            "--limit=25",
            "--next-page-token=page-1",
            "--json",
        ],
    ));
    let request = server.only_request();
    assert!(
        request.line.starts_with("GET /prefix/api/schedules?"),
        "{}",
        request.line
    );
    for parameter in [
        "status=paused",
        "workflow_type=reports.Daily",
        "page_size=25",
        "next_page_token=page-1",
    ] {
        assert!(request.line.contains(parameter), "{}", request.line);
    }
    assert!(request.line.contains("query=Region"), "{}", request.line);
    assert!(request.head.contains("Bearer fixture-token"));
    assert_eq!(result["next_page_token"], "page-2");
    assert_eq!(result["namespace"], "orders");
    assert_eq!(result["schedules"][0]["namespace"], "orders");
    assert_eq!(result["schedules"][1]["namespace"], "other");
}

#[test]
fn list_jsonl_and_human_output() {
    let scratch = Scratch::new();
    let server = Server::json(info(), schedules_page());
    let output = scratch.run(&server.url, &["schedule:list", "--output=jsonl"]);
    let lines: Vec<Value> = stdout(&output)
        .lines()
        .map(|line| serde_json::from_str(line).unwrap())
        .collect();
    assert_eq!(lines.len(), 2);
    assert_eq!(lines[1]["schedule_id"], "nightly");

    let output = scratch.run(&server.url, &["schedules", "list"]);
    assert_eq!(output.status.code(), Some(0));
    let text = stdout(&output);
    assert!(text.starts_with("Namespace: orders\n"), "{text}");
    assert!(
        text.contains("| nightly     | reports.Nightly | paused |"),
        "{text}"
    );
    assert!(text.ends_with("Next page token: page-2\n"), "{text}");

    let server = Server::json(info(), json!({"schedules": []}));
    let output = scratch.run(&server.url, &["schedule:list"]);
    assert_eq!(stdout(&output), "No schedules found in namespace orders.\n");
}

#[test]
fn list_refuses_invalid_options_before_any_schedule_request() {
    let scratch = Scratch::new();
    let server = Server::json(info(), schedules_page());
    for (args, message) in [
        (
            &["--limit=0"][..],
            "--limit must be an integer between 1 and 200.",
        ),
        (
            &["--limit=abc"],
            "--limit must be an integer between 1 and 200.",
        ),
        (&["--status= "], "--status must not be blank."),
        (
            &["--next-page-token="],
            "--next-page-token must not be blank.",
        ),
    ] {
        let mut argv = vec!["schedule:list", "--json"];
        argv.extend_from_slice(args);
        let output = scratch.run(&server.url, &argv);
        assert_eq!(output.status.code(), Some(2));
        let error: Value = serde_json::from_slice(&output.stdout).unwrap();
        assert_eq!(error["error"], message);
        assert_eq!(error["namespace"], "orders");
    }
    assert!(server.schedule_requests().is_empty());
}

#[test]
fn describe_encodes_the_id_as_one_segment_and_renders_the_schedule() {
    let scratch = Scratch::new();
    let server = Server::json(
        info(),
        json!({
            "schedule_id": "daily",
            "spec": {"cron_expressions": ["0 6 * * *"]},
            "overlap_policy": "buffer_one",
            "jitter_seconds": 30,
            "max_runs": 10,
            "remaining_actions": 7,
            "info": {"buffered_actions": [1, 2], "skipped_trigger_count": 3, "last_skip_reason": "overlap"}
        }),
    );
    let output = scratch.run(&server.url, &["schedule:describe", "a/b?c"]);
    assert!(server
        .only_request()
        .line
        .starts_with("GET /prefix/api/schedules/a%2Fb%3Fc HTTP/1.1"));
    assert_eq!(
        stdout(&output),
        "Schedule: a/b?c\n  Namespace: orders\n  Spec: {\"cron_expressions\":[\"0 6 * * *\"]}\n  Action: null\n  State: null\n  Overlap Policy: buffer_one\n  Jitter: 30s\n  Max Runs: 10 (7 remaining)\n  Buffered Actions: 2 pending\n  Skipped: 3 (last: overlap)\n"
    );
}

#[test]
fn empty_and_dot_ids_are_refused_without_a_schedule_request() {
    let scratch = Scratch::new();
    let server = Server::json(info(), json!({}));
    for id in ["", ".", ".."] {
        for command in ["schedule:describe", "schedule:delete", "schedule:pause"] {
            let output = scratch.run(&server.url, &[command, id, "--json"]);
            assert_eq!(output.status.code(), Some(2), "{command} {id:?}");
        }
    }
    assert!(server.schedule_requests().is_empty());
}

#[test]
fn describe_maps_http_errors_to_exit_codes() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        vec![(404, json!({"message": "Schedule not found."}).to_string())],
    );
    let output = scratch.run(&server.url, &["schedule:describe", "gone", "--json"]);
    assert_eq!(output.status.code(), Some(5));
    let error: Value = serde_json::from_slice(&output.stdout).unwrap();
    assert_eq!(error["error"], "Server error: Schedule not found.");
    assert_eq!(error["namespace"], "orders");
}

#[test]
fn history_all_follows_the_cursor_one_request_per_page() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        vec![
            (
                200,
                json!({"events": [{"sequence": 1, "event_type": "ScheduleCreated"}], "has_more": true, "next_cursor": 1}).to_string(),
            ),
            (
                200,
                json!({"events": [{"sequence": 2, "event_type": "ScheduleTriggered", "workflow_instance_id": "wf-1"}], "has_more": false, "next_cursor": null}).to_string(),
            ),
        ],
    );
    let result = success(&scratch.run(
        &server.url,
        &["schedule:history", "daily", "--all", "--limit=1", "--json"],
    ));
    let requests = server.schedule_requests();
    assert_eq!(requests.len(), 2);
    assert_eq!(
        requests[0].line,
        "GET /prefix/api/schedules/daily/history?limit=1 HTTP/1.1"
    );
    assert_eq!(
        requests[1].line,
        "GET /prefix/api/schedules/daily/history?limit=1&after_sequence=1 HTTP/1.1"
    );
    assert_eq!(
        result,
        json!({
            "schedule_id": "daily",
            "namespace": "orders",
            "events": [
                {"sequence": 1, "event_type": "ScheduleCreated", "namespace": "orders"},
                {"sequence": 2, "event_type": "ScheduleTriggered", "workflow_instance_id": "wf-1", "namespace": "orders"}
            ],
            "has_more": false,
            "next_cursor": null
        })
    );
}

#[test]
fn history_first_page_reports_the_cursor_to_continue() {
    let scratch = Scratch::new();
    let server = Server::json(
        info(),
        json!({"events": [{"sequence": 4, "event_type": "ScheduleTriggered", "recorded_at": "2026-01-01T06:00:00Z", "workflow_instance_id": "wf-1", "workflow_run_id": "run-1"}], "has_more": true, "next_cursor": 4}),
    );
    let output = scratch.run(
        &server.url,
        &["schedule:history", "daily", "--after-sequence=3"],
    );
    assert_eq!(
        server.only_request().line,
        "GET /prefix/api/schedules/daily/history?after_sequence=3 HTTP/1.1"
    );
    let text = stdout(&output);
    assert!(
        text.contains("| 4   | ScheduleTriggered | 2026-01-01T06:00:00Z | wf=wf-1 run=run-1 |"),
        "{text}"
    );
    assert!(
        text.ends_with(
            "More events available. Re-run with --after-sequence=4 (or --all) to continue.\n"
        ),
        "{text}"
    );

    for (option, message) in [
        (
            "--limit=501",
            "--limit must be an integer between 1 and 500.",
        ),
        (
            "--after-sequence=-1",
            "--after-sequence must be a non-negative integer.",
        ),
    ] {
        let output = scratch.run(&server.url, &["schedule:history", "daily", option]);
        assert_eq!(output.status.code(), Some(2));
        assert!(stderr(&output).starts_with(message), "{}", stderr(&output));
    }
    assert_eq!(server.schedule_requests().len(), 1);
}

#[test]
fn create_sends_the_2x_body_once() {
    let scratch = Scratch::new();
    let server = Server::json(info(), json!({"schedule_id": "daily"}));
    let output = scratch.run(
        &server.url,
        &[
            "schedule:create",
            "--schedule-id=daily",
            "--workflow-type=reports.Daily",
            "--cron=0 6 * * *",
            "--task-queue=reports",
            "--execution-timeout=60x",
            "--jitter=5",
            "--paused",
            "--note=hello",
            "--input={\"a\":[1,{}],\"b\":{\"0\":\"x\"}}",
        ],
    );
    assert_eq!(output.status.code(), Some(0), "{}", stderr(&output));
    let request = server.only_request();
    assert_eq!(request.line, "POST /prefix/api/schedules HTTP/1.1");
    assert_eq!(
        request.json(),
        json!({
            "schedule_id": "daily",
            "spec": {"cron_expressions": ["0 6 * * *"], "timezone": "UTC"},
            "action": {
                "workflow_type": "reports.Daily",
                "task_queue": "reports",
                "input": {"a": [1, []], "b": ["x"]},
                "execution_timeout_seconds": 60
            },
            "overlap_policy": "skip",
            "jitter_seconds": 5,
            "max_runs": null,
            "paused": true,
            "note": "hello"
        })
    );
    assert_eq!(
        stdout(&output),
        "Schedule created: daily\n  Namespace: orders\n"
    );
}

#[test]
fn create_reads_input_files_and_encodings() {
    let scratch = Scratch::new();
    let file = scratch.0.join("input.json");
    fs::write(&file, "[1, \"two\"]").unwrap();
    for (args, stdin, expected) in [
        (
            vec![format!("--input-file={}", file.display())],
            "",
            json!([1, "two"]),
        ),
        (
            vec!["--input-file=-".to_string()],
            "\"solo\"",
            json!(["solo"]),
        ),
        (
            vec!["--input=YWJj".into(), "--input-encoding=base64".into()],
            "",
            json!(["abc"]),
        ),
        (
            vec!["--input=plain".into(), "--input-encoding=raw".into()],
            "",
            json!(["plain"]),
        ),
    ] {
        let server = Server::json(info(), json!({}));
        let mut argv = vec!["schedule:create", "--interval=PT1H", "--json"];
        argv.extend(args.iter().map(String::as_str));
        let output = scratch.run_with_stdin(&server.url, &argv, stdin);
        assert_eq!(output.status.code(), Some(0), "{}", stdout(&output));
        assert_eq!(server.only_request().json()["action"]["input"], expected);
    }
}

#[test]
fn create_refuses_input_errors_before_mutation() {
    let scratch = Scratch::new();
    let server = Server::json(info(), json!({}));
    for (args, message) in [
        (&["--json"][..], "Either --cron or --interval is required."),
        (
            &["--cron=x", "--input={bad"],
            "--input must be valid JSON: Syntax error",
        ),
        (
            &["--cron=x", "--input=1", "--input-file=x"],
            "Use either --input or --input-file, not both.",
        ),
        (
            &["--cron=x", "--input=1", "--input-encoding=xml"],
            "--input-encoding must be one of: json, raw, base64",
        ),
        (
            &["--cron=x", "--input=Y", "--input-encoding=base64"],
            "--input must be valid base64 when --input-encoding=base64.",
        ),
        (
            &["--cron=x", "--input-file=/nonexistent/input.json"],
            "--input-file must be a readable file: /nonexistent/input.json",
        ),
    ] {
        let mut argv = vec!["schedule:create", "--json"];
        argv.extend_from_slice(args);
        let output = scratch.run(&server.url, &argv);
        assert_eq!(output.status.code(), Some(2));
        let error: Value = serde_json::from_slice(&output.stdout).unwrap();
        assert_eq!(error["error"], message);
    }
    assert!(server.schedule_requests().is_empty());
}

#[test]
fn create_reports_unencodable_input_after_the_preflight_without_sending() {
    let scratch = Scratch::new();
    let server = Server::json(info(), json!({}));
    let output = scratch.run(
        &server.url,
        &["schedule:create", "--cron=x", "--input=[1e400]", "--json"],
    );
    assert_eq!(output.status.code(), Some(3));
    let error: Value = serde_json::from_slice(&output.stdout).unwrap();
    assert_eq!(
        error["error"],
        "Server unreachable: Invalid value for \"json\" option: Inf and NaN cannot be JSON encoded"
    );
    assert!(server.schedule_requests().is_empty());
    assert!(!server.requests.lock().unwrap().is_empty());
}

#[test]
fn mutations_refuse_an_incompatible_server_before_sending() {
    let scratch = Scratch::new();
    let server = Server::json(incompatible(), json!({}));
    for args in [
        &["schedule:create", "--cron=x"][..],
        &["schedule:update", "daily", "--note=n"],
        &["schedule:delete", "daily"],
        &["schedule:pause", "daily"],
        &["schedule:resume", "daily"],
        &["schedule:trigger", "daily"],
        &["schedule:backfill", "daily"],
    ] {
        let output = scratch.run(&server.url, args);
        assert_eq!(output.status.code(), Some(8), "{args:?}");
    }
    assert!(server.schedule_requests().is_empty());
}

#[test]
fn update_sends_only_the_given_fields() {
    let scratch = Scratch::new();
    let server = Server::json(info(), json!({"schedule_id": "daily"}));
    let output = scratch.run(
        &server.url,
        &[
            "schedule:update",
            "daily",
            "--interval=PT2H",
            "--run-timeout=30",
            "--max-runs=100",
            "--cron",
        ],
    );
    assert_eq!(output.status.code(), Some(0), "{}", stderr(&output));
    let request = server.only_request();
    assert_eq!(request.line, "PUT /prefix/api/schedules/daily HTTP/1.1");
    assert_eq!(
        request.json(),
        json!({
            "spec": {"intervals": [{"every": "PT2H"}]},
            "action": {"run_timeout_seconds": 30},
            "max_runs": 100
        })
    );
    assert_eq!(
        stdout(&output),
        "Schedule updated: daily\n  Namespace: orders\n"
    );

    let output = scratch.run(&server.url, &["schedule:update", "daily", "--note"]);
    assert_eq!(output.status.code(), Some(2));
    assert!(stderr(&output).starts_with("No update fields provided."));
    assert_eq!(server.schedule_requests().len(), 1);
}

#[test]
fn delete_pause_resume_and_backfill_make_one_request_each() {
    let scratch = Scratch::new();
    for (args, line, body, human) in [
        (
            &["schedule:delete", "daily"][..],
            "DELETE /prefix/api/schedules/daily HTTP/1.1",
            None,
            "Schedule deleted: daily",
        ),
        (
            &["schedule:pause", "daily", "--note=maintenance"],
            "POST /prefix/api/schedules/daily/pause HTTP/1.1",
            Some(json!({"note": "maintenance"})),
            "Schedule paused: daily",
        ),
        (
            &["schedule:resume", "daily"],
            "POST /prefix/api/schedules/daily/resume HTTP/1.1",
            Some(json!([])),
            "Schedule resumed: daily",
        ),
        (
            &[
                "schedule:backfill",
                "daily",
                "--start-time=2026-04-01T00:00:00Z",
                "--end-time=2026-04-02T00:00:00Z",
            ],
            "POST /prefix/api/schedules/daily/backfill HTTP/1.1",
            Some(json!({"start_time": "2026-04-01T00:00:00Z", "end_time": "2026-04-02T00:00:00Z"})),
            "Backfill started for: daily",
        ),
    ] {
        let server = Server::json(info(), json!({"ok": true}));
        let output = scratch.run(&server.url, args);
        assert_eq!(output.status.code(), Some(0), "{}", stderr(&output));
        let request = server.only_request();
        assert_eq!(request.line, line);
        if let Some(body) = body {
            assert_eq!(request.json(), body);
        }
        assert_eq!(stdout(&output), format!("{human}\n  Namespace: orders\n"));

        let json_output = scratch.run(&server.url, &[args, &["--json"][..]].concat());
        assert_eq!(
            success(&json_output),
            json!({"ok": true, "namespace": "orders"})
        );
    }
}

#[test]
fn trigger_exit_code_follows_the_outcome() {
    let scratch = Scratch::new();
    for (body, code, human) in [
        (
            json!({"outcome": "triggered", "workflow_id": "wf-9"}),
            0,
            "Schedule triggered: daily (namespace orders) -> wf-9",
        ),
        (
            json!({"outcome": "buffered", "buffer_depth": 2}),
            0,
            "Schedule buffered: daily (namespace orders, buffer depth: 2)",
        ),
        (
            json!({"outcome": "skipped"}),
            0,
            "Skipped: daily (namespace orders) - schedule exhausted",
        ),
        (
            json!({"outcome": "trigger_failed", "reason": "no worker"}),
            1,
            "Trigger failed: daily (namespace orders) - no worker",
        ),
        (
            json!({}),
            1,
            "Schedule trigger outcome: daily (namespace orders, unknown)",
        ),
    ] {
        let server = Server::json(info(), body.clone());
        let output = scratch.run(
            &server.url,
            &["schedule:trigger", "daily", "--overlap-policy=allow_all"],
        );
        assert_eq!(output.status.code(), Some(code));
        assert_eq!(stdout(&output), format!("{human}\n"));
        let request = server.only_request();
        assert_eq!(
            request.line,
            "POST /prefix/api/schedules/daily/trigger HTTP/1.1"
        );
        assert_eq!(request.json(), json!({"overlap_policy": "allow_all"}));

        let output = scratch.run(&server.url, &["schedule:trigger", "daily", "--json"]);
        assert_eq!(output.status.code(), Some(code));
        let mut expected = body;
        expected["namespace"] = json!("orders");
        assert_eq!(
            serde_json::from_slice::<Value>(&output.stdout).unwrap(),
            expected
        );
    }
}

#[test]
fn a_mutation_is_never_resent_after_a_redirect_or_a_lost_response() {
    let scratch = Scratch::new();
    let server = Server::new(info(), vec![(307, "{}".into())]);
    let output = scratch.run(&server.url, &["schedule:pause", "daily", "--json"]);
    assert_ne!(output.status.code(), Some(0));
    assert_eq!(server.schedule_requests().len(), 1);

    let server = Server::new(info(), vec![(0, String::new())]);
    let output = scratch.run(&server.url, &["schedule:trigger", "daily", "--json"]);
    assert_eq!(output.status.code(), Some(3), "{}", stdout(&output));
    assert_eq!(server.schedule_requests().len(), 1);
}

#[test]
fn remote_values_are_literal_in_human_output() {
    let scratch = Scratch::new();
    let server = Server::json(
        info(),
        json!({"outcome": "triggered", "workflow_id": "<error>X</error>\u{1b}[2J"}),
    );
    let output = scratch.run(&server.url, &["schedule:trigger", "daily"]);
    let text = stdout(&output);
    assert!(text.contains("<error>X</error>\\u{001b}[2J"), "{text}");
}
