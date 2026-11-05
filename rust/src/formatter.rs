//! Symfony Console's output formatter and verbosity, as the 2.x CLI uses
//! them: `<info>`, `<comment>`, `<error>` and `<question>` styles (plus
//! `fg=…;bg=…;options=…` styles) become ANSI escapes on a decorated stream
//! and are removed otherwise; `\<` and `\>` are literal brackets.

use std::io::IsTerminal;
use std::sync::OnceLock;

/// Output verbosity from `--silent`, `-q`/`--quiet` and `SHELL_VERBOSITY`.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Verbosity {
    Silent,
    Quiet,
    Normal,
}

#[derive(Clone, Copy, Debug)]
struct Settings {
    verbosity: Verbosity,
    /// `Some` when `--ansi` or `--no-ansi` forces decoration.
    forced_decoration: Option<bool>,
}

static SETTINGS: OnceLock<Settings> = OnceLock::new();

/// Symfony's `hasParameterOption`: a long option matches `--name` or
/// `--name=…`; a short option matches any token that starts with it.
pub fn has_parameter_option(tokens: &[String], names: &[&str]) -> bool {
    tokens
        .iter()
        .take_while(|token| token.as_str() != "--")
        .any(|token| {
            names.iter().any(|name| {
                if name.starts_with("--") {
                    token == name || token.starts_with(&format!("{name}="))
                } else {
                    token.starts_with(name)
                }
            })
        })
}

/// Reads the global output options once, before any output.
pub fn configure(tokens: &[String]) {
    let verbosity = if has_parameter_option(tokens, &["--silent"]) {
        Verbosity::Silent
    } else if has_parameter_option(tokens, &["--quiet", "-q"]) {
        Verbosity::Quiet
    } else if has_parameter_option(tokens, &["-v", "--verbose"]) {
        Verbosity::Normal
    } else {
        match std::env::var("SHELL_VERBOSITY")
            .ok()
            .and_then(|value| value.trim().parse::<i64>().ok())
        {
            Some(-2) => Verbosity::Silent,
            Some(-1) => Verbosity::Quiet,
            _ => Verbosity::Normal,
        }
    };
    let forced_decoration = if has_parameter_option(tokens, &["--ansi"]) {
        Some(true)
    } else if has_parameter_option(tokens, &["--no-ansi"]) {
        Some(false)
    } else {
        None
    };
    let _ = SETTINGS.set(Settings {
        verbosity,
        forced_decoration,
    });
}

fn settings() -> Settings {
    *SETTINGS.get_or_init(|| Settings {
        verbosity: Verbosity::Normal,
        forced_decoration: None,
    })
}

pub fn verbosity() -> Verbosity {
    settings().verbosity
}

/// Whether input is interactive: a terminal, no `-n`, and not quiet.
pub fn interactive(tokens: &[String]) -> bool {
    verbosity() == Verbosity::Normal
        && std::io::stdin().is_terminal()
        && !has_parameter_option(tokens, &["--no-interaction", "-n"])
}

#[derive(Clone, Copy)]
pub enum Stream {
    Stdout,
    Stderr,
}

fn env_set(name: &str) -> bool {
    std::env::var_os(name).is_some()
}

/// Symfony's `StreamOutput::hasColorSupport` for a non-Windows stream.
fn has_color_support(is_terminal: bool) -> bool {
    let first_char_set = |name: &str| std::env::var(name).is_ok_and(|value| !value.is_empty());
    if first_char_set("NO_COLOR") {
        return false;
    }
    if first_char_set("FORCE_COLOR") {
        return true;
    }
    let msys = std::env::var("MSYSTEM")
        .is_ok_and(|value| matches!(value.to_uppercase().as_str(), "MINGW32" | "MINGW64"));
    if !is_terminal && !msys {
        return false;
    }
    if std::env::var("TERM_PROGRAM").is_ok_and(|value| value == "Hyper")
        || env_set("COLORTERM")
        || env_set("ANSICON")
        || std::env::var("ConEmuANSI").is_ok_and(|value| value == "ON")
    {
        return true;
    }
    let term = std::env::var("TERM").unwrap_or_default();
    if term == "dumb" {
        return false;
    }
    const PREFIXES: &[&str] = &[
        "screen", "xterm", "vt100", "vt220", "putty", "rxvt", "ansi", "cygwin", "linux",
    ];
    PREFIXES.iter().any(|prefix| term.starts_with(prefix))
        || term.ends_with("-256")
        || term.ends_with("-256color")
        || term.ends_with("-256-bce")
        || term.ends_with("-256color-bce")
}

pub fn decorated(stream: Stream) -> bool {
    if let Some(forced) = settings().forced_decoration {
        return forced;
    }
    has_color_support(match stream {
        Stream::Stdout => std::io::stdout().is_terminal(),
        Stream::Stderr => std::io::stderr().is_terminal(),
    })
}

/// A style's ANSI set and unset codes.
struct Style {
    set: Vec<&'static str>,
    unset: Vec<&'static str>,
}

fn color_code(color: &str, background: bool) -> Option<&'static str> {
    const COLORS: &[(&str, &str, &str)] = &[
        ("black", "30", "40"),
        ("red", "31", "41"),
        ("green", "32", "42"),
        ("yellow", "33", "43"),
        ("blue", "34", "44"),
        ("magenta", "35", "45"),
        ("cyan", "36", "46"),
        ("white", "37", "47"),
        ("default", "39", "49"),
        ("gray", "90", "100"),
        ("bright-red", "91", "101"),
        ("bright-green", "92", "102"),
        ("bright-yellow", "93", "103"),
        ("bright-blue", "94", "104"),
        ("bright-magenta", "95", "105"),
        ("bright-cyan", "96", "106"),
        ("bright-white", "97", "107"),
    ];
    COLORS
        .iter()
        .find(|(name, _, _)| *name == color)
        .map(|(_, fg, bg)| if background { *bg } else { *fg })
}

fn option_codes(option: &str) -> Option<(&'static str, &'static str)> {
    match option {
        "bold" => Some(("1", "22")),
        "underscore" => Some(("4", "24")),
        "blink" => Some(("5", "25")),
        "reverse" => Some(("7", "27")),
        "conceal" => Some(("8", "28")),
        _ => None,
    }
}

/// `createStyleFromString`: a named style or `fg=…;bg=…;options=…`.
fn style_from_tag(tag: &str) -> Option<Style> {
    let named = match tag {
        "error" => Some(("white", "red")),
        "info" => Some(("green", "")),
        "comment" => Some(("yellow", "")),
        "question" => Some(("black", "cyan")),
        _ => None,
    };
    let (mut set, mut unset) = (Vec::new(), Vec::new());
    if let Some((fg, bg)) = named {
        set.push(color_code(fg, false)?);
        unset.push("39");
        if !bg.is_empty() {
            set.push(color_code(bg, true)?);
            unset.push("49");
        }
        return Some(Style { set, unset });
    }
    let mut matched = false;
    for part in tag.split(';').filter(|part| !part.is_empty()) {
        let (key, value) = part.split_once('=')?;
        matched = true;
        match key.trim().to_lowercase().as_str() {
            "fg" => {
                set.push(color_code(value.trim(), false)?);
                unset.push("39");
            }
            "bg" => {
                set.push(color_code(value.trim(), true)?);
                unset.push("49");
            }
            "options" => {
                for option in value.split(',') {
                    let (on, off) = option_codes(option.trim())?;
                    set.push(on);
                    unset.push(off);
                }
            }
            "href" => {}
            _ => return None,
        }
    }
    matched.then_some(Style { set, unset })
}

fn apply(style: Option<&Style>, text: &str, decorated: bool) -> String {
    match style {
        Some(style) if decorated && !text.is_empty() && !style.set.is_empty() => format!(
            "\u{1b}[{}m{text}\u{1b}[{}m",
            style.set.join(";"),
            style.unset.join(";")
        ),
        _ => text.to_string(),
    }
}

/// A tag at `start` (the `<`) of `text`, as Symfony's tag pattern finds it:
/// returns (tag content, whether it closes, byte length).
fn tag_at(text: &str, start: usize) -> Option<(&str, bool, usize)> {
    let rest = &text[start + 1..];
    let (closing, body) = match rest.strip_prefix('/') {
        Some(body) => (true, body),
        None => (false, rest),
    };
    let end = body.find(['>', '<'])?;
    if !body[end..].starts_with('>') {
        return None;
    }
    let content = &body[..end];
    let valid = if closing {
        content.is_empty() || content.starts_with(|c: char| c.is_ascii_alphabetic())
    } else {
        content.starts_with(|c: char| c.is_ascii_alphabetic())
    };
    valid.then_some((content, closing, end + 2 + usize::from(closing)))
}

/// `OutputFormatter::format`.
pub fn format(text: &str, decorated: bool) -> String {
    let mut out = String::new();
    let mut stack: Vec<Style> = Vec::new();
    let mut offset = 0;
    let mut search = 0;
    while let Some(found) = text[search..].find('<') {
        let start = search + found;
        search = start + 1;
        if start > 0 && text.as_bytes()[start - 1] == b'\\' {
            continue;
        }
        let Some((content, closing, length)) = tag_at(text, start) else {
            continue;
        };
        out.push_str(&apply(stack.last(), &text[offset..start], decorated));
        offset = start + length;
        search = offset;
        if closing && content.is_empty() {
            stack.pop();
        } else if let Some(style) = style_from_tag(&content.to_lowercase()) {
            if closing {
                stack.pop();
            } else {
                stack.push(style);
            }
        } else {
            out.push_str(&apply(stack.last(), &text[start..offset], decorated));
        }
    }
    out.push_str(&apply(stack.last(), &text[offset..], decorated));
    out.replace('\0', "\\")
        .replace("\\<", "<")
        .replace("\\>", ">")
}

/// `OutputFormatter::escape`: brackets become literal.
pub fn escape(text: &str) -> String {
    let mut out = String::with_capacity(text.len());
    let mut previous = None;
    for character in text.chars() {
        if matches!(character, '<' | '>') && previous != Some('\\') {
            out.push('\\');
        }
        out.push(character);
        previous = Some(character);
    }
    if out.ends_with('\\') {
        let trimmed = out.trim_end_matches('\\');
        let count = out.len() - trimmed.len();
        out = format!("{trimmed}{}", "\0".repeat(count));
    }
    out
}

/// Render remote values as data. Only trusted templates may create markup
/// or terminal layout; machine JSON keeps the original values.
pub fn display_text(text: &str) -> String {
    use std::fmt::Write as _;
    let mut safe = String::new();
    for character in text.chars() {
        match character {
            '\n' => safe.push_str("\\n"),
            '\r' => safe.push_str("\\r"),
            '\t' => safe.push_str("\\t"),
            character if character.is_control() || matches!(character, '\u{2028}' | '\u{2029}') => {
                write!(safe, "\\u{{{:04x}}}", u32::from(character)).expect("write to string");
            }
            character => safe.push(character),
        }
    }
    escape(&safe)
}

/// PHP's `strip_tags`, used by 2.x's raw text help and listing.
pub fn strip_tags(text: &str) -> String {
    let mut out = String::with_capacity(text.len());
    let mut chars = text.chars().peekable();
    while let Some(character) = chars.next() {
        let starts_tag = character == '<' && chars.peek().is_some_and(|next| !next.is_whitespace());
        if !starts_tag {
            out.push(character);
            continue;
        }
        let mut quote: Option<char> = None;
        for inner in chars.by_ref() {
            match (inner, quote) {
                ('"' | '\'', None) => quote = Some(inner),
                (q, Some(open)) if q == open => quote = None,
                ('>', None) => break,
                _ => {}
            }
        }
    }
    out
}

/// Writes bytes to a stream and ignores failures. PHP's CLI ignores
/// SIGPIPE and Symfony's StreamOutput discards `fwrite` errors, so 2.x keeps
/// going (and exits normally) when a reader closes the pipe early.
pub fn emit(stream: Stream, text: &str) {
    use std::io::Write;
    let _ = match stream {
        Stream::Stdout => std::io::stdout().lock().write_all(text.as_bytes()),
        Stream::Stderr => std::io::stderr().lock().write_all(text.as_bytes()),
    };
}

/// Writes formatted text to stdout, as Symfony's `writeln` at normal
/// verbosity does: nothing when quiet or silent.
pub fn write_out(text: &str) {
    if verbosity() == Verbosity::Normal {
        emit(Stream::Stdout, &format(text, decorated(Stream::Stdout)));
    }
}

pub fn writeln_out(text: &str) {
    write_out(&format!("{text}\n"));
}

/// Command error and warning text on stderr, suppressed when quiet.
pub fn writeln_err(text: &str) {
    if verbosity() == Verbosity::Normal {
        emit(
            Stream::Stderr,
            &(format(text, decorated(Stream::Stderr)) + "\n"),
        );
    }
}

/// Console-level errors (usage, unknown command), which 2.x shows even
/// when quiet and hides only when silent.
pub fn writeln_err_quiet(text: &str) {
    if verbosity() != Verbosity::Silent {
        emit(
            Stream::Stderr,
            &(format(text, decorated(Stream::Stderr)) + "\n"),
        );
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn styles_become_ansi_only_when_decorated() {
        assert_eq!(
            format("<comment>Usage:</comment> x", true),
            "\u{1b}[33mUsage:\u{1b}[39m x"
        );
        assert_eq!(format("<comment>Usage:</comment> x", false), "Usage: x");
        assert_eq!(
            format("<error>bad</error>", true),
            "\u{1b}[37;41mbad\u{1b}[39;49m"
        );
        assert_eq!(
            format("<fg=red;options=bold>hot</>", true),
            "\u{1b}[31;1mhot\u{1b}[39;22m"
        );
    }

    #[test]
    fn unknown_tags_and_escapes_stay_literal() {
        assert_eq!(
            format("<name> <> \\<b\\> <info>x</info>", false),
            "<name> <> <b> x"
        );
        assert_eq!(format(&escape("a <name> b"), true), "a <name> b");
        assert_eq!(escape("ends\\"), "ends\0");
        assert_eq!(format(&escape("ends\\"), false), "ends\\");
    }

    #[test]
    fn strip_tags_follows_php() {
        assert_eq!(strip_tags("env:use [--] \\<name>"), "env:use [--] \\");
        assert_eq!(strip_tags("<info>dw</info> a < b"), "dw a < b");
        assert_eq!(strip_tags("x <unclosed"), "x ");
    }

    #[test]
    fn short_options_match_by_prefix_and_long_by_name() {
        let tokens = |raw: &[&str]| raw.iter().map(|t| t.to_string()).collect::<Vec<_>>();
        assert!(has_parameter_option(&tokens(&["-qh"]), &["-q"]));
        assert!(!has_parameter_option(&tokens(&["-vq"]), &["-q"]));
        assert!(has_parameter_option(&tokens(&["--help=x"]), &["--help"]));
        assert!(!has_parameter_option(&tokens(&["--helper"]), &["--help"]));
        assert!(!has_parameter_option(&tokens(&["--", "-q"]), &["-q"]));
    }
}
