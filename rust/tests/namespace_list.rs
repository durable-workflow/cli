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
        let nonce = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        let path = std::env::temp_dir().join(format!(
            "dw-namespace-{}-{nonce}-{}",
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

    fn command(&self) -> Command {
        self.command_named("namespace:list")
    }

    fn command_named(&self, name: &str) -> Command {
        let mut command = Command::new(env!("CARGO_BIN_EXE_dw"));
        command
            .arg(name)
            .env_clear()
            .env("HOME", &self.0)
            .env("DW_CONFIG_HOME", &self.0);
        command
    }

    fn run(&self, url: &str, args: &[&str]) -> Output {
        self.command()
            .args([
                "--server",
                url,
                "--namespace=selected",
                "--token=fixture-token",
            ])
            .args(args)
            .output()
            .unwrap()
    }

    fn describe(&self, url: &str, name: &str, args: &[&str]) -> Output {
        self.command_named("namespace:describe")
            .args([
                name,
                "--server",
                url,
                "--namespace=selected",
                "--token=fixture-token",
            ])
            .args(args)
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
    fn new(info: Value, status: u16, body: &str) -> Self {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let url = format!("http://{}/prefix", listener.local_addr().unwrap());
        listener.set_nonblocking(true).unwrap();
        let requests = Arc::new(Mutex::new(Vec::new()));
        let stop = Arc::new(AtomicBool::new(false));
        let observed = requests.clone();
        let stopped = stop.clone();
        let body = body.to_owned();
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
                        let cluster = request
                            .lines()
                            .next()
                            .unwrap_or("")
                            .contains("/api/cluster/info ");
                        observed.lock().unwrap().push(request);
                        let payload = if cluster {
                            info.to_string()
                        } else {
                            body.clone()
                        };
                        let status = if cluster { 200 } else { status };
                        let location = if (300..400).contains(&status) {
                            "Location: /unexpected-redirect\r\n"
                        } else {
                            ""
                        };
                        let headers = format!("HTTP/1.1 {status} Test\r\n{location}Content-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n", payload.len());
                        let _ = stream.write_all(headers.as_bytes());
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

    fn namespace_requests(&self) -> Vec<String> {
        self.requests
            .lock()
            .unwrap()
            .iter()
            .filter(|request| {
                request
                    .lines()
                    .next()
                    .unwrap_or("")
                    .ends_with("/api/namespaces HTTP/1.1")
            })
            .cloned()
            .collect()
    }

    fn describe_requests(&self) -> Vec<String> {
        self.requests
            .lock()
            .unwrap()
            .iter()
            .filter(|request| request.starts_with("GET /prefix/api/namespaces/"))
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
        "../../tests/fixtures/control-plane/namespace-list-parity.json"
    ))
    .unwrap();
    fixture["response_body"].clone()
}
fn success(output: Output) -> Value {
    assert_eq!(
        output.status.code(),
        Some(0),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    serde_json::from_slice(&output.stdout).unwrap()
}

#[test]
fn namespaces_preserve_the_server_envelope_and_use_one_sdk_request() {
    let scratch = Scratch::new();
    let mut response = body();
    response["next_page_token"] = json!("unfollowed");
    response["namespaces"][1]["namespace"] = json!("server-billing");
    let server = Server::new(info(), 200, &response.to_string());
    let result = success(scratch.run(&server.url, &["--json"]));
    assert_eq!(result["next_page_token"], "unfollowed");
    assert!(result.get("namespace").is_none());
    assert_eq!(result["namespaces"][0]["namespace"], "default");
    assert_eq!(result["namespaces"][1]["namespace"], "server-billing");
    let requests = server.namespace_requests();
    assert_eq!(requests.len(), 1);
    let request = requests[0].to_lowercase();
    assert!(request.starts_with("get /prefix/api/namespaces http/1.1"));
    for header in [
        "x-namespace: selected",
        "authorization: bearer fixture-token",
        "x-durable-workflow-protocol-version: 1.0",
        "x-durable-workflow-control-plane-version: 2",
    ] {
        assert!(request.contains(header), "missing {header}: {request}");
    }
}

#[test]
fn namespaces_jsonl_and_selected_profile_keep_each_resource_context() {
    let scratch = Scratch::new();
    let server = Server::new(info(), 200, &body().to_string());
    fs::write(scratch.0.join("config.json"), serde_json::to_vec(&json!({
        "schema": "durable-workflow.cli.config", "version": 1, "current_env": "fixture",
        "envs": {"fixture": {"server": server.url, "namespace": "profile-orders", "output": "jsonl",
            "token_source": {"type": "env", "value": "CLI_FIXTURE_AUTH"}}}
    })).unwrap()).unwrap();
    let output = scratch
        .command()
        .env("CLI_FIXTURE_AUTH", "profile-token")
        .output()
        .unwrap();
    assert_eq!(output.status.code(), Some(0));
    let rows: Vec<Value> = String::from_utf8(output.stdout)
        .unwrap()
        .lines()
        .map(|line| serde_json::from_str(line).unwrap())
        .collect();
    assert_eq!(rows.len(), 2);
    assert_eq!(rows[0]["namespace"], "default");
    assert_eq!(rows[1]["namespace"], "billing");
    let requests = server.namespace_requests();
    assert_eq!(requests.len(), 1);
    assert!(requests[0].contains("Bearer profile-token"));
    assert!(requests[0].contains("profile-orders"));
}

#[test]
fn namespaces_human_table_and_empty_results_follow_php() {
    let scratch = Scratch::new();
    let server = Server::new(info(), 200, &body().to_string());
    let output = scratch.run(&server.url, &[]);
    assert_eq!(output.status.code(), Some(0));
    let human = String::from_utf8(output.stdout).unwrap();
    assert!(human.contains("Retention (days)") && human.contains("billing"));
    assert!(!human.contains("Namespace: selected"));
    let server = Server::new(info(), 200, r#"{"namespaces":[]}"#);
    let output = scratch.run(&server.url, &[]);
    assert_eq!(output.status.code(), Some(0));
    assert_eq!(
        String::from_utf8(output.stdout).unwrap(),
        "No namespaces found.\n"
    );
    assert!(scratch
        .run(&server.url, &["--output=jsonl"])
        .stdout
        .is_empty());
}

#[test]
fn namespace_http_errors_keep_diagnostics_without_a_resolved_namespace() {
    let scratch = Scratch::new();
    for (status, exit) in [(401, 4), (403, 4), (404, 5), (422, 2), (500, 6)] {
        let server = Server::new(
            info(),
            status,
            r#"{"message":"namespace refusal","reason":"fixture"}"#,
        );
        let output = scratch.run(&server.url, &["--json"]);
        assert_eq!(output.status.code(), Some(exit));
        let result: Value = serde_json::from_slice(&output.stdout).unwrap();
        assert_eq!(result["reason"], "fixture");
        assert_eq!(result["command"], "namespace:list");
        assert!(result.get("namespace").is_none());
        assert_eq!(server.namespace_requests().len(), 1);
    }
    let server = Server::new(info(), 403, r#"{"message":"wrong credential class"}"#);
    let managed = server
        .url
        .replace("/prefix", "/api/runtime/v1/namespaces/managed");
    let output = scratch.run(&managed, &["--json"]);
    assert_eq!(output.status.code(), Some(4));
    let result: Value = serde_json::from_slice(&output.stdout).unwrap();
    assert!(result["error"]
        .as_str()
        .unwrap()
        .contains("requires a client runtime credential"));
    assert_eq!(server.namespace_requests().len(), 1);
}

#[test]
fn non_array_or_invalid_namespace_success_bodies_keep_php_empty_array_decoding() {
    let scratch = Scratch::new();
    for value in ["null", "true", "42", "\"scalar\"", "not JSON"] {
        let server = Server::new(info(), 200, value);
        assert_eq!(success(scratch.run(&server.url, &["--json"])), json!([]));
        assert_eq!(server.namespace_requests().len(), 1);
    }
}

#[test]
fn malformed_namespace_list_is_preserved_in_json_and_refused_by_the_human_table() {
    let scratch = Scratch::new();
    for value in [json!(true), json!(7), json!("text")] {
        let server = Server::new(info(), 200, &json!({"namespaces":value}).to_string());
        let machine = success(scratch.run(&server.url, &["--json"]));
        assert_eq!(machine["namespaces"], value);
        let human = scratch.run(&server.url, &[]);
        assert_eq!(human.status.code(), Some(1));
        assert!(human.stdout.is_empty());
        assert!(String::from_utf8(human.stderr)
            .unwrap()
            .contains("must be of type array"));
    }
}

#[test]
fn namespace_human_values_are_literal_and_machine_values_are_unchanged() {
    let scratch = Scratch::new();
    let attack = "<error>UNTRUSTED</error>\nFORGED\u{1b}[2J";
    let server = Server::new(
        info(),
        200,
        &json!({"namespaces":[{
            "name": attack, "description": attack, "retention_days": attack, "status": attack
        }]})
        .to_string(),
    );
    let output = scratch.run(&server.url, &[]);
    assert_eq!(output.status.code(), Some(0));
    let human = String::from_utf8(output.stdout).unwrap();
    assert!(human.contains("<error>UNTRUSTED</error>\\nFORGED\\u{001b}[2J"));
    assert!(!human
        .chars()
        .any(|value| value.is_control() && value != '\n'));
    assert_eq!(
        success(scratch.run(&server.url, &["--json"]))["namespaces"][0]["name"],
        attack
    );
    let server = Server::new(
        info(),
        422,
        &json!({"message":attack,"reason":attack}).to_string(),
    );
    let output = scratch.run(&server.url, &[]);
    let human = String::from_utf8(output.stderr).unwrap();
    assert!(human.contains("<error>UNTRUSTED</error>\\nFORGED\\u{001b}[2J"));
}

#[test]
fn namespace_usage_tls_and_compatibility_refusals_send_no_target_request() {
    let scratch = Scratch::new();
    let mut incompatible = info();
    incompatible["control_plane"]["version"] = json!("99");
    let server = Server::new(incompatible, 200, &body().to_string());
    assert_eq!(
        scratch.run(&server.url, &["--bad-option"]).status.code(),
        Some(1)
    );
    assert!(server.requests.lock().unwrap().is_empty());
    assert_eq!(scratch.run(&server.url, &["--json"]).status.code(), Some(8));
    assert!(server.namespace_requests().is_empty());
    let server = Server::new(info(), 200, &body().to_string());
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
fn namespace_describe_encodes_the_name_and_preserves_the_resource() {
    let scratch = Scratch::new();
    let server = Server::new(
        info(),
        200,
        r#"{"name":"billing","namespace":"server-billing","extra":"preserved"}"#,
    );
    let result = success(scratch.describe(&server.url, "bill /?#é", &["--json"]));
    assert_eq!(result["namespace"], "server-billing");
    assert_eq!(result["name"], "billing");
    assert_eq!(result["extra"], "preserved");
    let requests = server.describe_requests();
    assert_eq!(requests.len(), 1);
    assert!(requests[0]
        .to_lowercase()
        .starts_with("get /prefix/api/namespaces/bill%20%2f%3f%23%c3%a9 http/1.1"));
    assert!(requests[0].contains("Bearer fixture-token"));
}

#[test]
fn namespace_list_does_not_change_list_shaped_items_without_a_resource_name() {
    let scratch = Scratch::new();
    let payload = json!({"namespaces": [[], ["unnamed"], {"name": " "}]});
    let server = Server::new(info(), 200, &payload.to_string());
    assert_eq!(success(scratch.run(&server.url, &["--json"])), payload);
}

#[test]
fn namespace_describe_jsonl_is_one_complete_resource_and_keeps_fallback_rules() {
    let scratch = Scratch::new();
    for body in [
        r#"{}"#,
        r#"{"name":null,"namespace":"preserved"}"#,
        r#"{"name":"reported"}"#,
    ] {
        let server = Server::new(info(), 200, body);
        let output = scratch.describe(&server.url, "requested", &["--output=jsonl"]);
        assert_eq!(output.status.code(), Some(0));
        let text = String::from_utf8(output.stdout).unwrap();
        assert_eq!(text.lines().count(), 1);
        let result: Value = serde_json::from_str(&text).unwrap();
        let original: Value = serde_json::from_str(body).unwrap();
        let expected_name = original
            .get("name")
            .and_then(Value::as_str)
            .unwrap_or("requested");
        assert_eq!(result["name"], expected_name);
        assert_eq!(
            result["namespace"],
            original
                .get("namespace")
                .and_then(Value::as_str)
                .unwrap_or(expected_name)
        );
    }
}

#[test]
fn namespace_describe_human_fields_are_literal_and_errors_remain_global() {
    let scratch = Scratch::new();
    let attack = "<error>UNTRUSTED</error>\nFORGED\u{1b}[2J";
    let server = Server::new(
        info(),
        200,
        &json!({"name":attack,"description":attack,"status":attack}).to_string(),
    );
    let output = scratch.describe(&server.url, "billing", &[]);
    assert_eq!(output.status.code(), Some(0));
    let human = String::from_utf8(output.stdout).unwrap();
    assert!(human.contains("<error>UNTRUSTED</error>\\nFORGED\\u{001b}[2J"));
    assert!(!human
        .chars()
        .any(|value| value.is_control() && value != '\n'));
    for (status, exit) in [(403, 4), (404, 5), (500, 6)] {
        let server = Server::new(info(), status, r#"{"message":"describe refusal"}"#);
        let output = scratch.describe(&server.url, "billing", &["--json"]);
        assert_eq!(output.status.code(), Some(exit));
        let result: Value = serde_json::from_slice(&output.stdout).unwrap();
        assert!(result.get("namespace").is_none());
        assert_eq!(result["command"], "namespace:describe");
        assert_eq!(server.describe_requests().len(), 1);
    }
}

#[test]
fn namespace_describe_refuses_invalid_path_segments_before_the_target_request() {
    let scratch = Scratch::new();
    let server = Server::new(info(), 200, "{}");
    for name in ["", ".", ".."] {
        let output = scratch.describe(&server.url, name, &["--json"]);
        assert_eq!(output.status.code(), Some(2));
        assert!(server.describe_requests().is_empty());
    }
    let output = scratch
        .command_named("namespace:describe")
        .args(["--server", &server.url])
        .output()
        .unwrap();
    assert_eq!(output.status.code(), Some(1));
}

#[test]
fn namespace_reads_refuse_redirects_without_following_or_reporting_success() {
    let scratch = Scratch::new();
    for describe in [false, true] {
        for status in [301, 302, 303, 307, 308] {
            let server = Server::new(
                info(),
                status,
                r#"{"message":"redirected","name":"billing"}"#,
            );
            let output = if describe {
                scratch.describe(&server.url, "billing", &["--json"])
            } else {
                scratch.run(&server.url, &["--json"])
            };
            assert_eq!(output.status.code(), Some(1));
            let result: Value = serde_json::from_slice(&output.stdout).unwrap();
            assert_eq!(result["error"], "Server error: redirected");
            let requests = server.requests.lock().unwrap();
            let target = requests
                .iter()
                .filter(|request| !request.contains("/api/cluster/info "))
                .collect::<Vec<_>>();
            assert_eq!(target.len(), 1);
            assert!(!target[0].contains("unexpected-redirect"));
        }
    }
}
