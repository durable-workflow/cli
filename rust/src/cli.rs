//! Command-line parsing that follows the 2.x CLI (Symfony Console) rules, so
//! existing scripts keep working: options may appear before or after
//! arguments, `--name=value` and `--name value` are equivalent, `--` ends
//! option parsing, and an option that takes an optional value consumes the
//! next token only when it does not start with `-`.

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
    pub long: &'static str,
    pub short: Option<char>,
    pub mode: ValueMode,
    pub default: Option<&'static str>,
}

impl OptSpec {
    pub const fn flag(long: &'static str) -> Self {
        Self {
            long,
            short: None,
            mode: ValueMode::None,
            default: None,
        }
    }

    pub const fn required(long: &'static str) -> Self {
        Self {
            long,
            short: None,
            mode: ValueMode::Required,
            default: None,
        }
    }

    pub const fn optional(long: &'static str) -> Self {
        Self {
            long,
            short: None,
            mode: ValueMode::Optional,
            default: None,
        }
    }

    pub const fn short(mut self, short: char) -> Self {
        self.short = Some(short);
        self
    }

    pub const fn default(mut self, value: &'static str) -> Self {
        self.default = Some(value);
        self
    }
}

#[derive(Clone, Debug)]
pub struct ArgSpec {
    pub name: &'static str,
    pub required: bool,
}

#[derive(Clone, Debug)]
pub struct CommandSpec {
    pub name: &'static str,
    pub description: &'static str,
    pub args: Vec<ArgSpec>,
    pub opts: Vec<OptSpec>,
}

/// Options every command accepts, as in the 2.x application definition.
fn application_options() -> Vec<OptSpec> {
    vec![
        OptSpec::flag("help").short('h'),
        OptSpec::flag("quiet").short('q'),
        OptSpec::flag("silent"),
        OptSpec::flag("verbose").short('v'),
        OptSpec::flag("version").short('V'),
        OptSpec::flag("ansi"),
        OptSpec::flag("no-ansi"),
        OptSpec::flag("no-interaction").short('n'),
    ]
}

/// Connection options shared by every command that talks to a Server.
pub fn connection_options() -> Vec<OptSpec> {
    vec![
        OptSpec::optional("server").short('s'),
        OptSpec::optional("namespace"),
        OptSpec::optional("token"),
        OptSpec::required("tls-verify"),
        OptSpec::required("env"),
        output_option(),
    ]
}

pub fn output_option() -> OptSpec {
    OptSpec::required("output").default("table")
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
    args: HashMap<String, String>,
    opts: HashMap<String, Option<String>>,
    flags: HashSet<String>,
    defaults: HashMap<String, String>,
}

impl Input {
    pub fn arg(&self, name: &str) -> Option<&str> {
        self.args.get(name).map(String::as_str)
    }

    pub fn flag(&self, name: &str) -> bool {
        self.flags.contains(name)
    }

    /// True when the caller wrote the option, with or without a value.
    pub fn provided(&self, name: &str) -> bool {
        self.opts.contains_key(name) || self.flags.contains(name)
    }

    /// The option value, or its default when the caller did not write it.
    /// `Some(None)` means it was written without a value.
    pub fn opt(&self, name: &str) -> Option<Option<&str>> {
        match self.opts.get(name) {
            Some(value) => Some(value.as_deref()),
            None => self.defaults.get(name).map(|value| Some(value.as_str())),
        }
    }

    /// The non-empty string value of an option, if any.
    pub fn opt_str(&self, name: &str) -> Option<&str> {
        self.opt(name).flatten().filter(|value| !value.is_empty())
    }
}

pub fn synopsis(spec: &CommandSpec) -> String {
    let mut parts = vec![spec.name.to_string()];
    for opt in &spec.opts {
        let mut name = String::new();
        if let Some(short) = opt.short {
            name.push_str(&format!("-{short}|"));
        }
        name.push_str("--");
        name.push_str(opt.long);
        let value = opt.long.to_uppercase();
        parts.push(match opt.mode {
            ValueMode::None => format!("[{name}]"),
            ValueMode::Required => format!("[{name} {value}]"),
            ValueMode::Optional => format!("[{name} [{value}]]"),
        });
    }
    for arg in &spec.args {
        parts.push(if arg.required {
            format!("<{}>", arg.name)
        } else {
            format!("[<{}>]", arg.name)
        });
    }
    parts.join(" ")
}

pub fn parse(spec: &CommandSpec, tokens: &[String]) -> Result<Input, UsageError> {
    let mut all_opts = spec.opts.clone();
    all_opts.extend(application_options());
    let usage = |message: String| UsageError {
        message,
        synopsis: Some(synopsis(spec)),
    };

    let mut input = Input {
        command: spec.name.to_string(),
        ..Input::default()
    };
    for opt in &all_opts {
        if let Some(default) = opt.default {
            input
                .defaults
                .insert(opt.long.to_string(), default.to_string());
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
            let (name, inline) = match long.split_once('=') {
                Some((name, value)) => (name, Some(value.to_string())),
                None => (long, None),
            };
            let opt = all_opts
                .iter()
                .find(|opt| opt.long == name)
                .ok_or_else(|| usage(format!("The \"--{name}\" option does not exist.")))?;
            set_option(
                &mut input,
                opt,
                inline,
                tokens,
                &mut index,
                &format!("--{name}"),
            )
            .map_err(usage)?;
            continue;
        }

        // Short options may be bundled (-qn) or carry a value (-shttp://x).
        let shorts: Vec<char> = token[1..].chars().collect();
        let mut position = 0;
        while position < shorts.len() {
            let short = shorts[position];
            let opt = all_opts
                .iter()
                .find(|opt| opt.short == Some(short))
                .ok_or_else(|| usage(format!("The \"-{short}\" option does not exist.")))?;
            if opt.mode != ValueMode::None {
                let rest: String = shorts[position + 1..].iter().collect();
                let inline = if rest.is_empty() { None } else { Some(rest) };
                set_option(
                    &mut input,
                    opt,
                    inline,
                    tokens,
                    &mut index,
                    &format!("--{}", opt.long),
                )
                .map_err(usage)?;
                break;
            }
            input.flags.insert(opt.long.to_string());
            position += 1;
        }
    }

    for (position, value) in positional.into_iter().enumerate() {
        match spec.args.get(position) {
            Some(arg) => {
                input.args.insert(arg.name.to_string(), value);
            }
            None if spec.args.is_empty() => {
                return Err(usage(format!(
                    "No arguments expected for \"{}\" command, got \"{value}\".",
                    spec.name
                )));
            }
            None => {
                let expected: Vec<String> =
                    spec.args.iter().map(|arg| arg.name.to_string()).collect();
                return Err(usage(format!(
                    "Too many arguments, expected arguments \"{}\".",
                    expected.join("\" \"")
                )));
            }
        }
    }

    // Help is answered before argument validation, as in Symfony.
    if input.flag("help") {
        return Ok(input);
    }

    let missing: Vec<&str> = spec
        .args
        .iter()
        .filter(|arg| arg.required && !input.args.contains_key(arg.name))
        .map(|arg| arg.name)
        .collect();
    if !missing.is_empty() {
        return Err(usage(format!(
            "Not enough arguments (missing: \"{}\").",
            missing.join(", ")
        )));
    }

    Ok(input)
}

fn set_option(
    input: &mut Input,
    opt: &OptSpec,
    inline: Option<String>,
    tokens: &[String],
    index: &mut usize,
    display: &str,
) -> Result<(), String> {
    match opt.mode {
        ValueMode::None => {
            if inline.is_some() {
                return Err(format!("The \"{display}\" option does not accept a value."));
            }
            input.flags.insert(opt.long.to_string());
        }
        ValueMode::Required | ValueMode::Optional => {
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
                return Err(format!("The \"{display}\" option requires a value."));
            }
            input.opts.insert(opt.long.to_string(), value);
        }
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn spec() -> CommandSpec {
        let mut opts = connection_options();
        opts.push(OptSpec::flag("json"));
        CommandSpec {
            name: "test:cmd",
            description: "",
            args: vec![ArgSpec {
                name: "name",
                required: true,
            }],
            opts,
        }
    }

    fn tokens(raw: &[&str]) -> Vec<String> {
        raw.iter().map(|token| token.to_string()).collect()
    }

    #[test]
    fn options_may_follow_or_precede_arguments() {
        let input = parse(
            &spec(),
            &tokens(&["--env", "prod", "dev", "--json", "-shttp://x"]),
        )
        .unwrap();
        assert_eq!(input.arg("name"), Some("dev"));
        assert_eq!(input.opt_str("env"), Some("prod"));
        assert_eq!(input.opt_str("server"), Some("http://x"));
        assert!(input.flag("json"));
    }

    #[test]
    fn optional_value_does_not_consume_a_following_option() {
        let input = parse(&spec(), &tokens(&["dev", "--server", "--json"])).unwrap();
        assert!(input.provided("server"));
        assert_eq!(input.opt("server"), Some(None));
        assert!(input.flag("json"));
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
            error(&["dev", "--env"]),
            "The \"--env\" option requires a value."
        );
        assert_eq!(
            error(&["dev", "--json=1"]),
            "The \"--json\" option does not accept a value."
        );
        assert_eq!(error(&[]), "Not enough arguments (missing: \"name\").");
        assert_eq!(
            error(&["a", "b"]),
            "Too many arguments, expected arguments \"name\"."
        );
    }

    #[test]
    fn double_dash_ends_options() {
        let input = parse(&spec(), &tokens(&["--", "--not-an-option"])).unwrap();
        assert_eq!(input.arg("name"), Some("--not-an-option"));
    }
}
