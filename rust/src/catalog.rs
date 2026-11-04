//! The 2.x command surface: every command name, alias, description, usage
//! line and option definition, generated from the pinned 2.x CLI by
//! `parity/update-catalog.sh`. Lookup, listings and usage errors use it, so
//! they match 2.x even for commands this build has not ported yet.

use crate::cli::{ArgSpec, CommandSpec, OptSpec, ValueMode};
use serde_json::Value;
use std::sync::OnceLock;

pub struct Entry {
    pub name: String,
    pub description: String,
    pub synopsis: String,
    pub aliases: Vec<String>,
    pub hidden: bool,
    definition: Value,
}

fn entries() -> &'static [Entry] {
    static ENTRIES: OnceLock<Vec<Entry>> = OnceLock::new();
    ENTRIES.get_or_init(|| {
        let catalog: Value =
            serde_json::from_str(include_str!("catalog.json")).expect("catalog.json is valid JSON");
        catalog["commands"]
            .as_array()
            .expect("catalog has a commands list")
            .iter()
            .map(|command| Entry {
                name: text(&command["name"]),
                description: text(&command["description"]),
                synopsis: text(&command["synopsis"]),
                aliases: command["aliases"]
                    .as_array()
                    .map(|aliases| aliases.iter().map(text).collect())
                    .unwrap_or_default(),
                hidden: command["hidden"].as_bool().unwrap_or(false),
                definition: command["definition"].clone(),
            })
            .collect()
    })
}

fn text(value: &Value) -> String {
    value.as_str().unwrap_or_default().to_string()
}

pub fn all() -> &'static [Entry] {
    entries()
}

/// The entry for a command name or alias.
pub fn entry(name_or_alias: &str) -> Option<&'static Entry> {
    entries().iter().find(|entry| {
        entry.name == name_or_alias || entry.aliases.iter().any(|alias| alias == name_or_alias)
    })
}

/// Symfony serializes an empty definition map as a JSON list.
fn definition_items(value: &Value) -> Vec<&Value> {
    match value {
        Value::Object(map) => map.values().collect(),
        Value::Array(items) => items.iter().collect(),
        _ => vec![],
    }
}

fn option_spec(option: &Value) -> OptSpec {
    let mode = match (
        option["accept_value"].as_bool().unwrap_or(false),
        option["is_value_required"].as_bool().unwrap_or(false),
    ) {
        (false, _) => ValueMode::None,
        (true, true) => ValueMode::Required,
        (true, false) => ValueMode::Optional,
    };
    let shorts = option["shortcut"]
        .as_str()
        .unwrap_or_default()
        .split('|')
        .filter_map(|shortcut| shortcut.trim_start_matches('-').chars().next())
        .collect();
    OptSpec {
        long: text(&option["name"]).trim_start_matches("--").to_string(),
        shorts,
        mode,
        multiple: option["is_multiple"].as_bool().unwrap_or(false),
        default: option["default"].as_str().map(str::to_string),
    }
}

pub fn spec(name: &str) -> Option<CommandSpec> {
    let entry = entry(name)?;
    let definition = &entry.definition;
    Some(CommandSpec {
        name: entry.name.clone(),
        description: entry.description.clone(),
        synopsis: entry.synopsis.clone(),
        args: definition_items(&definition["arguments"])
            .into_iter()
            .map(|argument| ArgSpec {
                name: text(&argument["name"]),
                required: argument["is_required"].as_bool().unwrap_or(false),
                array: argument["is_array"].as_bool().unwrap_or(false),
            })
            .collect(),
        opts: definition_items(&definition["options"])
            .into_iter()
            .map(option_spec)
            .collect(),
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn catalog_covers_the_2x_surface() {
        assert!(all().len() >= 80);
        assert_eq!(
            entry("cluster:info").map(|entry| entry.name.as_str()),
            Some("server:info")
        );
        let health = spec("server:health").unwrap();
        let server = health.opts.iter().find(|opt| opt.long == "server").unwrap();
        assert_eq!(server.shorts, vec!['s']);
        assert_eq!(server.mode, ValueMode::Optional);
        assert!(health
            .opts
            .iter()
            .any(|opt| opt.long == "no-interaction" && opt.shorts == vec!['n']));
        assert!(health.args.is_empty());
    }
}
