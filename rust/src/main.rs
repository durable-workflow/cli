//! `dw`, the Durable Workflow command-line interface.

mod build_info;
mod catalog;
mod cli;
mod client;
mod commands;
mod compat;
mod config;
mod descriptor;
mod error;
mod formatter;
mod lookup;
mod output;
mod php;

use cli::{Input, UsageError};
use descriptor::Format;
use error::{exit, CliError, ErrorContext};
use lookup::LookupError;
use output::Mode;
use std::io::{BufRead, Write};

type Run = fn(&Input) -> Result<i32, CliError>;
type RunWithServer = fn(&client::Context) -> Result<i32, CliError>;

enum Handler {
    Local(Run),
    Server(RunWithServer),
}

/// Commands ported so far. Every other catalog command is recognized and
/// reported as not yet available.
fn handler(name: &str) -> Option<Handler> {
    use commands::{env, server, upgrade};
    Some(match name {
        "upgrade" => Handler::Local(upgrade::run),
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

/// Symfony's console error rendering: a blank line, the `<error>` block,
/// a blank line and, for usage errors, the command synopsis. Shown when
/// quiet, hidden when silent.
fn print_usage_error(error: &UsageError) {
    let mut lines = vec![String::new()];
    for line in error_block(&error.message) {
        lines.push(format!("<error>{}</error>", formatter::escape(&line)));
    }
    lines.push(String::new());
    if let Some(synopsis) = &error.synopsis {
        lines.push(format!("<info>{}</info>", formatter::escape(synopsis)));
        lines.push(String::new());
    }
    formatter::writeln_err_quiet(&lines.join("\n"));
}

/// Writes a description in its format: txt is styled for the stream, md
/// passes through the formatter undecorated, json and xml are raw.
fn write_description(text: &str, format: Format, to_stderr: bool) {
    if formatter::verbosity() != formatter::Verbosity::Normal {
        return;
    }
    match (format, to_stderr) {
        (Format::Txt, false) => formatter::write_out(text),
        (Format::Txt, true) => {
            let stream = formatter::Stream::Stderr;
            formatter::emit(
                stream,
                &formatter::format(text, formatter::decorated(stream)),
            )
        }
        (Format::Md, _) => {
            formatter::emit(formatter::Stream::Stdout, &formatter::format(text, false))
        }
        (Format::Json | Format::Xml, _) => formatter::emit(formatter::Stream::Stdout, text),
    }
}

/// The help command and `--help`: describe `command` with the help
/// command's own options, which 2.x binds leniently.
fn describe_help(command: &str, help_input: &Input) -> i32 {
    let help_synopsis = catalog::spec("help")
        .map(|spec| spec.synopsis)
        .unwrap_or_default();
    let format_name = help_input.opt_str("format").unwrap_or("txt");
    let Some(format) = Format::parse(format_name) else {
        print_usage_error(&UsageError {
            message: descriptor::unsupported_format(format_name),
            synopsis: Some(help_synopsis),
        });
        return exit::FAILURE;
    };
    let entry = catalog::entry(command).expect("resolved commands are in the catalog");
    write_description(
        &descriptor::describe_command(entry, format, help_input.flag("raw")),
        format,
        false,
    );
    exit::SUCCESS
}

fn help_options(tokens: &[String]) -> Input {
    let spec = catalog::spec("help").expect("help is in the catalog");
    cli::parse_lenient(&spec, tokens)
}

/// `dw list [namespace] [--format] [--raw] [--short]`.
fn run_list(input: &Input, synopsis: &str) -> i32 {
    let format_name = input.opt_str("format").unwrap_or("txt");
    let Some(format) = Format::parse(format_name) else {
        print_usage_error(&UsageError {
            message: descriptor::unsupported_format(format_name),
            synopsis: Some(synopsis.to_string()),
        });
        return exit::FAILURE;
    };
    let raw = input.flag("raw");
    let namespace = input.arg("namespace");
    if let Some(Err(error)) = namespace.map(lookup::find_namespace) {
        // 2.x writes the text listing header before it finds that the
        // namespace does not exist.
        if format == Format::Txt && !raw {
            write_description(&descriptor::application_header(), format, false);
        }
        print_usage_error(&UsageError {
            message: error.message().to_string(),
            synopsis: Some(synopsis.to_string()),
        });
        return exit::FAILURE;
    }
    write_description(
        &descriptor::describe_application(namespace, format, raw, input.flag("short")),
        format,
        false,
    );
    exit::SUCCESS
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
        formatter::writeln_err(&error::human(error, &context));
    }
    error.exit_code()
}

/// Symfony's single-suggestion confirmation. Only asked on a real terminal.
fn confirm_alternative(name: &str, alternative: &str) -> bool {
    let mut stdout = std::io::stdout();
    let decorated = formatter::decorated(formatter::Stream::Stdout);
    let _ = writeln!(stdout);
    for line in error_block(&format!("Command \"{name}\" is not defined.")) {
        let _ = writeln!(
            stdout,
            "{}",
            formatter::format(
                &format!("<error>{}</error>", formatter::escape(&line)),
                decorated
            )
        );
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
            if alternatives.len() == 1 && formatter::interactive(tokens) =>
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
                write_description(
                    &descriptor::describe_application(Some(&namespace), Format::Txt, false, false),
                    Format::Txt,
                    true,
                );
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
            // Symfony takes the next token as the value, whatever it is.
            return iter
                .peek()
                .filter(|next| !next.is_empty())
                .map(|next| (*next).clone());
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
    let line = build_info::version_line();
    formatter::writeln_out(&format!(
        "dw <info>{}</info>",
        line.strip_prefix("dw ").unwrap_or(&line)
    ));
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
    formatter::configure(&tokens);

    // Symfony answers --version before it looks for a command.
    if formatter::has_parameter_option(&tokens, &["--version", "-V"]) {
        print_version(&tokens);
        return exit::SUCCESS;
    }

    let wants_help = formatter::has_parameter_option(&tokens, &["--help", "-h"]);
    let Some((position, name)) = cli::first_argument(&tokens) else {
        if wants_help {
            // Help without a command describes the default command, list.
            return describe_help("list", &help_options(&[]));
        }
        write_description(
            &descriptor::describe_application(None, Format::Txt, false, false),
            Format::Txt,
            false,
        );
        return exit::SUCCESS;
    };
    let name = name.to_string();

    if name == "update" || name == "self-update" {
        formatter::writeln_err(&format!(
            "<error>Command \"{name}\" is not defined.</error>"
        ));
        formatter::writeln_err(
            "Use <info>dw upgrade</info> to explicitly update a standalone installation.",
        );
        return exit::INVALID;
    }

    let command = match resolve_command(&name, &tokens) {
        Ok(command) => command,
        Err(code) => return code,
    };
    let mut rest = tokens.clone();
    rest.remove(position);

    // --help anywhere shows help before the command's own options are
    // checked, as 2.x does; `help` itself ignores usage errors too.
    if wants_help {
        return describe_help(&command, &help_options(&rest));
    }
    if command == "help" {
        let input = help_options(&rest);
        let target = input.arg("command_name").unwrap_or("help");
        return match lookup::find(target, terminal_width()) {
            Ok(target) => describe_help(&target, &input),
            Err(error) => {
                let synopsis = catalog::spec("help").map(|spec| spec.synopsis);
                print_usage_error(&UsageError {
                    message: error.message().to_string(),
                    synopsis,
                });
                exit::FAILURE
            }
        };
    }

    let spec = catalog::spec(&command).expect("resolved commands are in the catalog");
    let input = match cli::parse(&spec, &rest) {
        Ok(input) => input,
        Err(error) => {
            print_usage_error(&error);
            return exit::FAILURE;
        }
    };
    if command == "list" {
        return run_list(&input, &spec.synopsis);
    }

    let Some(handler) = handler(&command) else {
        formatter::writeln_err(&format!(
            "<error>Command \"{command}\" is not available in this dw build yet. Use the 2.x dw for it.</error>"
        ));
        return exit::FAILURE;
    };
    let server_command = matches!(handler, Handler::Server(_));
    let result = match handler {
        Handler::Local(run) => run(&input),
        Handler::Server(run) => client::Context::new(&input).and_then(|context| {
            // doctor and server:start-dev opt out of the session warning in 2.x.
            if !matches!(command.as_str(), "doctor" | "server:start-dev") {
                context.warn_if_incompatible(client::uses_worker_protocol(&command));
            }
            run(&context)
        }),
    };
    match result {
        Ok(code) => code,
        Err(error) => report(&error, &input, server_command),
    }
}

fn main() {
    // Arguments that are not valid UTF-8 are read lossily instead of
    // aborting, like PHP, which treats argv as bytes.
    let arguments = std::env::args_os()
        .skip(1)
        .map(|argument| argument.to_string_lossy().into_owned())
        .collect();
    std::process::exit(run(arguments));
}

#[cfg(test)]
mod tests {
    use super::*;

    fn tokens(raw: &[&str]) -> Vec<String> {
        raw.iter().map(|token| token.to_string()).collect()
    }

    #[test]
    fn raw_options_follow_symfony_get_parameter_option() {
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
            raw_option(&tokens(&["--server", "--env"]), "server", Some('s')).as_deref(),
            Some("--env")
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
}
