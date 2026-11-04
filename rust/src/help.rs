//! Command help (`dw <command> --help`, `dw help <command>`) in the 2.x
//! layout, ported from Symfony Console's TextDescriptor and rendered from
//! the 2.x catalog.

use crate::catalog::Entry;
use serde_json::Value;

/// One option of a command definition, as the help page shows it.
struct HelpOption {
    name: String,
    shortcut: String,
    accept_value: bool,
    value_optional: bool,
    negatable: bool,
    multiple: bool,
    default: Value,
    description: String,
}

struct HelpArgument {
    name: String,
    description: String,
    default: Value,
}

fn items(value: &Value) -> Vec<&Value> {
    match value {
        Value::Object(map) => map.values().collect(),
        Value::Array(items) => items.iter().collect(),
        _ => vec![],
    }
}

fn text(value: &Value) -> String {
    value.as_str().unwrap_or_default().to_string()
}

/// The definition's options. A negatable option appears once, as
/// `--ansi|--no-ansi`; the catalog lists its `--no-` form separately.
fn options(definition: &Value) -> Vec<HelpOption> {
    let raw = items(&definition["options"]);
    let names: Vec<String> = raw.iter().map(|option| text(&option["name"])).collect();
    raw.iter()
        .filter(|option| {
            let name = text(&option["name"]);
            !name
                .strip_prefix("--no-")
                .is_some_and(|base| names.contains(&format!("--{base}")))
        })
        .map(|option| {
            let name = text(&option["name"]).trim_start_matches("--").to_string();
            HelpOption {
                negatable: names.contains(&format!("--no-{name}")),
                shortcut: text(&option["shortcut"])
                    .split('|')
                    .map(|part| part.trim_start_matches('-'))
                    .collect::<Vec<_>>()
                    .join("|"),
                accept_value: option["accept_value"].as_bool().unwrap_or(false),
                value_optional: !option["is_value_required"].as_bool().unwrap_or(false),
                multiple: option["is_multiple"].as_bool().unwrap_or(false),
                default: option["default"].clone(),
                description: strip_styles(&text(&option["description"])),
                name,
            }
        })
        .collect()
}

fn arguments(definition: &Value) -> Vec<HelpArgument> {
    items(&definition["arguments"])
        .into_iter()
        .map(|argument| HelpArgument {
            name: text(&argument["name"]),
            description: strip_styles(&text(&argument["description"])),
            default: argument["default"].clone(),
        })
        .collect()
}

fn width(text: &str) -> usize {
    text.chars().count()
}

/// `calculateTotalWidthForOptions`.
fn option_width(option: &HelpOption) -> usize {
    let mut length = 1 + width(&option.shortcut).max(1) + 4 + width(&option.name);
    if option.negatable {
        length += 6 + width(&option.name);
    } else if option.accept_value {
        length += 1 + width(&option.name) + if option.value_optional { 2 } else { 0 };
    }
    length
}

/// `formatDefaultValue`: JSON with slashes and Unicode unescaped.
fn default_text(value: &Value) -> String {
    serde_json::to_string(value)
        .unwrap_or_default()
        .replace("\\\\", "\\")
}

fn has_default(value: &Value) -> bool {
    match value {
        Value::Null => false,
        Value::Array(items) => !items.is_empty(),
        _ => true,
    }
}

/// Continuation lines of a description line up under its first line.
fn wrap_description(description: &str, total_width: usize) -> String {
    let indent = format!("\n{}", " ".repeat(total_width + 4));
    let lines: Vec<&str> = description.split(['\r', '\n']).map(str::trim).collect();
    lines.join(&indent)
}

fn describe_option(option: &HelpOption, total_width: usize) -> String {
    let default = if option.accept_value && has_default(&option.default) {
        format!(" [default: {}]", default_text(&option.default))
    } else {
        String::new()
    };
    let mut value = String::new();
    if option.accept_value {
        value = format!("={}", option.name.to_uppercase());
        if option.value_optional {
            value = format!("[{value}]");
        }
    }
    let shortcut = if option.shortcut.is_empty() {
        "    ".to_string()
    } else {
        format!("-{}, ", option.shortcut)
    };
    let long = if option.negatable {
        format!("--{0}|--no-{0}", option.name)
    } else {
        format!("--{}{value}", option.name)
    };
    let synopsis = format!("{shortcut}{long}");
    let spacing = total_width.saturating_sub(width(&synopsis));
    format!(
        "  {synopsis}  {}{}{default}{}",
        " ".repeat(spacing),
        wrap_description(&option.description, total_width),
        if option.multiple {
            " (multiple values allowed)"
        } else {
            ""
        }
    )
}

fn describe_argument(argument: &HelpArgument, total_width: usize) -> String {
    let default = if has_default(&argument.default) {
        format!(" [default: {}]", default_text(&argument.default))
    } else {
        String::new()
    };
    format!(
        "  {}  {}{}{default}",
        argument.name,
        " ".repeat(total_width.saturating_sub(argument.name.len())),
        wrap_description(&argument.description, total_width)
    )
}

/// `describeInputDefinition`.
fn describe_definition(options: &[HelpOption], arguments: &[HelpArgument]) -> String {
    let total_width = options
        .iter()
        .map(option_width)
        .chain(arguments.iter().map(|argument| width(&argument.name)))
        .max()
        .unwrap_or(0);
    let mut out = String::new();
    if !arguments.is_empty() {
        out.push_str("Arguments:\n");
        for argument in arguments {
            out.push_str(&describe_argument(argument, total_width));
            out.push('\n');
        }
    }
    if !arguments.is_empty() && !options.is_empty() {
        out.push('\n');
    }
    if !options.is_empty() {
        out.push_str("Options:");
        // Options with a multi-letter shortcut (-v|vv|vvv) come last.
        let (later, first): (Vec<&HelpOption>, Vec<&HelpOption>) = options
            .iter()
            .partition(|option| option.shortcut.chars().count() > 1);
        for option in first.into_iter().chain(later) {
            out.push('\n');
            out.push_str(&describe_option(option, total_width));
        }
    }
    out
}

/// Options every command inherits from the application definition.
const APPLICATION_OPTIONS: &[&str] = &[
    "help",
    "silent",
    "quiet",
    "verbose",
    "version",
    "ansi",
    "no-ansi",
    "no-interaction",
];

/// `getSynopsis(true)`: `name [options] [--] <argument>`. Symfony computes it
/// from the command's own definition, so `[options]` only appears when the
/// command defines options beyond the application's.
fn short_synopsis(name: &str, options: &[HelpOption], definition: &Value) -> String {
    let mut parts = vec![name.to_string()];
    let arguments = items(&definition["arguments"]);
    let own_options = options
        .iter()
        .any(|option| !APPLICATION_OPTIONS.contains(&option.name.as_str()));
    if own_options {
        parts.push("[options]".into());
        if !arguments.is_empty() {
            parts.push("[--]".into());
        }
    }
    for argument in arguments {
        let mut element = format!("<{}>", text(&argument["name"]));
        if argument["is_array"].as_bool().unwrap_or(false) {
            element.push_str("...");
        }
        if !argument["is_required"].as_bool().unwrap_or(false) {
            element = format!("[{element}]");
        }
        parts.push(element);
    }
    parts.join(" ")
}

/// Removes Symfony formatter styles (`<info>`, `<comment>`, `</>`, `<fg=…>`)
/// and unescapes `\<`. Other angle-bracket text, such as `<name>`, stays.
pub fn strip_styles(text: &str) -> String {
    const STYLES: &[&str] = &["info", "comment", "error", "question"];
    let mut out = String::with_capacity(text.len());
    let mut rest = text;
    while let Some(start) = rest.find('<') {
        if start > 0 && rest[..start].ends_with('\\') {
            out.push_str(&rest[..start - 1]);
            out.push('<');
            rest = &rest[start + 1..];
            continue;
        }
        out.push_str(&rest[..start]);
        let after = &rest[start + 1..];
        let Some(end) = after.find('>') else {
            out.push_str(&rest[start..]);
            return out;
        };
        let tag = after[..end].trim_start_matches('/');
        let is_style = tag.is_empty()
            || STYLES.contains(&tag)
            || tag.split(';').all(|part| {
                part.split_once('=').is_some_and(|(key, value)| {
                    matches!(key, "fg" | "bg" | "options" | "href") && !value.is_empty()
                })
            });
        if !is_style {
            out.push('<');
            rest = after;
            continue;
        }
        rest = &after[end + 1..];
    }
    out.push_str(rest);
    // Symfony escapes both brackets; `\<` is handled above.
    out.replace("\\>", ">")
}

/// Fills the catalog's `{dw}` and `{dw_name}` placeholders with this
/// binary's path, which the 2.x standalone binary reports for Symfony's
/// `%command.full_name%`.
fn with_program(text: &str) -> String {
    let program = std::env::current_exe()
        .ok()
        .map(|path| path.to_string_lossy().into_owned())
        .or_else(|| std::env::args().next())
        .unwrap_or_else(|| "dw".into());
    let name = std::path::Path::new(&program)
        .file_name()
        .map(|name| name.to_string_lossy().into_owned())
        .unwrap_or_else(|| program.clone());
    text.replace("{dw}", &program).replace("{dw_name}", &name)
}

/// The 2.x help page for a command, ending with a newline.
pub fn render(entry: &Entry) -> String {
    let definition = entry.definition();
    let options = options(definition);
    let arguments = arguments(definition);
    let mut out = String::new();
    let description = strip_styles(&entry.description);
    if !description.is_empty() {
        out.push_str(&format!("Description:\n  {description}\n\n"));
    }
    out.push_str("Usage:");
    let usages = std::iter::once(short_synopsis(&entry.name, &options, definition))
        .chain(entry.aliases.iter().cloned());
    for usage in usages {
        out.push_str(&format!("\n  {usage}"));
    }
    out.push('\n');
    if !options.is_empty() || !arguments.is_empty() {
        out.push('\n');
        out.push_str(&describe_definition(&options, &arguments));
        out.push('\n');
    }
    let help = strip_styles(&with_program(&entry.help));
    if !help.is_empty() && help != description {
        out.push_str(&format!("\nHelp:\n  {}\n", help.replace('\n', "\n  ")));
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn env_use_help_matches_2x() {
        let page = render(crate::catalog::entry("env:use").unwrap());
        assert!(page.starts_with(
            "Description:\n  Set the default dw environment profile\n\nUsage:\n  env:use [options] [--] <name>\n\nArguments:\n  name                  Profile name to use by default\n\nOptions:\n      --output=OUTPUT   Output format:"
        ));
        assert!(page.contains(
            "[default: \"table\"]\n      --json            Output the resulting profile as JSON"
        ));
        assert!(
            page.contains("\n      --ansi|--no-ansi  Force (or disable --no-ansi) ANSI output\n")
        );
        assert!(page.contains("\n  -v|vv|vvv, --verbose  Increase the verbosity"));
        assert!(
            page.contains("\nHelp:\n  Persist name as the default dw environment profile.\n  \n")
        );
        assert!(page.ends_with("    dw env:use prod --json\n"));
    }

    #[test]
    fn commands_without_own_options_omit_the_options_marker() {
        let page = render(crate::catalog::entry("runtime:check").unwrap());
        assert!(page.contains("Usage:\n  runtime:check\n"));
    }

    #[test]
    fn aliases_are_listed_as_usages() {
        let page = render(crate::catalog::entry("server:info").unwrap());
        assert!(page.contains("Usage:\n  server:info [options]\n  cluster:info\n"));
        assert!(page.contains("\n  -s, --server[=SERVER]        Server URL\n"));
    }

    #[test]
    fn only_formatter_styles_are_removed() {
        assert_eq!(
            strip_styles("<info>dw</info> <name> <comment>x</comment> </> \\<b\\>"),
            "dw <name> x  <b>"
        );
        assert_eq!(strip_styles("<fg=red;options=bold>hot</>"), "hot");
    }
}
