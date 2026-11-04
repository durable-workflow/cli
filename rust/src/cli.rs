//! Command-line parsing that follows the 2.x CLI (Symfony Console) rules, so
//! existing scripts keep working: options may appear before or after
//! arguments, `--name=value` and `--name value` are equivalent, `--` ends
//! option parsing, and an option that takes an optional value consumes the
//! next token only when it does not start with `-`. Command definitions come
//! from the 2.x catalog (see `catalog`).

use std::collections::{HashMap, HashSet};

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum ValueMode {
    /// A flag such as `--json`.
    None,
    /// `--env prod` or `--env=prod`; a bare `--env` is a usage error.
    Required,
    /// `--server`, `--server=x` or `--server x`.
    Optional,
}

#[derive(Clone, Debug)]
pub struct OptSpec {
    pub long: String,
    /// Single-character shortcuts, such as `s` for `-s`.
    pub shorts: Vec<char>,
    pub mode: ValueMode,
    pub multiple: bool,
    pub default: Option<String>,
}

#[derive(Clone, Debug)]
pub struct ArgSpec {
    pub name: String,
    pub required: bool,
    pub array: bool,
}

#[derive(Clone, Debug)]
pub struct CommandSpec {
    pub name: String,
    pub synopsis: String,
    pub args: Vec<ArgSpec>,
    pub opts: Vec<OptSpec>,
}

/// A usage error reported the way the 2.x CLI reports Symfony console
/// errors: a message block and the command synopsis on stderr, exit 1.
#[derive(Debug)]
pub struct UsageError {
    pub message: String,
    pub synopsis: Option<String>,
}

#[derive(Debug, Default)]
pub struct Input {
    pub command: String,
    args: HashMap<String, Vec<String>>,
    opts: HashMap<String, Vec<Option<String>>>,
    flags: HashSet<String>,
    defaults: HashMap<String, String>,
}

impl Input {
    pub fn arg(&self, name: &str) -> Option<&str> {
        self.args
            .get(name)
            .and_then(|values| values.first())
            .map(String::as_str)
    }

    pub fn flag(&self, name: &str) -> bool {
        self.flags.contains(name)
    }

    /// True when the caller wrote the option, with or without a value.
    pub fn provided(&self, name: &str) -> bool {
        self.opts.contains_key(name) || self.flags.contains(name)
    }

    /// The last written value of an option, or its default when the caller
    /// did not write it. `Some(None)` means it was written without a value.
    pub fn opt(&self, name: &str) -> Option<Option<&str>> {
        match self.opts.get(name).and_then(|values| values.last()) {
            Some(value) => Some(value.as_deref()),
            None => self.defaults.get(name).map(|value| Some(value.as_str())),
        }
    }

    /// The non-empty string value of an option, if any.
    pub fn opt_str(&self, name: &str) -> Option<&str> {
        self.opt(name).flatten().filter(|value| !value.is_empty())
    }
}

/// Splits raw tokens into the command name and the remaining tokens, using
/// the same rule as Symfony: the first token that is neither an option nor
/// the value of a known separate-value global option.
pub fn first_argument(tokens: &[String]) -> Option<(usize, &str)> {
    let mut index = 0;
    while index < tokens.len() {
        let token = tokens[index].as_str();
        if token == "--" {
            return tokens.get(index + 1).map(|name| (index + 1, name.as_str()));
        }
        if !token.starts_with('-') || token == "-" {
            return Some((index, token));
        }
        index += 1;
    }
    None
}

/// Parses until the first usage error and keeps what was bound before it,
/// as Symfony does for commands that ignore validation errors (`help`).
/// Required arguments are not enforced.
pub fn parse_lenient(spec: &CommandSpec, tokens: &[String]) -> Input {
    let mut lenient = spec.clone();
    lenient.args.iter_mut().for_each(|arg| arg.required = false);
    let mut bound: Vec<String> = Vec::new();
    for token in tokens {
        bound.push(token.clone());
        match parse(&lenient, &bound) {
            Ok(_) => {}
            // The next token may still supply the value.
            Err(error) if error.message.ends_with("option requires a value.") => {}
            Err(_) => {
                bound.pop();
                break;
            }
        }
    }
    loop {
        match parse(&lenient, &bound) {
            Ok(input) => return input,
            Err(_) => {
                bound.pop();
            }
        }
    }
}

pub fn parse(spec: &CommandSpec, tokens: &[String]) -> Result<Input, UsageError> {
    let usage = |message: String| UsageError {
        message,
        synopsis: Some(spec.synopsis.clone()),
    };

    let mut input = Input {
        command: spec.name.clone(),
        ..Input::default()
    };
    for opt in &spec.opts {
        if let Some(default) = &opt.default {
            input.defaults.insert(opt.long.clone(), default.clone());
        }
    }

    let mut positional = Vec::new();
    let mut only_args = false;
    let mut index = 0;
    while index < tokens.len() {
        let token = &tokens[index];
        index += 1;

        if only_args || token == "-" || !token.starts_with('-') {
            positional.push(token.clone());
            continue;
        }
        if token == "--" {
            only_args = true;
            continue;
        }
        if let Some(long) = token.strip_prefix("--") {
            parse_long(spec, &mut input, long, tokens, &mut index).map_err(usage)?;
        } else {
            parse_short(spec, &mut input, &token[1..], tokens, &mut index).map_err(usage)?;
        }
    }

    bind_arguments(spec, &mut input, positional).map_err(usage)?;

    // Help is answered before argument validation, as in Symfony.
    if input.flag("help") {
        return Ok(input);
    }

    let missing: Vec<&str> = spec
        .args
        .iter()
        .filter(|arg| arg.required && !input.args.contains_key(&arg.name))
        .map(|arg| arg.name.as_str())
        .collect();
    if !missing.is_empty() {
        return Err(usage(format!(
            "Not enough arguments (missing: \"{}\").",
            missing.join(", ")
        )));
    }
    Ok(input)
}

fn parse_long(
    spec: &CommandSpec,
    input: &mut Input,
    long: &str,
    tokens: &[String],
    index: &mut usize,
) -> Result<(), String> {
    let (name, inline) = match long.split_once('=') {
        Some((name, value)) => (name, Some(value.to_string())),
        None => (long, None),
    };
    let opt = spec
        .opts
        .iter()
        .find(|opt| opt.long == name)
        .ok_or_else(|| format!("The \"--{name}\" option does not exist."))?;
    set_option(input, opt, inline, tokens, index)
}

/// Short options may be bundled (`-qn`) or carry a value (`-shttp://x`).
fn parse_short(
    spec: &CommandSpec,
    input: &mut Input,
    shorts: &str,
    tokens: &[String],
    index: &mut usize,
) -> Result<(), String> {
    let chars: Vec<char> = shorts.chars().collect();
    let mut position = 0;
    while position < chars.len() {
        let short = chars[position];
        let opt = spec
            .opts
            .iter()
            .find(|opt| opt.shorts.contains(&short))
            .ok_or_else(|| format!("The \"-{short}\" option does not exist."))?;
        if opt.mode != ValueMode::None {
            let rest: String = chars[position + 1..].iter().collect();
            let inline = (!rest.is_empty()).then_some(rest);
            return set_option(input, opt, inline, tokens, index);
        }
        input.flags.insert(opt.long.clone());
        position += 1;
    }
    Ok(())
}

fn set_option(
    input: &mut Input,
    opt: &OptSpec,
    inline: Option<String>,
    tokens: &[String],
    index: &mut usize,
) -> Result<(), String> {
    if opt.mode == ValueMode::None {
        if inline.is_some() {
            return Err(format!(
                "The \"--{}\" option does not accept a value.",
                opt.long
            ));
        }
        input.flags.insert(opt.long.clone());
        return Ok(());
    }

    let mut value = inline;
    if value.is_none() {
        if let Some(next) = tokens.get(*index) {
            if !next.is_empty() && !next.starts_with('-') {
                value = Some(next.clone());
                *index += 1;
            }
        }
    }
    if value.is_none() && opt.mode == ValueMode::Required {
        return Err(format!("The \"--{}\" option requires a value.", opt.long));
    }
    let values = input.opts.entry(opt.long.clone()).or_default();
    if !opt.multiple {
        values.clear();
    }
    values.push(value);
    Ok(())
}

fn bind_arguments(
    spec: &CommandSpec,
    input: &mut Input,
    positional: Vec<String>,
) -> Result<(), String> {
    for (position, value) in positional.into_iter().enumerate() {
        if let Some(last) = spec
            .args
            .last()
            .filter(|arg| arg.array && position >= spec.args.len() - 1)
        {
            input.args.entry(last.name.clone()).or_default().push(value);
            continue;
        }
        match spec.args.get(position) {
            Some(arg) => {
                input.args.insert(arg.name.clone(), vec![value]);
            }
            None if spec.args.is_empty() => {
                return Err(format!(
                    "No arguments expected for \"{}\" command, got \"{value}\".",
                    spec.name
                ));
            }
            None => {
                let expected: Vec<&str> = spec.args.iter().map(|arg| arg.name.as_str()).collect();
                return Err(format!(
                    "Too many arguments to \"{}\" command, expected arguments \"{}\".",
                    spec.name,
                    expected.join("\" \"")
                ));
            }
        }
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn spec() -> CommandSpec {
        crate::catalog::spec("env:use").unwrap()
    }

    fn health() -> CommandSpec {
        crate::catalog::spec("server:health").unwrap()
    }

    fn tokens(raw: &[&str]) -> Vec<String> {
        raw.iter().map(|token| token.to_string()).collect()
    }

    #[test]
    fn options_may_follow_or_precede_arguments() {
        let input = parse(&spec(), &tokens(&["--output", "json", "dev", "--json"])).unwrap();
        assert_eq!(input.arg("name"), Some("dev"));
        assert_eq!(input.opt_str("output"), Some("json"));
        assert!(input.flag("json"));
        let input = parse(&health(), &tokens(&["-shttp://x", "-q"])).unwrap();
        assert_eq!(input.opt_str("server"), Some("http://x"));
        assert!(input.flag("quiet"));
    }

    #[test]
    fn optional_value_does_not_consume_a_following_option() {
        let input = parse(&health(), &tokens(&["--server", "--env", "prod"])).unwrap();
        assert!(input.provided("server"));
        assert_eq!(input.opt("server"), Some(None));
        assert_eq!(input.opt_str("env"), Some("prod"));
    }

    #[test]
    fn defaults_apply_only_when_not_written() {
        let input = parse(&spec(), &tokens(&["dev"])).unwrap();
        assert_eq!(input.opt_str("output"), Some("table"));
        assert!(!input.provided("output"));
    }

    #[test]
    fn usage_errors_match_the_2x_messages() {
        let error = |raw: &[&str]| parse(&spec(), &tokens(raw)).unwrap_err().message;
        assert_eq!(
            error(&["dev", "--bogus"]),
            "The \"--bogus\" option does not exist."
        );
        assert_eq!(
            error(&["dev", "--output"]),
            "The \"--output\" option requires a value."
        );
        assert_eq!(
            error(&["dev", "--json=1"]),
            "The \"--json\" option does not accept a value."
        );
        assert_eq!(error(&[]), "Not enough arguments (missing: \"name\").");
        assert_eq!(
            error(&["a", "b"]),
            "Too many arguments to \"env:use\" command, expected arguments \"name\"."
        );
        let list = crate::catalog::spec("env:list").unwrap();
        assert_eq!(
            parse(&list, &tokens(&["extra"])).unwrap_err().message,
            "No arguments expected for \"env:list\" command, got \"extra\"."
        );
    }

    #[test]
    fn usage_errors_carry_the_2x_synopsis() {
        let error = parse(&spec(), &tokens(&[])).unwrap_err();
        assert_eq!(
            error.synopsis.as_deref(),
            Some("env:use [--output OUTPUT] [--json] [--] <name>")
        );
    }

    #[test]
    fn lenient_parsing_keeps_what_came_before_the_first_error() {
        let help = crate::catalog::spec("help").unwrap();
        let input = parse_lenient(
            &help,
            &tokens(&["env:use", "--format=json", "--bogus", "--raw"]),
        );
        assert_eq!(input.arg("command_name"), Some("env:use"));
        assert_eq!(input.opt_str("format"), Some("json"));
        assert!(!input.flag("raw"));
        let input = parse_lenient(&help, &tokens(&["--bogus", "env:use"]));
        assert_eq!(input.arg("command_name"), None);
        assert_eq!(input.opt_str("format"), Some("txt"));
    }

    #[test]
    fn double_dash_ends_options() {
        let input = parse(&spec(), &tokens(&["--", "--not-an-option"])).unwrap();
        assert_eq!(input.arg("name"), Some("--not-an-option"));
    }
}
