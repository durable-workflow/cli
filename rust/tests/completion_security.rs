#![cfg(unix)]

use std::fs;
use std::io::{self, Read};
use std::os::unix::fs::{symlink, DirBuilderExt, PermissionsExt};
use std::path::{Path, PathBuf};
use std::process::{Command, Output, Stdio};
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

struct Scratch(PathBuf);

impl Scratch {
    fn new() -> Self {
        static NEXT: AtomicU64 = AtomicU64::new(0);
        let nonce = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        Self::create_in(&std::env::temp_dir(), nonce, &NEXT)
            .expect("cannot create private completion test directory")
    }

    fn create_in(root: &Path, nonce: u128, next: &AtomicU64) -> io::Result<Self> {
        for _ in 0..64 {
            let count = next.fetch_add(1, Ordering::Relaxed);
            let path = root.join(format!(
                "dw-completion-test-{}-{nonce}-{count}",
                std::process::id()
            ));
            match fs::DirBuilder::new().mode(0o700).create(&path) {
                Ok(()) => return Ok(Self(path)),
                Err(error) if error.kind() == io::ErrorKind::AlreadyExists => continue,
                Err(error) => return Err(error),
            }
        }
        Err(io::Error::new(
            io::ErrorKind::AlreadyExists,
            "completion test directory collisions exceeded the retry limit",
        ))
    }

    fn command(&self, binary: &Path) -> Command {
        let mut command = Command::new(binary);
        command
            .current_dir(&self.0)
            .env_clear()
            .env("HOME", &self.0)
            .env("TMPDIR", &self.0)
            .env("DW_CONFIG_HOME", self.0.join("config"))
            .env("PATH", std::env::var_os("PATH").unwrap_or_default());
        command
    }
}

impl Drop for Scratch {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}

#[test]
fn scratch_retries_collisions_without_reusing_existing_directories() {
    let root = Scratch::new();
    let next = AtomicU64::new(0);
    let existing = Scratch::create_in(&root.0, 0, &next).unwrap();
    fs::write(existing.0.join("sentinel"), "unchanged").unwrap();
    next.store(0, Ordering::Relaxed);

    let fresh = Scratch::create_in(&root.0, 0, &next).unwrap();
    assert_ne!(fresh.0, existing.0);
    assert_eq!(next.load(Ordering::Relaxed), 2);
    assert_eq!(
        fs::read_to_string(existing.0.join("sentinel")).unwrap(),
        "unchanged"
    );
    assert_eq!(
        fs::metadata(&fresh.0).unwrap().permissions().mode() & 0o777,
        0o700
    );
}

#[test]
fn scratch_reports_other_allocation_errors_without_retrying() {
    let root = Scratch::new();
    let next = AtomicU64::new(0);
    let error = Scratch::create_in(&root.0.join("missing"), 0, &next)
        .err()
        .unwrap();
    assert_eq!(error.kind(), io::ErrorKind::NotFound);
    assert_eq!(next.load(Ordering::Relaxed), 1);
}

#[test]
fn scratch_collision_retries_are_bounded() {
    let root = Scratch::new();
    let next = AtomicU64::new(0);
    let occupied: Vec<_> = (0..64)
        .map(|_| Scratch::create_in(&root.0, 0, &next).unwrap())
        .collect();
    next.store(0, Ordering::Relaxed);

    let error = Scratch::create_in(&root.0, 0, &next).err().unwrap();
    assert_eq!(error.kind(), io::ErrorKind::AlreadyExists);
    assert_eq!(next.load(Ordering::Relaxed), 64);
    assert!(occupied.iter().all(|scratch| scratch.0.is_dir()));
}

#[test]
fn scratch_directories_are_private_and_unique_across_threads() {
    let root = Scratch::new();
    let next = AtomicU64::new(0);
    let scratches: Vec<_> = std::thread::scope(|scope| {
        let create = || {
            (0..16)
                .map(|_| Scratch::create_in(&root.0, 0, &next).unwrap())
                .collect::<Vec<_>>()
        };
        let first = scope.spawn(create);
        let second = scope.spawn(create);
        first
            .join()
            .unwrap()
            .into_iter()
            .chain(second.join().unwrap())
            .collect()
    });
    let paths: std::collections::HashSet<_> = scratches.iter().map(|scratch| &scratch.0).collect();
    assert_eq!(paths.len(), 32);
    for path in paths {
        assert_eq!(
            fs::metadata(path).unwrap().permissions().mode() & 0o777,
            0o700
        );
    }
    drop(scratches);
    assert_eq!(fs::read_dir(&root.0).unwrap().count(), 0);
}

fn binary() -> PathBuf {
    std::env::var_os("DW_COMPLETION_TEST_BINARY")
        .map(PathBuf::from)
        .unwrap_or_else(|| env!("CARGO_BIN_EXE_dw").into())
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
            panic!("completion command exceeded the test deadline");
        }
        std::thread::sleep(Duration::from_millis(10));
    }
    child.wait_with_output().unwrap()
}

fn shell(shell: &str, scratch: &Scratch, script: &Path) -> Option<Command> {
    if Command::new(shell).arg("--version").output().is_err() {
        assert!(
            std::env::var_os("DW_REQUIRE_COMPLETION_SHELLS").is_none(),
            "required shell missing: {shell}"
        );
        eprintln!("Skipping unavailable {shell}; CI requires all three completion shells");
        return None;
    }
    let mut command = scratch.command(Path::new(shell));
    match shell {
        "bash" => {
            command
                .args(["--noprofile", "--norc", "-c", "source \"$1\"", "bash"])
                .arg(script);
        }
        "zsh" => {
            command
                .args([
                    "-f",
                    "-c",
                    // CI images can expose inherited insecure completion directories.
                    // Ignore those unrelated entries so this test exercises the
                    // generated script instead of requiring an interactive prompt.
                    "autoload -Uz compinit; compinit -D -i; source \"$1\"",
                    "zsh",
                ])
                .arg(script);
        }
        "fish" => {
            command
                .args(["--no-config", "--command", "source $argv[1]"])
                .arg(script);
        }
        _ => unreachable!(),
    }
    Some(command)
}

#[test]
fn crafted_executable_names_cannot_execute_when_completion_is_sourced() {
    let scratch = Scratch::new();
    let names = [
        "dw two",
        "dw'quote",
        "dw\"quote",
        "dw\nprintf touched > marker\n#",
        "dw; printf touched > marker;#",
        "dw$(printf touched > marker)",
        "dw`printf touched > marker`",
        "-dw",
        "dw() { :; }; printf touched > marker; _sf_x",
    ];
    for name in names {
        let link = scratch.0.join(name);
        symlink(binary(), &link).unwrap();
        for name in ["bash", "zsh", "fish"] {
            let mut generate = scratch.command(&link);
            generate.args(["completion", name]);
            let output = bounded(generate);
            let script = scratch.0.join("generated-completion");
            fs::write(&script, &output.stdout).unwrap();
            if let Some(command) = shell(name, &scratch, &script) {
                bounded(command);
            }
            assert!(
                !scratch.0.join("marker").exists(),
                "generated code executed a marker command"
            );
            assert_eq!(
                output.status.code(),
                Some(2),
                "unsafe executable name must be refused"
            );
            assert!(
                output.stdout.is_empty(),
                "refusal must emit no shell program"
            );
        }
    }
}

#[test]
fn safe_executable_names_still_generate_working_shell_syntax() {
    let scratch = Scratch::new();
    for command_name in ["dw", "dw.exe", "dw-cli", "dw_2"] {
        let link = scratch.0.join(command_name);
        symlink(binary(), &link).unwrap();
        for name in ["bash", "zsh", "fish"] {
            let mut generate = scratch.command(&link);
            generate.args(["completion", name]);
            let output = bounded(generate);
            assert!(
                output.status.success(),
                "safe completion generation failed: command={command_name:?} shell={name:?} status={:?} stdout={:?} stderr={:?}",
                output.status.code(),
                String::from_utf8_lossy(&output.stdout),
                String::from_utf8_lossy(&output.stderr),
            );
            let script = scratch.0.join("generated-completion");
            fs::write(&script, &output.stdout).unwrap();
            if let Some(command) = shell(name, &scratch, &script) {
                let sourced = bounded(command);
                assert!(
                    sourced.status.success(),
                    "safe completion script failed to source: command={command_name:?} shell={name:?} status={:?} stdout={:?} stderr={:?}",
                    sourced.status.code(),
                    String::from_utf8_lossy(&sourced.stdout),
                    String::from_utf8_lossy(&sourced.stderr),
                );
            }
        }
    }
}

fn debug_command(scratch: &Scratch, tail: bool) -> Command {
    let link = scratch.0.join("dw");
    if !link.exists() {
        symlink(binary(), &link).unwrap();
    }
    let mut command = scratch.command(&link);
    if tail {
        command.args(["completion", "--debug"]);
    } else {
        command
            .args(["_complete", "-a0.9"])
            .env("SYMFONY_COMPLETION_DEBUG", "1");
    }
    command
}

#[test]
fn debug_log_refuses_symlinks_hard_links_and_nonregular_entries() {
    for kind in [
        "symlink",
        "hardlink",
        "fifo",
        "directory",
        "shared-file",
        "directory-symlink",
        "shared-directory",
    ] {
        let scratch = Scratch::new();
        let directory = scratch.0.join("config/completion");
        fs::DirBuilder::new()
            .recursive(true)
            .mode(0o700)
            .create(&directory)
            .unwrap();
        let path = directory.join("sf_dw.log");
        let sentinel = scratch.0.join("sentinel");
        fs::write(&sentinel, "unchanged sentinel\n").unwrap();
        fs::set_permissions(&sentinel, fs::Permissions::from_mode(0o600)).unwrap();
        match kind {
            "symlink" => symlink(&sentinel, &path).unwrap(),
            "hardlink" => fs::hard_link(&sentinel, &path).unwrap(),
            "directory" => fs::create_dir(&path).unwrap(),
            "fifo" => {
                use std::ffi::CString;
                use std::os::unix::ffi::OsStrExt;
                let path = CString::new(path.as_os_str().as_bytes()).unwrap();
                assert_eq!(unsafe { libc::mkfifo(path.as_ptr(), 0o600) }, 0);
            }
            "shared-file" => {
                fs::write(&path, "shared").unwrap();
                fs::set_permissions(&path, fs::Permissions::from_mode(0o666)).unwrap();
            }
            "directory-symlink" => {
                fs::remove_dir(&directory).unwrap();
                symlink(&scratch.0, &directory).unwrap();
            }
            "shared-directory" => {
                fs::set_permissions(&directory, fs::Permissions::from_mode(0o777)).unwrap()
            }
            _ => unreachable!(),
        }
        for tail in [false, true] {
            let output = bounded(debug_command(&scratch, tail));
            assert_eq!(
                output.status.code(),
                Some(2),
                "unsafe log entry was not refused: {kind}"
            );
            assert!(output.stdout.is_empty());
            assert_eq!(
                fs::read_to_string(&sentinel).unwrap(),
                "unchanged sentinel\n"
            );
        }
    }
}

#[test]
fn debug_tail_follows_the_same_private_log_as_the_completion_writer() {
    let scratch = Scratch::new();
    assert_eq!(
        bounded(debug_command(&scratch, false)).status.code(),
        Some(126)
    );
    let path = scratch.0.join("config/completion/sf_dw.log");
    assert_eq!(
        fs::metadata(&path).unwrap().permissions().mode() & 0o777,
        0o600
    );
    assert_eq!(
        fs::metadata(path.parent().unwrap())
            .unwrap()
            .permissions()
            .mode()
            & 0o777,
        0o700
    );
    let log = fs::read(&path).unwrap();
    let mut child = debug_command(&scratch, true)
        .stdout(Stdio::piped())
        .spawn()
        .unwrap();
    let mut stdout = child.stdout.take().unwrap();
    let reader = std::thread::spawn(move || {
        let mut bytes = vec![0; log.len()];
        stdout.read_exact(&mut bytes).unwrap();
        bytes
    });
    // The tail emits the current log immediately. A separate reader avoids
    // blocking the test process on a broken follow implementation.
    let deadline = Instant::now() + Duration::from_secs(5);
    while !reader.is_finished() && Instant::now() < deadline {
        std::thread::sleep(Duration::from_millis(10));
    }
    let _ = child.kill();
    let _ = child.wait();
    assert!(reader.is_finished(), "tail failed to read the private log");
    assert_eq!(reader.join().unwrap(), fs::read(&path).unwrap());
}
