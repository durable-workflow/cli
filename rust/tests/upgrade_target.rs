//! `dw upgrade` must replace the running executable, never a `dw` entry in
//! the current directory (cli#86 review): run as plain `dw` from a directory
//! holding a `dw` symlink to another file, the target is still this binary.

#![cfg(unix)]

use std::os::unix::process::CommandExt;
use std::process::Command;

#[test]
fn upgrade_targets_the_running_binary_not_a_dw_in_the_working_directory() {
    let binary = std::fs::canonicalize(env!("CARGO_BIN_EXE_dw")).unwrap();
    let work = std::env::temp_dir().join(format!("dw-upgrade-cwd-{}", std::process::id()));
    std::fs::create_dir_all(&work).unwrap();
    let victim = work.join("victim.txt");
    std::fs::write(&victim, b"not dw").unwrap();
    let planted = work.join("dw");
    let _ = std::fs::remove_file(&planted);
    std::os::unix::fs::symlink(&victim, &planted).unwrap();

    let output = Command::new(&binary)
        .arg0("dw")
        .args(["upgrade", "--tag=2.3.4", "--dry-run", "--output=json"])
        .current_dir(&work)
        .env("PATH", binary.parent().unwrap())
        .env("DW_CONFIG_HOME", &work)
        .output()
        .unwrap();
    let stdout = String::from_utf8_lossy(&output.stdout);
    let payload: serde_json::Value = serde_json::from_str(stdout.trim()).unwrap();

    assert_eq!(payload["status"], "dry-run", "{stdout}");
    assert_eq!(
        payload["installation"]["path"],
        binary.to_string_lossy().as_ref()
    );
    assert_eq!(std::fs::read(&victim).unwrap(), b"not dw");
    std::fs::remove_dir_all(&work).unwrap();
}
