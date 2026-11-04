//! Output modes and rendering. JSON matches the 2.x CLI byte for byte
//! (see `encode`); the config file uses PHP's pretty-print layout.

use crate::cli::Input;
use crate::error::CliError;
use serde_json::Value;

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Mode {
    Table,
    Json,
    Jsonl,
}

impl Mode {
    pub fn machine_readable(self) -> bool {
        self != Mode::Table
    }
}

fn parse_mode(value: &str) -> Option<Mode> {
    match value {
        "table" => Some(Mode::Table),
        "json" => Some(Mode::Json),
        "jsonl" => Some(Mode::Jsonl),
        _ => None,
    }
}

/// Resolves `--json`, then an explicit `--output`, then the selected
/// profile's default, then table. Unknown values are refused so scripts
/// never continue on a silently ignored mode.
pub fn resolve_mode(
    input: &Input,
    profile_output: impl FnOnce() -> Option<String>,
) -> Result<Mode, CliError> {
    if input.flag("json") {
        return Ok(Mode::Json);
    }
    let invalid = || CliError::Invalid("--output must be one of: table, json, jsonl".into());
    if input.provided("output") {
        return match input.opt_str("output") {
            None => Ok(Mode::Table),
            Some(value) => parse_mode(value).ok_or_else(invalid),
        };
    }
    if let Some(value) = profile_output() {
        return parse_mode(&value).ok_or_else(invalid);
    }
    match input.opt_str("output") {
        None => Ok(Mode::Table),
        Some(value) => parse_mode(value).ok_or_else(invalid),
    }
}

/// Escapes non-ASCII characters as JSON \u escapes. Outside strings JSON is
/// pure ASCII, so this only touches string contents.
fn escape_non_ascii(encoded: String) -> String {
    if encoded.is_ascii() {
        return encoded;
    }
    let mut escaped = String::with_capacity(encoded.len() + 16);
    for character in encoded.chars() {
        if character.is_ascii() {
            escaped.push(character);
        } else {
            let mut units = [0u16; 2];
            for unit in character.encode_utf16(&mut units) {
                escaped.push_str(&format!("\\u{unit:04x}"));
            }
        }
    }
    escaped
}

/// One JSON document exactly as 2.x prints it: PHP's
/// `json_encode($value, JSON_UNESCAPED_SLASHES)` of the decoded value, so an
/// empty Server object prints as `[]` and floats use PHP's format.
pub fn encode(value: &Value) -> String {
    crate::php::json_encode(value)
}

/// PHP's JSON_PRETTY_PRINT: four-space indent.
pub fn encode_pretty(value: &Value) -> String {
    let mut buffer = Vec::new();
    let formatter = serde_json::ser::PrettyFormatter::with_indent(b"    ");
    let mut serializer = serde_json::Serializer::with_formatter(&mut buffer, formatter);
    serde::Serialize::serialize(value, &mut serializer).expect("JSON values always serialize");
    escape_non_ascii(String::from_utf8(buffer).expect("serde_json writes UTF-8"))
}

pub fn print_json(value: &Value) {
    println!("{}", encode(value));
}

/// A list envelope: one item per line for jsonl, the whole envelope otherwise
/// so counts and cursors survive.
pub fn print_json_list(mode: Mode, envelope: &Value, items_key: &str) {
    if mode == Mode::Jsonl {
        if let Some(items) = envelope.get(items_key).and_then(Value::as_array) {
            for item in items {
                print_json(item);
            }
        }
        return;
    }
    print_json(envelope);
}

/// The 2.x status text. Terminal colors are not applied yet.
pub fn format_status(status: Option<&Value>) -> String {
    match status {
        Some(Value::String(text)) if !text.is_empty() => text.clone(),
        Some(Value::Number(number)) => number.to_string(),
        Some(Value::Bool(true)) => "1".into(),
        _ => "-".into(),
    }
}

fn width(text: &str) -> usize {
    text.chars().count()
}

/// Symfony Console's default table style.
pub fn render_table(headers: &[&str], rows: &[Vec<String>]) -> String {
    let columns = headers.len();
    let mut widths: Vec<usize> = headers.iter().map(|header| width(header)).collect();
    for row in rows {
        for (index, cell) in row.iter().enumerate().take(columns) {
            widths[index] = widths[index].max(width(cell));
        }
    }
    let border = format!(
        "+{}+",
        widths
            .iter()
            .map(|w| "-".repeat(w + 2))
            .collect::<Vec<_>>()
            .join("+")
    );
    let line = |cells: Vec<&str>| {
        let padded: Vec<String> = cells
            .iter()
            .enumerate()
            .map(|(index, cell)| format!(" {}{} ", cell, " ".repeat(widths[index] - width(cell))))
            .collect();
        format!("|{}|", padded.join("|"))
    };
    let mut lines = vec![border.clone(), line(headers.to_vec()), border.clone()];
    for row in rows {
        let cells: Vec<&str> = (0..columns)
            .map(|index| row.get(index).map(String::as_str).unwrap_or(""))
            .collect();
        lines.push(line(cells));
    }
    lines.push(border);
    lines.join("\n")
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn json_matches_php_encoding_and_empty_objects() {
        assert_eq!(
            encode(&json!({"checks": {}, "list": {"0": "a"}})),
            r#"{"checks":[],"list":["a"]}"#
        );
        let value = json!({"url": "http://x/y", "name": "Zo\u{eb} \u{1f680}"});
        let expected = "{\"url\":\"http://x/y\",\"name\":\"Zo\\u00eb \\ud83d\\ude80\"}";
        assert_eq!(encode(&value), expected);
    }

    #[test]
    fn pretty_json_uses_four_spaces() {
        assert_eq!(
            encode_pretty(&json!({"a": [1]})),
            "{\n    \"a\": [\n        1\n    ]\n}"
        );
    }

    #[test]
    fn table_matches_symfony_layout() {
        let table = render_table(&["Field", "Value"], &[vec!["name".into(), "dev".into()]]);
        assert_eq!(table, "+-------+-------+\n| Field | Value |\n+-------+-------+\n| name  | dev   |\n+-------+-------+");
    }
}
