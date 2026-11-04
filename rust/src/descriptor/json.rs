//! Symfony's JsonDescriptor: PHP `json_encode` with default flags, so
//! slashes and non-ASCII characters are escaped.

use super::{ApplicationView, CommandView};
use crate::{build_info, lookup, php};
use serde_json::{json, Map, Value};

/// `json_encode($data)` without flags. Outside strings JSON has no `/`, so
/// escaping every slash only touches string contents.
fn encode(value: &Value) -> String {
    php::json_encode(value).replace('/', "\\/")
}

fn command_data(view: &CommandView, short: bool) -> Value {
    let entry = view.entry;
    let mut data = Map::new();
    data.insert("name".into(), json!(entry.name));
    data.insert("description".into(), json!(entry.description));
    if short {
        data.insert("usage".into(), json!(entry.aliases));
    } else {
        let usage: Vec<&str> = std::iter::once(view.synopsis())
            .chain(entry.aliases.iter().map(String::as_str))
            .collect();
        data.insert("usage".into(), json!(usage));
        data.insert("help".into(), json!(view.help));
        data.insert("definition".into(), entry.definition().clone());
    }
    data.insert("hidden".into(), json!(entry.hidden));
    Value::Object(data)
}

pub fn command(view: &CommandView) -> String {
    encode(&command_data(view, false))
}

pub fn application(described: Option<&str>, short: bool) -> String {
    let resolved = described.and_then(|namespace| lookup::find_namespace(namespace).ok());
    let view = ApplicationView::new(resolved.as_deref(), true);
    let line = build_info::version_line();
    let version = line.strip_prefix("dw ").unwrap_or(&line);
    let commands: Vec<Value> = view
        .commands
        .iter()
        .map(|entry| command_data(&CommandView::new(entry), short))
        .collect();
    let mut data = Map::new();
    data.insert(
        "application".into(),
        json!({"name": "dw", "version": version}),
    );
    data.insert("commands".into(), Value::Array(commands));
    match described {
        Some(namespace) => {
            data.insert("namespace".into(), json!(namespace));
        }
        None => {
            let namespaces: Vec<Value> = view
                .namespaces
                .iter()
                .map(|ns| json!({"id": ns.id, "commands": ns.names}))
                .collect();
            data.insert("namespaces".into(), Value::Array(namespaces));
        }
    }
    encode(&Value::Object(data))
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::catalog;

    #[test]
    fn command_json_matches_symfony_shape() {
        let text = command(&CommandView::new(catalog::entry("env:use").unwrap()));
        assert!(text.starts_with(r#"{"name":"env:use","description":"Set the default dw environment profile","usage":["env:use [--output OUTPUT] [--json] [--] <name>"],"help":"Persist <info>name<\/info>"#));
        assert!(text.contains("\\u2014"));
        assert!(text.ends_with(r#""hidden":false}"#));
    }
}
