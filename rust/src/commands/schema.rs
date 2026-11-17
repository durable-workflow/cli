//! `schema:list`, `schema:manifest` and `schema:show`: the JSON Schemas
//! bundled with the CLI, served from the files embedded at build time. A port
//! of 2.x `SchemaCommand\*`, `OutputSchemaRegistry` and
//! `ConfigSchemaRegistry`.

use crate::cli::Input;
use crate::error::{exit, CliError};
use crate::formatter::writeln_out;
use crate::output::render_table;
use serde_json::{Map, Value};

include!(concat!(env!("OUT_DIR"), "/schemas.rs"));

const OUTPUT_MANIFEST: &str = "schemas/output/manifest.json";
const CONFIG_MANIFEST: &str = "schemas/config/manifest.json";

fn read(path: &str) -> Result<Map<String, Value>, String> {
    let (_, contents) = SCHEMA_FILES
        .iter()
        .find(|(name, _)| *name == path)
        .ok_or_else(|| format!("Unable to read schema file [{path}]."))?;
    match serde_json::from_str(contents) {
        Ok(Value::Object(map)) => Ok(map),
        Ok(_) => Err(format!(
            "Schema file [{path}] must decode to a JSON object."
        )),
        Err(error) => Err(format!("Schema file [{path}] is invalid JSON: {error}")),
    }
}

/// `safeRelativePath`: only files under the registry's own directory.
fn safe_path(path: &str, prefix: &str) -> Result<String, String> {
    let path = path.trim_start_matches('/');
    if !path.starts_with(prefix) || path.contains("..") {
        return Err(format!("Unsafe schema path [{path}]."));
    }
    Ok(path.to_string())
}

fn group<'a>(manifest: &'a Map<String, Value>, key: &str) -> Option<&'a Map<String, Value>> {
    manifest.get(key).and_then(Value::as_object)
}

/// `OutputSchemaRegistry::manifestGroup`.
fn manifest_group(output: &str) -> Result<&'static str, String> {
    match output {
        "json" | "--json" | "--output=json" | "stdout-json" => Ok("commands"),
        "jsonl" | "--jsonl" | "--output=jsonl" => Ok("jsonl_commands"),
        other => Err(format!("Unsupported output schema mode [{other}].")),
    }
}

fn output_schema(command: &str, output: &str) -> Result<Map<String, Value>, String> {
    let manifest = read(OUTPUT_MANIFEST)?;
    let group_name = manifest_group(output)?;
    let entry = group(&manifest, group_name)
        .and_then(|commands| commands.get(command))
        .and_then(Value::as_object)
        .ok_or_else(|| {
            let label = if group_name == "jsonl_commands" {
                "JSONL"
            } else {
                "JSON"
            };
            format!("No {label} output schema is published for command [{command}].")
        })?;
    let file = entry
        .get("schema")
        .and_then(Value::as_str)
        .filter(|file| !file.trim().is_empty());
    let file = file
        .ok_or_else(|| format!("Output schema entry for [{command}] is missing a schema file."))?;
    read(&safe_path(file, "schemas/output/")?)
}

fn config_schema(name: &str) -> Result<Map<String, Value>, String> {
    let manifest = read(CONFIG_MANIFEST)?;
    let entry = group(&manifest, "schemas")
        .and_then(|schemas| schemas.get(name))
        .and_then(Value::as_object)
        .ok_or_else(|| format!("No config schema is published for [{name}]."))?;
    let file = entry
        .get("schema")
        .and_then(Value::as_str)
        .filter(|file| !file.trim().is_empty());
    let file =
        file.ok_or_else(|| format!("Config schema entry for [{name}] is missing a schema file."))?;
    read(&safe_path(file, "schemas/config/")?)
}

fn text(entry: &Map<String, Value>, key: &str) -> String {
    match entry.get(key) {
        None | Some(Value::Null) => "-".into(),
        value => crate::php::to_string(value),
    }
}

pub fn list(_input: &Input) -> Result<i32, CliError> {
    let manifest = read(OUTPUT_MANIFEST).map_err(CliError::Failure)?;
    let mut outputs: Vec<(String, &Map<String, Value>)> = Vec::new();
    for group_name in ["commands", "jsonl_commands"] {
        let commands = group(&manifest, group_name).ok_or_else(|| {
            CliError::Failure(format!(
                "Output schema manifest is missing a {group_name} map."
            ))
        })?;
        outputs.extend(commands.iter().filter_map(|(command, entry)| {
            entry.as_object().map(|entry| (command.clone(), entry))
        }));
    }
    let output_of = |entry: &Map<String, Value>| {
        entry
            .get("output")
            .map(|value| crate::php::to_string(Some(value)))
            .unwrap_or_default()
    };
    outputs.sort_by(|(left, a), (right, b)| {
        left.cmp(right)
            .then_with(|| output_of(a).cmp(&output_of(b)))
    });
    let mut rows: Vec<Vec<String>> = outputs
        .iter()
        .map(|(command, entry)| {
            vec![
                "output".into(),
                command.clone(),
                text(entry, "output"),
                text(entry, "schema"),
            ]
        })
        .collect();

    let config = read(CONFIG_MANIFEST).map_err(CliError::Failure)?;
    let schemas = group(&config, "schemas").ok_or_else(|| {
        CliError::Failure("Config schema manifest is missing a schemas map.".into())
    })?;
    let mut configs: Vec<(&String, &Map<String, Value>)> = schemas
        .iter()
        .filter_map(|(name, entry)| entry.as_object().map(|entry| (name, entry)))
        .collect();
    configs.sort_by_key(|(left, _)| *left);
    rows.extend(configs.into_iter().map(|(name, entry)| {
        vec![
            "config".into(),
            name.clone(),
            "-".into(),
            text(entry, "schema"),
        ]
    }));
    rows.sort_by(|left, right| left.join("\0").as_bytes().cmp(right.join("\0").as_bytes()));
    writeln_out(&render_table(&["Type", "Name", "Output", "Schema"], &rows));
    Ok(exit::SUCCESS)
}

pub fn manifest(_input: &Input) -> Result<i32, CliError> {
    let mut manifest = read(OUTPUT_MANIFEST).map_err(CliError::Failure)?;
    let config = read(CONFIG_MANIFEST).map_err(CliError::Failure)?;
    let schemas = config
        .get("schemas")
        .cloned()
        .unwrap_or_else(|| Value::Array(vec![]));
    manifest.insert("config_schemas".into(), schemas);
    writeln_out(&crate::php::json_encode_pretty(&Value::Object(manifest)));
    Ok(exit::SUCCESS)
}

pub fn show(input: &Input) -> Result<i32, CliError> {
    let name = input.arg("schema-name").unwrap_or_default();
    let output = input.opt("output").flatten().unwrap_or("json");
    let is_command = read(OUTPUT_MANIFEST)
        .map_err(CliError::Failure)?
        .get("commands")
        .and_then(Value::as_object)
        .is_some_and(|commands| commands.contains_key(name));
    let schema = if is_command {
        output_schema(name, output)
    } else {
        config_schema(name)
    };
    match schema {
        Ok(schema) => {
            writeln_out(&crate::php::json_encode_pretty(&Value::Object(schema)));
            Ok(exit::SUCCESS)
        }
        Err(message) => {
            writeln_out(&format!("<error>{message}</error>"));
            Ok(exit::INVALID)
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn every_manifest_entry_has_its_file() {
        let manifest = read(OUTPUT_MANIFEST).unwrap();
        for group_name in ["commands", "jsonl_commands"] {
            for (command, entry) in group(&manifest, group_name).unwrap() {
                let file = entry["schema"].as_str().unwrap();
                assert!(
                    read(&safe_path(file, "schemas/output/").unwrap()).is_ok(),
                    "{command}: {file}"
                );
            }
        }
        assert!(config_schema("external-executor-config").is_ok());
    }

    #[test]
    fn output_modes_follow_the_registry() {
        assert_eq!(manifest_group("--output=jsonl"), Ok("jsonl_commands"));
        assert_eq!(manifest_group("stdout-json"), Ok("commands"));
        assert_eq!(
            manifest_group("xml"),
            Err("Unsupported output schema mode [xml].".into())
        );
        assert_eq!(
            output_schema("nope", "json").unwrap_err(),
            "No JSON output schema is published for command [nope]."
        );
        assert!(safe_path("schemas/output/../config/x.json", "schemas/output/").is_err());
    }

    #[test]
    fn pretty_json_matches_php() {
        let value: Value = serde_json::from_str(r#"{"a":[1,{}],"b":{"c":"x/é"},"d":[]}"#).unwrap();
        assert_eq!(
            crate::php::json_encode_pretty(&value),
            "{\n    \"a\": [\n        1,\n        []\n    ],\n    \"b\": {\n        \"c\": \"x/\\u00e9\"\n    },\n    \"d\": []\n}"
        );
    }
}
