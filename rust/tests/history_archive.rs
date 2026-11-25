//! `workflow:history-archive` and `workflow:history-archive-verify`
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
            "workflow:history-archive-verify",
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
