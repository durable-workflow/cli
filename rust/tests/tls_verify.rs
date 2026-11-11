//! `--tls-verify` against a self-signed HTTPS Server: verified by default,
//! skipped only when the caller turns verification off, as in 2.x.

use rustls::pki_types::{CertificateDer, PrivateKeyDer, PrivatePkcs8KeyDer};
use std::io::{Read, Write};
use std::net::TcpListener;
use std::path::PathBuf;
use std::process::{Command, Output};
use std::sync::atomic::{AtomicU64, AtomicUsize, Ordering};
use std::sync::Arc;
use std::thread;
use std::time::{SystemTime, UNIX_EPOCH};

struct Scratch(PathBuf);

impl Scratch {
    fn new() -> Self {
        static NEXT: AtomicU64 = AtomicU64::new(0);
        let nonce = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap()
            .as_nanos();
        let path = std::env::temp_dir().join(format!(
            "dw-tls-{}-{nonce}-{}",
            std::process::id(),
            NEXT.fetch_add(1, Ordering::Relaxed)
        ));
        std::fs::create_dir(&path).unwrap();
        Self(path)
    }

    fn run(&self, args: &[&str], env: &[(&str, &str)]) -> Output {
        let mut command = Command::new(env!("CARGO_BIN_EXE_dw"));
        command
            .args(args)
            .env_clear()
            .env("HOME", &self.0)
            .env("DW_CONFIG_HOME", &self.0);
        for (name, value) in env {
            command.env(name, value);
        }
        command.output().unwrap()
    }
}

impl Drop for Scratch {
    fn drop(&mut self) {
        let _ = std::fs::remove_dir_all(&self.0);
    }
}

/// A loopback HTTPS Server whose certificate names only `localhost`, so a
/// verifying client refuses it both as untrusted and as the wrong host for
/// 127.0.0.1. Every request gets the same healthy JSON response.
struct SelfSignedServer {
    url: String,
    served: Arc<AtomicUsize>,
}

impl SelfSignedServer {
    fn start() -> Self {
        let certified = rcgen::generate_simple_self_signed(vec!["localhost".into()]).unwrap();
        let chain = vec![CertificateDer::from(certified.cert.der().to_vec())];
        let key =
            PrivateKeyDer::Pkcs8(PrivatePkcs8KeyDer::from(certified.key_pair.serialize_der()));
        let config = Arc::new(
            rustls::ServerConfig::builder_with_provider(Arc::new(
                rustls::crypto::ring::default_provider(),
            ))
            .with_safe_default_protocol_versions()
            .unwrap()
            .with_no_client_auth()
            .with_single_cert(chain, key)
            .unwrap(),
        );
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let url = format!("https://{}", listener.local_addr().unwrap());
        let served = Arc::new(AtomicUsize::new(0));
        let counter = Arc::clone(&served);
        thread::spawn(move || {
            for stream in listener.incoming() {
                let Ok(stream) = stream else { continue };
                let config = Arc::clone(&config);
                let counter = Arc::clone(&counter);
                thread::spawn(move || {
                    let connection = rustls::ServerConnection::new(config).unwrap();
                    let mut tls = rustls::StreamOwned::new(connection, stream);
                    let mut request = Vec::new();
                    let mut buffer = [0u8; 4096];
                    while !request.windows(4).any(|window| window == b"\r\n\r\n") {
                        match tls.read(&mut buffer) {
                            Ok(0) | Err(_) => return,
                            Ok(read) => request.extend_from_slice(&buffer[..read]),
                        }
                    }
                    counter.fetch_add(1, Ordering::SeqCst);
                    let body = br#"{"status":"ok"}"#;
                    let head = format!(
                        "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",
                        body.len()
                    );
                    let _ = tls.write_all(head.as_bytes());
                    let _ = tls.write_all(body);
                    let _ = tls.flush();
                    tls.conn.send_close_notify();
                    let _ = tls.flush();
                });
            }
        });
        Self { url, served }
    }

    fn served(&self) -> usize {
        self.served.load(Ordering::SeqCst)
    }
}

fn health(scratch: &Scratch, server: &SelfSignedServer, extra: &[&str]) -> Output {
    let mut args = vec!["server:health", "--server", &server.url, "--output=json"];
    args.extend_from_slice(extra);
    scratch.run(&args, &[])
}

#[test]
fn verification_is_on_by_default_and_refuses_a_self_signed_server() {
    let scratch = Scratch::new();
    let server = SelfSignedServer::start();
    let output = health(&scratch, &server, &[]);
    assert_ne!(output.status.code(), Some(0), "{output:?}");
    assert!(!String::from_utf8_lossy(&output.stdout).contains("\"ok\""));
    assert_eq!(
        server.served(),
        0,
        "no request may complete over an unverified connection"
    );
}

#[test]
fn explicit_true_still_verifies() {
    let scratch = Scratch::new();
    let server = SelfSignedServer::start();
    let output = health(&scratch, &server, &["--tls-verify=true"]);
    assert_ne!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(server.served(), 0);
}

#[test]
fn flag_false_skips_certificate_and_hostname_checks() {
    let scratch = Scratch::new();
    let server = SelfSignedServer::start();
    let output = health(&scratch, &server, &["--tls-verify=false"]);
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    let body: serde_json::Value = serde_json::from_slice(&output.stdout).unwrap();
    assert_eq!(body["status"], "ok");
    assert!(server.served() >= 1);
    assert_warned_once(&output, &server);
}

/// The warning leads stderr exactly once; the fixture's minimal cluster info
/// may add the usual compatibility warning after it.
fn assert_warned_once(output: &Output, server: &SelfSignedServer) {
    let stderr = String::from_utf8(output.stderr.clone()).unwrap();
    assert!(stderr.starts_with(&warning(server)), "{stderr}");
    assert_eq!(
        stderr
            .matches("TLS certificate verification is disabled")
            .count(),
        1
    );
}

fn warning(server: &SelfSignedServer) -> String {
    format!(
        "Warning: TLS certificate verification is disabled for {}. Anyone on the network path can read or change this traffic, including the auth token. Use --tls-verify=true with a trusted certificate.\n",
        server.url
    )
}

#[test]
fn verified_requests_print_no_tls_warning() {
    let scratch = Scratch::new();
    let server = SelfSignedServer::start();
    let output = health(&scratch, &server, &[]);
    assert!(!String::from_utf8_lossy(&output.stderr)
        .contains("TLS certificate verification is disabled"));
}

#[test]
fn plain_http_with_verification_off_prints_no_tls_warning() {
    let scratch = Scratch::new();
    let output = scratch.run(
        &[
            "server:health",
            "--server",
            "http://127.0.0.1:9",
            "--tls-verify=false",
            "--output=json",
        ],
        &[],
    );
    assert!(!String::from_utf8_lossy(&output.stderr).contains("TLS certificate verification"));
}

#[test]
fn quiet_still_warns_when_verification_is_off() {
    let scratch = Scratch::new();
    let server = SelfSignedServer::start();
    let output = health(&scratch, &server, &["--tls-verify=false", "-q"]);
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(String::from_utf8(output.stderr).unwrap(), warning(&server));
}

#[test]
fn environment_false_skips_verification() {
    let scratch = Scratch::new();
    let server = SelfSignedServer::start();
    let output = scratch.run(
        &["server:health", "--server", &server.url, "--output=json"],
        &[("DURABLE_WORKFLOW_TLS_VERIFY", "false")],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert_warned_once(&output, &server);
}

#[test]
fn profile_false_skips_verification_and_flag_true_overrides_it() {
    let scratch = Scratch::new();
    let server = SelfSignedServer::start();
    let set = scratch.run(
        &[
            "env:set",
            "self-signed",
            "--server",
            &server.url,
            "--tls-verify=false",
        ],
        &[],
    );
    assert_eq!(set.status.code(), Some(0), "{set:?}");

    let output = scratch.run(
        &["server:health", "--env=self-signed", "--output=json"],
        &[],
    );
    assert_eq!(output.status.code(), Some(0), "{output:?}");
    assert_warned_once(&output, &server);

    let served = server.served();
    let output = scratch.run(
        &[
            "server:health",
            "--env=self-signed",
            "--tls-verify=true",
            "--output=json",
        ],
        &[],
    );
    assert_ne!(output.status.code(), Some(0), "{output:?}");
    assert_eq!(server.served(), served);
}
