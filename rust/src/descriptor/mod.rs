//! Command and application descriptions (`dw help`, `--help`, `dw list`) in
//! the formats 2.x offers: txt (with `--raw`), json, md and xml. Ported from
//! Symfony Console's descriptors and rendered from the 2.x catalog.

mod json;
mod markdown;
mod txt;
mod xml;

use crate::catalog::{self, Entry};
use serde_json::Value;

/// One option of a command definition.
pub struct OptionView {
    pub name: String,
    /// Shortcut letters as Symfony stores them, such as `v|vv|vvv`.
    pub shortcut: String,
    pub accept_value: bool,
    pub value_required: bool,
    pub negatable: bool,
    pub multiple: bool,
    pub default: Value,
    pub description: String,
}

pub struct ArgumentView {
    pub name: String,
    pub required: bool,
    pub array: bool,
    pub default: Value,
    pub description: String,
}

pub struct CommandView<'a> {
    pub entry: &'a Entry,
    pub options: Vec<OptionView>,
    pub arguments: Vec<ArgumentView>,
    pub help: String,
}

/// Options every command inherits from the application definition.
pub const APPLICATION_OPTIONS: &[&str] = &[
    "help",
    "silent",
    "quiet",
    "verbose",
    "version",
    "ansi",
    "no-ansi",
    "no-interaction",
];

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

/// The definition's options. A negatable option appears once; the catalog
/// lists its `--no-` form separately, as Symfony's JSON does.
fn options(definition: &Value) -> Vec<OptionView> {
    let raw = items(&definition["options"]);
    let names: Vec<String> = raw.iter().map(|option| text(&option["name"])).collect();
    raw.iter()
        .filter(|option| {
            !text(&option["name"])
                .strip_prefix("--no-")
                .is_some_and(|base| names.contains(&format!("--{base}")))
        })
        .map(|option| {
            let name = text(&option["name"]).trim_start_matches("--").to_string();
            OptionView {
                negatable: names.contains(&format!("--no-{name}")),
                shortcut: text(&option["shortcut"])
                    .split('|')
                    .map(|part| part.trim_start_matches('-'))
                    .collect::<Vec<_>>()
                    .join("|"),
                accept_value: option["accept_value"].as_bool().unwrap_or(false),
                value_required: option["is_value_required"].as_bool().unwrap_or(false),
                multiple: option["is_multiple"].as_bool().unwrap_or(false),
                default: option["default"].clone(),
                description: text(&option["description"]),
                name,
            }
        })
        .collect()
}

fn arguments(definition: &Value) -> Vec<ArgumentView> {
    items(&definition["arguments"])
        .into_iter()
        .map(|argument| ArgumentView {
            name: text(&argument["name"]),
            required: argument["is_required"].as_bool().unwrap_or(false),
            array: argument["is_array"].as_bool().unwrap_or(false),
            default: argument["default"].clone(),
            description: text(&argument["description"]),
        })
        .collect()
}

/// This binary's path and name, which 2.x prints for Symfony's
/// `%command.full_name%` (the resolved executable) and the completion
/// command name (`basename($argv[0])`).
fn program() -> (String, String) {
    let path = std::env::current_exe()
        .ok()
        .map(|path| path.to_string_lossy().into_owned())
        .unwrap_or_else(|| "dw".into());
    let invoked = std::env::args_os()
        .next()
        .map(|arg| arg.to_string_lossy().into_owned())
        .unwrap_or_else(|| path.clone());
    let name = std::path::Path::new(&invoked)
        .file_name()
        .map(|name| name.to_string_lossy().into_owned())
        .unwrap_or(invoked);
    (path, name)
}

/// Symfony's completion command help, which depends on `$SHELL`.
fn completion_help(path: &str, name: &str) -> String {
    let shell = std::env::var("SHELL")
        .ok()
        .and_then(|shell| shell.rsplit('/').next().map(str::to_string))
        .unwrap_or_default();
    let (rc_file, completion_file) = match shell.as_str() {
        "fish" => (
            "~/.config/fish/config.fish".to_string(),
            format!("/etc/fish/completions/{name}.fish"),
        ),
        "zsh" => ("~/.zshrc".to_string(), format!("$fpath[1]/_{name}")),
        _ => (
            "~/.bashrc".to_string(),
            format!("/etc/bash_completion.d/{name}"),
        ),
    };
    [
        "The <info>completion</> command dumps the shell completion script required".to_string(),
        "to use shell autocompletion (currently, bash, fish, zsh completion are supported).".into(),
        String::new(),
        "<comment>Static installation".into(),
        "-------------------</>".into(),
        String::new(),
        "Dump the script to a global completion file and restart your shell:".into(),
        String::new(),
        format!("    <info>{path} completion {shell} | sudo tee {completion_file}</>"),
        String::new(),
        "Or dump the script to a local file and source it:".into(),
        String::new(),
        format!("    <info>{path} completion {shell} > completion.sh</>"),
        String::new(),
        "    <comment># source the file whenever you use the project</>".into(),
        "    <info>source completion.sh</>".into(),
        String::new(),
        format!("    <comment># or add this line at the end of your \"{rc_file}\" file:</>"),
        "    <info>source /path/to/completion.sh</>".into(),
        String::new(),
        "<comment>Dynamic installation".into(),
        "--------------------</>".into(),
        String::new(),
        format!(
            "Add this to the end of your shell configuration file (e.g. <info>\"{rc_file}\"</>):"
        ),
        String::new(),
        format!("    <info>eval \"$({path} completion {shell})\"</>"),
    ]
    .join("\n")
}

/// The processed help text, styles still in place.
fn processed_help(entry: &Entry) -> String {
    let (path, name) = program();
    if entry.name == "completion" {
        return completion_help(&path, &name);
    }
    entry
        .help
        .replace("{dw}", &path)
        .replace("{dw_name}", &name)
}

impl<'a> CommandView<'a> {
    pub fn new(entry: &'a Entry) -> Self {
        let definition = entry.definition();
        Self {
            entry,
            options: options(definition),
            arguments: arguments(definition),
            help: processed_help(entry),
        }
    }

    /// Whether the command defines options beyond the application's.
    pub fn has_own_options(&self) -> bool {
        self.options
            .iter()
            .any(|option| !APPLICATION_OPTIONS.contains(&option.name.as_str()))
    }

    /// `getSynopsis(true)`: `name [options] [--] <a> [<b>]`, computed from
    /// the command's own definition. Optional arguments nest.
    pub fn short_synopsis(&self) -> String {
        let mut elements = vec![self.entry.name.clone()];
        if self.has_own_options() {
            elements.push("[options]".into());
            if !self.arguments.is_empty() {
                elements.push("[--]".into());
            }
        }
        let mut tail = String::new();
        for argument in &self.arguments {
            let mut element = format!("<{}>", argument.name);
            if argument.array {
                element.push_str("...");
            }
            if !argument.required {
                element = format!("[{element}");
                tail.push(']');
            }
            elements.push(element);
        }
        elements.join(" ") + &tail
    }

    /// `getSynopsis()`, the long form the catalog carries.
    pub fn synopsis(&self) -> &str {
        &self.entry.synopsis
    }

    /// Aliases, and custom usages if any, after the synopsis.
    pub fn aliases(&self) -> &[String] {
        &self.entry.aliases
    }
}

/// A namespace and the command names (aliases included) it lists.
pub struct NamespaceView {
    pub id: String,
    pub names: Vec<String>,
}

/// Symfony's `ApplicationDescription`: commands grouped by first segment,
/// global commands first, everything sorted.
pub struct ApplicationView {
    pub commands: Vec<&'static Entry>,
    pub namespaces: Vec<NamespaceView>,
}

fn namespace_key(name: &str) -> String {
    match name.split_once(':') {
        Some((first, _)) => first.to_string(),
        None => "_global".to_string(),
    }
}

impl ApplicationView {
    /// `namespace` limits the view to that (already resolved) namespace.
    pub fn new(namespace: Option<&str>, show_hidden: bool) -> Self {
        let mut names: Vec<(String, &'static Entry)> = catalog::all()
            .iter()
            .flat_map(|entry| {
                std::iter::once(entry.name.clone())
                    .chain(entry.aliases.iter().cloned())
                    .map(move |name| (name, entry))
            })
            .filter(|(name, _)| match namespace {
                Some(namespace) => {
                    let depth = namespace.matches(':').count() + 1;
                    let parts: Vec<&str> = name.split(':').collect();
                    parts.len() > depth && parts[..depth].join(":") == namespace
                }
                None => true,
            })
            .collect();
        names.sort_by(|(a, _), (b, _)| {
            let (ka, kb) = (namespace_key(a), namespace_key(b));
            let rank = |key: &str| u8::from(key != "_global");
            rank(&ka)
                .cmp(&rank(&kb))
                .then_with(|| ka.cmp(&kb))
                .then_with(|| a.cmp(b))
        });
        let mut namespaces: Vec<NamespaceView> = Vec::new();
        let mut commands = Vec::new();
        for (name, entry) in names {
            let key = namespace_key(&name);
            if namespaces.last().is_none_or(|current| current.id != key) {
                // Symfony keeps the namespace even when all its commands are hidden.
                namespaces.push(NamespaceView {
                    id: key,
                    names: vec![],
                });
            }
            if entry.hidden && !show_hidden {
                continue;
            }
            if name == entry.name {
                commands.push(entry);
            }
            if let Some(current) = namespaces.last_mut() {
                current.names.push(name);
            }
        }
        Self {
            commands,
            namespaces,
        }
    }

    pub fn command(&self, name: &str) -> Option<&'static Entry> {
        catalog::entry(name)
    }
}

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Format {
    Txt,
    Json,
    Md,
    Xml,
}

impl Format {
    pub fn parse(value: &str) -> Option<Self> {
        match value {
            "txt" => Some(Format::Txt),
            "json" => Some(Format::Json),
            "md" => Some(Format::Md),
            "xml" => Some(Format::Xml),
            _ => None,
        }
    }
}

/// Symfony's error for an unsupported `--format`.
pub fn unsupported_format(format: &str) -> String {
    format!("Unsupported format \"{format}\".")
}

/// A command description, styles in place for the formatter.
pub fn describe_command(entry: &Entry, format: Format, raw: bool) -> String {
    let view = CommandView::new(entry);
    match format {
        Format::Txt => txt::command(&view, raw),
        Format::Json => json::command(&view),
        Format::Md => markdown::command(&view),
        Format::Xml => xml::command(&view),
    }
}

/// The listing header, which 2.x prints before failing on an unknown
/// `list` namespace.
pub fn application_header() -> String {
    txt::application_header()
}

/// The application description (`dw list`).
pub fn describe_application(
    namespace: Option<&str>,
    format: Format,
    raw: bool,
    short: bool,
) -> String {
    match format {
        Format::Txt => txt::application(namespace, raw),
        Format::Json => json::application(namespace, short),
        Format::Md => markdown::application(namespace, short),
        Format::Xml => xml::application(namespace, short),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn optional_arguments_nest_in_the_short_synopsis() {
        let entry = catalog::entry("help").unwrap();
        assert_eq!(
            CommandView::new(entry).short_synopsis(),
            "help [options] [--] [<command_name>]"
        );
    }

    #[test]
    fn application_view_groups_aliases_by_their_own_namespace() {
        let view = ApplicationView::new(None, false);
        assert_eq!(view.namespaces[0].id, "_global");
        assert!(view
            .namespaces
            .iter()
            .any(|ns| ns.id == "activities"
                && ns.names == ["activities:describe", "activities:list"]));
        assert!(!view.commands.iter().any(|entry| entry.hidden));
        let activity = ApplicationView::new(Some("activity"), false);
        assert_eq!(activity.namespaces.len(), 1);
    }

    #[test]
    fn completion_help_follows_the_shell() {
        let text = completion_help("/opt/dw", "dw");
        assert!(text.contains("<info>/opt/dw completion "));
        assert!(text.contains("currently, bash, fish, zsh completion are supported"));
    }
}
