//! The built dw starts a real native Server without any external executables.
use serde_json::Value;
use std::fs;
use std::io::{BufRead, BufReader};
use std::path::PathBuf;
use std::process::{Child, Command, Output, Stdio};
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{mpsc, Arc, Mutex};
use std::thread::JoinHandle;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

struct Project(PathBuf);
impl Project {
    fn new() -> Self {
        static NEXT: AtomicU64 = AtomicU64::new(0);
        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        let root = std::env::temp_dir().join(format!(
            "dw-native-dev-{}-{now}-{}",
            std::process::id(),
            NEXT.fetch_add(1, Ordering::Relaxed)
        ));
        fs::create_dir(&root).unwrap();
        fs::create_dir(root.join("empty-bin")).unwrap();
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
            .env("PATH", self.0.join("empty-bin"))
            .stdin(Stdio::null());
        #[cfg(windows)]
        {
            // Windows' network and SQLite libraries need the OS directory,
            // independent of PATH and any developer tools.
            if let Some(root) = std::env::var_os("SystemRoot") {
                command.env("SystemRoot", root);
            }
        }
        command
    }
    fn run(&self, args: &[&str]) -> Output {
        let child = self
            .command(args)
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .spawn()
            .unwrap();
        wait(child, Duration::from_secs(10))
    }
    fn client(&self, server: &DevServer, command: &[&str]) -> Value {
        let mut args = command.to_vec();
        args.extend([
            "--server",
            &server.url,
            "--token=dev-token",
            "--output=json",
        ]);
        let output = self.run(&args);
        assert!(
            output.status.success(),
            "{}{}",
            String::from_utf8_lossy(&output.stdout),
            String::from_utf8_lossy(&output.stderr)
        );
        serde_json::from_slice(&output.stdout).unwrap()
    }
}
impl Drop for Project {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}

fn wait(mut child: Child, duration: Duration) -> Output {
    let deadline = Instant::now() + duration;
    while child.try_wait().unwrap().is_none() {
        if Instant::now() >= deadline {
            let _ = child.kill();
            let _ = child.wait();
            panic!("dw exceeded test deadline");
        }
        std::thread::sleep(Duration::from_millis(10));
    }
    child.wait_with_output().unwrap()
}
struct DevServer {
    child: Child,
    url: String,
    text: Arc<Mutex<String>>,
    reader: Option<JoinHandle<()>>,
}
impl DevServer {
    fn start(project: &Project, extra: &[&str]) -> Self {
        let mut args = vec!["server:start-dev", "--port=0"];
        args.extend(extra);
        let mut child = project
            .command(&args)
            .stdout(Stdio::piped())
            .stderr(Stdio::inherit())
            .spawn()
            .unwrap();
        let stdout = child.stdout.take().unwrap();
        let (ready, receiver) = mpsc::channel();
        let text = Arc::new(Mutex::new(String::new()));
        let captured = text.clone();
        let reader = std::thread::spawn(move || {
            for line in BufReader::new(stdout).lines() {
                let Ok(line) = line else { break };
                if let Some((_, url)) = line.split_once("listening on ") {
                    let _ = ready.send(url.to_owned());
                }
                captured.lock().unwrap().push_str(&line);
                captured.lock().unwrap().push('\n');
            }
        });
        let url = match receiver.recv_timeout(Duration::from_secs(15)) {
            Ok(url) => url,
            Err(error) => {
                let _ = child.kill();
                let _ = child.wait();
                let _ = reader.join();
                panic!("native startup failed: {error}: {}", text.lock().unwrap());
            }
        };
        Self {
            child,
            url,
            text,
            reader: Some(reader),
        }
    }
    fn stop(&mut self) -> i32 {
        #[cfg(unix)]
        unsafe {
            libc::kill(self.child.id() as i32, libc::SIGTERM);
        }
        #[cfg(not(unix))]
        {
            let _ = self.child.kill();
        }
        let deadline = Instant::now() + Duration::from_secs(8);
        let code = loop {
            if let Some(status) = self.child.try_wait().unwrap() {
                break status.code().unwrap_or(1);
            }
            if Instant::now() >= deadline {
                let _ = self.child.kill();
                let _ = self.child.wait();
                panic!("native shutdown exceeded deadline");
            }
            std::thread::sleep(Duration::from_millis(10));
        };
        if let Some(reader) = self.reader.take() {
            reader.join().unwrap();
        }
        code
    }
    fn get(&self, path: &str, token: Option<&str>) -> (u16, Value) {
        let runtime = tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()
            .unwrap();
        runtime.block_on(async {
            let client = reqwest::Client::builder()
                .no_proxy()
                .timeout(Duration::from_secs(3))
                .build()
                .unwrap();
            let mut request = client.get(format!("{}{path}", self.url));
            if let Some(token) = token {
                request = request.bearer_auth(token);
            }
            let response = request.send().await.unwrap();
            let status = response.status().as_u16();
            let body = response.json().await.unwrap();
            (status, body)
        })
    }
}
impl Drop for DevServer {
    fn drop(&mut self) {
        if self.child.try_wait().ok().flatten().is_none() {
            let _ = self.child.kill();
            let _ = self.child.wait();
        }
        if let Some(reader) = self.reader.take() {
            let _ = reader.join();
        }
    }
}

#[test]
fn starts_in_an_empty_directory_with_bundled_sqlite_and_real_cli_admission() {
    let project = Project::new();
    let mut server = DevServer::start(&project, &[]);
    assert_eq!(server.get("/api/health", None).0, 200);
    let (status, ready) = server.get("/api/ready", None);
    assert_eq!(status, 200);
    assert_eq!(ready["development"], true);
    assert_eq!(server.get("/api/cluster/info", None).0, 401);
    let cluster = project.client(&server, &["server:info"]);
    assert!(cluster.get("capabilities").is_some());
    let started = project.client(
        &server,
        &[
            "workflow:start",
            "--type=dev.example",
            "--workflow-id=dev-first",
            "--input={}",
        ],
    );
    assert!(started.to_string().contains("dev-first"));
    let listed = project.client(&server, &["workflow:list"]);
    assert!(listed.to_string().contains("dev-first"));
    let described = project.client(&server, &["workflow:describe", "dev-first"]);
    assert!(described.to_string().contains("dev-first"));
    assert!(project
        .0
        .join("config/dev-server/database.sqlite")
        .is_file());
    assert!(!project.0.join("artisan").exists());
    let code = server.stop();
    #[cfg(unix)]
    assert_eq!(code, 143);
}

#[test]
fn restart_preserves_workflow_data_and_releases_the_directory_lock() {
    let project = Project::new();
    let directory = project.0.join("data with spaces");
    let args = ["--data-dir", directory.to_str().unwrap()];
    let mut first = DevServer::start(&project, &args);
    project.client(
        &first,
        &[
            "workflow:start",
            "--type=dev.example",
            "--workflow-id=keep-me",
            "--input=null",
        ],
    );
    let before = project.client(&first, &["workflow:describe", "keep-me"]);
    assert_eq!(before["workflow_id"], "keep-me");
    for name in ["run_id", "workflow_type", "status"] {
        assert!(
            before[name].as_str().is_some_and(|value| !value.is_empty()),
            "missing {name}"
        );
    }
    first.stop();
    let mut second = DevServer::start(&project, &args);
    let after = project.client(&second, &["workflow:describe", "keep-me"]);
    for name in ["workflow_id", "run_id", "workflow_type", "status"] {
        assert_eq!(before[name], after[name], "{name}");
    }
    assert!(after.to_string().contains("keep-me"));
    second.stop();
}

#[test]
fn same_data_directory_cannot_start_two_runtimes() {
    let project = Project::new();
    let mut first = DevServer::start(&project, &[]);
    let output = project.run(&["server:start-dev", "--port=0"]);
    assert_eq!(output.status.code(), Some(1));
    assert!(String::from_utf8_lossy(&output.stderr).contains("already in use"));
    assert_eq!(first.get("/api/ready", None).0, 200);
    first.stop();
}

#[test]
fn busy_port_refuses_before_creating_a_database() {
    let project = Project::new();
    let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
    let port = format!("--port={}", listener.local_addr().unwrap().port());
    let output = project.run(&["server:start-dev", &port]);
    assert_eq!(output.status.code(), Some(1));
    assert!(!project.0.join("config/dev-server/database.sqlite").exists());
}

#[test]
fn invalid_arguments_do_not_create_runtime_data() {
    let project = Project::new();
    for flags in [
        vec!["--port=65536"],
        vec!["--port=-1"],
        vec!["--port=8080.5"],
        vec!["--port"],
        vec!["--db=mysql"],
        vec!["--db=pgsql"],
        vec!["--db"],
        vec!["--host=0.0.0.0"],
        vec!["--host=example.test"],
        vec!["--host"],
        vec!["--token="],
        vec!["--data-dir="],
    ] {
        let mut args = vec!["server:start-dev"];
        args.extend(flags);
        let output = project.run(&args);
        assert_eq!(
            output.status.code(),
            Some(2),
            "{args:?}: {}",
            String::from_utf8_lossy(&output.stderr)
        );
        assert!(!project.0.join("config/dev-server").exists());
    }
}

#[test]
fn custom_token_is_enforced_and_is_not_printed() {
    let project = Project::new();
    let mut server = DevServer::start(&project, &["--token=synthetic-custom-token"]);
    assert_eq!(server.get("/api/cluster/info", Some("dev-token")).0, 401);
    assert_eq!(
        server
            .get("/api/cluster/info", Some("synthetic-custom-token"))
            .0,
        200
    );
    server.stop();
    assert!(!server
        .text
        .lock()
        .unwrap()
        .contains("synthetic-custom-token"));
}

#[cfg(unix)]
#[test]
fn sigint_closes_http_and_allows_immediate_restart() {
    let project = Project::new();
    let mut server = DevServer::start(&project, &[]);
    unsafe {
        libc::kill(server.child.id() as i32, libc::SIGINT);
    }
    let deadline = Instant::now() + Duration::from_secs(8);
    loop {
        if let Some(status) = server.child.try_wait().unwrap() {
            assert_eq!(status.code(), Some(130));
            break;
        }
        assert!(Instant::now() < deadline);
        std::thread::sleep(Duration::from_millis(10));
    }
    let mut restarted = DevServer::start(&project, &[]);
    assert_eq!(restarted.get("/api/ready", None).0, 200);
    restarted.stop();
}
