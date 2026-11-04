//! Symfony's MarkdownDescriptor. Help text passes through the formatter
//! undecorated (styles removed) by the caller.

use super::{ApplicationView, ArgumentView, CommandView, OptionView};
use crate::{build_info, lookup};
use serde_json::Value;

/// PHP `var_export` on one line, as the descriptor prints defaults.
fn var_export(value: &Value) -> String {
    match value {
        Value::Null => "NULL".into(),
        Value::Bool(flag) => flag.to_string(),
        Value::Number(number) => number.to_string(),
        Value::String(text) => format!("'{}'", text.replace('\\', "\\\\").replace('\'', "\\'")),
        Value::Array(items) => {
            let entries: String = items
                .iter()
                .enumerate()
                .map(|(index, item)| format!("  {index} => {},", var_export(item)))
                .collect();
            format!("array ({entries})")
        }
        Value::Object(map) => {
            let entries: String = map
                .iter()
                .map(|(key, item)| format!("  '{key}' => {},", var_export(item)))
                .collect();
            format!("array ({entries})")
        }
    }
}

/// `preg_replace('/\s*[\r\n]\s*/', "\n", $description)`.
fn collapse_newlines(description: &str) -> String {
    let mut out = String::new();
    let mut run = String::new();
    for character in description.chars() {
        if character.is_whitespace() {
            run.push(character);
            continue;
        }
        out.push_str(if run.contains(['\r', '\n']) {
            "\n"
        } else {
            &run
        });
        run.clear();
        out.push(character);
    }
    out.push_str(if run.contains(['\r', '\n']) {
        "\n"
    } else {
        &run
    });
    out
}

fn description_block(description: &str) -> String {
    if description.is_empty() {
        String::new()
    } else {
        format!("{}\n\n", collapse_newlines(description))
    }
}

fn yes_no(flag: bool) -> &'static str {
    if flag {
        "yes"
    } else {
        "no"
    }
}

fn describe_argument(argument: &ArgumentView) -> String {
    let name = if argument.name.is_empty() {
        "<none>"
    } else {
        &argument.name
    };
    format!(
        "#### `{name}`\n\n{}* Is required: {}\n* Is array: {}\n* Default: `{}`",
        description_block(&argument.description),
        yes_no(argument.required),
        yes_no(argument.array),
        var_export(&argument.default)
    )
}

fn describe_option(option: &OptionView) -> String {
    let mut name = format!("--{}", option.name);
    if option.negatable {
        name.push_str(&format!("|--no-{}", option.name));
    }
    if !option.shortcut.is_empty() {
        name.push_str(&format!("|-{}", option.shortcut.replace('|', "|-")));
    }
    format!(
        "#### `{name}`\n\n{}* Accept value: {}\n* Is value required: {}\n* Is multiple: {}\n* Is negatable: {}\n* Default: `{}`",
        description_block(&option.description),
        yes_no(option.accept_value),
        yes_no(option.value_required),
        yes_no(option.multiple),
        yes_no(option.negatable),
        var_export(&option.default)
    )
}

fn describe_definition(view: &CommandView) -> String {
    let mut out = String::new();
    if !view.arguments.is_empty() {
        out.push_str("### Arguments");
        for argument in &view.arguments {
            out.push_str("\n\n");
            out.push_str(&describe_argument(argument));
        }
    }
    if !view.options.is_empty() {
        if !view.arguments.is_empty() {
            out.push_str("\n\n");
        }
        out.push_str("### Options");
        for option in &view.options {
            out.push_str("\n\n");
            out.push_str(&describe_option(option));
        }
    }
    out
}

fn heading(name: &str) -> String {
    format!("`{name}`\n{}\n\n", "-".repeat(name.chars().count() + 2))
}

fn command_text(view: &CommandView, short: bool) -> String {
    let entry = view.entry;
    let mut out = heading(&entry.name);
    if !entry.description.is_empty() {
        out.push_str(&format!("{}\n\n", entry.description));
    }
    out.push_str("### Usage\n\n");
    if short {
        for usage in &entry.aliases {
            out.push_str(&format!("* `{usage}`\n"));
        }
        return out;
    }
    for usage in std::iter::once(view.synopsis()).chain(entry.aliases.iter().map(String::as_str)) {
        out.push_str(&format!("* `{usage}`\n"));
    }
    if !view.help.is_empty() {
        out.push('\n');
        out.push_str(&view.help);
    }
    if !view.options.is_empty() || !view.arguments.is_empty() {
        out.push_str("\n\n");
        out.push_str(&describe_definition(view));
    }
    out
}

pub fn command(view: &CommandView) -> String {
    command_text(view, false)
}

pub fn application(described: Option<&str>, short: bool) -> String {
    let resolved = described.and_then(|namespace| lookup::find_namespace(namespace).ok());
    let view = ApplicationView::new(resolved.as_deref(), false);
    let line = build_info::version_line();
    let title = line.clone();
    let mut out = format!("{title}\n{}", "=".repeat(title.chars().count()));
    for namespace in &view.namespaces {
        if namespace.id != "_global" {
            out.push_str("\n\n");
            out.push_str(&format!("**{}:**", namespace.id));
        }
        out.push_str("\n\n");
        let links: Vec<String> = namespace
            .names
            .iter()
            .map(|name| {
                let target = view
                    .command(name)
                    .map(|entry| entry.name.replace(':', ""))
                    .unwrap_or_default();
                format!("* [`{name}`](#{target})")
            })
            .collect();
        out.push_str(&links.join("\n"));
    }
    for entry in &view.commands {
        out.push_str("\n\n");
        out.push_str(&command_text(&CommandView::new(entry), short));
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::catalog;

    #[test]
    fn markdown_command_matches_symfony_layout() {
        let text = command(&CommandView::new(catalog::entry("env:use").unwrap()));
        assert!(text.starts_with("`env:use`\n---------\n\nSet the default dw environment profile\n\n### Usage\n\n* `env:use [--output OUTPUT] [--json] [--] <name>`\n\nPersist <info>name</info>"));
        assert!(text.contains("### Arguments\n\n#### `name`\n\nProfile name to use by default\n\n* Is required: yes\n* Is array: no\n* Default: `NULL`"));
        assert!(text.contains("#### `--output`\n\n"));
        assert!(text.contains("* Default: `'table'`"));
        assert!(text.contains("#### `--ansi|--no-ansi`"));
        assert!(text.contains("#### `--verbose|-v|-vv|-vvv`"));
    }

    #[test]
    fn var_export_matches_php() {
        assert_eq!(var_export(&Value::Bool(false)), "false");
        assert_eq!(var_export(&serde_json::json!([])), "array ()");
        assert_eq!(var_export(&serde_json::json!(["a"])), "array (  0 => 'a',)");
        assert_eq!(var_export(&Value::String("it's".into())), "'it\\'s'");
    }
}
