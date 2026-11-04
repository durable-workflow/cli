//! Shell completion, as 2.x provides it through Symfony Console:
//! `completion` dumps the bash, zsh or fish script, and the hidden
//! `_complete` answers the script's requests. A port of Symfony 7.4's
//! `DumpCompletionCommand`, `CompleteCommand`, `CompletionInput` and the
//! shell outputs.
//!
//! 2.x has no input-dependent completers: every suggested value is a fixed
//! list, recorded from the 2.x CLI in `completion.json`
//! (`parity/update-completion.sh`).

use crate::catalog;
use crate::cli::Input;
use crate::descriptor::{CommandView, OptionView, APPLICATION_OPTIONS};
use crate::error::{exit, CliError};
use crate::formatter::{write_out, writeln_err, writeln_out};
use serde_json::Value;
use std::collections::{HashMap, VecDeque};
use std::sync::OnceLock;

const COMPLETION_API_VERSION: &str = "1";
const SHELLS: [&str; 3] = ["bash", "fish", "zsh"];

fn script(shell: &str) -> Option<&'static str> {
    match shell {
        "bash" => Some(include_str!("../completion/completion.bash")),
        "fish" => Some(include_str!("../completion/completion.fish")),
        "zsh" => Some(include_str!("../completion/completion.zsh")),
        _ => None,
    }
}

fn tables() -> &'static Value {
    static TABLES: OnceLock<Value> = OnceLock::new();
    TABLES.get_or_init(|| {
        serde_json::from_str(include_str!("../completion.json")).expect("completion.json is valid")
    })
}

/// `basename($_SERVER['argv'][0])`.
fn command_name() -> String {
    let argv0 = std::env::args_os()
        .next()
        .map(|arg| arg.to_string_lossy().into_owned())
        .unwrap_or_default();
    basename(&argv0)
}

/// PHP `basename()` for `/`-separated paths (and `\` on Windows).
fn basename(path: &str) -> String {
    let trimmed = path.trim_end_matches(|c| c == '/' || (cfg!(windows) && c == '\\'));
    trimmed
        .rsplit(|c| c == '/' || (cfg!(windows) && c == '\\'))
        .next()
        .unwrap_or_default()
        .to_string()
}

fn log_path() -> std::path::PathBuf {
    std::env::temp_dir().join(format!("sf_{}.log", command_name()))
}

// ---------------------------------------------------------------------------
// `completion`

pub fn completion(input: &Input) -> Result<i32, CliError> {
    if input.flag("debug") {
        let path = log_path();
        if !path.exists() {
            let _ = std::fs::write(&path, "");
        }
        let _ = std::process::Command::new("tail")
            .arg("-f")
            .arg(&path)
            .status();
        return Ok(exit::SUCCESS);
    }
    let shell = match input.arg("shell") {
        Some(shell) => shell.to_string(),
        None => basename(&std::env::var("SHELL").unwrap_or_default()),
    };
    let Some(text) = script(&shell) else {
        let supported = SHELLS.join("\", \"");
        if shell.is_empty() {
            writeln_err(&format!(
                "<error>Shell not detected, Symfony shell completion only supports \"{supported}\").</>"
            ));
        } else {
            writeln_err(&format!(
                "<error>Detected shell \"{shell}\", which is not supported by Symfony shell completion (supported shells: \"{supported}\").</>"
            ));
        }
        return Ok(exit::INVALID);
    };
    write_out(
        &text
            .replace("{{ COMMAND_NAME }}", &command_name())
            .replace("{{ VERSION }}", COMPLETION_API_VERSION),
    );
    Ok(exit::SUCCESS)
}

// ---------------------------------------------------------------------------
// Input definitions and Symfony's ArgvInput parsing, errors suppressed.

#[derive(Clone)]
struct Opt {
    name: String,
    shortcuts: Vec<String>,
    accept_value: bool,
    value_required: bool,
    array: bool,
    negatable: bool,
    description: String,
}

#[derive(Clone)]
struct Arg {
    name: String,
    array: bool,
}

#[derive(Clone)]
struct Definition {
    args: Vec<Arg>,
    opts: Vec<Opt>,
}

impl Definition {
    fn option(&self, name: &str) -> Option<&Opt> {
        self.opts.iter().find(|opt| opt.name == name)
    }

    fn negation(&self, name: &str) -> Option<&Opt> {
        let base = name.strip_prefix("no-")?;
        self.opts
            .iter()
            .find(|opt| opt.negatable && opt.name == base)
    }

    fn shortcut(&self, shortcut: &str) -> Option<&Opt> {
        self.opts
            .iter()
            .find(|opt| opt.shortcuts.iter().any(|candidate| candidate == shortcut))
    }
}

fn option_from(view: &OptionView) -> Opt {
    Opt {
        name: view.name.clone(),
        shortcuts: view
            .shortcut
            .split('|')
            .filter(|part| !part.is_empty())
            .map(str::to_string)
            .collect(),
        accept_value: view.accept_value,
        value_required: view.value_required,
        array: view.multiple,
        negatable: view.negatable,
        description: view.description.clone(),
    }
}

/// The application definition: the `command` argument and the global
/// options, in Symfony's order.
fn application_definition() -> Definition {
    let list = catalog::entry("list").expect("list is in the catalog");
    let view = CommandView::new(list);
    let opts = view
        .options
        .iter()
        .filter(|option| APPLICATION_OPTIONS.contains(&option.name.as_str()))
        .map(option_from)
        .collect();
    Definition {
        args: vec![Arg {
            name: "command".into(),
            array: false,
        }],
        opts,
    }
}

/// A command's definition merged with the application's: the `command`
/// argument first, the command's options, then the global ones.
fn command_definition(entry: &catalog::Entry) -> Definition {
    let view = CommandView::new(entry);
    let mut args = vec![Arg {
        name: "command".into(),
        array: false,
    }];
    args.extend(view.arguments.iter().map(|argument| Arg {
        name: argument.name.clone(),
        array: argument.array,
    }));
    Definition {
        args,
        opts: view.options.iter().map(option_from).collect(),
    }
}

#[derive(Clone, Debug, PartialEq)]
enum OptValue {
    Str(String),
    Bool(bool),
    Null,
    List(Vec<Option<String>>),
}

#[derive(Default)]
struct Parsed {
    /// Bound arguments in binding order, with their values.
    arguments: Vec<(String, Vec<String>, bool)>,
    options: HashMap<String, OptValue>,
}

impl Parsed {
    fn argument(&self, name: &str) -> Option<&(String, Vec<String>, bool)> {
        self.arguments.iter().find(|(bound, ..)| bound == name)
    }
}

struct Parser<'a> {
    definition: &'a Definition,
    parsed: VecDeque<String>,
    result: Parsed,
}

impl Parser<'_> {
    fn run(definition: &Definition, tokens: &[String]) -> Parsed {
        let mut parser = Parser {
            definition,
            parsed: tokens.iter().cloned().collect(),
            result: Parsed::default(),
        };
        let mut parse_options = true;
        while let Some(token) = parser.parsed.pop_front() {
            // CompletionInput::parseToken suppresses every parse error.
            let outcome = if parse_options && token.is_empty() {
                parser.argument(&token)
            } else if parse_options && token == "--" {
                parse_options = false;
                Ok(())
            } else if parse_options && token.starts_with("--") {
                parser.long_token(&token)
            } else if parse_options && token.starts_with('-') && token != "-" {
                parser.short_token(&token)
            } else {
                parser.argument(&token)
            };
            let _ = outcome;
        }
        parser.result
    }

    fn argument(&mut self, token: &str) -> Result<(), ()> {
        let count = self.result.arguments.len();
        if let Some(argument) = self.definition.args.get(count) {
            self.result.arguments.push((
                argument.name.clone(),
                vec![token.to_string()],
                argument.array,
            ));
            return Ok(());
        }
        if count > 0 {
            if let Some(argument) = self
                .definition
                .args
                .get(count - 1)
                .filter(|argument| argument.array)
            {
                if let Some(bound) = self
                    .result
                    .arguments
                    .iter_mut()
                    .find(|(name, ..)| *name == argument.name)
                {
                    bound.1.push(token.to_string());
                    return Ok(());
                }
            }
        }
        Err(())
    }

    fn long_token(&mut self, token: &str) -> Result<(), ()> {
        let name = &token[2..];
        match name.split_once('=') {
            Some((name, value)) => {
                if value.is_empty() {
                    self.parsed.push_front(String::new());
                }
                self.long_option(name, Some(value.to_string()))
            }
            None => self.long_option(name, None),
        }
    }

    fn short_token(&mut self, token: &str) -> Result<(), ()> {
        let name: Vec<char> = token[1..].chars().collect();
        if name.len() > 1 {
            let first = name[0].to_string();
            if self
                .definition
                .shortcut(&first)
                .is_some_and(|option| option.accept_value)
            {
                return self.short_option(&first, Some(name[1..].iter().collect()));
            }
            return self.short_option_set(&name);
        }
        self.short_option(&name.iter().collect::<String>(), None)
    }

    fn short_option_set(&mut self, name: &[char]) -> Result<(), ()> {
        for (index, character) in name.iter().enumerate() {
            let option = self
                .definition
                .shortcut(&character.to_string())
                .cloned()
                .ok_or(())?;
            if option.accept_value {
                let rest = (index + 1 < name.len()).then(|| name[index + 1..].iter().collect());
                return self.long_option(&option.name, rest);
            }
            self.long_option(&option.name, None)?;
        }
        Ok(())
    }

    fn short_option(&mut self, shortcut: &str, value: Option<String>) -> Result<(), ()> {
        let option = self.definition.shortcut(shortcut).cloned().ok_or(())?;
        self.long_option(&option.name, value)
    }

    fn long_option(&mut self, name: &str, mut value: Option<String>) -> Result<(), ()> {
        let Some(option) = self.definition.option(name).cloned() else {
            let negated = self.definition.negation(name).cloned().ok_or(())?;
            if value.is_some() {
                return Err(());
            }
            self.result
                .options
                .insert(negated.name, OptValue::Bool(false));
            return Ok(());
        };
        if value.is_some() && !option.accept_value {
            return Err(());
        }
        if value.as_deref().is_none_or(str::is_empty) && option.accept_value {
            if let Some(next) = self.parsed.pop_front() {
                if next.is_empty() || !next.starts_with('-') {
                    value = Some(next);
                } else {
                    self.parsed.push_front(next);
                }
            }
        }
        let value = match value {
            Some(value) => OptValue::Str(value),
            None if option.value_required => return Err(()),
            None if !option.array && !option.accept_value => OptValue::Bool(true),
            None => OptValue::Null,
        };
        if option.array {
            let entry = self
                .result
                .options
                .entry(option.name)
                .or_insert_with(|| OptValue::List(vec![]));
            if let OptValue::List(items) = entry {
                items.push(match value {
                    OptValue::Str(text) => Some(text),
                    _ => None,
                });
            }
        } else {
            self.result.options.insert(option.name, value);
        }
        Ok(())
    }
}

// ---------------------------------------------------------------------------
// CompletionInput

#[derive(Debug, PartialEq)]
enum CompletionType {
    ArgumentValue,
    OptionValue,
    OptionName,
    None,
}

struct CompletionInput {
    /// All tokens, the program name included.
    tokens: Vec<String>,
    current: usize,
    parsed: Parsed,
    completion_type: CompletionType,
    completion_name: Option<String>,
    completion_value: String,
}

/// `$token[0]` for a possibly empty PHP string.
fn first_char(token: Option<&String>) -> Option<char> {
    token.and_then(|token| token.chars().next())
}

impl CompletionInput {
    fn new(tokens: Vec<String>, current: usize) -> Self {
        Self {
            tokens,
            current,
            parsed: Parsed::default(),
            completion_type: CompletionType::None,
            completion_name: None,
            completion_value: String::new(),
        }
    }

    fn cursor_free(&self) -> Result<bool, String> {
        if self.current > self.tokens.len() {
            return Err(
                "Current index is invalid, it must be the number of input tokens or one more."
                    .into(),
            );
        }
        Ok(self.current >= self.tokens.len())
    }

    fn option_from_token<'a>(definition: &'a Definition, token: &str) -> Option<&'a Opt> {
        let name = token.trim_start_matches('-');
        if name.is_empty() {
            return None;
        }
        if token.chars().nth(1) == Some('-') {
            return definition.option(name);
        }
        definition.shortcut(&name.chars().next()?.to_string())
    }

    fn bind(&mut self, definition: &Definition) -> Result<(), String> {
        self.parsed = Parser::run(definition, self.tokens.get(1..).unwrap_or_default());
        self.completion_name = None;
        self.completion_value = String::new();
        let free = self.cursor_free()?;
        let relevant = if free {
            self.current
                .checked_sub(1)
                .and_then(|index| self.tokens.get(index))
        } else {
            self.tokens.get(self.current)
        };
        let relevant = relevant.cloned();

        if first_char(relevant.as_ref()) == Some('-') {
            let token = relevant.clone().unwrap_or_default();
            let (option_token, option_value) = token
                .split_once('=')
                .map_or((token.as_str(), ""), |(name, value)| (name, value));
            let option = Self::option_from_token(definition, option_token);
            if option.is_none() && !free {
                self.completion_type = CompletionType::OptionName;
                self.completion_value = token.clone();
                return Ok(());
            }
            if let Some(option) = option.filter(|option| option.accept_value) {
                self.completion_type = CompletionType::OptionValue;
                self.completion_name = Some(option.name.clone());
                self.completion_value = if !option_value.is_empty() {
                    option_value.to_string()
                } else if !option_token.starts_with("--") {
                    option_token.get(2..).unwrap_or_default().to_string()
                } else {
                    String::new()
                };
                return Ok(());
            }
        }

        let previous = self
            .current
            .checked_sub(1)
            .and_then(|index| self.tokens.get(index))
            .cloned();
        if let Some(previous) =
            previous.filter(|token| token.starts_with('-') && !token.trim_matches('-').is_empty())
        {
            if let Some(option) =
                Self::option_from_token(definition, &previous).filter(|option| option.accept_value)
            {
                self.completion_type = CompletionType::OptionValue;
                self.completion_name = Some(option.name.clone());
                self.completion_value = relevant.unwrap_or_default();
                return Ok(());
            }
        }

        self.completion_type = CompletionType::ArgumentValue;
        let mut last_name = None;
        for argument in &definition.args {
            last_name = Some(argument.name.clone());
            let Some((_, values, array)) = self.parsed.argument(&argument.name) else {
                break;
            };
            self.completion_name = Some(argument.name.clone());
            self.completion_value = if *array {
                values.last().cloned().unwrap_or_default()
            } else {
                values[0].clone()
            };
        }
        if self.current >= self.tokens.len() {
            let name = last_name.unwrap_or_default();
            let bound = self.parsed.argument(&name).is_some();
            let array = definition
                .args
                .iter()
                .any(|argument| argument.name == name && argument.array);
            if !bound || array {
                self.completion_name = Some(name);
            } else {
                self.completion_type = CompletionType::None;
                self.completion_name = None;
            }
            self.completion_value = String::new();
        }
        Ok(())
    }

    /// `ArgvInput::getFirstArgument()`, over the tokens after the program.
    fn first_argument(&self, definition: &Definition) -> Option<String> {
        let tokens = self.tokens.get(1..).unwrap_or_default();
        let mut is_option = false;
        for (index, token) in tokens.iter().enumerate() {
            if token.starts_with('-') {
                if token.contains('=') || index + 1 >= tokens.len() {
                    continue;
                }
                let name = if token.chars().nth(1) == Some('-') {
                    token[2..].to_string()
                } else {
                    token.chars().last().map(String::from).unwrap_or_default()
                };
                let stored = self.parsed.options.get(&name).or_else(|| {
                    definition
                        .shortcut(&name)
                        .and_then(|option| self.parsed.options.get(&option.name))
                });
                if stored == Some(&OptValue::Str(tokens[index + 1].clone())) {
                    is_option = true;
                }
                continue;
            }
            if is_option {
                is_option = false;
                continue;
            }
            return Some(token.clone());
        }
        None
    }
}

// ---------------------------------------------------------------------------
// `_complete`

enum Suggestion<'a> {
    Value { value: String, description: String },
    Option(&'a Opt),
}

fn suggested_values(command: &str, kind: &str, name: &str) -> Vec<String> {
    tables()["values"][command][kind][name]
        .as_array()
        .map(|items| {
            items
                .iter()
                .filter_map(Value::as_str)
                .map(str::to_string)
                .collect()
        })
        .unwrap_or_default()
}

fn render(shell: &str, suggestions: &[Suggestion]) {
    let mut lines = Vec::new();
    for suggestion in suggestions {
        match suggestion {
            Suggestion::Value { value, description } => {
                if shell == "bash" || description.is_empty() {
                    lines.push(value.clone());
                } else {
                    lines.push(format!("{value}\t{description}"));
                }
            }
            Suggestion::Option(_) => {}
        }
    }
    for suggestion in suggestions {
        if let Suggestion::Option(option) = suggestion {
            let described = |name: String| {
                if shell == "bash" || option.description.is_empty() {
                    name
                } else {
                    format!("{name}\t{}", option.description)
                }
            };
            lines.push(described(format!("--{}", option.name)));
            if option.negatable {
                lines.push(described(format!("--no-{}", option.name)));
            }
        }
    }
    let text = lines.join("\n");
    match shell {
        "bash" => writeln_out(&text),
        "zsh" => write_out(&format!("{text}\n")),
        _ => write_out(&text),
    }
}

/// `FILTER_VALIDATE_BOOL` truthiness of `SYMFONY_COMPLETION_DEBUG`.
fn debugging() -> bool {
    std::env::var("SYMFONY_COMPLETION_DEBUG")
        .map(|value| {
            matches!(
                value.trim().to_ascii_lowercase().as_str(),
                "1" | "true" | "on" | "yes"
            )
        })
        .unwrap_or(false)
}

fn log(lines: &[String]) {
    if !debugging() {
        return;
    }
    use std::io::Write;
    if let Ok(mut file) = std::fs::OpenOptions::new()
        .create(true)
        .append(true)
        .open(log_path())
    {
        let _ = file.write_all((lines.join("\n") + "\n").as_bytes());
    }
}

pub fn complete(input: &Input) -> Result<i32, CliError> {
    match run_complete(input) {
        Ok(code) => Ok(code),
        Err(message) => {
            log(&["<error>Error!</error>".into(), message]);
            Ok(exit::INVALID)
        }
    }
}

fn run_complete(input: &Input) -> Result<i32, String> {
    // PHP truthiness: an empty string and "0" are false.
    let truthy = |name: &str| input.opt_str(name).filter(|value| *value != "0");
    let version = if truthy("symfony").is_some() {
        Some("1")
    } else {
        truthy("api-version")
    };
    if let Some(version) = version {
        if crate::compat::version::php_version_compare(version, COMPLETION_API_VERSION) < 0 {
            let message = format!("Completion script version is not supported (\"{version}\" given, \">={COMPLETION_API_VERSION}\" required).");
            log(&[message.clone()]);
            writeln_out(&format!("{message} Install the Symfony completion script again by using the \"completion\" command."));
            return Ok(126);
        }
    }
    let shell = input
        .opt_str("shell")
        .ok_or("The \"--shell\" option must be set.")?;
    if !SHELLS.contains(&shell) {
        return Err(format!("Shell completion is not supported for your shell: \"{shell}\" (supported: \"bash\", \"fish\", \"zsh\")."));
    }
    let current = input
        .opt_str("current")
        .filter(|value| value.bytes().all(|b| b.is_ascii_digit()) && *value != "0");
    let current: usize = current
        .and_then(|value| value.parse().ok())
        .ok_or("The \"--current\" option must be set and it must be an integer.")?;
    let tokens: Vec<String> = input
        .opt_all("input")
        .into_iter()
        .map(str::to_string)
        .collect();

    let mut completion = CompletionInput::new(tokens, current);
    let application = application_definition();
    completion.bind(&application)?;
    let command = completion
        .first_argument(&application)
        .and_then(|name| crate::lookup::find(&name, 80).ok())
        .and_then(|name| catalog::entry(&name));

    let mut suggestions: Vec<Suggestion> = Vec::new();
    let definition;
    match command {
        None => {
            if completion.completion_type == CompletionType::ArgumentValue
                && completion.completion_name.as_deref() == Some("command")
            {
                for item in tables()["application"].as_array().into_iter().flatten() {
                    suggestions.push(Suggestion::Value {
                        value: item["value"].as_str().unwrap_or_default().to_string(),
                        description: item["description"].as_str().unwrap_or_default().to_string(),
                    });
                }
            } else if completion.completion_type == CompletionType::OptionName {
                definition = application;
                suggestions.extend(definition.opts.iter().map(Suggestion::Option));
            }
        }
        Some(entry) => {
            definition = command_definition(entry);
            completion.bind(&definition)?;
            if completion.completion_type == CompletionType::ArgumentValue
                && completion.completion_name.as_deref() == Some("command")
            {
                let mut names: Vec<String> = std::iter::once(entry.name.clone())
                    .chain(entry.aliases.iter().cloned())
                    .filter(|name| !name.is_empty())
                    .collect();
                if let Some(name) = names
                    .iter()
                    .find(|name| name.starts_with(&completion.completion_value))
                    .cloned()
                {
                    names = vec![name];
                }
                suggestions.extend(names.into_iter().map(|value| Suggestion::Value {
                    value,
                    description: String::new(),
                }));
            } else if completion.completion_type == CompletionType::OptionName {
                suggestions.extend(definition.opts.iter().map(Suggestion::Option));
            } else {
                let name = completion.completion_name.clone().unwrap_or_default();
                let values = match completion.completion_type {
                    CompletionType::OptionValue if definition.option(&name).is_some() => {
                        suggested_values(&entry.name, "options", &name)
                    }
                    CompletionType::ArgumentValue
                        if definition.args.iter().any(|argument| argument.name == name) =>
                    {
                        suggested_values(&entry.name, "arguments", &name)
                    }
                    _ => vec![],
                };
                suggestions.extend(values.into_iter().map(|value| Suggestion::Value {
                    value,
                    description: String::new(),
                }));
            }
        }
    }
    render(shell, &suggestions);
    Ok(exit::SUCCESS)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn bound(tokens: &[&str], current: usize, definition: &Definition) -> CompletionInput {
        let mut input = CompletionInput::new(
            tokens.iter().map(|token| token.to_string()).collect(),
            current,
        );
        input.bind(definition).unwrap();
        input
    }

    #[test]
    fn completion_types_follow_symfony() {
        let upgrade = command_definition(catalog::entry("upgrade").unwrap());
        let value = bound(&["dw", "upgrade", "--output="], 2, &upgrade);
        assert_eq!(
            (value.completion_type, value.completion_name.as_deref()),
            (CompletionType::OptionValue, Some("output"))
        );
        let separate = bound(&["dw", "upgrade", "--output"], 3, &upgrade);
        assert_eq!(separate.completion_type, CompletionType::OptionValue);
        let names = bound(&["dw", "upgrade", "--ou"], 2, &upgrade);
        assert_eq!(
            (names.completion_type, names.completion_value.as_str()),
            (CompletionType::OptionName, "--ou")
        );
        let done = bound(&["dw", "upgrade"], 2, &upgrade);
        assert_eq!(done.completion_type, CompletionType::None);
        let first = bound(&["dw"], 1, &application_definition());
        assert_eq!(
            (first.completion_type, first.completion_name.as_deref()),
            (CompletionType::ArgumentValue, Some("command"))
        );
    }

    #[test]
    fn the_first_argument_skips_option_values() {
        let application = application_definition();
        let input = bound(&["dw", "-v", "upgrade", "--tag", "x"], 5, &application);
        assert_eq!(
            input.first_argument(&application).as_deref(),
            Some("upgrade")
        );
        assert_eq!(
            bound(&["dw", "--ansi"], 2, &application).first_argument(&application),
            None
        );
    }

    #[test]
    fn an_invalid_cursor_is_refused() {
        let mut input = CompletionInput::new(vec!["dw".into()], 3);
        assert!(input.bind(&application_definition()).is_err());
    }

    #[test]
    fn scripts_are_symfony_74() {
        for shell in SHELLS {
            let text = script(shell).unwrap();
            assert!(
                text.contains("{{ COMMAND_NAME }}") && text.contains("{{ VERSION }}"),
                "{shell}"
            );
        }
        assert_eq!(basename("/usr/local/bin/dw"), "dw");
        assert_eq!(basename("/bin/zsh/"), "zsh");
        assert_eq!(basename(""), "");
    }
}
