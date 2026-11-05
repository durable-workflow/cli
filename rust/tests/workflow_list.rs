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
            "dw-workflow-list-{}-{now}-{}",
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

    fn run(&self, url: &str, args: &[&str]) -> Output {
        Command::new(env!("CARGO_BIN_EXE_dw"))
            .args([
                "workflow:list",
                "--server",
                url,
                "--namespace=orders",
                "--token=fixture-token",
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

struct Server {
    url: String,
    requests: Arc<Mutex<Vec<String>>>,
    stop: Arc<AtomicBool>,
    thread: Option<JoinHandle<()>>,
}

impl Server {
    fn new(info: Value, status: u16, header: Option<&str>, body: Value) -> Self {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let url = format!("http://{}/prefix", listener.local_addr().unwrap());
        listener.set_nonblocking(true).unwrap();
        let requests = Arc::new(Mutex::new(Vec::new()));
        let stop = Arc::new(AtomicBool::new(false));
        let observed = requests.clone();
        let stopped = stop.clone();
        let header = header.map(str::to_owned);
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
                        observed.lock().unwrap().push(request);
                        let payload =
                            serde_json::to_vec(if cluster { &info } else { &body }).unwrap();
                        let protocol = if cluster {
                            Some("2")
                        } else {
                            header.as_deref()
                        };
                        let protocol = protocol
                            .map(|version| {
                                format!("X-Durable-Workflow-Control-Plane-Version: {version}\r\n")
                            })
                            .unwrap_or_default();
                        let status = if cluster { 200 } else { status };
                        let headers = format!("HTTP/1.1 {status} Test\r\n{protocol}Content-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n", payload.len());
                        let _ = stream.write_all(headers.as_bytes());
                        let _ = stream.write_all(&payload);
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

    fn list_requests(&self) -> Vec<String> {
        self.requests
            .lock()
            .unwrap()
            .iter()
            .filter(|request| request.starts_with("GET /prefix/api/workflows?"))
            .cloned()
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
    serde_json::from_str(include_str!(
        "../src/compat/fixtures/cluster-info-php-2.11.8.json"
    ))
    .unwrap()
}

fn body() -> Value {
    let fixture: Value = serde_json::from_str(include_str!(
        "../../tests/fixtures/control-plane/workflow-list-parity.json"
    ))
    .unwrap();
    let mut body = fixture["response_body"].clone();
    body["control_plane"] = json!({
        "schema": "durable-workflow.v2.control-plane-response", "version": 1, "operation": "list",
        "workflow_count": 2, "next_page_token": "next-polyglot-page",
        "contract": {"schema": "durable-workflow.v2.control-plane-response.contract", "version": 1,
            "legacy_field_policy": "reject_non_canonical", "legacy_fields": {"query": "query_name"},
            "required_fields": [], "success_fields": []}
    });
    body
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

#[test]
fn list_uses_published_sdk_transport_filters_and_exact_server_envelope() {
    let scratch = Scratch::new();
    let server = Server::new(info(), 200, Some("2"), body());
    let output = scratch.run(
        &server.url,
        &[
            "--type=orders.process",
            "--status=running",
            "--query=CustomerId=\"42\"",
            "--limit=25",
            "--json",
        ],
    );
    let result = success(&output);
    assert_eq!(result["next_page_token"], "next-polyglot-page");
    assert_eq!(result["namespace"], "orders");
    assert_eq!(result["workflows"][0]["namespace"], "orders");
    assert_eq!(result["workflows"][1]["workflow_id"], "wf-polyglot-list-2");
    assert_eq!(result["control_plane_version"], "2");
    assert_eq!(result["control_plane_schema_version"], 1);
    assert_eq!(result["workflow_count"], 2);
    let requests = server.list_requests();
    assert_eq!(requests.len(), 1);
    let request = requests[0].to_lowercase();
    assert!(request.starts_with("get /prefix/api/workflows?workflow_type=orders.process&status=running&query=customerid%3d%2242%22&page_size=25 "));
    for header in [
        "x-namespace: orders",
        "authorization: bearer fixture-token",
        "x-durable-workflow-protocol-version: 1.0",
        "x-durable-workflow-control-plane-version: 2",
    ] {
        assert!(request.contains(header), "missing {header}: {request}");
    }
}

#[test]
fn jsonl_preserves_items_and_server_namespace_without_following_the_next_page() {
    let scratch = Scratch::new();
    let mut response = body();
    response["namespace"] = json!("server-namespace");
    response["workflows"][0]["namespace"] = json!("original-item-namespace");
    let server = Server::new(info(), 200, Some("2"), response);
    let output = scratch.run(&server.url, &["--output=jsonl"]);
    assert_eq!(output.status.code(), Some(0));
    let items: Vec<Value> = String::from_utf8(output.stdout)
        .unwrap()
        .lines()
        .map(|line| serde_json::from_str(line).unwrap())
        .collect();
    assert_eq!(items.len(), 2);
    assert_eq!(items[0]["namespace"], "original-item-namespace");
    assert_eq!(items[1]["namespace"], "server-namespace");
    assert_eq!(server.list_requests().len(), 1);
}

#[test]
fn aliases_and_visibility_page_sizes_keep_php_casts() {
    let scratch = Scratch::new();
    let server = Server::new(info(), 200, Some("2"), body());
    for (value, expected) in [
        ("25trailing", "25"),
        ("", "0"),
        ("-4", "-4"),
        ("1e2", "100"),
    ] {
        let output = scratch.run(&server.url, &[&format!("--limit={value}"), "--json"]);
        success(&output);
        assert!(server
            .list_requests()
            .last()
            .unwrap()
            .contains(&format!("page_size={expected} ")));
    }
    for args in [&["workflows:list"][..], &["workflow", "list"][..]] {
        let output = Command::new(env!("CARGO_BIN_EXE_dw"))
            .args(args)
            .args(["--server", &server.url, "--json"])
            .env_clear()
            .env("DW_CONFIG_HOME", &scratch.0)
            .output()
            .unwrap();
        success(&output);
    }
}

#[test]
fn usage_profile_and_contract_refusals_send_no_list_request() {
    let scratch = Scratch::new();
    let mut incompatible = info();
    incompatible["control_plane"]["version"] = json!("99");
    let server = Server::new(incompatible, 200, Some("2"), body());
    assert_eq!(
        scratch.run(&server.url, &["--bad-option"]).status.code(),
        Some(1)
    );
    assert!(server.requests.lock().unwrap().is_empty());
    assert_eq!(
        scratch
            .run(&server.url, &["--env=missing", "--json"])
            .status
            .code(),
        Some(2)
    );
    assert!(server.requests.lock().unwrap().is_empty());
    assert_eq!(scratch.run(&server.url, &["--json"]).status.code(), Some(8));
    assert!(server.list_requests().is_empty());

    let server = Server::new(info(), 200, Some("2"), body());
    let output = scratch.run(&server.url, &["--status=bogus", "--json"]);
    assert_eq!(output.status.code(), Some(2));
    let error: Value = serde_json::from_slice(&output.stdout).unwrap();
    assert_eq!(error["namespace"], "orders");
    assert!(server.list_requests().is_empty());

    let server = Server::new(info(), 200, Some("2"), body());
    let https = server.url.replacen("http:", "https:", 1);
    assert_eq!(
        scratch
            .run(&https, &["--tls-verify=false", "--json"])
            .status
            .code(),
        Some(2)
    );
    assert!(server.requests.lock().unwrap().is_empty());
}

#[test]
fn selected_profile_supplies_namespace_token_and_jsonl_mode() {
    let scratch = Scratch::new();
    let server = Server::new(info(), 200, Some("2"), body());
    fs::write(scratch.0.join("config.json"), serde_json::to_vec(&json!({
        "schema": "durable-workflow.cli.config", "version": 1, "current_env": "fixture",
            "envs": {"fixture": {"server": server.url, "namespace": "profile-orders", "output": "jsonl", "token_source": {"type": "env", "value": "CLI_FIXTURE_AUTH"}}}
    })).unwrap()).unwrap();
    let output = Command::new(env!("CARGO_BIN_EXE_dw"))
        .arg("workflow:list")
        .env_clear()
        .env("DW_CONFIG_HOME", &scratch.0)
        .env("CLI_FIXTURE_AUTH", "profile-fixture-token")
        .output()
        .unwrap();
    assert_eq!(
        output.status.code(),
        Some(0),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    let items: Vec<Value> = String::from_utf8(output.stdout)
        .unwrap()
        .lines()
        .map(|line| serde_json::from_str(line).unwrap())
        .collect();
    assert_eq!(items.len(), 2);
    assert_eq!(items[0]["namespace"], "profile-orders");
    let requests = server.list_requests();
    assert_eq!(requests.len(), 1);
    let request = requests[0].to_lowercase();
    assert!(request.contains("x-namespace: profile-orders"));
    assert!(request.contains("authorization: bearer profile-fixture-token"));
    assert!(request.starts_with("get /prefix/api/workflows?page_size=20 "));
}

#[test]
fn remote_errors_are_literal_in_human_output_and_unchanged_in_json() {
    let scratch = Scratch::new();
    let attack = "<error>UNTRUSTED</error>\nFORGED\u{1b}[2J";
    let server = Server::new(
        info(),
        422,
        None,
        json!({"message": attack, "reason": attack, "errors": {"field": [attack]}}),
    );
    let output = scratch.run(&server.url, &[]);
    assert_eq!(output.status.code(), Some(2));
    let human = String::from_utf8(output.stderr).unwrap();
    assert!(human.contains("<error>UNTRUSTED</error>\\nFORGED\\u{001b}[2J"));
    assert!(!human
        .chars()
        .any(|character| character.is_control() && character != '\n'));
    let output = scratch.run(&server.url, &["--json"]);
    let machine: Value = serde_json::from_slice(&output.stdout).unwrap();
    assert_eq!(machine["server_response"]["message"], attack);
}

#[test]
fn http_failures_remain_http_errors_and_are_not_retried_or_masked_by_contracts() {
    let scratch = Scratch::new();
    for (status, exit) in [(401, 4), (403, 4), (404, 5), (422, 2), (500, 6)] {
        let server = Server::new(
            info(),
            status,
            None,
            json!({"message": "fixture rejection", "reason": "fixture"}),
        );
        let output = scratch.run(&server.url, &["--json"]);
        assert_eq!(output.status.code(), Some(exit));
        let error: Value = serde_json::from_slice(&output.stdout).unwrap();
        assert_eq!(error["namespace"], "orders");
        assert_eq!(error["reason"], "fixture");
        assert_eq!(server.list_requests().len(), 1);
    }
}

#[test]
fn malformed_success_is_refused_and_remote_human_values_are_not_terminal_instructions() {
    let scratch = Scratch::new();
    for (header, response) in [
        (None, body()),
        (Some("99"), body()),
        (Some("2"), json!({"workflows": []})),
    ] {
        let server = Server::new(info(), 200, header, response);
        let output = scratch.run(&server.url, &["--json"]);
        assert_eq!(output.status.code(), Some(1));
        assert_eq!(server.list_requests().len(), 1);
    }
    let attack = "<error>UNTRUSTED</error>\nFORGED\u{1b}[2J\u{2028}";
    let mut response = body();
    response["workflows"][0]["workflow_id"] = json!(attack);
    response["workflows"][0]["status"] = json!(attack);
    let server = Server::new(info(), 200, Some("2"), response);
    let output = scratch.run(&server.url, &[]);
    assert_eq!(output.status.code(), Some(0));
    let human = String::from_utf8(output.stdout).unwrap();
    assert!(human.contains("<error>UNTRUSTED</error>\\nFORGED\\u{001b}[2J\\u{2028}"));
    assert!(!human
        .chars()
        .any(|character| character.is_control() && character != '\n'));
    let machine = success(&scratch.run(&server.url, &["--json"]));
    assert_eq!(machine["workflows"][0]["workflow_id"], attack);
}
