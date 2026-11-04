#![cfg(unix)]

use std::fs;
use std::os::unix::fs::{DirBuilderExt, PermissionsExt};
use std::os::unix::process::CommandExt;
use std::path::PathBuf;
use std::process::{Child, Command, Output, Stdio};
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

static PROJECT_SEQUENCE: AtomicU64 = AtomicU64::new(0);

struct Project(PathBuf);

impl Project {
    fn new() -> Self {
        let nonce = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        let sequence = PROJECT_SEQUENCE.fetch_add(1, Ordering::Relaxed);
        let root = std::env::temp_dir().join(format!(
            "dw-dev-test-{}-{nonce}-{sequence}",
            std::process::id()
        ));
        fs::DirBuilder::new().mode(0o700).create(&root).unwrap();
        fs::create_dir(root.join("bin")).unwrap();
        fs::write(root.join("artisan"), "fixture project\n").unwrap();
        Self(root)
    }

    fn tool(&self, name: &str, body: &str) {
        let path = self.0.join("bin").join(name);
        fs::write(&path, format!("#!/bin/sh\n{body}\n")).unwrap();
        fs::set_permissions(path, fs::Permissions::from_mode(0o700)).unwrap();
    }

    fn php(&self) {
        self.tool(
            "php",
            &format!(
                "{}\n{}",
                SIGNAL_FIXTURE,
                r#"
printf '%s\n' "$@" > launch.args
printf '%s' "$DB_CONNECTION" > launch.db
if [ -f compose.started ]; then printf yes > compose.before; fi
printf '<info>child stdout λ</info>\n'
printf 'child stderr\n' >&2
exit "${DW_DEV_PHP_EXIT:-0}"
"#
            ),
        );
    }

    fn docker(&self) {
        self.tool(
            "docker",
            &format!(
                "{}\n{}",
                SIGNAL_FIXTURE,
                r#"
printf '%s\n' "$@" > compose.args
printf started > compose.started
exit "${DW_DEV_DOCKER_EXIT:-0}"
"#
            ),
        );
    }

    fn command(&self, args: &[&str]) -> Command {
        let mut command = Command::new(env!("CARGO_BIN_EXE_dw"));
        command
            .arg("server:start-dev")
            .args(args)
            .current_dir(&self.0)
            .env_clear()
            .env("HOME", &self.0)
            .env("DW_ENV", "missing-dev-test-profile")
            .env("PATH", self.0.join("bin"))
            .stdin(Stdio::null());
        // Keep these tests independent of the test runner's own terminal.
        unsafe {
            command.pre_exec(|| {
                if libc::setsid() < 0 {
                    Err(std::io::Error::last_os_error())
                } else {
                    Ok(())
                }
            });
        }
        command
    }

    fn run(&self, args: &[&str], env: &[(&str, &str)]) -> Output {
        let mut command = self.command(args);
        command.envs(env.iter().copied());
        wait(
            command
                .stdout(Stdio::piped())
                .stderr(Stdio::piped())
                .spawn()
                .unwrap(),
            Duration::from_secs(8),
        )
    }
}

impl Drop for Project {
    fn drop(&mut self) {
        if let Ok(pid) = fs::read_to_string(self.0.join("child.pid")) {
            if let Ok(pid) = pid.trim().parse::<i32>() {
                // Only the fixture's still-existing session may be cleaned up.
                let descendant = fs::read_to_string(self.0.join("descendant.pid"))
                    .ok()
                    .and_then(|pid| pid.trim().parse::<i32>().ok());
                if unsafe { libc::getpgid(pid) } == pid
                    || descendant.is_some_and(|child| unsafe { libc::getpgid(child) } == pid)
                {
                    unsafe {
                        libc::kill(-pid, libc::SIGKILL);
                    }
                }
            }
        }
        let _ = fs::remove_dir_all(&self.0);
    }
}

const SIGNAL_FIXTURE: &str = r#"
if [ -n "$DW_DEV_SIGNAL_MODE" ]; then
    trap 'printf 2 > received.signal; exit 0' INT
    trap 'printf 15 > received.signal; exit 0' TERM
    if [ "$DW_DEV_SIGNAL_MODE" = ignore ]; then trap '' INT TERM; fi
    printf '%s' "$$" > child.pid
    while :; do /bin/sleep 1; done
fi
"#;

fn wait(mut child: Child, timeout: Duration) -> Output {
    let deadline = Instant::now() + timeout;
    while child.try_wait().unwrap().is_none() {
        if Instant::now() >= deadline {
            let _ = child.kill();
            let _ = child.wait();
            panic!("development process exceeded its test deadline");
        }
        std::thread::sleep(Duration::from_millis(10));
    }
    child.wait_with_output().unwrap()
}

fn recorded_pid(project: &Project, filename: &str) -> Option<i32> {
    fs::read_to_string(project.0.join(filename))
        .ok()?
        .trim()
        .parse()
        .ok()
}

#[test]
fn validation_precedes_every_child_launch() {
    let project = Project::new();
    project.php();
    project.docker();
    for args in [
        vec!["--port=0"],
        vec!["--port=65536"],
        vec!["--port=-1"],
        vec!["--port=8080.5"],
        vec!["--port"],
        vec!["--db=unknown"],
        vec!["--db"],
        vec!["--host=http://localhost"],
        vec!["--host"],
    ] {
        let output = project.run(&args, &[]);
        assert_eq!(output.status.code(), Some(2));
        assert!(output.stderr.is_empty());
        assert!(!project.0.join("launch.args").exists());
        assert!(!project.0.join("compose.args").exists());
    }
}

#[test]
fn missing_prerequisites_refuse_without_launching_http() {
    let project = Project::new();
    let output = project.run(&[], &[]);
    assert_eq!(output.status.code(), Some(1));
    assert!(String::from_utf8_lossy(&output.stdout).contains("external PHP executable"));
    project.php();
    let output = project.run(&["--db=mysql"], &[]);
    assert_eq!(output.status.code(), Some(1));
    assert!(String::from_utf8_lossy(&output.stdout).contains("Docker Compose is required"));
    fs::remove_file(project.0.join("artisan")).unwrap();
    let output = project.run(&[], &[]);
    assert_eq!(output.status.code(), Some(1));
    assert!(String::from_utf8_lossy(&output.stdout).contains("No readable artisan file"));
    assert!(!project.0.join("launch.args").exists());
}

#[test]
fn sqlite_forwards_arguments_and_merges_formatted_stdout_and_stderr() {
    for host in ["127.0.0.1", "localhost", "0.0.0.0", "::1"] {
        let project = Project::new();
        project.php();
        let output = project.run(&["--port=09090", &format!("--host={host}")], &[]);
        assert!(output.status.success());
        assert!(output.stderr.is_empty());
        assert_eq!(
            fs::read_to_string(project.0.join("launch.args")).unwrap(),
            format!("artisan\nserve\n--no-reload\n--port=09090\n--host={host}\n")
        );
        assert_eq!(
            fs::read_to_string(project.0.join("launch.db")).unwrap(),
            "sqlite"
        );
        assert!(String::from_utf8_lossy(&output.stdout).ends_with("child stdout λ\nchild stderr\n"));
    }
}

#[test]
fn compose_must_succeed_before_http_and_both_child_exit_codes_are_preserved() {
    for db in ["mysql", "pgsql"] {
        let project = Project::new();
        project.php();
        project.docker();
        let output = project.run(&[&format!("--db={db}")], &[("DW_DEV_DOCKER_EXIT", "17")]);
        assert_eq!(output.status.code(), Some(17));
        assert!(!project.0.join("launch.args").exists());
        assert!(!String::from_utf8_lossy(&output.stdout).contains("Starting Laravel HTTP server"));
        let output = project.run(&[&format!("--db={db}")], &[("DW_DEV_PHP_EXIT", "42")]);
        assert_eq!(output.status.code(), Some(42));
        assert_eq!(
            fs::read_to_string(project.0.join("compose.args")).unwrap(),
            format!("compose\nup\n-d\n{db}\nredis\n")
        );
        assert_eq!(fs::read_to_string(project.0.join("launch.db")).unwrap(), db);
        assert_eq!(
            fs::read_to_string(project.0.join("compose.before")).unwrap(),
            "yes"
        );
        assert!(String::from_utf8_lossy(&output.stdout).contains("Laravel HTTP process failed"));
    }
}

#[test]
fn quiet_suppresses_child_output_without_changing_its_exit_status() {
    let project = Project::new();
    project.php();
    let output = project.run(&["-q"], &[("DW_DEV_PHP_EXIT", "42")]);
    assert_eq!(output.status.code(), Some(42));
    assert!(output.stdout.is_empty() && output.stderr.is_empty());
}

fn interrupt(db: &str, signal: i32, ignore: bool, second: bool) {
    let project = Project::new();
    project.php();
    project.docker();
    let mut command = project.command(&[&format!("--db={db}")]);
    command.env("DW_DEV_SIGNAL_MODE", if ignore { "ignore" } else { "wait" });
    let mut child = command
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .unwrap();
    let start_deadline = Instant::now() + Duration::from_secs(5);
    while recorded_pid(&project, "child.pid").is_none() {
        assert!(
            child.try_wait().unwrap().is_none(),
            "CLI stopped before child startup"
        );
        if Instant::now() >= start_deadline {
            let _ = child.kill();
            let _ = child.wait();
            panic!("fixture child did not start");
        }
        std::thread::sleep(Duration::from_millis(10));
    }
    let started = Instant::now();
    assert_eq!(unsafe { libc::kill(child.id() as i32, signal) }, 0);
    if second {
        std::thread::sleep(Duration::from_secs(2));
        assert_eq!(unsafe { libc::kill(child.id() as i32, libc::SIGTERM) }, 0);
    }
    let output = wait(child, Duration::from_secs(7));
    assert_eq!(output.status.code(), Some(128 + signal));
    let pid: i32 = fs::read_to_string(project.0.join("child.pid"))
        .unwrap()
        .trim()
        .parse()
        .unwrap();
    assert_ne!(
        unsafe { libc::kill(pid, 0) },
        0,
        "managed child survived interruption"
    );
    if db == "mysql" {
        assert!(!String::from_utf8_lossy(&output.stdout).contains("Starting Laravel HTTP server"));
    }
    if ignore {
        assert!(!project.0.join("received.signal").exists());
        assert!(started.elapsed() >= Duration::from_secs(5));
        assert!(started.elapsed() < Duration::from_millis(6500));
    } else {
        assert_eq!(
            fs::read_to_string(project.0.join("received.signal")).unwrap(),
            signal.to_string()
        );
    }
}

#[test]
fn sigint_and_sigterm_are_forwarded_during_compose_and_http() {
    for db in ["sqlite", "mysql"] {
        for signal in [libc::SIGINT, libc::SIGTERM] {
            interrupt(db, signal, false, false);
        }
    }
}

#[test]
fn an_ignored_signal_cannot_keep_children_alive_past_five_seconds() {
    interrupt("sqlite", libc::SIGTERM, true, false);
}

#[test]
fn a_second_interrupt_preserves_the_first_signal_and_cleanup_deadline() {
    interrupt("sqlite", libc::SIGINT, true, true);
}

#[test]
fn interruption_still_stops_descendants_while_the_output_pipe_drains() {
    let project = Project::new();
    project.tool(
        "php",
        r#"
printf '%s' "$$" > child.pid
/bin/sh -c 'trap "exit 0" TERM; printf "%s" "$$" > descendant.pid; while :; do /bin/sleep 1; done' &
exit 0
"#,
    );
    let mut child = project
        .command(&[])
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .unwrap();
    let deadline = Instant::now() + Duration::from_secs(3);
    while recorded_pid(&project, "descendant.pid").is_none() {
        if Instant::now() >= deadline {
            let _ = child.kill();
            let _ = child.wait();
            panic!("descendant did not start");
        }
        std::thread::sleep(Duration::from_millis(10));
    }
    assert!(child.try_wait().unwrap().is_none());
    assert_eq!(unsafe { libc::kill(child.id() as i32, libc::SIGTERM) }, 0);
    let output = wait(child, Duration::from_secs(7));
    assert_eq!(output.status.code(), Some(143));
    let pid: i32 = fs::read_to_string(project.0.join("descendant.pid"))
        .unwrap()
        .trim()
        .parse()
        .unwrap();
    let deadline = Instant::now() + Duration::from_secs(1);
    while unsafe { libc::kill(pid, 0) } == 0 && Instant::now() < deadline {
        std::thread::sleep(Duration::from_millis(10));
    }
    assert_ne!(
        unsafe { libc::kill(pid, 0) },
        0,
        "descendant survived interruption"
    );
}
