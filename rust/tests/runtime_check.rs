use std::process::{Command, Output};

fn run(args: &[&str]) -> Output {
    Command::new(env!("CARGO_BIN_EXE_dw"))
        .args(args)
        .env_clear()
        // Runtime checks must work without an external PHP or other tool,
        // and must not load or require the selected connection profile.
        .env("PATH", "")
        .env("DW_ENV", "missing-runtime-test-profile")
        .output()
        .expect("run the packaged binary")
}

#[test]
fn reports_the_executed_binary_and_compilation_target_without_external_tools() {
    let version = run(&["--version"]);
    assert!(version.status.success());
    let runtime = run(&["runtime:check"]);
    assert!(runtime.status.success());
    assert!(runtime.stderr.is_empty());
    assert_eq!(
        String::from_utf8(runtime.stdout).unwrap(),
        format!(
            "Rust runtime: {}\nTarget: {}\n",
            String::from_utf8(version.stdout).unwrap().trim_end(),
            env!("DW_CLI_TARGET")
        )
    );
}

#[test]
fn quiet_and_silent_runtime_checks_keep_the_success_exit_code() {
    for flag in ["--quiet", "--silent"] {
        let runtime = run(&["runtime:check", flag]);
        assert!(runtime.status.success());
        assert!(runtime.stdout.is_empty());
        assert!(runtime.stderr.is_empty());
    }
}

#[test]
fn application_json_keeps_runtime_hidden_and_uses_the_same_help() {
    let listing = run(&["list", "--format=json"]);
    let help = run(&["help", "runtime:check", "--format=json"]);
    assert!(listing.status.success() && help.status.success());
    let listing: serde_json::Value = serde_json::from_slice(&listing.stdout).unwrap();
    let help: serde_json::Value = serde_json::from_slice(&help.stdout).unwrap();
    let runtime = listing["commands"]
        .as_array()
        .unwrap()
        .iter()
        .find(|command| command["name"] == "runtime:check")
        .unwrap();
    assert_eq!(runtime["hidden"], true);
    assert_eq!(runtime["help"], help["help"]);
}
