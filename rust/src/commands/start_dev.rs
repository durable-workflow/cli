//! Run the current Laravel project's development server, as in 2.x.

use crate::cli::Input;
use crate::error::CliError;
use crate::formatter::{self, writeln_out};
use std::ffi::OsString;
use std::io::{self, Read, Write};
use std::path::{Path, PathBuf};
use std::process::{Command, ExitStatus, Stdio};
use std::time::{Duration, Instant};

const COMPOSE_TIMEOUT: Duration = Duration::from_secs(120);
const SHUTDOWN_GRACE: Duration = Duration::from_secs(5);

pub fn run(input: &Input) -> Result<i32, CliError> {
    let Some(port) = input.opt_str("port").filter(|value| {
        !value.is_empty()
            && value.bytes().all(|byte| byte.is_ascii_digit())
            && value.parse::<u16>().is_ok_and(|port| port > 0)
    }) else {
        writeln_out("<error>--port must be an integer between 1 and 65535.</error>");
        return Ok(2);
    };
    let Some(db @ ("sqlite" | "mysql" | "pgsql")) = input.opt_str("db") else {
        writeln_out("<error>--db must be sqlite, mysql or pgsql.</error>");
        return Ok(2);
    };
    let Some(host) = input
        .opt_str("host")
        .filter(|value| *value == "localhost" || value.parse::<std::net::IpAddr>().is_ok())
    else {
        writeln_out("<error>--host must be an IP address or localhost.</error>");
        return Ok(2);
    };
    let directory = match std::env::current_dir() {
        Ok(directory) if readable_file(&directory.join("artisan")) => directory,
        _ => {
            writeln_out("<error>No readable artisan file. Run this command from an installed, configured Laravel project.</error>");
            writeln_out(
                "Standalone Server quickstart: https://durable-workflow.com/docs/quickstart/",
            );
            return Ok(1);
        }
    };
    let Some(php) = executable("php", &directory) else {
        writeln_out(
            "<error>An external PHP executable is required on PATH to run artisan.</error>",
        );
        return Ok(1);
    };
    let signals = match signals::Guard::new() {
        Ok(signals) => signals,
        Err(_) => {
            writeln_out("<error>Development startup failed.</error>");
            writeln_out("Cannot register development process signal handlers.");
            return Ok(1);
        }
    };
    let result = launch(&directory, &php, db, host, port, &signals);
    match result {
        Ok(code) => Ok(code),
        Err(message) => {
            writeln_out("<error>Development startup failed.</error>");
            if formatter::verbosity() == formatter::Verbosity::Normal {
                formatter::emit(formatter::Stream::Stdout, &(message + "\n"));
            }
            Ok(1)
        }
    }
}

fn launch(
    directory: &Path,
    php: &Path,
    db: &str,
    host: &str,
    port: &str,
    signals: &signals::Guard,
) -> Result<i32, String> {
    if db != "sqlite" {
        let Some(docker) = executable("docker", directory) else {
            writeln_out("<error>Docker Compose is required for mysql or pgsql mode. Install Docker and configure the project services.</error>");
            return Ok(1);
        };
        writeln_out(&format!(
            "Starting the project's {db} and redis Compose services..."
        ));
        let mut command = Command::new(docker);
        command
            .args(["compose", "up", "-d", db, "redis"])
            .current_dir(directory);
        let result = process(command, Some(COMPOSE_TIMEOUT), false, signals)?;
        match result {
            Outcome::Interrupted(signal) => return Ok(128 + signal),
            Outcome::Exit(code) if code != 0 => {
                writeln_out("<error>Dependencies failed to start. Check the project Compose configuration and diagnostics above.</error>");
                return Ok(code);
            }
            _ => {}
        }
    }
    if let Some(signal) = signals.received() {
        return Ok(128 + signal);
    }
    let address = if host.contains(':') {
        format!("[{host}]")
    } else {
        host.into()
    };
    writeln_out(&format!(
        "<info>Starting Laravel HTTP server at http://{address}:{port}</info>"
    ));
    writeln_out(&format!(
        "Database driver: {db}. Press Ctrl+C to stop the HTTP process."
    ));
    let mut command = Command::new(php);
    command
        .arg("artisan")
        .arg("serve")
        .arg("--no-reload")
        .arg(format!("--port={port}"))
        .arg(format!("--host={host}"))
        .current_dir(directory)
        .env("DB_CONNECTION", db);
    match process(command, None, true, signals)? {
        Outcome::Interrupted(signal) => Ok(128 + signal),
        Outcome::Exit(code) => {
            if code != 0 {
                writeln_out("<error>Laravel HTTP process failed. Check the project configuration and diagnostics above.</error>");
            }
            Ok(code)
        }
    }
}

fn readable_file(path: &Path) -> bool {
    path.is_file() && std::fs::File::open(path).is_ok()
}

fn executable(name: &str, directory: &Path) -> Option<PathBuf> {
    let paths = std::env::var_os("PATH")?;
    let extensions: Vec<OsString> = if cfg!(windows) {
        std::iter::once(OsString::new())
            .chain(
                std::env::var_os("PATHEXT")
                    .unwrap_or_else(|| ".EXE;.BAT;.CMD;.COM".into())
                    .to_string_lossy()
                    .split(';')
                    .map(OsString::from)
                    .collect::<Vec<_>>(),
            )
            .collect()
    } else {
        vec![OsString::new()]
    };
    for path in std::env::split_paths(&paths) {
        for extension in &extensions {
            let mut filename = OsString::from(name);
            filename.push(extension);
            let candidate = directory.join(&path).join(filename);
            if candidate.is_file() && executable_file(&candidate) {
                return Some(candidate);
            }
        }
    }
    None
}

#[cfg(unix)]
fn executable_file(path: &Path) -> bool {
    use std::os::unix::ffi::OsStrExt;
    std::ffi::CString::new(path.as_os_str().as_bytes())
        .is_ok_and(|path| unsafe { libc::access(path.as_ptr(), libc::X_OK) } == 0)
}

#[cfg(not(unix))]
fn executable_file(_path: &Path) -> bool {
    true
}

enum Outcome {
    Exit(i32),
    Interrupted(i32),
}

struct ManagedChild {
    child: std::process::Child,
    armed: bool,
}

impl Drop for ManagedChild {
    fn drop(&mut self) {
        if self.armed {
            signals::force_stop(&mut self.child);
        }
    }
}

fn terminal() -> Option<std::fs::File> {
    #[cfg(unix)]
    {
        std::fs::OpenOptions::new()
            .read(true)
            .write(true)
            .open("/dev/tty")
            .ok()
    }
    #[cfg(not(unix))]
    {
        None
    }
}

fn command_line(command: &Command) -> String {
    std::iter::once(command.get_program())
        .chain(command.get_args())
        .map(|part| format!("'{}'", part.to_string_lossy().replace('\'', "'\\''")))
        .collect::<Vec<_>>()
        .join(" ")
}

fn process(
    mut command: Command,
    timeout: Option<Duration>,
    allow_tty: bool,
    signals: &signals::Guard,
) -> Result<Outcome, String> {
    if let Some(signal) = signals.received() {
        return Ok(Outcome::Interrupted(signal));
    }
    let description = command_line(&command);
    let reader = if let Some(tty) = allow_tty.then(terminal).flatten() {
        command
            .stdin(tty.try_clone().map_err(|error| error.to_string())?)
            .stdout(tty.try_clone().map_err(|error| error.to_string())?)
            .stderr(tty);
        None
    } else {
        let (reader, writer) = os_pipe::pipe().map_err(|error| error.to_string())?;
        command
            .stdin(Stdio::null())
            .stdout(writer.try_clone().map_err(|error| error.to_string())?)
            .stderr(writer);
        Some(reader)
    };
    signals::session(&mut command);
    let child = command
        .spawn()
        .map_err(|error| format!("Unable to launch the development process: {error}"))?;
    let mut managed = ManagedChild { child, armed: true };
    // Command retains copies of its pipe writers after spawn. Close them
    // before waiting for EOF from the output reader.
    drop(command);
    let output = reader.map(|reader| std::thread::spawn(move || stream_output(reader)));
    let start = Instant::now();
    let mut shutdown = None;
    let mut status = None;
    let result = loop {
        if let Some(signal) = signals.received() {
            let deadline = *shutdown.get_or_insert_with(|| {
                signals::send(&mut managed.child, signal);
                Instant::now() + SHUTDOWN_GRACE
            });
            if status.is_none() {
                status = poll(&mut managed.child)?;
            }
            if status.is_some() && !signals::group_alive(managed.child.id()) {
                managed.armed = false;
                break Ok(Outcome::Interrupted(signal));
            }
            if Instant::now() >= deadline {
                signals::force_stop(&mut managed.child);
                managed.armed = false;
                break Ok(Outcome::Interrupted(signal));
            }
        } else {
            if status.is_none() {
                status = poll(&mut managed.child)?;
            }
            // Descendants may still hold the output pipe after the direct
            // child exits. Keep handling interruption while it drains.
            if let Some(status) =
                status.filter(|_| output.as_ref().is_none_or(|reader| reader.is_finished()))
            {
                managed.armed = false;
                break exit_status(status);
            }
            if timeout.is_some_and(|timeout| start.elapsed() >= timeout) {
                signals::force_stop(&mut managed.child);
                managed.armed = false;
                break Err(format!(
                    "The process \"{description}\" exceeded the timeout of {} seconds.",
                    timeout.expect("checked").as_secs()
                ));
            }
        }
        std::thread::sleep(Duration::from_millis(10));
    };
    if let Some(output) = output {
        let _ = output.join();
    }
    result
}

fn exit_status(status: ExitStatus) -> Result<Outcome, String> {
    if let Some(code) = status.code() {
        return Ok(Outcome::Exit(code));
    }
    #[cfg(unix)]
    {
        use std::os::unix::process::ExitStatusExt;
        Err(format!(
            "The process has been signaled with signal \"{}\".",
            status.signal().unwrap_or(0)
        ))
    }
    #[cfg(not(unix))]
    {
        Ok(Outcome::Exit(1))
    }
}

fn poll(child: &mut std::process::Child) -> Result<Option<ExitStatus>, String> {
    match child.try_wait() {
        Err(error) if error.kind() == io::ErrorKind::Interrupted => Ok(None),
        other => other.map_err(|error| error.to_string()),
    }
}

fn stream_output(mut reader: impl Read) {
    let mut buffer = [0; 8192];
    let mut pending = Vec::new();
    loop {
        let count = match reader.read(&mut buffer) {
            Ok(count) => count,
            Err(error) if error.kind() == io::ErrorKind::Interrupted => continue,
            Err(_) => break,
        };
        if count == 0 {
            break;
        }
        pending.extend_from_slice(&buffer[..count]);
        loop {
            match std::str::from_utf8(&pending) {
                Ok(text) => {
                    formatter::write_out(text);
                    pending.clear();
                    break;
                }
                Err(error) => {
                    let valid = error.valid_up_to();
                    if valid > 0 {
                        formatter::write_out(
                            std::str::from_utf8(&pending[..valid]).expect("valid prefix"),
                        );
                    }
                    let Some(length) = error.error_len() else {
                        pending.drain(..valid);
                        break;
                    };
                    raw_output(&pending[valid..valid + length]);
                    pending.drain(..valid + length);
                }
            }
        }
    }
    raw_output(&pending);
}

fn raw_output(bytes: &[u8]) {
    if formatter::verbosity() == formatter::Verbosity::Normal {
        let _ = io::stdout().lock().write_all(bytes);
    }
}

#[cfg(unix)]
mod signals {
    use super::*;
    use std::sync::atomic::{AtomicI32, Ordering};
    static RECEIVED: AtomicI32 = AtomicI32::new(0);
    extern "C" fn capture(signal: i32) {
        // This lock-free operation is the only work in the signal handler.
        let _ = RECEIVED.compare_exchange(0, signal, Ordering::Relaxed, Ordering::Relaxed);
    }
    pub struct Guard {
        previous: Vec<(i32, libc::sigaction)>,
    }
    impl Guard {
        pub fn new() -> io::Result<Self> {
            RECEIVED.store(0, Ordering::Relaxed);
            let mut guard = Self {
                previous: Vec::new(),
            };
            for signal in [libc::SIGINT, libc::SIGTERM] {
                let mut action: libc::sigaction = unsafe { std::mem::zeroed() };
                action.sa_sigaction = capture as *const () as usize;
                unsafe {
                    libc::sigemptyset(&mut action.sa_mask);
                }
                let mut previous = unsafe { std::mem::zeroed() };
                if unsafe { libc::sigaction(signal, &action, &mut previous) } != 0 {
                    return Err(io::Error::last_os_error());
                }
                guard.previous.push((signal, previous));
            }
            Ok(guard)
        }
        pub fn received(&self) -> Option<i32> {
            match RECEIVED.load(Ordering::Relaxed) {
                0 => None,
                signal => Some(signal),
            }
        }
    }
    impl Drop for Guard {
        fn drop(&mut self) {
            for (signal, action) in &self.previous {
                unsafe {
                    libc::sigaction(*signal, action, std::ptr::null_mut());
                }
            }
        }
    }
    pub fn session(command: &mut Command) {
        use std::os::unix::process::CommandExt;
        unsafe {
            command.pre_exec(|| {
                if libc::setsid() < 0 {
                    Err(io::Error::last_os_error())
                } else {
                    Ok(())
                }
            });
        }
    }
    pub fn send(child: &mut std::process::Child, signal: i32) {
        unsafe {
            libc::kill(-(child.id() as i32), signal);
        }
    }
    pub fn group_alive(pid: u32) -> bool {
        unsafe { libc::kill(-(pid as i32), 0) == 0 }
    }
    pub fn force_stop(child: &mut std::process::Child) {
        send(child, libc::SIGKILL);
        let _ = child.kill();
        let _ = child.wait();
    }
}

#[cfg(not(unix))]
mod signals {
    use super::*;
    pub struct Guard;
    impl Guard {
        pub fn new() -> io::Result<Self> {
            Ok(Self)
        }
        pub fn received(&self) -> Option<i32> {
            None
        }
    }
    pub fn session(_command: &mut Command) {}
    pub fn group_alive(_pid: u32) -> bool {
        false
    }
    pub fn send(child: &mut std::process::Child, _signal: i32) {
        let _ = child.kill();
    }
    pub fn force_stop(child: &mut std::process::Child) {
        let _ = child.kill();
        let _ = child.wait();
    }
}

#[cfg(all(test, unix))]
mod tests {
    use super::*;

    #[test]
    fn a_timed_out_compose_process_is_stopped_before_returning() {
        let signals = signals::Guard::new().unwrap();
        let mut command = Command::new("/bin/sh");
        command.args(["-c", "exec /bin/sleep 30"]);
        let started = Instant::now();
        let result = process(command, Some(Duration::from_millis(20)), false, &signals);
        assert!(result.is_err_and(|message| message.contains("exceeded the timeout")));
        assert!(started.elapsed() < Duration::from_secs(1));
    }
}
