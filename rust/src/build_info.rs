//! The CLI's version identity, set at build time by the release workflow
//! (`DW_CLI_VERSION`, `DW_CLI_COMMIT`, `DW_CLI_BUILD_DATE`) and otherwise
//! taken from the crate version.

/// The bare version, as compared with Server-advertised CLI ranges.
pub fn version() -> &'static str {
    option_env!("DW_CLI_VERSION")
        .map(|version| version.strip_prefix('v').unwrap_or(version))
        .unwrap_or(env!("CARGO_PKG_VERSION"))
}

/// The compilation target, including the linked runtime ABI.
pub fn target() -> &'static str {
    env!("DW_CLI_TARGET")
}

/// `dw <version> (commit <sha>, built <date>)`, as `dw --version` prints it.
pub fn version_line() -> String {
    let commit = option_env!("DW_CLI_COMMIT")
        .map(|commit| &commit[..commit.len().min(12)])
        .unwrap_or("unknown");
    let built = option_env!("DW_CLI_BUILD_DATE").unwrap_or("source checkout");
    format!("dw {} (commit {commit}, built {built})", version())
}
