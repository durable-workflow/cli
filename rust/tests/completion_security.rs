#![cfg(unix)]

use std::fs;
use std::io::Read;
use std::os::unix::fs::{symlink, DirBuilderExt, PermissionsExt};
use std::path::{Path, PathBuf};
use std::process::{Command, Output, Stdio};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

struct Scratch(PathBuf);

impl Scratch {
    fn new() -> Self {
        let nonce = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        let path =
            std::env::temp_dir().join(format!("dw-completion-test-{}-{nonce}", std::process::id()));
        fs::DirBuilder::new().mode(0o700).create(&path).unwrap();
        Self(path)
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
                    "autoload -Uz compinit; compinit -D; source \"$1\"",
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
            assert!(output.status.success());
            let script = scratch.0.join("generated-completion");
            fs::write(&script, &output.stdout).unwrap();
            if let Some(command) = shell(name, &scratch, &script) {
                assert!(
                    bounded(command).status.success(),
                    "safe completion script failed to source"
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
