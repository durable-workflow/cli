//! `dw`, the Durable Workflow command-line interface.

mod build_info;
mod catalog;
mod cli;
mod client;
mod commands;
mod compat;
mod config;
mod error;
mod lookup;
mod output;
mod php;

use cli::{Input, UsageError};
use error::{exit, CliError, ErrorContext};
use lookup::LookupError;
use output::Mode;
use std::io::{BufRead, IsTerminal, Write};

type Run = fn(&Input) -> Result<i32, CliError>;
type RunWithServer = fn(&client::Context) -> Result<i32, CliError>;

enum Handler {
    Local(Run),
    Server(RunWithServer),
}

/// Commands ported so far. Every other catalog command is recognized and
/// reported as not yet available.
fn handler(name: &str) -> Option<Handler> {
    use commands::{env, server};
    Some(match name {
        "server:health" => Handler::Server(server::health),
        "server:info" => Handler::Server(server::info),
        "env:list" => Handler::Local(env::list),
        "env:set" => Handler::Local(env::set),
        "env:use" => Handler::Local(env::use_env),
        "env:show" => Handler::Local(env::show),
        "env:delete" => Handler::Local(env::delete),
        _ => return None,
    })
}

/// Commands whose errors carry the resolved namespace. None of the commands
/// ported so far are namespace scoped.
const NAMESPACE_SCOPED: &[&str] = &[];

/// Verbs of a group and the command each one names.
type GroupVerbs = &'static [(&'static str, &'static str)];

/// `dw schedule list` and similar spellings for the canonical names.
const GROUPED_ALIASES: &[(&[&str], GroupVerbs)] = &[
    (
        &["activity", "activities"],
        &[("describe", "activity:describe"), ("list", "activity:list")],
    ),
    (
        &["workflow", "workflows"],
        &[
            ("list", "workflow:list"),
            ("migrate-v1", "workflow:migrate-v1"),
        ],
    ),
    (
        &["schedule", "schedules"],
        &[
            ("backfill", "schedule:backfill"),
            ("create", "schedule:create"),
            ("delete", "schedule:delete"),
            ("describe", "schedule:describe"),
            ("history", "schedule:history"),
            ("list", "schedule:list"),
            ("pause", "schedule:pause"),
            ("resume", "schedule:resume"),
            ("trigger", "schedule:trigger"),
            ("update", "schedule:update"),
        ],
    ),
    (
        &["search-attribute", "search-attributes"],
        &[
            ("create", "search-attribute:create"),
            ("delete", "search-attribute:delete"),
            ("list", "search-attribute:list"),
        ],
    ),
];

const OPTIONS_WITH_SEPARATE_VALUES: &[&str] = &[
    "--env",
    "--namespace",
    "--output",
    "--server",
    "--source-id",
    "--tls-verify",
    "--token",
    "-s",
];

/// Rewrites `dw [options] schedule list ...` to `dw schedule:list [options] ...`.
fn rewrite_grouped(tokens: &[String]) -> Option<Vec<String>> {
    let mut index = 0;
    while index < tokens.len() {
        let token = tokens[index].as_str();
        if token == "--" {
            return None;
        }
        if token.starts_with('-') {
            if !token.contains('=')
                && OPTIONS_WITH_SEPARATE_VALUES.contains(&token)
                && index + 1 < tokens.len()
            {
                index += 1;
            }
            index += 1;
            continue;
        }
        let verb = tokens.get(index + 1)?;
        let command = GROUPED_ALIASES
            .iter()
            .find(|(groups, _)| groups.contains(&token))
            .and_then(|(_, verbs)| verbs.iter().find(|(name, _)| name == verb))
            .map(|(_, command)| command.to_string())?;
        let mut rewritten = vec![command];
        rewritten.extend_from_slice(&tokens[..index]);
        rewritten.extend_from_slice(&tokens[index + 2..]);
        return Some(rewritten);
    }
    None
}

/// Symfony's terminal width: $COLUMNS, else 80.
fn terminal_width() -> usize {
    std::env::var("COLUMNS")
        .ok()
        .and_then(|columns| columns.trim().parse().ok())
        .filter(|columns: &usize| *columns > 0)
        .unwrap_or(80)
}

/// Symfony's error block: each message line padded to the widest, wrapped
/// at the terminal width.
fn error_block(message: &str) -> Vec<String> {
    let wrap = terminal_width().saturating_sub(1).saturating_sub(4).max(1);
    let mut lines = Vec::new();
    for line in message.lines() {
        let chars: Vec<char> = line.chars().collect();
        if chars.is_empty() {
            lines.push(String::new());
        }
        for chunk in chars.chunks(wrap) {
            lines.push(chunk.iter().collect::<String>());
        }
    }
    let width = lines
        .iter()
        .map(|line| line.chars().count() + 4)
        .max()
        .unwrap_or(0);
    let mut block = vec![" ".repeat(width)];
    for line in lines {
        let padding = width - line.chars().count() - 4;
        block.push(format!("  {line}  {}", " ".repeat(padding)));
    }
    block.push(" ".repeat(width));
    block
}

fn print_usage_error(error: &UsageError) {
    eprintln!();
    for line in error_block(&error.message) {
        eprintln!("{line}");
    }
    eprintln!();
    if let Some(synopsis) = &error.synopsis {
        eprintln!("{synopsis}");
        eprintln!();
    }
}

const APPLICATION_HELP: &str = "Usage:
  command [options] [arguments]

Options:
  -h, --help            Display help for the given command. When no command is given display help for the list command
      --silent          Do not output any message
  -q, --quiet           Only errors are displayed. All other output is suppressed
  -V, --version         Display this application version
      --ansi|--no-ansi  Force (or disable --no-ansi) ANSI output
  -n, --no-interaction  Do not ask any interactive question
  -v|vv|vvv, --verbose  Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug";

/// The 2.x `list` text: all visible commands, or one namespace.
fn listing(namespace: Option<&str>) -> String {
    let mut entries: Vec<&catalog::Entry> = catalog::all()
        .iter()
        .filter(|entry| !entry.hidden)
        .filter(|entry| match namespace {
            Some(namespace) => entry.name.starts_with(&format!("{namespace}:")),
            None => true,
        })
        .collect();
    let group = |entry: &catalog::Entry| entry.name.rsplit_once(':').map(|(ns, _)| ns.to_string());
    entries.sort_by(|a, b| group(a).cmp(&group(b)).then_with(|| a.name.cmp(&b.name)));
    let width = entries
        .iter()
        .map(|entry| entry.name.chars().count())
        .max()
        .unwrap_or(0)
        + 2;

    let mut text = format!("{}\n\n{APPLICATION_HELP}\n\n", build_info::version_line());
    text.push_str(&match namespace {
        Some(namespace) => format!("Available commands for the \"{namespace}\" namespace:"),
        None => "Available commands:".to_string(),
    });
    let mut current: Option<String> = None;
    for entry in entries {
        let entry_group = group(entry);
        if namespace.is_none() && entry_group.is_some() && entry_group != current {
            text.push_str(&format!(
                "\n {}",
                entry_group.as_deref().unwrap_or_default()
            ));
        }
        current = entry_group;
        let aliases = if entry.aliases.is_empty() {
            String::new()
        } else {
            format!("[{}] ", entry.aliases.join("|"))
        };
        let padding = width - entry.name.chars().count();
        text.push_str(&format!(
            "\n  {}{}{aliases}{}",
            entry.name,
            " ".repeat(padding),
            entry.description
        ));
    }
    text
}

fn print_help(spec: &cli::CommandSpec) {
    println!("Description:");
    println!("  {}", spec.description);
    println!();
    println!("Usage:");
    println!("  {}", spec.synopsis);
}

/// The output mode for reporting an error. It never fails: a broken profile
/// or unknown mode falls back to human output.
fn error_mode(input: &Input, server_command: bool) -> Mode {
    output::resolve_mode(input, || {
        if !server_command {
            return None;
        }
        let store = config::ProfileStore::new().ok()?;
        let flags = config::ConnectionFlags {
            env: input.opt_str("env"),
            server: None,
            namespace: None,
            token: None,
            tls_verify: None,
        };
        config::resolve(&store, &flags).ok()?.profile?.output
    })
    .unwrap_or(Mode::Table)
}

fn report(error: &CliError, input: &Input, server_command: bool) -> i32 {
    let namespace = if NAMESPACE_SCOPED.contains(&input.command.as_str()) {
        client::Context::new(input)
            .ok()
            .map(|context| context.connection.namespace)
    } else {
        None
    };
    let context = ErrorContext {
        command: &input.command,
        namespace,
        server_flag: input.opt_str("server").map(str::to_string),
        env_flag: input.opt_str("env").map(str::to_string),
    };
    if error_mode(input, server_command).machine_readable() {
        output::print_json(&error::envelope(error, &context));
    } else {
        eprintln!("{}", error::human(error, &context));
    }
    error.exit_code()
}

fn interactive(tokens: &[String]) -> bool {
    std::io::stdin().is_terminal()
        && !tokens
            .iter()
            .any(|token| token == "-n" || token == "--no-interaction")
}

/// Symfony's single-suggestion confirmation. Only asked on a real terminal.
fn confirm_alternative(name: &str, alternative: &str) -> bool {
    let mut stdout = std::io::stdout();
    let _ = writeln!(stdout);
    for line in error_block(&format!("Command \"{name}\" is not defined.")) {
        let _ = writeln!(stdout, "{line}");
    }
    let _ = write!(
        stdout,
        "\n Do you want to run \"{alternative}\" instead?  (yes/no) [no]:\n > "
    );
    let _ = stdout.flush();
    let mut answer = String::new();
    let _ = std::io::stdin().lock().read_line(&mut answer);
    let _ = writeln!(stdout);
    matches!(answer.trim().to_lowercase().as_str(), "y" | "yes")
}

/// Resolves the command name the way 2.x does, or reports why not.
fn resolve_command(name: &str, tokens: &[String]) -> Result<String, i32> {
    match lookup::find(name, terminal_width()) {
        Ok(command) => Ok(command),
        Err(LookupError::NotFound { alternatives, .. })
            if alternatives.len() == 1 && interactive(tokens) =>
        {
            if confirm_alternative(name, &alternatives[0]) {
                Ok(alternatives[0].clone())
            } else {
                Err(exit::FAILURE)
            }
        }
        Err(error) => {
            // A name that is itself a namespace lists that namespace.
            if let Ok(namespace) = lookup::find_namespace(name) {
                eprintln!("{}", listing(Some(&namespace)));
            } else {
                print_usage_error(&UsageError {
                    message: error.message().to_string(),
                    synopsis: None,
                });
            }
            Err(exit::FAILURE)
        }
    }
}

/// The value of a global option as Symfony's getParameterOption reads it
/// from raw tokens: `--name=v`, `--name v`, `-sv` or `-s v`, before `--`.
fn raw_option(tokens: &[String], long: &str, short: Option<char>) -> Option<String> {
    let long_flag = format!("--{long}");
    let long_prefix = format!("--{long}=");
    let short_flag = short.map(|c| format!("-{c}"));
    let mut iter = tokens
        .iter()
        .take_while(|token| token.as_str() != "--")
        .peekable();
    while let Some(token) = iter.next() {
        if let Some(value) = token.strip_prefix(&long_prefix) {
            return Some(value.to_string()).filter(|value| !value.is_empty());
        }
        let is_short = short_flag.as_deref() == Some(token.as_str());
        if *token == long_flag || is_short {
            return iter
                .peek()
                .filter(|next| !next.is_empty() && !next.starts_with('-'))
                .map(|next| next.to_string());
        }
        if let Some(value) = short_flag
            .as_deref()
            .and_then(|flag| token.strip_prefix(flag))
        {
            return Some(value.to_string());
        }
    }
    None
}

/// Whether the caller named a Server for `--version`, by flag or environment.
fn names_a_server(tokens: &[String]) -> bool {
    raw_option(tokens, "server", Some('s')).is_some()
        || raw_option(tokens, "env", None).is_some()
        || config::env_string("DURABLE_WORKFLOW_SERVER_URL").is_some()
        || config::env_string("DW_ENV").is_some()
}

/// `dw --version`, plus the first compatibility warning when the caller
/// named a Server, as 2.x does.
fn print_version(tokens: &[String]) {
    println!("{}", build_info::version_line());
    if !names_a_server(tokens) {
        return;
    }
    let Ok(store) = config::ProfileStore::new() else {
        return;
    };
    let options: Vec<Option<String>> = [
        ("env", None),
        ("server", Some('s')),
        ("namespace", None),
        ("token", None),
        ("tls-verify", None),
    ]
    .into_iter()
    .map(|(long, short)| raw_option(tokens, long, short))
    .collect();
    let flags = config::ConnectionFlags {
        env: options[0].as_deref(),
        server: options[1].as_deref(),
        namespace: options[2].as_deref(),
        token: options[3].as_deref(),
        tls_verify: options[4].as_deref(),
    };
    let info = config::resolve(&store, &flags)
        .and_then(|connection| client::Context::with_connection(connection, Mode::Table))
        .and_then(|context| context.cluster_info(Some(client::PREFLIGHT_TIMEOUT)));
    if let Ok(info) = info {
        client::warn_first(&info, true);
    }
}

fn run(raw: Vec<String>) -> i32 {
    let tokens = rewrite_grouped(&raw).unwrap_or(raw);

    // Symfony answers --version before it looks for a command.
    if tokens
        .iter()
        .take_while(|token| token.as_str() != "--")
        .any(|token| token == "--version" || token == "-V")
    {
        print_version(&tokens);
        return exit::SUCCESS;
    }

    let Some((position, name)) = cli::first_argument(&tokens) else {
        println!("{}", listing(None));
        return exit::SUCCESS;
    };
    let name = name.to_string();

    if name == "update" || name == "self-update" {
        eprintln!("Command \"{name}\" is not defined.");
        eprintln!("Use dw upgrade to explicitly update a standalone installation.");
        return exit::INVALID;
    }

    let command = match resolve_command(&name, &tokens) {
        Ok(command) => command,
        Err(code) => return code,
    };
    let spec = catalog::spec(&command).expect("resolved commands are in the catalog");
    let mut rest = tokens.clone();
    rest.remove(position);
    let input = match cli::parse(&spec, &rest) {
        Ok(input) => input,
        Err(error) => {
            print_usage_error(&error);
            return exit::FAILURE;
        }
    };
    if input.flag("help") {
        print_help(&spec);
        return exit::SUCCESS;
    }
    if command == "list" {
        match input.arg("namespace") {
            Some(namespace) => match lookup::find_namespace(namespace) {
                Ok(namespace) => println!("{}", listing(Some(&namespace))),
                Err(error) => {
                    // 2.x writes the listing header before it finds that the
                    // namespace does not exist.
                    print!("{}\n\n{APPLICATION_HELP}\n\n", build_info::version_line());
                    print_usage_error(&UsageError {
                        message: error.message().to_string(),
                        synopsis: Some(spec.synopsis.clone()),
                    });
                    return exit::FAILURE;
                }
            },
            None => println!("{}", listing(None)),
        }
        return exit::SUCCESS;
    }

    let Some(handler) = handler(&command) else {
        eprintln!(
            "Command \"{command}\" is not available in this dw build yet. Use the 2.x dw for it."
        );
        return exit::FAILURE;
    };
    let server_command = matches!(handler, Handler::Server(_));
    let result = match handler {
        Handler::Local(run) => run(&input),
        Handler::Server(run) => client::Context::new(&input).and_then(|context| {
            context.warn_if_incompatible(client::uses_worker_protocol(&command));
            run(&context)
        }),
    };
    match result {
        Ok(code) => code,
        Err(error) => report(&error, &input, server_command),
    }
}

fn main() {
    let code = run(std::env::args().skip(1).collect());
    std::process::exit(code);
}

#[cfg(test)]
mod tests {
    use super::*;

    fn tokens(raw: &[&str]) -> Vec<String> {
        raw.iter().map(|token| token.to_string()).collect()
    }

    #[test]
    fn raw_options_follow_symfony_parameter_rules() {
        let t = tokens(&[
            "--version",
            "-shttp://a",
            "--env",
            "prod",
            "--namespace=",
            "--",
            "--token=x",
        ]);
        assert_eq!(
            raw_option(&t, "server", Some('s')).as_deref(),
            Some("http://a")
        );
        assert_eq!(raw_option(&t, "env", None).as_deref(), Some("prod"));
        assert_eq!(raw_option(&t, "namespace", None), None);
        assert_eq!(raw_option(&t, "token", None), None);
        assert_eq!(
            raw_option(&tokens(&["--server", "--env"]), "server", Some('s')),
            None
        );
    }

    #[test]
    fn grouped_aliases_move_leading_options_behind_the_command() {
        assert_eq!(
            rewrite_grouped(&tokens(&["--env", "prod", "schedule", "list", "--json"])),
            Some(tokens(&["schedule:list", "--env", "prod", "--json"]))
        );
        assert_eq!(rewrite_grouped(&tokens(&["schedule", "frobnicate"])), None);
        assert_eq!(rewrite_grouped(&tokens(&["env:list"])), None);
    }

    #[test]
    fn error_blocks_pad_every_line_to_the_widest() {
        let block =
            error_block("Command \"x\" is not defined.\n\nDid you mean this?\n    env:list");
        assert_eq!(block.len(), 6);
        assert!(block.iter().all(|line| line.chars().count() == 31));
        assert_eq!(block[1].trim_end(), "  Command \"x\" is not defined.");
        assert_eq!(block[2].trim(), "");
        assert_eq!(block[4].trim_end(), "      env:list");
    }

    #[test]
    fn namespace_listing_sizes_names_without_aliases() {
        let text = listing(Some("activity"));
        assert!(text.contains(
            "Available commands for the \"activity\" namespace:\n  activity:complete  Complete"
        ));
        assert!(text.contains("\n  activity:describe  [activities:describe] Show"));
    }
}
