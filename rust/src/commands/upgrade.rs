//! `dw upgrade`: replace the running standalone binary with a newer (or a
//! pinned) release, verified against the release's `SHA256SUMS`. A port of
//! 2.x `UpgradeCommand`, `InstallationTarget`, `ReleaseCatalog` and
//! `ReleaseVersion`. Installations another tool manages (Homebrew, a
//! Composer vendor tree) are refused with a pointer at that tool.

use crate::cli::Input;
use crate::error::exit;
use crate::error::CliError;
use crate::formatter::writeln_out;
use crate::{build_info, output};
use serde_json::{json, Map, Value};
use sha2::{Digest, Sha256};
use std::path::Path;
use std::time::Duration;

const REPO: &str = "durable-workflow/cli";
const DOWNLOAD_BASE: &str = "https://github.com";
const DEFAULT_AUTHORITY_URL: &str =
    "https://api.github.com/repos/durable-workflow/cli/releases/latest";

pub fn run(input: &Input) -> Result<i32, CliError> {
    Ok(upgrade(
        input,
        &Catalog::from_env(),
        &detect(),
        &default_replacer,
    ))
}

// ---------------------------------------------------------------------------
// Semantic version precedence (ReleaseVersion).

struct SemVer {
    core: [String; 3],
    prerelease: Option<Vec<String>>,
}

fn parse_semver(version: &str) -> Result<SemVer, String> {
    let trimmed = crate::php::trim(version);
    let bare = trimmed.strip_prefix('v').unwrap_or(trimmed);
    let invalid = || format!("invalid semantic version [{bare}]");
    let (without_build, build) = match bare.split_once('+') {
        Some((head, build)) => (head, Some(build)),
        None => (bare, None),
    };
    if let Some(build) = build {
        let identifier = |part: &str| {
            !part.is_empty() && part.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'-')
        };
        if !build.split('.').all(identifier) {
            return Err(invalid());
        }
    }
    let (core, prerelease) = match without_build.split_once('-') {
        Some((core, prerelease)) => (core, Some(prerelease)),
        None => (without_build, None),
    };
    let numeric = |part: &str| {
        !part.is_empty()
            && part.bytes().all(|b| b.is_ascii_digit())
            && (part == "0" || !part.starts_with('0'))
    };
    let parts: Vec<&str> = core.split('.').collect();
    if parts.len() != 3 || !parts.iter().all(|part| numeric(part)) {
        return Err(invalid());
    }
    let prerelease = match prerelease {
        None => None,
        Some(text) => {
            let identifiers: Vec<&str> = text.split('.').collect();
            let valid = |part: &&str| {
                !part.is_empty()
                    && part.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'-')
                    && (!part.bytes().all(|b| b.is_ascii_digit()) || numeric(part))
            };
            if !identifiers.iter().all(valid) {
                return Err(invalid());
            }
            Some(identifiers.into_iter().map(str::to_string).collect())
        }
    };
    Ok(SemVer {
        core: [parts[0].into(), parts[1].into(), parts[2].into()],
        prerelease,
    })
}

fn compare_numeric(left: &str, right: &str) -> std::cmp::Ordering {
    left.len().cmp(&right.len()).then_with(|| left.cmp(right))
}

/// `ReleaseVersion::compare`: SemVer precedence, build metadata ignored.
pub fn compare_versions(left: &str, right: &str) -> Result<std::cmp::Ordering, String> {
    use std::cmp::Ordering;
    let (left, right) = (parse_semver(left)?, parse_semver(right)?);
    for index in 0..3 {
        let ordering = compare_numeric(&left.core[index], &right.core[index]);
        if ordering != Ordering::Equal {
            return Ok(ordering);
        }
    }
    let (left, right) = match (&left.prerelease, &right.prerelease) {
        (None, None) => return Ok(Ordering::Equal),
        (None, Some(_)) => return Ok(Ordering::Greater),
        (Some(_), None) => return Ok(Ordering::Less),
        (Some(left), Some(right)) => (left, right),
    };
    for index in 0..left.len().max(right.len()) {
        let (Some(l), Some(r)) = (left.get(index), right.get(index)) else {
            return Ok(if left.len() < right.len() {
                Ordering::Less
            } else {
                Ordering::Greater
            });
        };
        let (l_num, r_num) = (crate::php::ctype_digit(l), crate::php::ctype_digit(r));
        let ordering = match (l_num, r_num) {
            (true, true) => compare_numeric(l, r),
            (true, false) => Ordering::Less,
            (false, true) => Ordering::Greater,
            (false, false) => l.cmp(r),
        };
        if ordering != Ordering::Equal {
            return Ok(ordering);
        }
    }
    Ok(Ordering::Equal)
}

// ---------------------------------------------------------------------------
// The running installation (InstallationTarget).

#[derive(Clone, Debug, PartialEq)]
pub struct Target {
    pub kind: &'static str,
    pub path: String,
    pub upgradeable: bool,
    pub asset: Option<String>,
    pub reason: String,
}

const COMPOSER_REASON: &str = "dw is installed as a Composer dependency. Update it with the package manager that owns this vendor tree, or reinstall a pinned public release with install.sh.";
const HOMEBREW_REASON: &str =
    "dw is installed via Homebrew. Use `brew upgrade durable-workflow/tap/dw` instead.";

impl Target {
    fn to_value(&self) -> Value {
        json!({
            "kind": self.kind,
            "path": self.path,
            "upgradeable": self.upgradeable,
            "asset": self.asset,
            "reason": self.reason,
        })
    }
}

/// `PHP_OS_FAMILY` for this build.
fn os_family() -> &'static str {
    match std::env::consts::OS {
        "linux" | "android" => "Linux",
        "macos" | "ios" => "Darwin",
        "windows" => "Windows",
        "freebsd" | "openbsd" | "netbsd" | "dragonfly" => "BSD",
        "solaris" | "illumos" => "Solaris",
        _ => "Unknown",
    }
}

/// What `php_uname('m')` reports on this platform.
fn machine() -> &'static str {
    match (std::env::consts::OS, std::env::consts::ARCH) {
        ("macos", "aarch64") => "arm64",
        ("windows", "x86_64") => "AMD64",
        (_, arch) => arch,
    }
}

/// The published asset for a platform, if any.
pub fn map_asset(os_family: &str, arch: &str) -> Option<String> {
    let arch = match arch.to_ascii_lowercase().as_str() {
        "x86_64" | "amd64" => "x86_64",
        "arm64" | "aarch64" => "aarch64",
        _ => return None,
    };
    match os_family {
        "Linux" => Some(format!("dw-linux-{arch}")),
        // 2.x had no Intel macOS binary; 2.5.0 publishes dw-macos-x86_64.
        "Darwin" => Some(format!("dw-macos-{arch}")),
        "Windows" | "WINNT" if arch == "x86_64" => Some("dw-windows-x86_64.exe".into()),
        _ => None,
    }
}

/// PHP `realpath`: the canonical path, without Windows' verbatim prefix.
fn realpath(path: &str) -> Option<String> {
    let resolved = std::fs::canonicalize(path)
        .ok()?
        .to_string_lossy()
        .into_owned();
    Some(match resolved.strip_prefix(r"\\?\") {
        Some(rest) => match rest.strip_prefix("UNC\\") {
            Some(unc) => format!(r"\\{unc}"),
            None => rest.to_string(),
        },
        None => resolved,
    })
}

fn looks_like_path(value: &str) -> bool {
    let bytes = value.as_bytes();
    value.contains('/')
        || value.contains('\\')
        || (bytes.len() >= 3
            && bytes[0].is_ascii_alphabetic()
            && bytes[1] == b':'
            && matches!(bytes[2], b'\\' | b'/'))
}

#[cfg(unix)]
fn access(path: &str, mode: libc::c_int) -> bool {
    let Ok(path) = std::ffi::CString::new(path) else {
        return false;
    };
    // SAFETY: path is a valid NUL-terminated string for the duration of the call.
    unsafe { libc::access(path.as_ptr(), mode) == 0 }
}

/// PHP `is_writable`.
fn is_writable(path: &str) -> bool {
    #[cfg(unix)]
    {
        access(path, libc::W_OK)
    }
    #[cfg(not(unix))]
    {
        std::fs::metadata(path).is_ok_and(|metadata| !metadata.permissions().readonly())
    }
}

/// The fallback when the running executable cannot be resolved. Only an
/// explicit path in argv[0] (one containing a separator) is trusted; a bare
/// name would resolve against the current directory, so the install is
/// treated as unresolvable and refused.
fn resolve_fallback(argv0: &str) -> String {
    if looks_like_path(argv0) {
        realpath(argv0).unwrap_or_default()
    } else {
        String::new()
    }
}

fn inside_vendor(path: &str) -> bool {
    path.replace('\\', "/").contains("/vendor/")
}

fn inside_homebrew(path: &str) -> bool {
    let path = path.replace('\\', "/");
    path.contains("/Cellar/") || path.contains("/opt/homebrew/") || path.contains("/linuxbrew/")
}

/// `InstallationTarget::detect`. The binary to replace is the running
/// executable (`executable`, canonicalized); an explicit argv[0] path is
/// only a fallback when the platform cannot report it. 2.x resolved argv[0] first, which made a
/// `dw` entry in the current directory the target. This build is never a
/// PHAR, so the PHAR branches of 2.x cannot apply.
pub fn detect_target(executable: Option<&str>, argv0: &str, os_family: &str, arch: &str) -> Target {
    let asset = map_asset(os_family, arch);
    let resolved = executable
        .and_then(realpath)
        .unwrap_or_else(|| resolve_fallback(argv0));
    let target = |kind, path: String, upgradeable, asset: Option<String>, reason: &str| Target {
        kind,
        path,
        upgradeable,
        asset,
        reason: reason.to_string(),
    };
    if resolved.is_empty() {
        return target(
            "unknown",
            String::new(),
            false,
            asset,
            "could not resolve the dw executable path",
        );
    }
    if inside_vendor(&resolved) {
        return target("composer-vendor", resolved, false, asset, COMPOSER_REASON);
    }
    if inside_homebrew(&resolved) {
        return target("homebrew", resolved, false, asset, HOMEBREW_REASON);
    }
    match asset {
        None => {
            let reason =
                format!("no published release asset matches this platform ({os_family}/{arch})");
            target("binary", resolved, false, None, &reason)
        }
        Some(asset) => target("binary", resolved, true, Some(asset), ""),
    }
}

fn detect() -> Target {
    let executable = std::env::current_exe()
        .ok()
        .map(|path| path.to_string_lossy().into_owned());
    let argv0 = std::env::args_os()
        .next()
        .map(|arg| arg.to_string_lossy().into_owned())
        .unwrap_or_default();
    detect_target(executable.as_deref(), &argv0, os_family(), machine())
}

// ---------------------------------------------------------------------------
// GitHub releases (ReleaseCatalog).

pub struct Catalog {
    authority_url: String,
    download_base: String,
    /// Release downloads never leave HTTPS, even across redirects.
    https_downloads: bool,
}

impl Catalog {
    fn from_env() -> Self {
        let authority = std::env::var("DURABLE_WORKFLOW_RELEASE_API_URL").unwrap_or_default();
        Self {
            authority_url: if authority.is_empty() {
                DEFAULT_AUTHORITY_URL.into()
            } else {
                authority
            },
            download_base: DOWNLOAD_BASE.into(),
            https_downloads: true,
        }
    }

    #[cfg(test)]
    pub fn new(authority_url: &str, download_base: &str) -> Self {
        Self {
            authority_url: authority_url.into(),
            download_base: download_base.into(),
            https_downloads: false,
        }
    }

    fn client(max_redirects: usize, https_only: bool) -> Result<reqwest::Client, String> {
        let mut headers = reqwest::header::HeaderMap::new();
        headers.insert(
            reqwest::header::ACCEPT,
            "application/vnd.github+json, */*"
                .parse()
                .expect("static header"),
        );
        headers.insert(
            reqwest::header::USER_AGENT,
            "dw-cli-upgrade".parse().expect("static header"),
        );
        let policy = if max_redirects == 0 {
            reqwest::redirect::Policy::none()
        } else {
            reqwest::redirect::Policy::limited(max_redirects)
        };
        reqwest::Client::builder()
            .default_headers(headers)
            .redirect(policy)
            .https_only(https_only)
            .connect_timeout(Duration::from_secs(30))
            .read_timeout(Duration::from_secs(30))
            .build()
            .map_err(|error| error.to_string())
    }

    fn block_on<T>(future: impl std::future::Future<Output = T>) -> T {
        tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()
            .expect("a current-thread runtime always builds")
            .block_on(future)
    }

    /// The latest published stable release tag.
    pub fn supported_tag(&self) -> Result<String, String> {
        let data = Self::block_on(self.authority()).map_err(|message| {
            format!("could not fetch the latest stable CLI release: {message}")
        })?;
        let tag = match data.get("tag_name") {
            Some(Value::String(tag)) if !tag.is_empty() => tag.trim_start_matches('v').to_string(),
            _ => return Err("latest stable CLI release must include a tag_name".into()),
        };
        // 2.x's anchored pattern: nothing but MAJOR.MINOR.PATCH, so no
        // surrounding whitespace, prerelease or build metadata.
        let stable = tag == crate::php::trim(&tag)
            && parse_semver(&tag).is_ok_and(|version| version.prerelease.is_none())
            && !tag.contains('+');
        if data.get("draft") != Some(&Value::Bool(false))
            || data.get("prerelease") != Some(&Value::Bool(false))
            || !stable
        {
            return Err("latest CLI release must be a published stable version".into());
        }
        Ok(tag)
    }

    /// Symfony's `$response->toArray()`, with its exception messages.
    async fn authority(&self) -> Result<Map<String, Value>, String> {
        let url = &self.authority_url;
        let response = Self::client(0, false)?
            .get(url)
            .send()
            .await
            .map_err(|error| transport_message(&error, url))?;
        let status = response.status();
        let line = status_line(&response);
        let json_type = response
            .headers()
            .get(reqwest::header::CONTENT_TYPE)
            .and_then(|value| value.to_str().ok())
            .is_some_and(|value| {
                value
                    .to_ascii_lowercase()
                    .split(|c: char| !c.is_alphanumeric())
                    .any(|word| word == "json")
            });
        let body = response
            .bytes()
            .await
            .map_err(|error| transport_message(&error, url))?;
        if status.as_u16() >= 300 {
            return Err(http_error_message(&line, url, json_type, &body));
        }
        if body.is_empty() {
            return Err("Response body is empty.".into());
        }
        let decoded: Value =
            serde_json::from_slice(&body).map_err(|_| format!("Syntax error for \"{url}\"."))?;
        match decoded {
            Value::Object(map) => Ok(map),
            Value::Array(items) => Ok(items
                .into_iter()
                .enumerate()
                .map(|(index, item)| (index.to_string(), item))
                .collect()),
            other => Err(format!(
                "JSON content was expected to decode to an array, \"{}\" returned for \"{url}\".",
                debug_type(&other)
            )),
        }
    }

    pub fn download_url(&self, tag: &str, asset: &str) -> String {
        format!(
            "{}/{REPO}/releases/download/{}/{asset}",
            self.download_base.trim_end_matches('/'),
            tag.trim_start_matches('v')
        )
    }

    pub fn fetch(&self, url: &str) -> Result<Vec<u8>, String> {
        Self::block_on(async {
            let failed = |message: String| format!("failed to download {url}: {message}");
            let response = Self::client(5, self.https_downloads)
                .map_err(failed)?
                .get(url)
                .send()
                .await
                .map_err(|error| failed(transport_message(&error, url)))?;
            let status = response.status().as_u16();
            if status == 404 {
                return Err(format!("asset not found: {url}"));
            }
            if status >= 400 {
                return Err(format!("HTTP {status} fetching {url}"));
            }
            if status >= 300 {
                // The redirect limit was reached on a 3xx.
                let line = status_line(&response);
                return Err(failed(format!("{line} returned for \"{url}\".")));
            }
            response
                .bytes()
                .await
                .map(|bytes| bytes.to_vec())
                .map_err(|error| failed(transport_message(&error, url)))
        })
    }
}

fn status_line(response: &reqwest::Response) -> String {
    let version = match response.version() {
        reqwest::Version::HTTP_09 => "HTTP/0.9",
        reqwest::Version::HTTP_10 => "HTTP/1.0",
        reqwest::Version::HTTP_2 => "HTTP/2",
        reqwest::Version::HTTP_3 => "HTTP/3",
        _ => "HTTP/1.1",
    };
    let status = response.status();
    match status.canonical_reason() {
        Some(reason) => format!("{version} {} {reason}", status.as_u16()),
        None => format!("{version} {}", status.as_u16()),
    }
}

/// Symfony's HTTP exception message: the status line, or an RFC 7807 /
/// Hydra title and detail from a JSON error body.
fn http_error_message(line: &str, url: &str, json_type: bool, body: &[u8]) -> String {
    let fallback = format!("{line} returned for \"{url}\".");
    if !json_type {
        return fallback;
    }
    let Ok(Value::Object(body)) = serde_json::from_slice::<Value>(body) else {
        return fallback;
    };
    if body.is_empty() {
        return fallback;
    }
    let text = |key: &str| {
        body.get(key)
            .filter(|value| !value.is_null())
            .map(|value| crate::php::to_string(Some(value)))
    };
    let scalar = |key: &str| {
        body.get(key)
            .is_none_or(|value| crate::php::is_scalar(Some(value)) || value.is_null())
    };
    let joined = |title: Option<String>, detail: Option<String>| {
        let separator = if title.is_some() && detail.is_some() {
            "\n\n"
        } else {
            ""
        };
        format!(
            "{}{separator}{}",
            title.unwrap_or_default(),
            detail.unwrap_or_default()
        )
    };
    let (hydra_title, hydra_description) = (text("hydra:title"), text("hydra:description"));
    if hydra_title.is_some() || hydra_description.is_some() {
        return joined(hydra_title, hydra_description);
    }
    let (title, detail) = (text("title"), text("detail"));
    if (title.is_some() || detail.is_some()) && scalar("title") && scalar("detail") {
        return joined(title, detail);
    }
    fallback
}

fn debug_type(value: &Value) -> &'static str {
    match value {
        Value::Null => "null",
        Value::Bool(_) => "bool",
        Value::Number(number) if number.is_f64() => "float",
        Value::Number(_) => "int",
        Value::String(_) => "string",
        _ => "array",
    }
}

/// A transport failure, worded like Symfony's (`… for "url".`). The detail
/// comes from this client rather than libcurl.
fn transport_message(error: &reqwest::Error, url: &str) -> String {
    let detail = if error.is_timeout() {
        "Idle timeout reached".to_string()
    } else if error.is_connect() {
        let host = reqwest::Url::parse(url)
            .ok()
            .map(|parsed| match parsed.port_or_known_default() {
                Some(port) => format!("{}:{port}", parsed.host_str().unwrap_or_default()),
                None => parsed.host_str().unwrap_or_default().to_string(),
            })
            .unwrap_or_default();
        format!("Failed to connect to {host}: Could not connect to server")
    } else {
        let mut message = error.to_string();
        let mut source = std::error::Error::source(error);
        while let Some(cause) = source {
            message = format!("{message}: {cause}");
            source = cause.source();
        }
        message
    };
    format!("{detail} for \"{url}\".")
}

/// `ReleaseCatalog::lookupChecksum`.
pub fn lookup_checksum(sums: &str, asset: &str) -> Result<String, String> {
    for line in sums.split("\r\n").flat_map(|line| line.split(['\n', '\r'])) {
        let line = crate::php::trim(line);
        if line.is_empty() || line.starts_with('#') {
            continue;
        }
        let Some(hash) = line
            .get(..64)
            .filter(|hash| hash.bytes().all(|b| b.is_ascii_hexdigit()))
        else {
            continue;
        };
        let rest = &line[64..];
        let name = rest.trim_start_matches([' ', '\t', '\n', '\r', '\x0b', '\x0c']);
        if name.len() == rest.len() {
            continue;
        }
        let name = name.strip_prefix('*').unwrap_or(name);
        if name.is_empty() {
            continue;
        }
        if crate::php::trim(name) == asset {
            return Ok(hash.to_ascii_lowercase());
        }
    }
    Err(format!("checksum for {asset} not found in SHA256SUMS"))
}

// ---------------------------------------------------------------------------
// Replacing the binary.

pub enum ReplaceError {
    Permission(String),
    Other(String),
}

fn random_suffix() -> String {
    use std::hash::{BuildHasher, Hasher};
    let mut hasher = std::collections::hash_map::RandomState::new().build_hasher();
    hasher.write_u128(
        std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .map(|d| d.as_nanos())
            .unwrap_or(0),
    );
    hasher.write_u32(std::process::id());
    format!("{:012x}", hasher.finish() & 0xffff_ffff_ffff)
}

/// `UpgradeCommand::defaultReplacer`: stage beside the destination, then
/// rename over it. On Windows the running executable is moved aside first,
/// since Windows refuses to replace a running image but allows renaming it.
pub fn default_replacer(destination: &str, bytes: &[u8]) -> Result<(), ReplaceError> {
    use std::io::Write;
    let parent = Path::new(destination)
        .parent()
        .map(|dir| dir.to_string_lossy().into_owned())
        .unwrap_or_default();
    let dir = if parent.is_empty() {
        ".".to_string()
    } else {
        parent
    };
    if !Path::new(&dir).is_dir() {
        return Err(ReplaceError::Other(format!(
            "install directory {dir} does not exist"
        )));
    }
    if !is_writable(&dir) {
        return Err(ReplaceError::Permission(format!(
            "install directory {dir} is not writable"
        )));
    }
    #[cfg(windows)]
    remove_stale_asides(destination);
    let temp = format!("{dir}/.dw-upgrade-{}", random_suffix());
    // A fresh, owner-only file: never an existing path or a planted link.
    let mut options = std::fs::OpenOptions::new();
    options.write(true).create_new(true);
    #[cfg(unix)]
    {
        use std::os::unix::fs::OpenOptionsExt;
        options.mode(0o700);
    }
    let mut file = options
        .open(&temp)
        .map_err(|_| ReplaceError::Permission(format!("could not create temp file in {dir}")))?;
    if file
        .write_all(bytes)
        .and_then(|()| file.sync_all())
        .is_err()
    {
        drop(file);
        let _ = std::fs::remove_file(&temp);
        return Err(ReplaceError::Other(format!(
            "short write while staging {temp}"
        )));
    }
    drop(file);
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        // Keep the installed binary's own permissions (an ordinary install
        // is 0755), always owner-executable, never group- or world-writable,
        // setuid or setgid.
        let mode = std::fs::metadata(destination)
            .map_or(0o700, |metadata| metadata.permissions().mode() & 0o755)
            | 0o100;
        if std::fs::set_permissions(&temp, std::fs::Permissions::from_mode(mode)).is_err() {
            let _ = std::fs::remove_file(&temp);
            return Err(ReplaceError::Other(format!(
                "could not chmod {mode:o} on {temp}"
            )));
        }
    }
    #[cfg(windows)]
    let aside = {
        let aside = format!("{destination}.old-{}", random_suffix());
        std::fs::rename(destination, &aside).ok().map(|()| aside)
    };
    if std::fs::rename(&temp, destination).is_err() {
        let _ = std::fs::remove_file(&temp);
        #[cfg(windows)]
        if let Some(aside) = &aside {
            if std::fs::rename(aside, destination).is_err() {
                return Err(ReplaceError::Other(format!(
                    "could not replace {destination}, and could not restore it from {aside}"
                )));
            }
        }
        return Err(ReplaceError::Permission(format!(
            "could not replace {destination} (destination not writable)"
        )));
    }
    #[cfg(windows)]
    if let Some(aside) = aside {
        // Fails while the old image is still running; the next upgrade
        // removes it (remove_stale_asides).
        let _ = std::fs::remove_file(aside);
    }
    Ok(())
}

/// Whether `file_name` is an aside an earlier Windows upgrade of
/// `binary_name` left behind: exactly `<binary>.old-<12 lowercase hex>`.
#[cfg(any(windows, test))]
fn is_stale_aside(file_name: &str, binary_name: &str) -> bool {
    file_name
        .strip_prefix(binary_name)
        .and_then(|rest| rest.strip_prefix(".old-"))
        .is_some_and(|suffix| {
            suffix.len() == 12
                && suffix
                    .bytes()
                    .all(|b| matches!(b, b'0'..=b'9' | b'a'..=b'f'))
        })
}

/// Removes asides an earlier Windows upgrade left behind while the old
/// executable was still running.
#[cfg(windows)]
fn remove_stale_asides(destination: &str) {
    let path = Path::new(destination);
    let (Some(dir), Some(name)) = (path.parent(), path.file_name()) else {
        return;
    };
    let name = name.to_string_lossy();
    let Ok(entries) = std::fs::read_dir(dir) else {
        return;
    };
    for entry in entries.flatten() {
        if is_stale_aside(&entry.file_name().to_string_lossy(), &name) {
            let _ = std::fs::remove_file(entry.path());
        }
    }
}

// ---------------------------------------------------------------------------
// The command (UpgradeCommand).

fn permission_hint(path: &str) -> String {
    if path.starts_with("/usr/") || path.starts_with("/opt/") {
        return "Re-run with elevated privileges (e.g. `sudo dw upgrade`) or reinstall to a user-writable path such as ~/.local/bin and re-run `dw upgrade`.".into();
    }
    let dir = Path::new(path)
        .parent()
        .map(|dir| dir.to_string_lossy().into_owned())
        .unwrap_or_default();
    let dir = if dir.is_empty() { ".".to_string() } else { dir };
    format!("Re-run with write access to {dir}, or reinstall to a user-writable path such as ~/.local/bin and re-run `dw upgrade`.")
}

struct Emitter {
    json: bool,
}

impl Emitter {
    fn emit(&self, payload: Value, code: i32) -> i32 {
        if self.json {
            writeln_out(&output::encode(&payload));
        } else {
            render_human(&payload);
        }
        code
    }
}

fn text(payload: &Value, key: &str) -> String {
    crate::php::to_string(payload.get(key))
}

fn render_human(payload: &Value) {
    match payload["status"].as_str().unwrap_or_default() {
        "noop" => writeln_out(&format!(
            "<info>dw is already at {}</info>",
            payload
                .get("current_version")
                .and_then(Value::as_str)
                .unwrap_or("unknown")
        )),
        "newer" => writeln_out(&format!("<info>{}</info>", text(payload, "reason"))),
        "dry-run" => {
            let operation = if payload["direction"] == "downgrade" {
                "downgrade"
            } else {
                "upgrade"
            };
            writeln_out(&format!(
                "<info>Would {operation} {} -> {}</info>",
                text(payload, "current_version"),
                text(payload, "target_version")
            ));
            writeln_out(&format!("  asset:    {}", text(payload, "asset_url")));
            writeln_out(&format!("  checksum: {}", text(payload, "checksum_url")));
        }
        status @ ("upgraded" | "downgraded") => {
            let verb = if status == "upgraded" {
                "Upgraded"
            } else {
                "Downgraded"
            };
            writeln_out(&format!(
                "<info>{verb} dw to {}</info>",
                text(payload, "target_version")
            ));
            writeln_out(&format!(
                "  path: {}",
                crate::php::to_string(payload["installation"].get("path"))
            ));
        }
        "refused" => writeln_out(&format!(
            "<error>Upgrade refused:</error> {}",
            text(payload, "reason")
        )),
        "permission-denied" => {
            writeln_out(&format!(
                "<error>Upgrade failed:</error> {}",
                text(payload, "reason")
            ));
            if let Some(hint) = payload
                .get("hint")
                .and_then(Value::as_str)
                .filter(|hint| !hint.is_empty())
            {
                writeln_out(&format!("  {hint}"));
            }
        }
        _ => writeln_out(&format!(
            "<error>Upgrade failed:</error> {}",
            payload
                .get("reason")
                .map(|reason| crate::php::to_string(Some(reason)))
                .unwrap_or_else(|| "unknown error".into())
        )),
    }
}

/// The payload fields every outcome carries after `status` (and `reason`).
fn outcome(
    status: &str,
    reason: Option<String>,
    target: &Target,
    current: &str,
    target_version: Option<&str>,
) -> Map<String, Value> {
    let mut payload = Map::new();
    payload.insert("status".into(), json!(status));
    if let Some(reason) = reason {
        payload.insert("reason".into(), json!(reason));
    }
    payload.insert("installation".into(), target.to_value());
    payload.insert("current_version".into(), json!(current));
    payload.insert("target_version".into(), json!(target_version));
    payload
}

type Replacer = dyn Fn(&str, &[u8]) -> Result<(), ReplaceError>;

pub fn upgrade(input: &Input, catalog: &Catalog, target: &Target, replacer: &Replacer) -> i32 {
    let mode = input.opt_str("output").unwrap_or("table");
    let mode = if mode.is_empty() { "table" } else { mode };
    if mode != "table" && mode != "json" {
        writeln_out("<error>--output must be one of: table, json</error>");
        return exit::INVALID;
    }
    let out = Emitter {
        json: mode == "json",
    };
    let current = build_info::version();
    let requested = input
        .opt_str("tag")
        .map(|tag| crate::php::trim(tag).trim_start_matches('v').to_string())
        .filter(|tag| !tag.is_empty());
    let dry_run = input.flag("dry-run");
    let force = input.flag("force");
    let emit = |payload: Map<String, Value>, code| out.emit(Value::Object(payload), code);

    if !target.upgradeable && !dry_run {
        return emit(
            outcome(
                "refused",
                Some(target.reason.clone()),
                target,
                current,
                None,
            ),
            exit::FAILURE,
        );
    }

    let target_version = match &requested {
        Some(tag) => tag.clone(),
        None => match catalog.supported_tag() {
            Ok(tag) => tag,
            Err(reason) => {
                return emit(
                    outcome("error", Some(reason), target, current, None),
                    exit::FAILURE,
                )
            }
        },
    };
    let version = Some(target_version.as_str());

    let Some(asset) = target.asset.clone() else {
        let reason = if target.reason.is_empty() {
            "no release asset maps to this platform".to_string()
        } else {
            target.reason.clone()
        };
        return emit(
            outcome("refused", Some(reason), target, current, version),
            exit::FAILURE,
        );
    };

    let comparison = match compare_versions(current, &target_version) {
        Ok(ordering) => ordering,
        Err(message) => {
            let reason = format!("cannot determine upgrade direction: {message}");
            return emit(
                outcome("error", Some(reason), target, current, version),
                exit::FAILURE,
            );
        }
    };
    if comparison.is_eq() && !force {
        let reason = format!("dw is already at {current}");
        return emit(
            outcome("noop", Some(reason), target, current, version),
            exit::SUCCESS,
        );
    }
    let downgrade = comparison.is_gt();
    if downgrade && requested.is_none() {
        let reason = format!("dw {current} is newer than the latest stable release {target_version}; no change was made");
        return emit(
            outcome("newer", Some(reason), target, current, version),
            exit::SUCCESS,
        );
    }
    let direction = if downgrade {
        "downgrade"
    } else if comparison.is_lt() {
        "upgrade"
    } else {
        "reinstall"
    };
    let binary_url = catalog.download_url(&target_version, &asset);
    let sums_url = catalog.download_url(&target_version, "SHA256SUMS");

    if dry_run {
        let mut payload = outcome("dry-run", None, target, current, version);
        payload.insert("direction".into(), json!(direction));
        payload.insert("asset_url".into(), json!(binary_url));
        payload.insert("checksum_url".into(), json!(sums_url));
        return emit(payload, exit::SUCCESS);
    }
    if !target.upgradeable {
        return emit(
            outcome(
                "refused",
                Some(target.reason.clone()),
                target,
                current,
                version,
            ),
            exit::FAILURE,
        );
    }

    let fetched = catalog.fetch(&sums_url).and_then(|sums| {
        let expected = lookup_checksum(&String::from_utf8_lossy(&sums), &asset)?;
        Ok((expected, catalog.fetch(&binary_url)?))
    });
    let (expected, binary) = match fetched {
        Ok(fetched) => fetched,
        Err(reason) => {
            return emit(
                outcome("error", Some(reason), target, current, version),
                exit::FAILURE,
            )
        }
    };
    let actual = format!("{:x}", Sha256::digest(&binary));
    if expected != actual {
        let reason = format!("checksum mismatch for {asset} (expected {expected}, got {actual})");
        return emit(
            outcome("error", Some(reason), target, current, version),
            exit::FAILURE,
        );
    }

    match replacer(&target.path, &binary) {
        Ok(()) => {}
        Err(ReplaceError::Permission(reason)) => {
            let mut payload = Map::new();
            payload.insert("status".into(), json!("permission-denied"));
            payload.insert("reason".into(), json!(reason));
            payload.insert("hint".into(), json!(permission_hint(&target.path)));
            payload.extend(
                outcome("", None, target, current, version)
                    .into_iter()
                    .filter(|(key, _)| key != "status"),
            );
            return emit(payload, exit::FAILURE);
        }
        Err(ReplaceError::Other(reason)) => {
            return emit(
                outcome("error", Some(reason), target, current, version),
                exit::FAILURE,
            );
        }
    }

    let mut payload = outcome(
        if downgrade { "downgraded" } else { "upgraded" },
        None,
        target,
        current,
        version,
    );
    payload.insert("direction".into(), json!(direction));
    emit(payload, exit::SUCCESS)
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::cmp::Ordering;

    #[test]
    fn semver_precedence_matches_release_version() {
        let cases = [
            ("2.3.5", "2.5.0", Ordering::Less),
            ("2.5.0-dev", "2.5.0", Ordering::Less),
            ("2.5.0-rc.1", "2.5.0-rc.2", Ordering::Less),
            ("2.5.0-rc.10", "2.5.0-rc.9", Ordering::Greater),
            ("2.5.0-alpha", "2.5.0-alpha.1", Ordering::Less),
            ("2.5.0-1", "2.5.0-alpha", Ordering::Less),
            ("v2.5.0", "2.5.0+build.7", Ordering::Equal),
            ("10.0.0", "9.99.99", Ordering::Greater),
            (
                "12345678901234567890.0.0",
                "12345678901234567889.0.0",
                Ordering::Greater,
            ),
        ];
        for (left, right, expected) in cases {
            assert_eq!(
                compare_versions(left, right),
                Ok(expected),
                "{left} vs {right}"
            );
        }
        for invalid in [
            "2.5",
            "02.5.0",
            "2.5.0-01",
            "2.5.0-",
            "x",
            "2.5.0+",
            "2.5.0-a..b",
        ] {
            assert_eq!(
                compare_versions(invalid, "1.0.0"),
                Err(format!("invalid semantic version [{invalid}]")),
                "{invalid}"
            );
        }
    }

    #[test]
    fn assets_map_like_2x() {
        assert_eq!(
            map_asset("Linux", "x86_64").as_deref(),
            Some("dw-linux-x86_64")
        );
        assert_eq!(
            map_asset("Linux", "aarch64").as_deref(),
            Some("dw-linux-aarch64")
        );
        assert_eq!(
            map_asset("Darwin", "arm64").as_deref(),
            Some("dw-macos-aarch64")
        );
        assert_eq!(
            map_asset("Darwin", "x86_64").as_deref(),
            Some("dw-macos-x86_64")
        );
        assert_eq!(
            map_asset("Windows", "AMD64").as_deref(),
            Some("dw-windows-x86_64.exe")
        );
        assert_eq!(map_asset("Linux", "riscv64"), None);
    }

    #[test]
    fn the_running_executable_wins_over_argv0() {
        let dir = std::env::temp_dir().join(format!("dw-upgrade-exe-{}", random_suffix()));
        std::fs::create_dir_all(&dir).unwrap();
        let real = dir.join("real-dw");
        std::fs::write(&real, b"x").unwrap();
        let expected = realpath(&real.to_string_lossy()).unwrap();
        let target = detect_target(Some(&real.to_string_lossy()), "dw", "Linux", "x86_64");
        assert_eq!(target.path, expected);
        // Without a reportable executable, only an explicit argv[0] path is
        // trusted; a bare name is refused rather than resolved in the cwd.
        let fallback = detect_target(None, &real.to_string_lossy(), "Linux", "x86_64");
        assert_eq!(fallback.path, expected);
        let bare = detect_target(None, "dw", "Linux", "x86_64");
        assert_eq!(
            (bare.kind, bare.upgradeable, bare.path.as_str()),
            ("unknown", false, "")
        );
        let unresolvable = detect_target(Some("/nonexistent/dw"), "dw", "Linux", "x86_64");
        assert_eq!(unresolvable.kind, "unknown");
        let missing = detect_target(None, "/nonexistent/dw", "Linux", "x86_64");
        assert_eq!(missing.kind, "unknown");
        std::fs::remove_dir_all(&dir).unwrap();
    }

    #[test]
    fn only_exact_asides_are_stale() {
        assert!(is_stale_aside("dw.exe.old-0123456789ab", "dw.exe"));
        for other in [
            "dw.exe.old-0123456789AB",
            "dw.exe.old-0123456789a",
            "dw.exe.old-0123456789abc",
            "dw.exe.old-backup",
            "dw.exe.old-",
            "dw.exe",
            "other.exe.old-0123456789ab",
            "dw.exe.old-0123456789ab.keep",
        ] {
            assert!(!is_stale_aside(other, "dw.exe"), "{other}");
        }
    }

    #[test]
    fn managed_installs_are_refused() {
        let root = std::env::temp_dir().join(format!("dw-upgrade-managed-{}", random_suffix()));
        let file = |relative: &str| {
            let path = root.join(relative);
            std::fs::create_dir_all(path.parent().unwrap()).unwrap();
            std::fs::write(&path, b"x").unwrap();
            path.to_string_lossy().into_owned()
        };
        let vendor = detect_target(Some(&file("app/vendor/bin/dw")), "dw", "Linux", "x86_64");
        assert_eq!(
            (vendor.kind, vendor.upgradeable),
            ("composer-vendor", false)
        );
        let brew = detect_target(Some(&file("opt/homebrew/bin/dw")), "dw", "Darwin", "arm64");
        assert_eq!(
            (brew.kind, brew.reason.as_str()),
            ("homebrew", HOMEBREW_REASON)
        );
        let empty = detect_target(None, "", "Linux", "x86_64");
        assert_eq!(
            (empty.kind, empty.reason.as_str()),
            ("unknown", "could not resolve the dw executable path")
        );
        let plain = file("bin/dw");
        let other = detect_target(Some(&plain), "dw", "Linux", "riscv64");
        assert_eq!(
            other.reason,
            "no published release asset matches this platform (Linux/riscv64)"
        );
        assert_eq!(other.path, realpath(&plain).unwrap());
        std::fs::remove_dir_all(&root).unwrap();
    }

    #[test]
    fn checksums_parse_like_2x() {
        let hash = "A".repeat(64);
        let sums = format!(
            "# comment\n{} *dw-linux-x86_64\r\n{hash}  dw-macos-aarch64 \n",
            "b".repeat(64)
        );
        assert_eq!(
            lookup_checksum(&sums, "dw-linux-x86_64"),
            Ok("b".repeat(64))
        );
        assert_eq!(
            lookup_checksum(&sums, "dw-macos-aarch64"),
            Ok("a".repeat(64))
        );
        assert_eq!(
            lookup_checksum(&sums, "dw-windows-x86_64.exe"),
            Err("checksum for dw-windows-x86_64.exe not found in SHA256SUMS".into())
        );
        assert!(lookup_checksum(&format!("{hash}dw-linux-x86_64"), "dw-linux-x86_64").is_err());
    }

    #[test]
    fn http_errors_use_symfony_messages() {
        let url = "https://api.example/x";
        assert_eq!(
            http_error_message("HTTP/1.1 404 Not Found", url, false, b"{}"),
            "HTTP/1.1 404 Not Found returned for \"https://api.example/x\"."
        );
        assert_eq!(
            http_error_message(
                "HTTP/1.1 403 Forbidden",
                url,
                true,
                br#"{"title":"Forbidden","detail":"rate limited"}"#
            ),
            "Forbidden\n\nrate limited"
        );
        assert_eq!(
            http_error_message("HTTP/1.1 403 Forbidden", url, true, br#"{"detail":"only"}"#),
            "only"
        );
        assert_eq!(
            http_error_message(
                "HTTP/1.1 404 Not Found",
                url,
                true,
                br#"{"message":"Not Found"}"#
            ),
            "HTTP/1.1 404 Not Found returned for \"https://api.example/x\"."
        );
    }

    /// A loopback HTTP/1.1 server answering fixed routes.
    fn serve(routes: Vec<(&'static str, u16, &'static str, Vec<u8>)>) -> String {
        use std::io::{BufRead, BufReader, Write};
        let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
        let base = format!("http://{}", listener.local_addr().unwrap());
        std::thread::spawn(move || {
            for stream in listener.incoming().flatten() {
                let mut reader = BufReader::new(stream.try_clone().unwrap());
                let mut request = String::new();
                if reader.read_line(&mut request).is_err() {
                    continue;
                }
                loop {
                    let mut line = String::new();
                    if reader.read_line(&mut line).map(|n| n == 0).unwrap_or(true) || line == "\r\n"
                    {
                        break;
                    }
                }
                let path = request.split_whitespace().nth(1).unwrap_or("/").to_string();
                let (status, content_type, body) = routes
                    .iter()
                    .find(|(route, ..)| *route == path)
                    .map(|(_, status, content_type, body)| (*status, *content_type, body.clone()))
                    .unwrap_or((404, "text/plain", b"missing".to_vec()));
                let reason = reqwest::StatusCode::from_u16(status)
                    .ok()
                    .and_then(|s| s.canonical_reason())
                    .unwrap_or("");
                let mut stream = stream;
                let head = format!(
                    "HTTP/1.1 {status} {reason}\r\nContent-Type: {content_type}\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",
                    body.len()
                );
                let _ = stream
                    .write_all(head.as_bytes())
                    .and_then(|()| stream.write_all(&body));
            }
        });
        base
    }

    #[test]
    fn the_latest_stable_tag_is_read_like_2x() {
        let json = "application/json";
        let base = serve(vec![
            (
                "/ok",
                200,
                json,
                br#"{"tag_name":"v9.9.9","draft":false,"prerelease":false}"#.to_vec(),
            ),
            (
                "/pre",
                200,
                json,
                br#"{"tag_name":"9.9.9-rc.1","draft":false,"prerelease":true}"#.to_vec(),
            ),
            (
                "/notag",
                200,
                json,
                br#"{"draft":false,"prerelease":false}"#.to_vec(),
            ),
            ("/html", 200, "text/html", b"<html>".to_vec()),
            (
                "/padded",
                200,
                json,
                br#"{"tag_name":"9.9.9\n","draft":false,"prerelease":false}"#.to_vec(),
            ),
            (
                "/problem",
                403,
                "application/problem+json",
                br#"{"title":"Forbidden","detail":"rate limited"}"#.to_vec(),
            ),
        ]);
        let tag = |path: &str| Catalog::new(&format!("{base}{path}"), &base).supported_tag();
        assert_eq!(tag("/ok"), Ok("9.9.9".into()));
        assert_eq!(
            tag("/pre"),
            Err("latest CLI release must be a published stable version".into())
        );
        assert_eq!(
            tag("/padded"),
            Err("latest CLI release must be a published stable version".into())
        );
        assert_eq!(
            tag("/notag"),
            Err("latest stable CLI release must include a tag_name".into())
        );
        let prefix = "could not fetch the latest stable CLI release: ";
        assert_eq!(
            tag("/missing"),
            Err(format!(
                "{prefix}HTTP/1.1 404 Not Found returned for \"{base}/missing\"."
            ))
        );
        assert_eq!(
            tag("/html"),
            Err(format!("{prefix}Syntax error for \"{base}/html\"."))
        );
        assert_eq!(
            tag("/problem"),
            Err(format!("{prefix}Forbidden\n\nrate limited"))
        );
    }

    #[test]
    fn release_downloads_refuse_plain_http() {
        let base = serve(vec![("/x", 200, "text/plain", b"x".to_vec())]);
        let catalog = Catalog {
            authority_url: String::new(),
            download_base: base.clone(),
            https_downloads: true,
        };
        let error = catalog.fetch(&format!("{base}/x")).unwrap_err();
        assert!(
            error.starts_with(&format!("failed to download {base}/x: ")),
            "{error}"
        );
    }

    fn input(arguments: &[&str]) -> Input {
        let spec = crate::catalog::spec("upgrade").unwrap();
        let tokens: Vec<String> = arguments
            .iter()
            .map(|argument| argument.to_string())
            .collect();
        crate::cli::parse(&spec, &tokens).unwrap()
    }

    #[test]
    fn an_upgrade_verifies_the_checksum_before_replacing() {
        let binary = b"new dw".to_vec();
        let sums = format!("{:x}  dw-linux-x86_64\n", Sha256::digest(&binary)).into_bytes();
        let release = "/durable-workflow/cli/releases/download/9.9.9";
        let routes = vec![
            (
                "/latest",
                200,
                "application/json",
                br#"{"tag_name":"9.9.9","draft":false,"prerelease":false}"#.to_vec(),
            ),
            (
                Box::leak(format!("{release}/SHA256SUMS").into_boxed_str()) as &str,
                200,
                "text/plain",
                sums,
            ),
            (
                Box::leak(format!("{release}/dw-linux-x86_64").into_boxed_str()) as &str,
                200,
                "application/octet-stream",
                binary.clone(),
            ),
            (
                "/durable-workflow/cli/releases/download/9.9.8/SHA256SUMS",
                200,
                "text/plain",
                format!("{}  dw-linux-x86_64\n", "0".repeat(64)).into_bytes(),
            ),
            (
                "/durable-workflow/cli/releases/download/9.9.8/dw-linux-x86_64",
                200,
                "application/octet-stream",
                b"tampered".to_vec(),
            ),
        ];
        let base = serve(routes);
        let dir = std::env::temp_dir().join(format!("dw-upgrade-flow-{}", random_suffix()));
        std::fs::create_dir_all(&dir).unwrap();
        let path = dir.join("dw");
        std::fs::write(&path, b"old dw").unwrap();
        let target = Target {
            kind: "binary",
            path: path.to_string_lossy().into_owned(),
            upgradeable: true,
            asset: Some("dw-linux-x86_64".into()),
            reason: String::new(),
        };
        let catalog = Catalog::new(&format!("{base}/latest"), &base);

        assert_eq!(
            upgrade(
                &input(&["--tag=9.9.8", "--output=json"]),
                &catalog,
                &target,
                &default_replacer
            ),
            exit::FAILURE
        );
        assert_eq!(
            std::fs::read(&path).unwrap(),
            b"old dw",
            "a checksum mismatch leaves the binary alone"
        );

        assert_eq!(
            upgrade(&input(&["--dry-run"]), &catalog, &target, &default_replacer),
            exit::SUCCESS
        );
        assert_eq!(std::fs::read(&path).unwrap(), b"old dw");

        assert_eq!(
            upgrade(
                &input(&["--output=json"]),
                &catalog,
                &target,
                &default_replacer
            ),
            exit::SUCCESS
        );
        assert_eq!(std::fs::read(&path).unwrap(), binary);

        assert_eq!(
            upgrade(
                &input(&["--output=xml"]),
                &catalog,
                &target,
                &default_replacer
            ),
            exit::INVALID
        );
        std::fs::remove_dir_all(&dir).unwrap();
    }

    #[test]
    fn replacer_stages_and_renames() {
        let dir = std::env::temp_dir().join(format!("dw-upgrade-test-{}", random_suffix()));
        std::fs::create_dir_all(&dir).unwrap();
        let destination = dir.join("dw");
        std::fs::write(&destination, b"old").unwrap();
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            std::fs::set_permissions(&destination, std::fs::Permissions::from_mode(0o4755))
                .unwrap();
        }
        assert!(default_replacer(&destination.to_string_lossy(), b"new").is_ok());
        assert_eq!(std::fs::read(&destination).unwrap(), b"new");
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            // The installed mode is kept, without setuid.
            assert_eq!(
                std::fs::metadata(&destination)
                    .unwrap()
                    .permissions()
                    .mode()
                    & 0o7777,
                0o755
            );
            // A group- or world-writable install does not stay writable.
            std::fs::set_permissions(&destination, std::fs::Permissions::from_mode(0o777)).unwrap();
            assert!(default_replacer(&destination.to_string_lossy(), b"newer").is_ok());
            let mode = std::fs::metadata(&destination)
                .unwrap()
                .permissions()
                .mode();
            assert_eq!(mode & 0o7777, 0o755);
        }
        assert_eq!(std::fs::read_dir(&dir).unwrap().count(), 1);
        let missing = dir.join("missing").join("dw");
        assert!(
            matches!(default_replacer(&missing.to_string_lossy(), b"x"), Err(ReplaceError::Other(message)) if message.ends_with("does not exist"))
        );
        std::fs::remove_dir_all(&dir).unwrap();
    }
}
