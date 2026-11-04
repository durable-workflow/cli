use serde_json::{json, Value};
use std::fs;
use std::io::{Read, Write};
use std::net::TcpListener;
use std::path::PathBuf;
use std::process::{Command, Output, Stdio};
use std::sync::atomic::{AtomicBool, AtomicU64, AtomicUsize, Ordering};
use std::sync::Arc;
use std::thread::{self, JoinHandle};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

struct Scratch(PathBuf);

impl Scratch {
    fn new() -> Self {
        static NEXT: AtomicU64 = AtomicU64::new(0);
        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        for _ in 0..64 {
            let count = NEXT.fetch_add(1, Ordering::Relaxed);
            let path = std::env::temp_dir().join(format!(
                "dw-doctor-security-{}-{now}-{count}",
                std::process::id()
            ));
            let mut builder = fs::DirBuilder::new();
            #[cfg(unix)]
            {
                use std::os::unix::fs::DirBuilderExt;
                builder.mode(0o700);
            }
            match builder.create(&path) {
                Ok(()) => return Self(path),
                Err(error) if error.kind() == std::io::ErrorKind::AlreadyExists => continue,
                Err(error) => panic!("cannot create doctor test directory: {error}"),
            }
        }
        panic!("doctor test directory collisions exceeded the retry limit");
    }

    fn command(&self) -> Command {
        let binary = std::env::var_os("DW_DOCTOR_TEST_BINARY")
            .map(PathBuf::from)
            .unwrap_or_else(|| env!("CARGO_BIN_EXE_dw").into());
        let mut command = Command::new(binary);
        command
            .arg("doctor")
            .current_dir(&self.0)
            .env_clear()
            .env("HOME", &self.0)
            .env("DW_CONFIG_HOME", &self.0)
            .env("PATH", std::env::var_os("PATH").unwrap_or_default());
        command
    }

    fn profile(&self, name: &str, url: &str) {
        fs::write(
            self.0.join("config.json"),
            serde_json::to_vec(&json!({
                "schema": "durable-workflow.cli.config", "version": 1,
                "current_env": name, "envs": {(name): {"server": url}}
            }))
            .unwrap(),
        )
        .unwrap();
    }
}

impl Drop for Scratch {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}

struct Server {
    url: String,
    requests: Arc<AtomicUsize>,
    stop: Arc<AtomicBool>,
    thread: Option<JoinHandle<()>>,
}

impl Server {
    fn new(status: u16, body: Value) -> Self {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let url = format!("http://{}", listener.local_addr().unwrap());
        listener.set_nonblocking(true).unwrap();
        let requests = Arc::new(AtomicUsize::new(0));
        let stop = Arc::new(AtomicBool::new(false));
        let observed = requests.clone();
        let stopped = stop.clone();
        let body = serde_json::to_vec(&body).unwrap();
        let thread = thread::spawn(move || {
            while !stopped.load(Ordering::Relaxed) {
                match listener.accept() {
                    Ok((mut stream, _)) => {
                        observed.fetch_add(1, Ordering::Relaxed);
                        stream
                            .set_read_timeout(Some(Duration::from_secs(1)))
                            .unwrap();
                        let mut buffer = [0; 4096];
                        let _ = stream.read(&mut buffer);
                        let header = format!(
                            "HTTP/1.1 {status} Test\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",
                            body.len()
                        );
                        let _ = stream.write_all(header.as_bytes());
                        let _ = stream.write_all(&body);
                    }
                    Err(error) if error.kind() == std::io::ErrorKind::WouldBlock => {
                        thread::sleep(Duration::from_millis(2));
                    }
                    Err(error) => panic!("doctor fixture accept failed: {error}"),
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
}

impl Drop for Server {
    fn drop(&mut self) {
        self.stop.store(true, Ordering::Relaxed);
        self.thread.take().unwrap().join().unwrap();
    }
}

fn bounded(mut command: Command) -> Output {
    let mut child = command
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .unwrap();
    let deadline = Instant::now() + Duration::from_secs(5);
    while child.try_wait().unwrap().is_none() {
        if Instant::now() > deadline {
            let _ = child.kill();
            let _ = child.wait();
            panic!("doctor exceeded the fixture deadline");
        }
        thread::sleep(Duration::from_millis(5));
    }
    child.wait_with_output().unwrap()
}

fn credential_cases(source: &str) {
    let server = Server::new(200, json!({"server_id": "fixture"}));
    let closed = TcpListener::bind("127.0.0.1:0").unwrap();
    let unavailable = format!("http://{}", closed.local_addr().unwrap());
    drop(closed);
    for base in [&server.url, &unavailable] {
        let authority = base.strip_prefix("http://").unwrap();
        for url in [
            format!("http://fixture-url-user:fixture-url-password@{authority}/runtime"),
            format!("http://fixture-url-user@{authority}/runtime"),
            format!("{base}/runtime?token=fixture-url-query"),
            format!("{base}/runtime?signature=fixture-url-signature"),
            format!("{base}/runtime#fixture-url-fragment"),
        ] {
            for mode in ["--no-ansi", "--output=json", "--output=jsonl"] {
                let scratch = Scratch::new();
                let mut command = scratch.command();
                command.arg(mode);
                match source {
                    "flag" => {
                        command.arg(format!("--server={url}"));
                    }
                    "environment" => {
                        command.env("DURABLE_WORKFLOW_SERVER_URL", &url);
                    }
                    "profile" => {
                        scratch.profile("target", &url);
                        command.arg("--env=target");
                    }
                    _ => unreachable!(),
                }
                let output = bounded(command);
                for bytes in [&output.stdout, &output.stderr] {
                    let text = String::from_utf8_lossy(bytes);
                    assert!(
                        !text.contains("fixture-url-"),
                        "URL credential leaked via {source} {mode}: {text}"
                    );
                }
                assert_eq!(
                    output.status.code(),
                    Some(2),
                    "credential URL must refuse before diagnostics: {source} {mode}"
                );
                if mode != "--no-ansi" {
                    let data: Value = serde_json::from_slice(&output.stdout).unwrap();
                    assert!(!data.to_string().contains("fixture-url-"));
                    assert_eq!(data["exit_code"], 2);
                }
            }
        }
    }
    assert_eq!(
        server.requests.load(Ordering::Relaxed),
        0,
        "credential-bearing runtime URL reached the network"
    );
}

#[test]
fn flag_url_credentials_are_refused_without_echo_or_request() {
    credential_cases("flag");
}

#[test]
fn environment_url_credentials_are_refused_without_echo_or_request() {
    credential_cases("environment");
}

#[test]
fn profile_url_credentials_are_refused_without_echo_or_request() {
    credential_cases("profile");
}

fn attack() -> &'static str {
    "<error>UNTRUSTED</error>\nFORGED_HEADING:\r\t\u{1b}[2J\u{1b}]8;;https://fixture.invalid\u{7}link\u{1b}]8;;\u{7}\u{85}\u{9b}31m\u{2028}\u{2029}"
}

fn assert_safe_human(output: &Output, code: i32) {
    assert_eq!(output.status.code(), Some(code));
    let text = String::from_utf8(output.stdout.clone()).unwrap();
    let plain = text.replace("\u{1b}[32m", "").replace("\u{1b}[39m", "");
    assert!(
        plain.contains("<error>UNTRUSTED</error>"),
        "remote formatter markup was interpreted: {plain:?}"
    );
    assert!(
        !plain.chars().any(|c| c.is_control() && c != '\n'),
        "remote terminal controls survived: {plain:?}"
    );
    assert!(!plain.contains(['\u{2028}', '\u{2029}']));
    assert!(
        !plain.contains("\nFORGED_HEADING:"),
        "remote text forged a diagnostic section"
    );
    assert!(output.stderr.is_empty());
}

#[test]
fn cluster_info_is_safe_in_human_output_and_exact_in_json() {
    let text = attack();
    let info = json!({
        "server_id": text, "version": text, "default_namespace": text,
        "control_plane": {"version": text},
        "worker_protocol": {"version": text, "invocable_carrier_contract": {
            "schema": text, "version": 1, "carrier_type": text
        }}
    });
    let server = Server::new(200, info.clone());
    for mode in ["--no-ansi", "--ansi", "--output=json", "--output=jsonl"] {
        let scratch = Scratch::new();
        scratch.profile(text, &server.url);
        let mut command = scratch.command();
        command.arg(mode);
        let output = bounded(command);
        if mode.starts_with("--output=") {
            assert_eq!(output.status.code(), Some(0));
            let data: Value = serde_json::from_slice(&output.stdout).unwrap();
            assert_eq!(data["server"]["cluster_info"], info);
            assert_eq!(data["connection"]["profile"]["name"], text);
        } else {
            assert_safe_human(&output, 0);
        }
    }
}

#[test]
fn http_error_text_is_safe_in_human_output_and_exact_in_json() {
    let server = Server::new(500, json!({"message": attack()}));
    for mode in ["--no-ansi", "--ansi", "--output=json", "--output=jsonl"] {
        let scratch = Scratch::new();
        let mut command = scratch.command();
        command.arg(format!("--server={}", server.url)).arg(mode);
        let output = bounded(command);
        if mode.starts_with("--output=") {
            assert_eq!(output.status.code(), Some(6));
            let data: Value = serde_json::from_slice(&output.stdout).unwrap();
            assert_eq!(
                data["server"]["error"],
                format!("Server error: {}", attack())
            );
        } else {
            assert_safe_human(&output, 6);
        }
    }
}
