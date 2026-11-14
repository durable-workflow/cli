//! Self-contained native Server for local development.

use crate::cli::Input;
use crate::error::CliError;
use crate::{config, formatter};
use durable_workflow_server::runtime::{router, Runtime};
use std::fs::{File, OpenOptions};
use std::net::{IpAddr, Ipv4Addr, SocketAddr};
use std::path::PathBuf;
use std::time::Duration;

const DEVELOPMENT_TOKEN: &str = "dev-token";
const SERVER_SOURCE: &str = "b6e04ce7e679464e91f6ceae83310d3bb3204092";
const SHUTDOWN_GRACE: Duration = Duration::from_secs(5);

struct Settings {
    address: SocketAddr,
    directory: PathBuf,
    token: String,
}

impl Settings {
    fn parse(input: &Input) -> Result<Self, CliError> {
        let port = input
            .opt_str("port")
            .filter(|value| value.bytes().all(|byte| byte.is_ascii_digit()))
            .and_then(|value| value.parse::<u16>().ok())
            .ok_or_else(|| {
                CliError::Invalid(
                    "--port must be an integer between 0 and 65535 (0 selects an available port)."
                        .into(),
                )
            })?;
        if input.opt_str("db") != Some("sqlite") {
            return Err(CliError::Invalid("The built-in development Server uses bundled SQLite. Use --db=sqlite; no database service is needed.".into()));
        }
        let address = match input.opt_str("host") {
            Some("localhost") => IpAddr::V4(Ipv4Addr::LOCALHOST),
            Some(value) => value.parse::<IpAddr>().map_err(|_| {
                CliError::Invalid("--host must be a loopback IP address or localhost.".into())
            })?,
            None => {
                return Err(CliError::Invalid(
                    "--host requires a loopback IP address or localhost.".into(),
                ))
            }
        };
        if !address.is_loopback() {
            return Err(CliError::Invalid(
                "The development Server binds to loopback only. Use 127.0.0.1, ::1 or localhost."
                    .into(),
            ));
        }
        let token = match input.opt("token").flatten() {
            Some(value) if !value.trim().is_empty() => value.to_owned(),
            Some(_) => return Err(CliError::Invalid("--token must not be empty.".into())),
            None => DEVELOPMENT_TOKEN.into(),
        };
        let directory = match input.opt("data-dir").flatten() {
            Some("") => return Err(CliError::Invalid("--data-dir must not be empty.".into())),
            Some(path) => PathBuf::from(path),
            None => config::default_path()?.with_file_name("dev-server"),
        };
        Ok(Self {
            address: SocketAddr::new(address, port),
            directory,
            token,
        })
    }

    fn prepare_directory(&mut self) -> Result<File, CliError> {
        let mut builder = std::fs::DirBuilder::new();
        builder.recursive(true);
        #[cfg(unix)]
        {
            use std::os::unix::fs::DirBuilderExt;
            builder.mode(0o700);
        }
        builder.create(&self.directory).map_err(|error| {
            CliError::Failure(format!("Cannot create development data directory: {error}"))
        })?;
        self.directory = self.directory.canonicalize().map_err(|error| {
            CliError::Failure(format!(
                "Cannot resolve development data directory: {error}"
            ))
        })?;
        let mut options = OpenOptions::new();
        options.create(true).truncate(false).read(true).write(true);
        #[cfg(unix)]
        {
            use std::os::unix::fs::OpenOptionsExt;
            options.mode(0o600);
        }
        let lock = options
            .open(self.directory.join("server.lock"))
            .map_err(|error| {
                CliError::Failure(format!("Cannot open development data lock: {error}"))
            })?;
        lock.try_lock().map_err(|_| CliError::Failure("The development data directory is already in use, or cannot be locked. Stop its Server or select another --data-dir.".into()))?;
        Ok(lock)
    }
}

pub fn run(input: &Input) -> Result<i32, CliError> {
    let mut settings = Settings::parse(input)?;
    let _lock = settings.prepare_directory()?;
    // Keep SQLite and native's relative payload storage inside the selected
    // data directory. A relative DB filename also works with Windows drives.
    std::env::set_current_dir(&settings.directory).map_err(|error| {
        CliError::Failure(format!("Cannot use development data directory: {error}"))
    })?;
    let runtime = tokio::runtime::Builder::new_multi_thread()
        .worker_threads(2)
        .enable_all()
        .build()
        .map_err(|error| {
            CliError::Failure(format!("Cannot start the development runtime: {error}"))
        })?;
    runtime.block_on(serve(settings))
}

async fn stopped() -> i32 {
    #[cfg(unix)]
    {
        let mut terminate =
            tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate())
                .expect("install SIGTERM handler");
        tokio::select! {
            _ = tokio::signal::ctrl_c() => 130,
            _ = terminate.recv() => 143,
        }
    }
    #[cfg(not(unix))]
    {
        let _ = tokio::signal::ctrl_c().await;
        130
    }
}

async fn serve(settings: Settings) -> Result<i32, CliError> {
    let shutdown = stopped();
    tokio::pin!(shutdown);
    // Reserve the address before opening/migrating the database. A busy port
    // must not launch a second maintenance loop over the same data.
    let listener = tokio::net::TcpListener::bind(settings.address)
        .await
        .map_err(|error| {
            CliError::Failure(format!(
                "Cannot bind development Server at {}: {error}",
                settings.address
            ))
        })?;
    let address = listener
        .local_addr()
        .map_err(|error| CliError::Failure(error.to_string()))?;
    let runtime = tokio::select! {
        result = Runtime::open("database.sqlite", settings.token.clone()) => result.map_err(|error| CliError::Failure(format!("Cannot open development database: {error}")))?,
        code = &mut shutdown => return Ok(code),
    };
    runtime.set_maintenance_repair(true);
    formatter::writeln_out(&format!(
        "<info>Durable Workflow development Server listening on http://{address}</info>"
    ));
    formatter::writeln_out(&format!(
        "Data: {}",
        formatter::display_text(&settings.directory.to_string_lossy())
    ));
    formatter::writeln_out(&format!(
        "Embedded native source: {SERVER_SOURCE} (development build)"
    ));
    if settings.token == DEVELOPMENT_TOKEN {
        formatter::writeln_out("Development token: dev-token");
        formatter::writeln_out(&format!("Connect in another terminal: dw env:set local --server=http://{address} --token=dev-token --make-default"));
    } else {
        formatter::writeln_out("Authentication: the custom bearer token supplied with --token.");
    }
    formatter::writeln_out("Press Ctrl+C to stop. Workflow data is kept for the next start.");

    let (stop, finished) = tokio::sync::oneshot::channel::<()>();
    let service = router(runtime.clone()).into_make_service_with_connect_info::<SocketAddr>();
    let http = axum::serve(listener, service).with_graceful_shutdown(async {
        let _ = finished.await;
    });
    let http = std::future::IntoFuture::into_future(http);
    tokio::pin!(http);
    let outcome = tokio::select! {
        result = &mut http => result.map(|()| 0).map_err(|error| CliError::Failure(format!("Development HTTP Server failed: {error}"))),
        code = &mut shutdown => {
            let _ = stop.send(());
            let _ = tokio::time::timeout(SHUTDOWN_GRACE, &mut http).await;
            Ok(code)
        }
    };
    runtime.close().await;
    outcome
}
