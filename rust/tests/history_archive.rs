//! `workflow:history-archive` and `workflow:history-archive-check`
//! (durable-workflow/server#520) against the real native Server that
//! `dw server:start-dev` runs, with a local external payload store.
#![cfg(unix)]
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use std::fs;
use std::io::{BufRead, BufReader};
use std::path::{Path, PathBuf};
use std::process::{Child, Command, Output, Stdio};
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::mpsc;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

const OTHER_OWNER: &str = "5f0c2a1e-0000-4000-8000-0000000005b2";

struct Project(PathBuf);

impl Project {
    fn new() -> Self {
        static NEXT: AtomicU64 = AtomicU64::new(0);
        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        let root = std::env::temp_dir().join(format!(
            "dw-history-archive-{}-{now}-{}",
            std::process::id(),
            NEXT.fetch_add(1, Ordering::Relaxed)
        ));
        fs::create_dir(&root).unwrap();
        Self(root)
    }

    fn command(&self, arguments: &[&str]) -> Command {
        let mut command = Command::new(env!("CARGO_BIN_EXE_dw"));
        command
            .args(arguments)
            .current_dir(&self.0)
            .env_clear()
            .env("HOME", &self.0)
            .env("DW_CONFIG_HOME", self.0.join("config"))
            .stdin(Stdio::null());
        #[cfg(windows)]
        if let Some(root) = std::env::var_os("SystemRoot") {
            command.env("SystemRoot", root);
        }
        command
    }

    fn run(&self, args: &[&str]) -> Output {
        let mut child = self
            .command(args)
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .spawn()
            .unwrap();
        let deadline = Instant::now() + Duration::from_secs(60);
        while child.try_wait().unwrap().is_none() {
            if Instant::now() >= deadline {
                let _ = child.kill();
                panic!("dw exceeded test deadline");
            }
            std::thread::sleep(Duration::from_millis(10));
        }
        child.wait_with_output().unwrap()
    }

    /// `dw <args> --server <url> --token=dev-token --json`: (exit, JSON stdout, stderr).
    fn dw(&self, server: &DevServer, args: &[&str]) -> (i32, Value, String) {
        let mut args = args.to_vec();
        args.extend(["--server", &server.url, "--token=dev-token", "--json"]);
        let output = self.run(&args);
        (
            output.status.code().unwrap_or(-1),
            serde_json::from_slice(&output.stdout).unwrap_or(Value::Null),
            String::from_utf8_lossy(&output.stderr).into_owned(),
        )
    }

    fn verify(&self, dir: &Path) -> (i32, Value) {
        let output = self.run(&[
            "workflow:history-archive-check",
            dir.to_str().unwrap(),
            "--json",
        ]);
        (
            output.status.code().unwrap_or(-1),
            serde_json::from_slice(&output.stdout).unwrap_or(Value::Null),
        )
    }
}

impl Drop for Project {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}

struct DevServer {
    child: Child,
    url: String,
    runtime: tokio::runtime::Runtime,
}

impl DevServer {
    fn start(project: &Project) -> Self {
        let mut child = project
            .command(&["server:start-dev", "--port=0"])
            .stdout(Stdio::piped())
            .stderr(Stdio::inherit())
            .spawn()
            .unwrap();
        let stdout = child.stdout.take().unwrap();
        let (ready, receiver) = mpsc::channel();
        std::thread::spawn(move || {
            for line in BufReader::new(stdout).lines() {
                let Ok(line) = line else { break };
                if let Some((_, url)) = line.split_once("listening on ") {
                    let _ = ready.send(url.to_owned());
                }
            }
        });
        let url = receiver
            .recv_timeout(Duration::from_secs(30))
            .expect("native startup");
        let runtime = tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()
            .unwrap();
        Self {
            child,
            url,
            runtime,
        }
    }

    fn stop(&mut self) {
        let _ = self.child.kill();
        let _ = self.child.wait();
    }

    /// One admin request; `headers` are added as given.
    fn request(
        &self,
        method: &str,
        path: &str,
        headers: &[(&str, String)],
        body: Vec<u8>,
    ) -> (u16, Value) {
        self.runtime.block_on(async {
            let client = reqwest::Client::builder()
                .no_proxy()
                .timeout(Duration::from_secs(10))
                .build()
                .unwrap();
            let mut request = client
                .request(method.parse().unwrap(), format!("{}{path}", self.url))
                .bearer_auth("dev-token")
                .header("X-Durable-Workflow-Control-Plane-Version", "2")
                .body(body);
            for (name, value) in headers {
                request = request.header(*name, value);
            }
            let response = request.send().await.unwrap();
            let status = response.status().as_u16();
            (status, response.json().await.unwrap_or(Value::Null))
        })
    }

    fn json(&self, method: &str, path: &str, body: Value) -> (u16, Value) {
        self.request(
            method,
            path,
            &[("Content-Type", "application/json".into())],
            body.to_string().into_bytes(),
        )
    }

    /// Upload `text` as an Avro payload; answers its reference.
    fn upload(&self, text: &str) -> Value {
        use durable_workflow_server::codec::{Value as Payload, ValueCodec};
        let blob = ValueCodec::new()
            .unwrap()
            .encode(&Payload::Array(vec![Payload::String(text.into())]))
            .unwrap();
        let sha: String = Sha256::digest(blob.as_bytes())
            .iter()
            .map(|byte| format!("{byte:02x}"))
            .collect();
        let (status, body) = self.request(
            "POST",
            "/api/external-payloads/v1",
            &[
                ("Content-Type", "application/octet-stream".into()),
                ("X-Durable-Workflow-Payload-Codec", "avro".into()),
                ("X-Durable-Workflow-Payload-Size", blob.len().to_string()),
                ("X-Durable-Workflow-Payload-SHA256", sha),
            ],
            blob.into_bytes(),
        );
        assert_eq!(status, 201, "{body}");
        body["reference"].clone()
    }

    /// A run of `workflow` whose input is stored by reference, terminated
    /// unless `open`: (run id, reference).
    fn run_with_external_input(&self, workflow: &str, open: bool) -> (String, Value) {
        let reference = self.upload(&format!("{workflow} input"));
        let (status, started) = self.json("POST", "/api/workflows", json!({"workflow_id": workflow,
            "workflow_type": "echo", "task_queue": workflow, "input": {"codec": "avro", "external_payload": reference}}));
        assert_eq!(status, 201, "{started}");
        if !open {
            let (status, body) = self.json(
                "POST",
                &format!("/api/workflows/{workflow}/terminate"),
                json!({"reason": "archive"}),
            );
            assert_eq!(status, 200, "{body}");
        }
        (started["run_id"].as_str().unwrap().to_owned(), reference)
    }
}

impl Drop for DevServer {
    fn drop(&mut self) {
        self.stop();
    }
}

/// A dev Server whose default namespace stores payloads under `payloads/`.
fn server(project: &Project) -> DevServer {
    let server = DevServer::start(project);
    let root = project.0.join("payloads");
    let (status, body) = server.json("PUT", "/api/namespaces/default/external-storage", json!({"driver": "local",
        "enabled": true, "threshold_bytes": 16, "config": {"uri": format!("file://{}", root.display())}}));
    assert_eq!(status, 200, "{body}");
    server
}

fn codes(report: &Value) -> Vec<String> {
    report["findings"]
        .as_array()
        .unwrap()
        .iter()
        .map(|finding| finding["code"].as_str().unwrap().to_owned())
        .collect()
}

#[test]
fn an_archive_verifies_after_the_server_and_payload_store_are_gone() {
    let project = Project::new();
    let mut server = server(&project);
    let (run, reference) = server.run_with_external_input("archived", false);
    let dir = project.0.join("archive");
    let directory = dir.to_str().unwrap();

    let (code, result, stderr) = project.dw(
        &server,
        &["workflow:history-archive", "archived", &run, directory],
    );
    assert_eq!(code, 0, "{result} {stderr}");
    assert_eq!(result["outcome"], "verified", "{result}");
    assert_eq!(
        (
            result["objects"].clone(),
            result["copied"].clone(),
            result["resumed"].clone()
        ),
        (json!(1), json!(1), json!(0))
    );
    assert_eq!(result["verification"]["outcome"], "verified");
    let id = reference["reference_id"].as_str().unwrap();
    let bytes = fs::read(dir.join("objects").join(id)).unwrap();
    assert_eq!(
        bytes.len() as i64,
        reference["size_bytes"].as_i64().unwrap()
    );
    let manifest: Value =
        serde_json::from_slice(&fs::read(dir.join("manifest.json")).unwrap()).unwrap();
    assert_eq!(
        manifest["schema"],
        "durable-workflow.history-archive.manifest.v1"
    );
    assert_eq!(manifest["objects"][0]["sha256"], reference["sha256"]);
    let receipt: Value =
        serde_json::from_slice(&fs::read(dir.join("receipt.json")).unwrap()).unwrap();
    assert_eq!(receipt["outcome"], "verified");
    assert!(
        !dir.join(".dw-history-archive.json").exists(),
        "the progress record outlived the copy"
    );
    // The hold was released.
    let (status, hold) = server.request(
        "GET",
        "/api/system/external-payload-backup-hold",
        &[],
        Vec::new(),
    );
    assert_eq!((status, &hold["active"]), (200, &json!(false)), "{hold}");

    // A completed archive is never overwritten.
    let (code, error, _) = project.dw(
        &server,
        &["workflow:history-archive", "archived", &run, directory],
    );
    assert_eq!(code, 2, "{error}");
    assert!(
        error["error"]
            .as_str()
            .unwrap()
            .contains("already holds a completed archive"),
        "{error}"
    );

    // Without the Server or its payload store, the archive still verifies.
    server.stop();
    fs::remove_dir_all(project.0.join("payloads")).unwrap();
    let (code, report) = project.verify(&dir);
    assert_eq!(code, 0, "{report}");
    assert_eq!(report["outcome"], "verified");
    assert_eq!(report["verified_objects"], 1);

    // Damage is reported with its finding and exit 1.
    let mut damaged = bytes.clone();
    damaged[0] ^= 1;
    fs::write(dir.join("objects").join(id), damaged).unwrap();
    let (code, report) = project.verify(&dir);
    assert_eq!(
        (code, report["outcome"].clone()),
        (1, json!("incomplete")),
        "{report}"
    );
    assert_eq!(codes(&report), ["object_digest_mismatch"]);
    fs::write(dir.join("objects").join(id), &bytes).unwrap();
    fs::remove_file(dir.join("receipt.json")).unwrap();
    let (code, report) = project.verify(&dir);
    assert_eq!(
        (code, codes(&report)),
        (1, vec!["receipt_missing".to_owned()]),
        "{report}"
    );

    // The Server's v1 conformance archive verifies; a directory that is not
    // an archive is refused.
    let fixture = Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/history-archive-v1");
    assert_eq!(project.verify(&fixture).0, 0);
    let (code, report) = project.verify(&project.0);
    assert_eq!(
        (code, report["outcome"].clone()),
        (2, json!("refused")),
        "{report}"
    );
}

#[test]
fn an_unfinished_archive_resumes_and_releases_the_hold_a_crashed_copy_left() {
    let project = Project::new();
    let server = server(&project);
    let (run, _) = server.run_with_external_input("resumed", false);
    let dir = project.0.join("archive");
    let directory = dir.to_str().unwrap();
    let (code, result, stderr) = project.dw(
        &server,
        &["workflow:history-archive", "resumed", &run, directory],
    );
    assert_eq!(code, 0, "{result} {stderr}");

    // A copy crashed after fetching its object: no receipt, and its hold
    // still active under the owner it recorded.
    fs::remove_file(dir.join("receipt.json")).unwrap();
    let (status, body) = server.json(
        "POST",
        "/api/system/external-payload-backup-hold/acquire",
        json!({"owner": OTHER_OWNER, "ttl": 600}),
    );
    assert_eq!(status, 200, "{body}");
    fs::write(
        dir.join(".dw-history-archive.json"),
        json!({"schema": "dw.history-archive.in-progress.v1",
        "owner": OTHER_OWNER, "workflow_id": "resumed", "run_id": run})
        .to_string(),
    )
    .unwrap();

    let (code, result, stderr) = project.dw(
        &server,
        &["workflow:history-archive", "resumed", &run, directory],
    );
    assert_eq!(code, 0, "{result} {stderr}");
    assert_eq!(result["outcome"], "verified", "{result}");
    assert_eq!(
        (result["copied"].clone(), result["resumed"].clone()),
        (json!(0), json!(1))
    );
    assert_ne!(result["hold"]["owner"], OTHER_OWNER);
    assert_eq!(project.verify(&dir).0, 0);

    // An unfinished archive of another run is not touched.
    let (other, _) = server.run_with_external_input("other", false);
    fs::remove_file(dir.join("receipt.json")).unwrap();
    let (code, error, _) = project.dw(
        &server,
        &["workflow:history-archive", "other", &other, directory],
    );
    assert_eq!(code, 2, "{error}");
    assert!(
        error["error"].as_str().unwrap().contains("another run"),
        "{error}"
    );
}

#[test]
fn open_runs_held_holds_and_foreign_directories_are_refused_before_copying() {
    let project = Project::new();
    let server = server(&project);

    // An open run's history is incomplete: refused, and nothing is left.
    let (open, _) = server.run_with_external_input("open", true);
    let dir = project.0.join("open-archive");
    let (code, result, stderr) = project.dw(
        &server,
        &[
            "workflow:history-archive",
            "open",
            &open,
            dir.to_str().unwrap(),
        ],
    );
    assert_eq!(code, 2, "{result} {stderr}");
    assert_eq!(result["outcome"], "refused", "{result}");
    assert_eq!(
        result["blockers"][0]["code"], "history_incomplete",
        "{result}"
    );
    assert!(!dir.exists(), "a refused archive left its directory");

    // Another backup holds the hold: refused with the Server's 409.
    let (run, _) = server.run_with_external_input("closed", false);
    let (status, body) = server.json(
        "POST",
        "/api/system/external-payload-backup-hold/acquire",
        json!({"owner": OTHER_OWNER, "ttl": 600}),
    );
    assert_eq!(status, 200, "{body}");
    let dir = project.0.join("held-archive");
    let (code, _, stderr) = project.dw(
        &server,
        &[
            "workflow:history-archive",
            "closed",
            &run,
            dir.to_str().unwrap(),
        ],
    );
    assert_eq!(code, 2, "{stderr}");
    assert!(!dir.exists());
    let (status, body) = server.json(
        "POST",
        "/api/system/external-payload-backup-hold/release",
        json!({"owner": OTHER_OWNER}),
    );
    assert_eq!(status, 200, "{body}");

    // A directory with other files is never written into.
    let foreign = project.0.join("foreign");
    fs::create_dir(&foreign).unwrap();
    fs::write(foreign.join("notes.txt"), "keep").unwrap();
    let (code, _, stderr) = project.dw(
        &server,
        &[
            "workflow:history-archive",
            "closed",
            &run,
            foreign.to_str().unwrap(),
        ],
    );
    assert_eq!(code, 2, "{stderr}");
    assert_eq!(fs::read_dir(&foreign).unwrap().count(), 1);

    // An out-of-range hold lease is a usage error.
    let output = project.run(&[
        "workflow:history-archive",
        "closed",
        &run,
        "x",
        "--hold-ttl=5",
        "--server",
        &server.url,
    ]);
    assert_eq!(output.status.code(), Some(1));
}

/// A loopback Server for the paths dw calls while archiving. Each route
/// answers as given; every request line is recorded.
struct Mock {
    url: String,
    requests: std::sync::Arc<std::sync::Mutex<Vec<String>>>,
}

type Route = (&'static str, u16, Vec<u8>);

/// A route status that answers 200 only after 20 seconds.
const SLOW: u16 = 299;

impl Mock {
    fn new(routes: Vec<Route>) -> Self {
        use std::io::{Read, Write};
        let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
        let url = format!("http://{}/prefix", listener.local_addr().unwrap());
        let requests = std::sync::Arc::new(std::sync::Mutex::new(Vec::new()));
        let observed = requests.clone();
        let info = std::sync::Arc::new(
            include_bytes!("../src/compat/fixtures/cluster-info-php-2.11.8.json").to_vec(),
        );
        let routes = std::sync::Arc::new(routes);
        std::thread::spawn(move || {
            for stream in listener.incoming() {
                let Ok(mut stream) = stream else { continue };
                let (routes, observed, info) = (routes.clone(), observed.clone(), info.clone());
                // One thread per connection, so a slow answer blocks nothing else.
                std::thread::spawn(move || {
                    stream
                        .set_read_timeout(Some(Duration::from_secs(2)))
                        .unwrap();
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
                    let line = String::from_utf8_lossy(&bytes)
                        .lines()
                        .next()
                        .unwrap_or_default()
                        .to_owned();
                    let (mut status, body) = if line.starts_with("GET /prefix/api/cluster/info ") {
                        (200, info.to_vec())
                    } else {
                        observed.lock().unwrap().push(line.clone());
                        routes
                            .iter()
                            .find(|(prefix, _, _)| line.starts_with(prefix))
                            .map(|(_, status, body)| (*status, body.clone()))
                            .unwrap_or((404, b"{}".to_vec()))
                    };
                    if status == SLOW {
                        std::thread::sleep(Duration::from_secs(20));
                        status = 200;
                    }
                    let head = format!(
                    "HTTP/1.1 {status} Test\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",
                    body.len()
                );
                    let _ = stream.write_all(head.as_bytes());
                    let _ = stream.write_all(&body);
                });
            }
        });
        Self { url, requests }
    }

    fn lines(&self) -> Vec<String> {
        self.requests.lock().unwrap().clone()
    }
}

const MOCK_ID: &str = "ep_01ARZ3NDEKTSV4RRFFQ69G5FAV";
const MOCK_BYTES: &[u8] = b"archived bytes";

/// A complete export with one runtime reference to `MOCK_BYTES`.
fn mock_export() -> Vec<u8> {
    let sha: String = Sha256::digest(MOCK_BYTES)
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect();
    let envelope = json!({"codec": "avro", "external_payload": {"schema": "durable-workflow.v2.runtime-external-payload-reference.v1",
        "reference_id": MOCK_ID, "codec": "avro", "size_bytes": MOCK_BYTES.len(), "sha256": sha}});
    json!({"schema": "durable-workflow.v2.history-export", "schema_version": 2, "history_complete": true,
        "workflow": {"instance_id": "wf", "run_id": "run", "last_history_sequence": 1, "details_state": "retained", "details_pruned_at": null},
        "payloads": {"codec": "avro", "arguments": {"available": true, "data": envelope}},
        "history_events": [{"sequence": 1, "type": "StartAccepted", "payload": {}}],
        "redaction": {"applied": false}, "payload_manifest": {"version": 1, "entries": []}})
    .to_string()
    .into_bytes()
}

fn held() -> Vec<u8> {
    json!({"schema": "durable-workflow.external-payload-backup-hold.v1", "active": true, "owner": "x",
        "acquired_at": "2026-11-25 00:00:00", "expires_at": "2026-11-25 00:15:00", "database_time": "2026-11-25 00:00:01"})
    .to_string()
    .into_bytes()
}

fn not_owned() -> Vec<u8> {
    json!({"reason": "backup_hold_not_owned"})
        .to_string()
        .into_bytes()
}

const ACQUIRE: &str = "POST /prefix/api/system/external-payload-backup-hold/acquire ";
const RENEW: &str = "POST /prefix/api/system/external-payload-backup-hold/renew ";
const RELEASE: &str = "POST /prefix/api/system/external-payload-backup-hold/release ";
const STATUS: &str = "GET /prefix/api/system/external-payload-backup-hold?owner=";
const EXPORT: &str = "GET /prefix/api/workflows/wf/runs/run/history/export ";
const OBJECT: &str = "GET /prefix/api/external-payloads/v1/ep_01ARZ3NDEKTSV4RRFFQ69G5FAV ";

fn mock_archive(project: &Project, mock: &Mock, dir: &Path) -> (i32, Value) {
    let output = project.run(&[
        "workflow:history-archive",
        "wf",
        "run",
        dir.to_str().unwrap(),
        "--server",
        &mock.url,
        "--token=fixture-token",
        "--json",
    ]);
    (
        output.status.code().unwrap_or(-1),
        serde_json::from_slice(&output.stdout).unwrap_or(Value::Null),
    )
}

#[test]
fn a_lost_hold_or_a_failed_object_leaves_no_receipt_and_still_releases() {
    let project = Project::new();

    // The hold is no longer this owner's when the copy confirms it.
    let mock = Mock::new(vec![
        (ACQUIRE, 200, held()),
        (RENEW, 200, held()),
        (EXPORT, 200, mock_export()),
        (OBJECT, 200, MOCK_BYTES.to_vec()),
        (STATUS, 409, not_owned()),
        (RELEASE, 409, not_owned()),
    ]);
    let dir = project.0.join("lost");
    let (code, result) = mock_archive(&project, &mock, &dir);
    assert_eq!(
        (code, result["outcome"].clone()),
        (1, json!("incomplete")),
        "{result}"
    );
    assert_eq!(
        result["hold_lost"]["reason"], "backup_hold_not_owned",
        "{result}"
    );
    assert_eq!(result["verification"]["outcome"], "verified", "{result}");
    assert!(!dir.join("receipt.json").exists());
    assert!(
        mock.lines().iter().any(|line| line.starts_with(RELEASE)),
        "{:?}",
        mock.lines()
    );
    // The unfinished archive is resumable; no owner is left to release.
    let progress: Value =
        serde_json::from_slice(&fs::read(dir.join(".dw-history-archive.json")).unwrap()).unwrap();
    assert_eq!(progress["owner"], Value::Null, "{progress}");

    // A renewal refused before verification stops the copy the same way.
    let mock = Mock::new(vec![
        (ACQUIRE, 200, held()),
        (RENEW, 409, not_owned()),
        (EXPORT, 200, mock_export()),
        (OBJECT, 200, MOCK_BYTES.to_vec()),
        (STATUS, 200, held()),
        (RELEASE, 409, not_owned()),
    ]);
    let dir = project.0.join("renewal");
    let (code, result) = mock_archive(&project, &mock, &dir);
    assert_eq!(
        (code, result["outcome"].clone()),
        (1, json!("incomplete")),
        "{result}"
    );
    assert_eq!(
        result["hold_lost"]["reason"], "backup_hold_not_owned",
        "{result}"
    );
    assert!(
        !mock.lines().iter().any(|line| line.starts_with(STATUS)),
        "verified after losing the hold"
    );
    assert!(!dir.join("receipt.json").exists());

    // The owner holds the hold again, but from a later acquisition: the hold
    // lapsed in between, so no receipt.
    let reacquired = json!({"active": true, "owner": "x", "acquired_at": "2026-11-25 00:09:00",
        "expires_at": "2026-11-25 00:24:00", "database_time": "2026-11-25 00:09:01"});
    let mock = Mock::new(vec![
        (ACQUIRE, 200, held()),
        (RENEW, 200, held()),
        (EXPORT, 200, mock_export()),
        (OBJECT, 200, MOCK_BYTES.to_vec()),
        (STATUS, 200, reacquired.to_string().into_bytes()),
        (RELEASE, 200, held()),
    ]);
    let dir = project.0.join("reacquired");
    let (code, result) = mock_archive(&project, &mock, &dir);
    assert_eq!(
        (code, result["outcome"].clone()),
        (1, json!("incomplete")),
        "{result}"
    );
    assert!(!dir.join("receipt.json").exists());

    // The object is gone at the source.
    let mock = Mock::new(vec![
        (ACQUIRE, 200, held()),
        (RENEW, 200, held()),
        (EXPORT, 200, mock_export()),
        (
            OBJECT,
            404,
            json!({"reason": "external_payload_not_found"})
                .to_string()
                .into_bytes(),
        ),
        (STATUS, 200, held()),
        (RELEASE, 200, held()),
    ]);
    let dir = project.0.join("missing");
    let (code, result) = mock_archive(&project, &mock, &dir);
    assert_eq!(
        (code, result["outcome"].clone()),
        (1, json!("incomplete")),
        "{result}"
    );
    assert_eq!(
        result["failures"][0]["reason"], "external_payload_not_found",
        "{result}"
    );
    assert_eq!(
        result["verification"]["findings"][0]["code"], "object_missing",
        "{result}"
    );
    assert!(!dir.join("receipt.json").exists());
    assert!(dir.join("manifest.json").exists());
    assert!(mock.lines().iter().any(|line| line.starts_with(RELEASE)));
}

#[test]
fn a_resumed_copy_replaces_corrupt_and_partial_objects_and_releases_the_recorded_owner() {
    let project = Project::new();
    let dir = project.0.join("resume");
    fs::create_dir_all(dir.join("objects")).unwrap();
    fs::write(
        dir.join(".dw-history-archive.json"),
        json!({"schema": "dw.history-archive.in-progress.v1",
        "owner": OTHER_OWNER, "workflow_id": "wf", "run_id": "run"})
        .to_string(),
    )
    .unwrap();
    fs::write(dir.join("objects").join(MOCK_ID), b"archived bytez").unwrap();
    fs::write(
        dir.join("objects").join(format!("{MOCK_ID}.partial")),
        b"half",
    )
    .unwrap();
    fs::write(
        dir.join("objects").join("ep_01BX5ZZKBKACTAV9WEVGEMMVRZ"),
        b"no longer referenced",
    )
    .unwrap();
    let mock = Mock::new(vec![
        (ACQUIRE, 200, held()),
        (RENEW, 200, held()),
        (EXPORT, 200, mock_export()),
        (OBJECT, 200, MOCK_BYTES.to_vec()),
        (STATUS, 200, held()),
        (RELEASE, 200, held()),
    ]);
    let (code, result) = mock_archive(&project, &mock, &dir);
    assert_eq!(
        (code, result["outcome"].clone()),
        (0, json!("verified")),
        "{result}"
    );
    assert_eq!(
        (result["copied"].clone(), result["resumed"].clone()),
        (json!(1), json!(0)),
        "{result}"
    );
    assert_eq!(
        fs::read(dir.join("objects").join(MOCK_ID)).unwrap(),
        MOCK_BYTES
    );
    let mut names: Vec<String> = fs::read_dir(&dir)
        .unwrap()
        .map(|entry| entry.unwrap().file_name().to_string_lossy().into_owned())
        .collect();
    names.sort();
    assert_eq!(
        names,
        [
            "history-export.json",
            "manifest.json",
            "objects",
            "receipt.json"
        ]
    );
    assert_eq!(fs::read_dir(dir.join("objects")).unwrap().count(), 1);
    // The crashed copy's owner was released before this copy acquired.
    let lines = mock.lines();
    assert!(
        lines[0].starts_with(RELEASE) && lines[1].starts_with(ACQUIRE),
        "{lines:?}"
    );
    assert_eq!(project.verify(&dir).0, 0);

    // The recorded owner cannot be released: the run stops, and its record
    // still names that owner for the next run.
    let stuck = project.0.join("stuck");
    fs::create_dir_all(&stuck).unwrap();
    let record = json!({"schema": "dw.history-archive.in-progress.v1", "owner": OTHER_OWNER, "workflow_id": "wf", "run_id": "run"});
    fs::write(stuck.join(".dw-history-archive.json"), record.to_string()).unwrap();
    let mock = Mock::new(vec![(RELEASE, 500, b"{}".to_vec())]);
    let (code, _) = mock_archive(&project, &mock, &stuck);
    assert_eq!(code, 1);
    assert!(
        !mock.lines().iter().any(|line| line.starts_with(ACQUIRE)),
        "{:?}",
        mock.lines()
    );
    let kept: Value =
        serde_json::from_slice(&fs::read(stuck.join(".dw-history-archive.json")).unwrap()).unwrap();
    assert_eq!(kept["owner"], OTHER_OWNER, "{kept}");

    // A linked objects directory is refused before anything is touched.
    let linked = project.0.join("linked");
    fs::create_dir_all(&linked).unwrap();
    fs::write(
        linked.join(".dw-history-archive.json"),
        json!({"schema": "dw.history-archive.in-progress.v1",
        "owner": null, "workflow_id": "wf", "run_id": "run"})
        .to_string(),
    )
    .unwrap();
    let target = project.0.join("elsewhere");
    fs::create_dir_all(&target).unwrap();
    fs::write(target.join(MOCK_ID), b"keep").unwrap();
    std::os::unix::fs::symlink(&target, linked.join("objects")).unwrap();
    let mock = Mock::new(vec![]);
    let (code, _) = mock_archive(&project, &mock, &linked);
    assert_eq!(code, 2);
    assert!(mock.lines().is_empty(), "{:?}", mock.lines());
    assert_eq!(fs::read(target.join(MOCK_ID)).unwrap(), b"keep");
}

#[test]
fn a_refused_acquire_surfaces_its_error_and_leaves_no_owner_recorded() {
    let project = Project::new();
    let dir = project.0.join("forbidden");
    let mock = Mock::new(vec![(
        ACQUIRE,
        403,
        json!({"reason": "forbidden", "message": "This action requires the admin role."})
            .to_string()
            .into_bytes(),
    )]);
    let (code, error) = mock_archive(&project, &mock, &dir);
    assert_eq!(code, 4, "{error}");
    assert!(!dir.exists(), "a refused acquire left its directory");
    // The owner never held the hold: nothing to release.
    assert!(
        !mock.lines().iter().any(|line| line.starts_with(RELEASE)),
        "{:?}",
        mock.lines()
    );

    // A 5xx may hide a hold that was taken: its owner is released and kept.
    let unclear = project.0.join("unclear");
    let mock = Mock::new(vec![
        (ACQUIRE, 503, b"{}".to_vec()),
        (RELEASE, 500, b"{}".to_vec()),
    ]);
    let (code, error) = mock_archive(&project, &mock, &unclear);
    assert_eq!(code, 6, "{error}");
    assert!(
        mock.lines().iter().any(|line| line.starts_with(RELEASE)),
        "{:?}",
        mock.lines()
    );
    let kept: Value =
        serde_json::from_slice(&fs::read(unclear.join(".dw-history-archive.json")).unwrap())
            .unwrap();
    assert!(kept["owner"].is_string(), "{kept}");

    // An unfinished archive recorded against another Server is not resumed
    // here, so its recorded owner is never released at the wrong Server.
    let elsewhere = project.0.join("elsewhere");
    fs::create_dir_all(&elsewhere).unwrap();
    fs::write(elsewhere.join(".dw-history-archive.json"), json!({"schema": "dw.history-archive.in-progress.v1",
        "owner": OTHER_OWNER, "workflow_id": "wf", "run_id": "run", "server": "http://127.0.0.1:9/other",
        "namespace": "default"}).to_string()).unwrap();
    let mock = Mock::new(vec![]);
    let (code, error) = mock_archive(&project, &mock, &elsewhere);
    assert_eq!(code, 2, "{error}");
    assert!(
        error["error"]
            .as_str()
            .unwrap()
            .contains("--server=http://127.0.0.1:9/other"),
        "{error}"
    );
    assert!(mock.lines().is_empty(), "{:?}", mock.lines());
}

/// Start `dw workflow:history-archive` against `mock`, wait until the mock
/// has seen a request starting with `first`, and send `signal`.
fn signal_during(
    project: &Project,
    mock: &Mock,
    dir: &Path,
    first: &str,
    signal: i32,
) -> std::process::Child {
    let child = project
        .command(&[
            "workflow:history-archive",
            "wf",
            "run",
            dir.to_str().unwrap(),
            "--server",
            &mock.url,
            "--token=fixture-token",
            "--json",
        ])
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .unwrap();
    wait_for(mock, first);
    unsafe { libc::kill(child.id() as i32, signal) };
    child
}

fn wait_for(mock: &Mock, prefix: &str) {
    let deadline = Instant::now() + Duration::from_secs(30);
    while !mock.lines().iter().any(|line| line.starts_with(prefix)) {
        assert!(
            Instant::now() < deadline,
            "never saw {prefix}: {:?}",
            mock.lines()
        );
        std::thread::sleep(Duration::from_millis(20));
    }
}

fn exit_within(mut child: std::process::Child, seconds: u64) -> i32 {
    let deadline = Instant::now() + Duration::from_secs(seconds);
    loop {
        if let Some(status) = child.try_wait().unwrap() {
            return status.code().unwrap_or(-1);
        }
        if Instant::now() >= deadline {
            let _ = child.kill();
            panic!("dw did not exit within {seconds}s");
        }
        std::thread::sleep(Duration::from_millis(20));
    }
}

#[test]
fn sigterm_stops_the_copy_and_releases_and_a_second_signal_stops_the_release() {
    let project = Project::new();

    // SIGTERM while an object downloads: the copy stops, the hold is
    // released, and the unfinished archive keeps no owner.
    let mock = Mock::new(vec![
        (ACQUIRE, 200, held()),
        (RENEW, 200, held()),
        (EXPORT, 200, mock_export()),
        (OBJECT, SLOW, MOCK_BYTES.to_vec()),
        (RELEASE, 200, held()),
    ]);
    let dir = project.0.join("terminated");
    let child = signal_during(&project, &mock, &dir, OBJECT, libc::SIGTERM);
    assert_eq!(exit_within(child, 10), 143);
    assert!(
        mock.lines().iter().any(|line| line.starts_with(RELEASE)),
        "{:?}",
        mock.lines()
    );
    assert!(!dir.join("receipt.json").exists());
    let progress: Value =
        serde_json::from_slice(&fs::read(dir.join(".dw-history-archive.json")).unwrap()).unwrap();
    assert_eq!(progress["owner"], Value::Null, "{progress}");

    // Ctrl-C during the copy, then again while the release hangs: dw stops
    // waiting and keeps its owner recorded for the next run.
    let mock = Mock::new(vec![
        (ACQUIRE, 200, held()),
        (RENEW, 200, held()),
        (EXPORT, 200, mock_export()),
        (OBJECT, SLOW, MOCK_BYTES.to_vec()),
        (RELEASE, SLOW, held()),
    ]);
    let dir = project.0.join("interrupted");
    let child = signal_during(&project, &mock, &dir, OBJECT, libc::SIGINT);
    wait_for(&mock, RELEASE);
    unsafe { libc::kill(child.id() as i32, libc::SIGINT) };
    assert_eq!(exit_within(child, 10), 130);
    let progress: Value =
        serde_json::from_slice(&fs::read(dir.join(".dw-history-archive.json")).unwrap()).unwrap();
    assert!(progress["owner"].is_string(), "{progress}");
    assert_eq!(progress["server"], json!(mock.url), "{progress}");
}

#[test]
fn a_hard_linked_object_is_fetched_again_on_resume() {
    use std::os::unix::fs::MetadataExt;
    let project = Project::new();
    let dir = project.0.join("linked-object");
    fs::create_dir_all(dir.join("objects")).unwrap();
    fs::write(
        dir.join(".dw-history-archive.json"),
        json!({"schema": "dw.history-archive.in-progress.v1",
        "owner": null, "workflow_id": "wf", "run_id": "run"})
        .to_string(),
    )
    .unwrap();
    // The right bytes, but shared with a file outside the archive.
    let outside = project.0.join("outside-copy");
    fs::write(&outside, MOCK_BYTES).unwrap();
    fs::hard_link(&outside, dir.join("objects").join(MOCK_ID)).unwrap();
    let mock = Mock::new(vec![
        (ACQUIRE, 200, held()),
        (RENEW, 200, held()),
        (EXPORT, 200, mock_export()),
        (OBJECT, 200, MOCK_BYTES.to_vec()),
        (STATUS, 200, held()),
        (RELEASE, 200, held()),
    ]);
    let (code, result) = mock_archive(&project, &mock, &dir);
    assert_eq!(
        (code, result["outcome"].clone()),
        (0, json!("verified")),
        "{result}"
    );
    assert_eq!(
        (result["copied"].clone(), result["resumed"].clone()),
        (json!(1), json!(0)),
        "{result}"
    );
    assert_eq!(
        fs::metadata(dir.join("objects").join(MOCK_ID))
            .unwrap()
            .nlink(),
        1
    );
}
