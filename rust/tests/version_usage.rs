//! Namespace-scoped version usage through the actual CLI and SDK transport.
use serde_json::{json, Value};
use std::fs;
use std::io::{Read, Write};
use std::net::TcpListener;
use std::path::PathBuf;
use std::process::{Command, Output};
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::thread::{self, JoinHandle};
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
            "dw-version-usage-{}-{now}-{}",
            std::process::id(),
            NEXT.fetch_add(1, Ordering::Relaxed)
        ));
        let mut builder = fs::DirBuilder::new();
        #[cfg(unix)]
        {
            use std::os::unix::fs::DirBuilderExt;
            builder.mode(0o700);
        }
        builder.create(&path).unwrap();
        Self(path)
    }
    fn run(&self, server: &Server, args: &[&str]) -> Output {
        self.command(server, &["workflow:version-usage"], args)
    }
    fn command(&self, server: &Server, command: &[&str], args: &[&str]) -> Output {
        Command::new(env!("CARGO_BIN_EXE_dw"))
            .args(command)
            .args([
                "--server",
                &server.url,
                "--token=fixture-token",
                "--no-ansi",
            ])
            .args(args)
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

enum Reply {
    Json(u16, Value),
    Redirect(String),
    Drop,
}
struct Server {
    url: String,
    requests: Arc<Mutex<Vec<String>>>,
    stop: Arc<AtomicBool>,
    thread: Option<JoinHandle<()>>,
}
impl Server {
    fn new(info: Value, reply: Reply) -> Self {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let url = format!("http://{}/prefix", listener.local_addr().unwrap());
        listener.set_nonblocking(true).unwrap();
        let requests = Arc::new(Mutex::new(Vec::new()));
        let stop = Arc::new(AtomicBool::new(false));
        let observed = requests.clone();
        let stopped = stop.clone();
        let thread = thread::spawn(move || {
            while !stopped.load(Ordering::Relaxed) {
                match listener.accept() {
                    Ok((mut stream, _)) => {
                        stream
                            .set_read_timeout(Some(Duration::from_secs(2)))
                            .unwrap();
                        let mut bytes = Vec::new();
                        let mut buffer = [0; 2048];
                        while !bytes.windows(4).any(|part| part == b"\r\n\r\n")
                            && bytes.len() < 65536
                        {
                            match stream.read(&mut buffer) {
                                Ok(0) | Err(_) => break,
                                Ok(size) => bytes.extend_from_slice(&buffer[..size]),
                            }
                        }
                        let request = String::from_utf8(bytes).unwrap();
                        let cluster = request.starts_with("GET /prefix/api/cluster/info ");
                        let usage = request.starts_with("GET /prefix/api/workflow-version-usage?");
                        observed.lock().unwrap().push(request);
                        let (status, body, extra) = if cluster {
                            (200, info.clone(), String::new())
                        } else if usage {
                            match &reply {
                                Reply::Json(status, body) => (*status, body.clone(), String::new()),
                                Reply::Redirect(url) => {
                                    (302, json!({}), format!("Location: {url}\r\n"))
                                }
                                Reply::Drop => continue,
                            }
                        } else {
                            (
                                404,
                                json!({"message":"Unknown fixture route"}),
                                String::new(),
                            )
                        };
                        let payload = serde_json::to_vec(&body).unwrap();
                        let head = format!("HTTP/1.1 {status} Test\r\nX-Durable-Workflow-Control-Plane-Version: 2\r\nContent-Type: application/json\r\n{extra}Content-Length: {}\r\nConnection: close\r\n\r\n", payload.len());
                        let _ = stream.write_all(head.as_bytes());
                        let _ = stream.write_all(&payload);
                    }
                    Err(error) if error.kind() == std::io::ErrorKind::WouldBlock => {
                        thread::sleep(Duration::from_millis(2))
                    }
                    Err(error) => panic!("fixture accept: {error}"),
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
    fn requests(&self) -> Vec<String> {
        self.requests.lock().unwrap().clone()
    }
    fn usage(&self) -> Vec<String> {
        self.requests()
            .into_iter()
            .filter(|r| r.starts_with("GET /prefix/api/workflow-version-usage?"))
            .collect()
    }
}
impl Drop for Server {
    fn drop(&mut self) {
        self.stop.store(true, Ordering::Relaxed);
        self.thread.take().unwrap().join().unwrap();
    }
}
fn info() -> Value {
    let mut info: Value = serde_json::from_str(include_str!(
        "../src/compat/fixtures/cluster-info-php-2.11.8.json"
    ))
    .unwrap();
    info["capabilities"]["workflow_version_usage"] = json!(true);
    info
}
fn page() -> Value {
    json!({"schema_version":1,"namespace":"orders","workflow_type":"orders.process","change_id":"billing-v2","min_version":1,"state":null,"consistency":"live","through_run_id":"run-z",
        "counts":{"total":5,"legacy":1,"current":1,"unknown":3},
        "runs":[
            {"instance_id":"wf-1","run_id":"run-a","status":"running","state":"legacy","recorded_version":-1,"unknown_reason":null},
            {"instance_id":"wf-2","run_id":"run-b","status":"pending","state":"current","recorded_version":1,"unknown_reason":null},
            {"instance_id":"wf-3","run_id":"run-c","status":"waiting","state":"unknown","recorded_version":null,"unknown_reason":"marker_absent"},
            {"instance_id":"wf-4","run_id":"run-d","status":"waiting","state":"unknown","recorded_version":null,"unknown_reason":"history_unavailable"},
            {"instance_id":"wf-5","run_id":"run-e","status":"waiting","state":"unknown","recorded_version":null,"unknown_reason":"history_invalid"}],
        "limit":50,"returned_count":5,"has_more":false,"next_cursor":null})
}
fn args() -> Vec<&'static str> {
    vec![
        "orders.process",
        "billing-v2",
        "--min-version=1",
        "--namespace=orders",
    ]
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
fn assert_failed(output: &Output) {
    assert!(
        !output.status.success(),
        "unexpected success: {}",
        String::from_utf8_lossy(&output.stdout)
    );
    if !output.stdout.is_empty() {
        let failure: Value = serde_json::from_slice(&output.stdout).expect("structured error");
        assert!(failure.get("error").is_some());
        assert!(
            failure.get("counts").is_none(),
            "failure emitted successful counts"
        );
    }
}

#[test]
fn preserves_mixed_decisions_and_all_counts_in_json_and_jsonl() {
    let scratch = Scratch::new();
    for mode in ["--output=json", "--output=jsonl"] {
        let body = page();
        let server = Server::new(info(), Reply::Json(200, body.clone()));
        let mut query = args();
        query.push(mode);
        let output = scratch.run(&server, &query);
        assert_eq!(success(&output), body);
        assert_eq!(String::from_utf8(output.stdout).unwrap().lines().count(), 1);
        assert_eq!(server.usage().len(), 1);
        assert_eq!(server.requests().len(), 2);
    }
}

#[test]
fn human_output_exposes_unknown_reasons_and_live_counts() {
    let server = Server::new(info(), Reply::Json(200, page()));
    let output = Scratch::new().run(&server, &args());
    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    let text = String::from_utf8(output.stdout).unwrap();
    for expected in [
        "total=5",
        "legacy=1",
        "current=1",
        "unknown=3",
        "marker_absent",
        "history_unavailable",
        "history_invalid",
        "run-a",
        "run-e",
        "Live",
    ] {
        assert!(text.contains(expected), "missing {expected}: {text}");
    }
    assert!(!text.contains("safe_to_remove"));
}

#[test]
fn filtered_empty_page_retains_unfiltered_unknown_counts() {
    let mut body = page();
    body["state"] = json!("legacy");
    body["runs"] = json!([]);
    body["returned_count"] = json!(0);
    let server = Server::new(info(), Reply::Json(200, body.clone()));
    let mut query = args();
    query.extend(["--state=legacy", "--output=jsonl"]);
    assert_eq!(success(&Scratch::new().run(&server, &query)), body);
}

#[test]
fn encodes_exact_query_values_and_namespace_without_following_cursor() {
    let workflow_type = "Commande/é?&";
    let change_id = "change/#?=& é";
    let mut body = page();
    body["workflow_type"] = json!(workflow_type);
    body["change_id"] = json!(change_id);
    body["state"] = json!("unknown");
    body["runs"] = json!([body["runs"][2].clone()]);
    body["returned_count"] = json!(1);
    body["limit"] = json!(1);
    body["has_more"] = json!(true);
    body["next_cursor"] = json!("next/opaque?&=");
    let server = Server::new(info(), Reply::Json(200, body.clone()));
    let query = [
        workflow_type,
        change_id,
        "--min-version=1",
        "--namespace=orders",
        "--state=unknown",
        "--limit=1",
        "--cursor=prior/opaque?&=",
        "--output=json",
    ];
    assert_eq!(success(&Scratch::new().run(&server, &query)), body);
    let requests = server.usage();
    assert_eq!(requests.len(), 1);
    let request = &requests[0];
    assert!(request
        .to_ascii_lowercase()
        .contains("x-namespace: orders\r\n"));
    let target = request
        .lines()
        .next()
        .unwrap()
        .split_whitespace()
        .nth(1)
        .unwrap();
    let url = reqwest::Url::parse(&format!("http://fixture.test{target}")).unwrap();
    let values: std::collections::BTreeMap<_, _> = url.query_pairs().into_owned().collect();
    assert_eq!(values.get("workflow_type").unwrap(), workflow_type);
    assert_eq!(values.get("change_id").unwrap(), change_id);
    assert_eq!(values.get("cursor").unwrap(), "prior/opaque?&=");
    assert_eq!(values.get("min_version").unwrap(), "1");
    assert_eq!(values.get("limit").unwrap(), "1");
    assert_eq!(values.get("state").unwrap(), "unknown");
    assert_eq!(values.len(), 6);
}

#[test]
fn requires_literal_true_capability_before_usage_request() {
    let scratch = Scratch::new();
    for capability in [
        Value::Null,
        json!(false),
        json!("true"),
        json!(1),
        json!({"supported":true}),
    ] {
        let mut cluster = info();
        cluster["capabilities"]["workflow_version_usage"] = capability;
        let server = Server::new(cluster, Reply::Json(200, page()));
        let mut query = args();
        query.push("--output=json");
        let output = scratch.run(&server, &query);
        assert_eq!(output.status.code(), Some(8));
        assert_failed(&output);
        assert!(format!(
            "{}{}",
            String::from_utf8_lossy(&output.stderr),
            String::from_utf8_lossy(&output.stdout)
        )
        .contains("workflow_version_usage"));
        assert!(server.usage().is_empty());
        assert_eq!(server.requests().len(), 1);
    }
    let mut cluster = info();
    cluster["capabilities"]
        .as_object_mut()
        .unwrap()
        .remove("workflow_version_usage");
    let server = Server::new(cluster, Reply::Json(200, page()));
    assert_eq!(scratch.run(&server, &args()).status.code(), Some(8));
    assert!(server.usage().is_empty());
}

#[test]
fn rejects_invalid_arguments_before_any_http_request() {
    let server = Server::new(info(), Reply::Json(200, page()));
    let scratch = Scratch::new();
    let mut cases: Vec<Vec<String>> =
        vec![vec!["orders.process".into(), "billing-v2".into()], vec![]];
    for bad in ["2147483648", "-2147483649", "1.5", "1e2", "", " 1"] {
        cases.push(vec![
            "orders.process".into(),
            "billing-v2".into(),
            format!("--min-version={bad}"),
        ]);
    }
    for bad in [
        "--limit=0",
        "--limit=201",
        "--limit=1.5",
        "--state=closed",
        "--cursor=",
        "--namespace=",
        "--namespace= ",
        "--namespace=bad\nnamespace",
    ] {
        let mut query: Vec<_> = args()
            .into_iter()
            .filter(|a| !a.starts_with("--namespace"))
            .map(str::to_owned)
            .collect();
        query.push(bad.into());
        cases.push(query);
    }
    for (workflow_type, change_id) in [
        ("".to_string(), "billing-v2".to_string()),
        ("é".repeat(128), "billing-v2".into()),
        ("orders.process".into(), "".into()),
        ("orders.process".into(), "é".repeat(513)),
    ] {
        cases.push(vec![workflow_type, change_id, "--min-version=1".into()]);
    }
    for case in cases {
        let query: Vec<_> = case.iter().map(String::as_str).collect();
        let output = scratch.run(&server, &query);
        assert_eq!(
            output.status.code(),
            Some(if case.is_empty() { 1 } else { 2 }),
            "{case:?}: {}",
            String::from_utf8_lossy(&output.stderr)
        );
        assert_failed(&output);
    }
    assert!(server.requests().is_empty());
}

#[test]
fn accepts_signed_integer_boundaries_and_selected_default_namespace() {
    let scratch = Scratch::new();
    for minimum in [i32::MIN, i32::MAX] {
        let mut body = page();
        body["namespace"] = json!("default");
        body["min_version"] = json!(minimum);
        body["counts"] = json!({"total":0,"legacy":0,"current":0,"unknown":0});
        body["runs"] = json!([]);
        body["returned_count"] = json!(0);
        body["through_run_id"] = Value::Null;
        let server = Server::new(info(), Reply::Json(200, body.clone()));
        let version = format!("--min-version={minimum}");
        let output = scratch.run(
            &server,
            &["orders.process", "billing-v2", &version, "--output=json"],
        );
        assert_eq!(success(&output), body);
        assert!(server.usage()[0]
            .to_ascii_lowercase()
            .contains("x-namespace: default\r\n"));
    }
}

#[test]
fn rejects_inconsistent_scope_counts_run_states_and_continuations() {
    let mut mutations = vec![
        ("/namespace", json!("other")),
        ("/namespace", Value::Null),
        ("/workflow_type", json!("other")),
        ("/change_id", json!("other")),
        ("/min_version", json!(2)),
        ("/state", json!("legacy")),
        ("/schema_version", json!(2)),
        ("/consistency", json!("snapshot")),
        ("/limit", json!(200)),
        ("/counts/total", json!(0)),
        ("/counts/unknown", json!(-1)),
        ("/counts/current", json!(1.5)),
        ("/returned_count", json!(0)),
        ("/through_run_id", Value::Null),
        ("/through_run_id", json!("run-0")),
        ("/runs/0/status", json!("completed")),
        ("/runs/0/instance_id", json!("")),
        ("/runs/0/recorded_version", json!(1)),
        ("/runs/1/recorded_version", json!(-1)),
        ("/runs/0/unknown_reason", json!("marker_absent")),
        ("/runs/2/recorded_version", json!(0)),
        ("/runs/2/unknown_reason", Value::Null),
        ("/runs/2/unknown_reason", json!("future_reason")),
        ("/runs/1/run_id", json!("run-a")),
        ("/has_more", json!(true)),
        ("/next_cursor", json!("unexpected")),
    ];
    let scratch = Scratch::new();
    for (pointer, value) in mutations.drain(..) {
        let mut body = page();
        *body.pointer_mut(pointer).unwrap() = value;
        let server = Server::new(info(), Reply::Json(200, body));
        let mut query = args();
        query.push("--output=json");
        let output = scratch.run(&server, &query);
        assert_failed(&output);
        assert!(
            format!(
                "{}{}",
                String::from_utf8_lossy(&output.stderr),
                String::from_utf8_lossy(&output.stdout)
            )
            .contains("Counts have not been established"),
            "{pointer}: {}",
            String::from_utf8_lossy(&output.stderr)
        );
        assert_eq!(server.usage().len(), 1);
    }
}

#[test]
fn required_nullable_fields_and_unknown_fields_cannot_hide_incomplete_data() {
    let scratch = Scratch::new();
    for field in ["state", "through_run_id", "next_cursor"] {
        let mut body = page();
        body.as_object_mut().unwrap().remove(field);
        let server = Server::new(info(), Reply::Json(200, body));
        assert_failed(&scratch.run(&server, &args()));
    }
    for field in ["recorded_version", "unknown_reason"] {
        let mut body = page();
        body["runs"][2].as_object_mut().unwrap().remove(field);
        let server = Server::new(info(), Reply::Json(200, body));
        assert_failed(&scratch.run(&server, &args()));
    }
    let mut body = page();
    body["safe_to_remove"] = json!(true);
    let server = Server::new(info(), Reply::Json(200, body));
    assert_failed(&scratch.run(&server, &args()));
}

#[test]
fn errors_remain_failures_and_redirects_and_lost_replies_are_not_retried() {
    let scratch = Scratch::new();
    for status in [401, 403, 404, 405, 422, 500, 503] {
        let server = Server::new(
            info(),
            Reply::Json(
                status,
                json!({"reason":"validation_failed","message":"fixture failure"}),
            ),
        );
        assert_failed(&scratch.run(&server, &args()));
        assert_eq!(server.usage().len(), 1, "HTTP {status}");
        assert_eq!(server.requests().len(), 2);
    }
    let destination = Server::new(info(), Reply::Json(200, page()));
    let server = Server::new(info(), Reply::Redirect(destination.url.clone()));
    assert_failed(&scratch.run(&server, &args()));
    assert!(destination.requests().is_empty());
    assert_eq!(server.usage().len(), 1);
    let server = Server::new(info(), Reply::Drop);
    assert_failed(&scratch.run(&server, &args()));
    assert_eq!(server.usage().len(), 1);
}

#[test]
fn help_is_offline_and_grouped_alias_uses_the_same_command() {
    let server = Server::new(info(), Reply::Json(200, page()));
    let scratch = Scratch::new();
    let output = scratch.run(&server, &["--help"]);
    assert!(output.status.success());
    let help = String::from_utf8(output.stdout).unwrap();
    for expected in [
        "--min-version",
        "--state",
        "--cursor",
        "workflow_version_usage",
        "unknown",
    ] {
        assert!(help.contains(expected), "{expected}");
    }
    assert!(server.requests().is_empty());
    let mut query = args();
    query.push("--output=json");
    let output = scratch.command(&server, &["workflow", "version-usage"], &query);
    assert_eq!(success(&output), page());
    assert_eq!(server.usage().len(), 1);
}
