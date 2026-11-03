//! `dw`, the Durable Workflow command-line interface.

mod cli;
mod client;
mod commands;
mod config;
mod error;
mod output;

use cli::{CommandSpec, Input, UsageError};
use error::{exit, CliError, ErrorContext};
use output::Mode;

type Run = fn(&Input) -> Result<i32, CliError>;
type RunWithServer = fn(&client::Context) -> Result<i32, CliError>;

enum Handler {
    Local(Run),
    Server(RunWithServer),
}

struct Command {
    spec: fn() -> CommandSpec,
    handler: Handler,
}

fn registry() -> Vec<Command> {
    use commands::{env, server};
    vec![
        Command {
            spec: server::health_spec,
            handler: Handler::Server(server::health),
        },
        Command {
            spec: env::list_spec,
            handler: Handler::Local(env::list),
        },
        Command {
            spec: env::set_spec,
            handler: Handler::Local(env::set),
        },
        Command {
            spec: env::use_spec,
            handler: Handler::Local(env::use_env),
        },
        Command {
            spec: env::show_spec,
            handler: Handler::Local(env::show),
        },
        Command {
            spec: env::delete_spec,
            handler: Handler::Local(env::delete),
        },
    ]
}

/// Commands whose errors carry the resolved namespace. None of the commands
/// ported so far are namespace scoped.
const NAMESPACE_SCOPED: &[&str] = &[];

/// `dw schedule list` and similar spellings for the canonical names.
const GROUPED_ALIASES: &[(&[&str], &[(&str, &str)])] = &[
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

fn version_line() -> String {
    let commit = option_env!("DW_CLI_COMMIT")
        .map(|commit| &commit[..commit.len().min(12)])
        .unwrap_or("unknown");
    let built = option_env!("DW_CLI_BUILD_DATE").unwrap_or("source checkout");
    let version = option_env!("DW_CLI_VERSION").unwrap_or(env!("CARGO_PKG_VERSION"));
    format!("dw {version} (commit {commit}, built {built})")
}

fn print_usage_error(error: &UsageError) {
    let width = error.message.chars().count() + 4;
    eprintln!();
    eprintln!("{}", " ".repeat(width));
    eprintln!("  {}  ", error.message);
    eprintln!("{}", " ".repeat(width));
    eprintln!();
    if let Some(synopsis) = &error.synopsis {
        eprintln!("{synopsis}");
        eprintln!();
    }
}

fn print_list(commands: &[Command]) {
    println!("{}", version_line());
    println!();
    println!("Usage:");
    println!("  command [options] [arguments]");
    println!();
    println!("Available commands:");
    let mut specs: Vec<CommandSpec> = commands.iter().map(|command| (command.spec)()).collect();
    specs.sort_by_key(|spec| spec.name);
    let width = specs.iter().map(|spec| spec.name.len()).max().unwrap_or(0);
    for spec in specs {
        println!("  {:width$}  {}", spec.name, spec.description);
    }
}

fn print_help(spec: &CommandSpec) {
    println!("Description:");
    println!("  {}", spec.description);
    println!();
    println!("Usage:");
    println!("  {}", cli::synopsis(spec));
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

fn run(raw: Vec<String>) -> i32 {
    let commands = registry();
    let tokens = rewrite_grouped(&raw).unwrap_or(raw);

    let command_name = tokens.iter().find(|token| !token.starts_with('-')).cloned();
    let Some(command_name) = command_name else {
        if tokens
            .iter()
            .any(|token| token == "--version" || token == "-V")
        {
            println!("{}", version_line());
        } else {
            print_list(&commands);
        }
        return exit::SUCCESS;
    };

    if command_name == "list" {
        print_list(&commands);
        return exit::SUCCESS;
    }
    if command_name == "update" || command_name == "self-update" {
        eprintln!("Command \"{command_name}\" is not defined.");
        eprintln!("Use dw upgrade to explicitly update a standalone installation.");
        return exit::INVALID;
    }

    let Some(command) = commands
        .iter()
        .find(|command| (command.spec)().name == command_name)
    else {
        print_usage_error(&UsageError {
            message: format!("Command \"{command_name}\" is not defined."),
            synopsis: None,
        });
        return exit::FAILURE;
    };

    let spec = (command.spec)();
    let position = tokens
        .iter()
        .position(|token| *token == command_name)
        .unwrap_or(0);
    let mut rest = tokens.clone();
    rest.remove(position);
    let input = match cli::parse(&spec, &rest) {
        Ok(input) => input,
        Err(error) => {
            print_usage_error(&error);
            return exit::FAILURE;
        }
    };
    if input.flag("version") {
        println!("{}", version_line());
        return exit::SUCCESS;
    }
    if input.flag("help") {
        print_help(&spec);
        return exit::SUCCESS;
    }

    let result = match &command.handler {
        Handler::Local(run) => run(&input),
        Handler::Server(run) => client::Context::new(&input).and_then(|context| run(&context)),
    };
    match result {
        Ok(code) => code,
        Err(error) => report(
            &error,
            &input,
            matches!(command.handler, Handler::Server(_)),
        ),
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
    fn grouped_aliases_move_leading_options_behind_the_command() {
        assert_eq!(
            rewrite_grouped(&tokens(&["--env", "prod", "schedule", "list", "--json"])),
            Some(tokens(&["schedule:list", "--env", "prod", "--json"]))
        );
        assert_eq!(rewrite_grouped(&tokens(&["schedule", "frobnicate"])), None);
        assert_eq!(rewrite_grouped(&tokens(&["env:list"])), None);
    }
}
