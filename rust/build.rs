//! Embeds the repository's published JSON schemas (`schemas/`) so the
//! `schema:*` commands serve exactly the files 2.x bundles.

use std::fmt::Write as _;
use std::path::{Path, PathBuf};

fn collect(dir: &Path, root: &Path, files: &mut Vec<(String, PathBuf)>) {
    let mut entries: Vec<_> = std::fs::read_dir(dir)
        .unwrap_or_else(|error| panic!("cannot read {}: {error}", dir.display()))
        .map(|entry| entry.expect("directory entry").path())
        .collect();
    entries.sort();
    for path in entries {
        // Never follow a symlink out of schemas/.
        let metadata = std::fs::symlink_metadata(&path)
            .unwrap_or_else(|error| panic!("cannot stat {}: {error}", path.display()));
        if metadata.file_type().is_symlink() {
            continue;
        }
        if path.is_dir() {
            collect(&path, root, files);
        } else if path
            .extension()
            .is_some_and(|extension| extension == "json")
        {
            let relative = path.strip_prefix(root).expect("under the repository root");
            let relative = relative.to_string_lossy().replace('\\', "/");
            files.push((relative, path));
        }
    }
}

fn main() {
    println!(
        "cargo:rustc-env=DW_CLI_TARGET={}",
        std::env::var("TARGET").expect("set by cargo")
    );
    let crate_dir = PathBuf::from(std::env::var("CARGO_MANIFEST_DIR").expect("set by cargo"));
    let root = crate_dir
        .parent()
        .expect("the crate lives in rust/")
        .to_path_buf();
    let schemas = root.join("schemas");
    println!("cargo:rerun-if-changed={}", schemas.display());
    let mut files = Vec::new();
    collect(&schemas, &root, &mut files);
    let mut generated = String::from("/// Every bundled schema file, by repository-relative path.\npub static SCHEMA_FILES: &[(&str, &str)] = &[\n");
    for (relative, path) in &files {
        println!("cargo:rerun-if-changed={}", path.display());
        writeln!(
            generated,
            "    ({relative:?}, include_str!({:?})),",
            path.to_string_lossy()
        )
        .expect("write to string");
    }
    generated.push_str("];\n");
    let out = PathBuf::from(std::env::var("OUT_DIR").expect("set by cargo")).join("schemas.rs");
    std::fs::write(out, generated).expect("write the generated schema table");
}
