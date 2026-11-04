//! Symfony's TextDescriptor. Output keeps formatter styles; `raw` applies
//! PHP's `strip_tags` to each written piece, as 2.x's `--raw` does.

use super::{ApplicationView, ArgumentView, CommandView, OptionView, APPLICATION_OPTIONS};
use crate::{build_info, catalog, formatter, lookup};
use serde_json::Value;

struct Writer {
    raw: bool,
    out: String,
}

impl Writer {
    fn new(raw: bool) -> Self {
        Self {
            raw,
            out: String::new(),
        }
    }

    fn text(&mut self, content: &str) {
        if self.raw {
            self.out.push_str(&formatter::strip_tags(content));
        } else {
            self.out.push_str(content);
        }
    }
}

fn width(text: &str) -> usize {
    text.chars().count()
}

/// `calculateTotalWidthForOptions`.
fn option_width(option: &OptionView) -> usize {
    let mut length = 1 + width(&option.shortcut).max(1) + 4 + width(&option.name);
    if option.negatable {
        length += 6 + width(&option.name);
    } else if option.accept_value {
        length += 1 + width(&option.name) + if option.value_required { 0 } else { 2 };
    }
    length
}

fn has_default(value: &Value) -> bool {
    match value {
        Value::Null => false,
        Value::Array(items) => !items.is_empty(),
        _ => true,
    }
}

/// `formatDefaultValue`: escaped strings, JSON with slashes and Unicode
/// unescaped.
fn default_text(value: &Value) -> String {
    let escaped = match value {
        Value::String(text) => Value::String(formatter::escape(text)),
        Value::Array(items) => Value::Array(
            items
                .iter()
                .map(|item| match item {
                    Value::String(text) => Value::String(formatter::escape(text)),
                    other => other.clone(),
                })
                .collect(),
        ),
        other => other.clone(),
    };
    serde_json::to_string(&escaped)
        .unwrap_or_default()
        .replace("\\\\", "\\")
}

/// `preg_replace('/\s*[\r\n]\s*/', "\n" . indent, $description)`.
fn wrap_description(description: &str, total_width: usize) -> String {
    let indent = format!("\n{}", " ".repeat(total_width + 4));
    let mut out = String::new();
    let mut run = String::new();
    for character in description.chars() {
        if character.is_whitespace() {
            run.push(character);
            continue;
        }
        flush_whitespace(&mut out, &mut run, &indent);
        out.push(character);
    }
    flush_whitespace(&mut out, &mut run, &indent);
    out
}

fn flush_whitespace(out: &mut String, run: &mut String, indent: &str) {
    if run.contains(['\r', '\n']) {
        out.push_str(indent);
    } else {
        out.push_str(run);
    }
    run.clear();
}

fn describe_option(writer: &mut Writer, option: &OptionView, total_width: usize) {
    let default = if option.accept_value && has_default(&option.default) {
        format!(
            "<comment> [default: {}]</comment>",
            default_text(&option.default)
        )
    } else {
        String::new()
    };
    let mut value = String::new();
    if option.accept_value {
        value = format!("={}", option.name.to_uppercase());
        if !option.value_required {
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
    writer.text(&format!(
        "  <info>{synopsis}</info>  {}{}{default}{}",
        " ".repeat(spacing),
        wrap_description(&option.description, total_width),
        if option.multiple {
            "<comment> (multiple values allowed)</comment>"
        } else {
            ""
        }
    ));
}

fn describe_argument(writer: &mut Writer, argument: &ArgumentView, total_width: usize) {
    let default = if has_default(&argument.default) {
        format!(
            "<comment> [default: {}]</comment>",
            default_text(&argument.default)
        )
    } else {
        String::new()
    };
    writer.text(&format!(
        "  <info>{}</info>  {}{}{default}",
        argument.name,
        " ".repeat(total_width.saturating_sub(argument.name.len())),
        wrap_description(&argument.description, total_width)
    ));
}

/// `describeInputDefinition`.
fn describe_definition(writer: &mut Writer, options: &[&OptionView], arguments: &[ArgumentView]) {
    let total_width = options
        .iter()
        .map(|option| option_width(option))
        .chain(arguments.iter().map(|argument| width(&argument.name)))
        .max()
        .unwrap_or(0);
    if !arguments.is_empty() {
        writer.text("<comment>Arguments:</comment>");
        writer.text("\n");
        for argument in arguments {
            describe_argument(writer, argument, total_width);
            writer.text("\n");
        }
    }
    if !arguments.is_empty() && !options.is_empty() {
        writer.text("\n");
    }
    if !options.is_empty() {
        writer.text("<comment>Options:</comment>");
        // Options with a multi-letter shortcut (-v|vv|vvv) come last.
        let (later, first): (Vec<&&OptionView>, Vec<&&OptionView>) = options
            .iter()
            .partition(|option| option.shortcut.chars().count() > 1);
        for option in first.into_iter().chain(later) {
            writer.text("\n");
            describe_option(writer, option, total_width);
        }
    }
}

/// `describeCommand`.
pub fn command(view: &CommandView, raw: bool) -> String {
    let mut writer = Writer::new(raw);
    let description = &view.entry.description;
    if !description.is_empty() {
        writer.text("<comment>Description:</comment>");
        writer.text("\n");
        writer.text(&format!("  {description}"));
        writer.text("\n\n");
    }
    writer.text("<comment>Usage:</comment>");
    let usages = std::iter::once(view.short_synopsis()).chain(view.aliases().iter().cloned());
    for usage in usages {
        writer.text("\n");
        writer.text(&format!("  {}", formatter::escape(&usage)));
    }
    writer.text("\n");
    if !view.options.is_empty() || !view.arguments.is_empty() {
        writer.text("\n");
        let options: Vec<&OptionView> = view.options.iter().collect();
        describe_definition(&mut writer, &options, &view.arguments);
        writer.text("\n");
    }
    if !view.help.is_empty() && view.help != *description {
        writer.text("\n");
        writer.text("<comment>Help:</comment>");
        writer.text("\n");
        writer.text(&format!("  {}", view.help.replace('\n', "\n  ")));
        writer.text("\n");
    }
    writer.out
}

/// `getLongVersion()`: `dw <info>version (commit …, built …)</info>`.
fn long_version() -> String {
    let line = build_info::version_line();
    let details = line.strip_prefix("dw ").unwrap_or(&line);
    format!("dw <info>{details}</info>")
}

/// The application options, in the order 2.x lists them.
fn application_options() -> Vec<OptionView> {
    let entry = catalog::entry("list").expect("list is in the catalog");
    CommandView::new(entry)
        .options
        .into_iter()
        .filter(|option| APPLICATION_OPTIONS.contains(&option.name.as_str()))
        .collect()
}

/// The `-h`/`--help` … `--verbose` block every listing starts with.
fn describe_application_header(writer: &mut Writer) {
    writer.text(&format!("{}\n\n", long_version()));
    writer.text("<comment>Usage:</comment>\n");
    writer.text("  command [options] [arguments]\n\n");
    let options = application_options();
    let refs: Vec<&OptionView> = options.iter().collect();
    describe_definition(writer, &refs, &[]);
    writer.text("\n");
    writer.text("\n");
}

/// The header alone, which 2.x prints before failing on an unknown
/// `list` namespace.
pub fn application_header() -> String {
    let mut writer = Writer::new(false);
    describe_application_header(&mut writer);
    writer.out
}

/// `describeApplication`; `described` is the namespace as the caller wrote it.
pub fn application(described: Option<&str>, raw: bool) -> String {
    let resolved = described.and_then(|namespace| lookup::find_namespace(namespace).ok());
    let view = ApplicationView::new(resolved.as_deref(), false);
    let mut writer = Writer::new(raw);
    if raw {
        let width = view
            .commands
            .iter()
            .flat_map(|entry| std::iter::once(&entry.name).chain(entry.aliases.iter()))
            .map(|name| width(name))
            .max()
            .map_or(0, |widest| widest + 2);
        for entry in &view.commands {
            writer.text(&format!("{:<width$} {}", entry.name, entry.description));
            writer.text("\n");
        }
        return writer.out;
    }

    describe_application_header(&mut writer);
    // When describing one namespace, its aliases are listed too.
    let listed: Vec<&str> = match &resolved {
        Some(_) => view
            .namespaces
            .iter()
            .flat_map(|ns| ns.names.iter().map(String::as_str))
            .collect(),
        None => view
            .commands
            .iter()
            .map(|entry| entry.name.as_str())
            .collect(),
    };
    let width = view
        .namespaces
        .iter()
        .flat_map(|ns| ns.names.iter())
        .filter(|name| listed.contains(&name.as_str()))
        .map(|name| width(name))
        .max()
        .map_or(0, |widest| widest + 2);
    match described {
        Some(namespace) => writer.text(&format!(
            "<comment>Available commands for the \"{namespace}\" namespace:</comment>"
        )),
        None => writer.text("<comment>Available commands:</comment>"),
    }
    for namespace in &view.namespaces {
        let names: Vec<&String> = namespace
            .names
            .iter()
            .filter(|name| listed.contains(&name.as_str()))
            .collect();
        if names.is_empty() {
            continue;
        }
        if described.is_none() && namespace.id != "_global" {
            writer.text("\n");
            writer.text(&format!(" <comment>{}</comment>", namespace.id));
        }
        for name in names {
            writer.text("\n");
            let entry = view.command(name).expect("listed names are in the catalog");
            let aliases = if *name == entry.name && !entry.aliases.is_empty() {
                format!("[{}] ", entry.aliases.join("|"))
            } else {
                String::new()
            };
            writer.text(&format!(
                "  <info>{name}</info>{}{aliases}{}",
                " ".repeat(width.saturating_sub(width_of(name))),
                entry.description
            ));
        }
    }
    writer.text("\n");
    writer.out
}

fn width_of(text: &str) -> usize {
    width(text)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn plain(text: &str) -> String {
        formatter::format(text, false)
    }

    #[test]
    fn env_use_help_matches_2x() {
        let page = plain(&command(
            &CommandView::new(catalog::entry("env:use").unwrap()),
            false,
        ));
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
        assert!(page.ends_with("    dw env:use prod --json\n"));
    }

    #[test]
    fn styles_mark_headings_and_names() {
        let page = command(&CommandView::new(catalog::entry("env:use").unwrap()), false);
        assert!(page.starts_with("<comment>Description:</comment>\n"));
        assert!(page.contains("  <info>name</info>  "));
        assert!(page.contains("<comment> [default: \"table\"]</comment>"));
    }

    #[test]
    fn raw_help_strips_tags_like_2x() {
        let page = plain(&command(
            &CommandView::new(catalog::entry("env:use").unwrap()),
            true,
        ));
        assert!(page.contains("Usage:\n  env:use [options] [--] \\\n"));
        assert!(page.contains("\n  name                  Profile name to use by default\n"));
    }

    #[test]
    fn multiple_values_and_defaults_are_marked() {
        let view = CommandView::new(catalog::entry("workflow-task:complete").unwrap());
        let page = plain(&command(&view, false));
        assert!(page.contains(" (multiple values allowed)"));
        assert!(page.contains("[default: \"cli\"]"));
    }

    #[test]
    fn descriptions_wrap_on_newlines_only() {
        assert_eq!(
            wrap_description("one\n\n  two three", 2),
            "one\n      two three"
        );
        assert_eq!(wrap_description("a  b", 2), "a  b");
    }

    #[test]
    fn default_values_are_escaped_json() {
        assert_eq!(default_text(&Value::String("<x>".into())), "\"\\<x\\>\"");
        assert_eq!(
            default_text(&serde_json::json!(["a/b", "é"])),
            "[\"a/b\",\"é\"]"
        );
        assert_eq!(default_text(&Value::Bool(false)), "false");
    }

    #[test]
    fn listing_marks_namespaces_and_aliases() {
        let text = plain(&application(Some("activity"), false));
        assert!(text.contains(
            "Available commands for the \"activity\" namespace:\n  activity:complete  Complete"
        ));
        assert!(text.contains("\n  activity:describe  [activities:describe] Show"));
        let raw = plain(&application(None, true));
        assert!(raw.lines().any(|line| line.starts_with("env:list ")
            && line.ends_with("List configured dw environment profiles")));
    }
}
