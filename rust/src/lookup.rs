//! Command lookup with the 2.x (Symfony Console) rules: exact names and
//! aliases, unambiguous abbreviations per `:` segment (`env:li`, `e:l`),
//! and the same "not defined", "ambiguous" and "Did you mean" messages.

use crate::catalog;
use std::cmp::Ordering;

#[derive(Debug, PartialEq, Eq)]
pub enum LookupError {
    /// No command matched. `alternatives` drives the interactive prompt.
    NotFound {
        message: String,
        alternatives: Vec<String>,
    },
    /// The namespace part of the name does not exist, or is ambiguous.
    Namespace {
        message: String,
    },
    Ambiguous {
        message: String,
    },
}

impl LookupError {
    pub fn message(&self) -> &str {
        match self {
            LookupError::NotFound { message, .. }
            | LookupError::Namespace { message }
            | LookupError::Ambiguous { message } => message,
        }
    }
}

/// Every name and alias, hidden commands included, in catalog order.
fn all_names() -> Vec<String> {
    catalog::all()
        .iter()
        .flat_map(|entry| std::iter::once(entry.name.clone()).chain(entry.aliases.iter().cloned()))
        .collect()
}

fn canonical(name_or_alias: &str) -> &'static catalog::Entry {
    catalog::entry(name_or_alias).expect("names come from the catalog")
}

/// Every namespace prefix of the visible commands and aliases, first seen first.
pub fn namespaces() -> Vec<String> {
    let mut found: Vec<String> = Vec::new();
    for entry in catalog::all().iter().filter(|entry| !entry.hidden) {
        for name in std::iter::once(&entry.name).chain(entry.aliases.iter()) {
            let parts: Vec<&str> = name.split(':').collect();
            for end in 1..parts.len() {
                let namespace = parts[..end].join(":");
                if !found.contains(&namespace) {
                    found.push(namespace);
                }
            }
        }
    }
    found
}

/// Symfony's `^p0[^:]*:p1[^:]*...` match: each `:` segment of the candidate
/// starts with the matching segment of the abbreviation. `whole` also
/// requires the same number of segments.
fn abbreviates(abbreviation: &str, candidate: &str, ignore_case: bool, whole: bool) -> bool {
    let fold = |text: &str| {
        if ignore_case {
            text.to_lowercase()
        } else {
            text.to_string()
        }
    };
    let wanted: Vec<String> = abbreviation.split(':').map(fold).collect();
    let segments: Vec<String> = candidate.split(':').map(fold).collect();
    if segments.len() < wanted.len() || (whole && segments.len() != wanted.len()) {
        return false;
    }
    wanted
        .iter()
        .zip(&segments)
        .all(|(prefix, segment)| segment.starts_with(prefix.as_str()))
}

/// PHP's byte-wise levenshtein().
fn levenshtein(a: &str, b: &str) -> usize {
    let (a, b) = (a.as_bytes(), b.as_bytes());
    let mut previous: Vec<usize> = (0..=b.len()).collect();
    for (i, &left) in a.iter().enumerate() {
        let mut current = vec![i + 1];
        for (j, &right) in b.iter().enumerate() {
            let substitution = previous[j] + usize::from(left != right);
            current.push(substitution.min(previous[j + 1] + 1).min(current[j] + 1));
        }
        previous = current;
    }
    previous[b.len()]
}

/// PHP's strnatcasecmp() for the ASCII names used here.
fn natural_case_cmp(a: &str, b: &str) -> Ordering {
    let (mut a, mut b) = (a.as_bytes(), b.as_bytes());
    loop {
        match (a.first(), b.first()) {
            (None, None) => return Ordering::Equal,
            (None, Some(_)) => return Ordering::Less,
            (Some(_), None) => return Ordering::Greater,
            (Some(x), Some(y)) if x.is_ascii_digit() && y.is_ascii_digit() => {
                let digits = |s: &[u8]| s.iter().take_while(|c| c.is_ascii_digit()).count();
                let (da, db) = (digits(a), digits(b));
                let (na, nb) = (&a[..da], &b[..db]);
                let trim = |s: &[u8]| -> Vec<u8> {
                    s.iter().skip_while(|c| **c == b'0').copied().collect()
                };
                let (ta, tb) = (trim(na), trim(nb));
                let order = ta.len().cmp(&tb.len()).then_with(|| ta.cmp(&tb));
                if order != Ordering::Equal {
                    return order;
                }
                a = &a[da..];
                b = &b[db..];
            }
            (Some(x), Some(y)) => {
                let order = x.to_ascii_lowercase().cmp(&y.to_ascii_lowercase());
                if order != Ordering::Equal {
                    return order;
                }
                a = &a[1..];
                b = &b[1..];
            }
        }
    }
}

/// Symfony's findAlternatives(): close names by segment and whole-name
/// distance, sorted naturally.
fn alternatives(name: &str, collection: &[String]) -> Vec<String> {
    const THRESHOLD: f64 = 1e3;
    let mut scores: Vec<(String, f64)> = Vec::new();
    let score_of = |scores: &mut Vec<(String, f64)>, item: &str| {
        scores.iter().position(|(key, _)| key == item)
    };

    for (i, subname) in name.split(':').enumerate() {
        for item in collection {
            let parts: Vec<&str> = item.split(':').collect();
            let existing = score_of(&mut scores, item);
            let Some(part) = parts.get(i) else {
                if let Some(index) = existing {
                    scores[index].1 += THRESHOLD;
                }
                continue;
            };
            let distance = levenshtein(subname, part);
            let close = distance as f64 <= subname.len() as f64 / 3.0
                || (!subname.is_empty() && part.contains(subname));
            match (close, existing) {
                (true, Some(index)) => scores[index].1 += distance as f64,
                (true, None) => scores.push((item.clone(), distance as f64)),
                (false, Some(index)) => scores[index].1 += THRESHOLD,
                (false, None) => {}
            }
        }
    }

    for item in collection {
        let distance = levenshtein(name, item);
        if distance as f64 <= name.len() as f64 / 3.0 || item.contains(name) {
            match score_of(&mut scores, item) {
                Some(index) => scores[index].1 -= distance as f64,
                None => scores.push((item.clone(), distance as f64)),
            }
        }
    }

    let mut kept: Vec<String> = scores
        .into_iter()
        .filter(|(_, score)| *score < 2.0 * THRESHOLD)
        .map(|(item, _)| item)
        .collect();
    kept.sort_by(|a, b| natural_case_cmp(a, b));
    kept.dedup();
    kept
}

fn did_you_mean(alternatives: &[String]) -> String {
    if alternatives.is_empty() {
        return String::new();
    }
    format!(
        "\n\nDid you mean {}?\n    {}",
        if alternatives.len() == 1 {
            "this"
        } else {
            "one of these"
        },
        alternatives.join("\n    ")
    )
}

pub fn find_namespace(namespace: &str) -> Result<String, LookupError> {
    let all = namespaces();
    let matches: Vec<&String> = all
        .iter()
        .filter(|candidate| abbreviates(namespace, candidate, false, false))
        .collect();
    if matches.is_empty() {
        let message = format!(
            "There are no commands defined in the \"{namespace}\" namespace.{}",
            did_you_mean(&alternatives(namespace, &all))
        );
        return Err(LookupError::Namespace { message });
    }
    let exact = matches.iter().any(|candidate| *candidate == namespace);
    if matches.len() > 1 && !exact {
        let suggestions: Vec<&str> = matches.iter().map(|m| m.as_str()).collect();
        let message = format!(
            "The namespace \"{namespace}\" is ambiguous.\nDid you mean one of these?\n    {}.",
            suggestions.join("\n    ")
        );
        return Err(LookupError::Namespace { message });
    }
    Ok(if exact {
        namespace.to_string()
    } else {
        matches[0].clone()
    })
}

/// The canonical command name for `name`, or the 2.x lookup error.
pub fn find(name: &str, terminal_width: usize) -> Result<String, LookupError> {
    if let Some(entry) = catalog::entry(name) {
        return Ok(entry.name.clone());
    }

    let all = all_names();
    let mut matches: Vec<String> = all
        .iter()
        .filter(|c| abbreviates(name, c, false, false))
        .cloned()
        .collect();
    if matches.is_empty() {
        matches = all
            .iter()
            .filter(|c| abbreviates(name, c, true, false))
            .cloned()
            .collect();
    }

    if matches.is_empty() || !matches.iter().any(|c| abbreviates(name, c, true, true)) {
        if let Some(position) = name.rfind(':') {
            find_namespace(&name[..position])?;
        }
        let visible: Vec<String> = alternatives(name, &all)
            .into_iter()
            .filter(|candidate| !canonical(candidate).hidden)
            .collect();
        let message = format!(
            "Command \"{name}\" is not defined.{}",
            did_you_mean(&visible)
        );
        return Err(LookupError::NotFound {
            message,
            alternatives: visible,
        });
    }

    // Drop aliases whose command is already listed, then collapse aliases
    // of a single command.
    if matches.len() > 1 {
        let listed = matches.clone();
        matches.retain(|candidate| {
            let command = &canonical(candidate).name;
            command == candidate || !listed.contains(command)
        });
        matches.dedup();
    }
    let mut commands: Vec<&str> = matches.iter().map(|m| canonical(m).name.as_str()).collect();
    commands.dedup();
    if commands.len() == 1 {
        matches.truncate(1);
    }

    if matches.len() > 1 {
        let usable = terminal_width.saturating_sub(10);
        let longest = matches.iter().map(|m| m.chars().count()).max().unwrap_or(0);
        let visible: Vec<String> = matches
            .iter()
            .filter(|m| !canonical(m).hidden)
            .map(|m| {
                let line = format!("{m:longest$} {}", canonical(m).description);
                if line.chars().count() > usable {
                    format!(
                        "{}...",
                        line.chars()
                            .take(usable.saturating_sub(3))
                            .collect::<String>()
                    )
                } else {
                    line
                }
            })
            .collect();
        if visible.len() > 1 {
            let message = format!(
                "Command \"{name}\" is ambiguous.\nDid you mean one of these?\n    {}.",
                visible.join("\n    ")
            );
            return Err(LookupError::Ambiguous { message });
        }
        matches.retain(|m| !canonical(m).hidden);
    }

    let entry = canonical(&matches[0]);
    if entry.hidden {
        return Err(LookupError::NotFound {
            message: format!("The command \"{name}\" does not exist."),
            alternatives: vec![],
        });
    }
    Ok(entry.name.clone())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn exact_names_aliases_and_abbreviations_resolve() {
        assert_eq!(find("env:list", 80).unwrap(), "env:list");
        assert_eq!(find("cluster:info", 80).unwrap(), "server:info");
        assert_eq!(find("server:healt", 80).unwrap(), "server:health");
        assert_eq!(find("e:li", 80).unwrap(), "env:list");
    }

    #[test]
    fn unknown_names_get_the_2x_messages() {
        assert_eq!(
            find("nope", 80).unwrap_err().message(),
            "Command \"nope\" is not defined."
        );
        assert_eq!(
            find("nope:cmd", 80).unwrap_err().message(),
            "There are no commands defined in the \"nope\" namespace."
        );
        assert_eq!(
            find("srver:health", 80).unwrap_err().message(),
            "There are no commands defined in the \"srver\" namespace.\n\nDid you mean this?\n    server"
        );
        assert_eq!(
            find("envlist", 80).unwrap_err(),
            LookupError::NotFound {
                message: "Command \"envlist\" is not defined.\n\nDid you mean this?\n    env:list"
                    .into(),
                alternatives: vec!["env:list".into()],
            }
        );
        assert!(find("env:lst", 80)
            .unwrap_err()
            .message()
            .starts_with("Command \"env:lst\" is not defined.\n\nDid you mean one of these?\n    activities:list\n    activity:list\n"));
    }

    #[test]
    fn ambiguous_abbreviations_list_the_candidates() {
        let message = find("env:s", 80).unwrap_err().message().to_string();
        assert!(message.starts_with(
            "Command \"env:s\" is ambiguous.\nDid you mean one of these?\n    env:set "
        ));
        assert!(message.ends_with('.'));
    }

    #[test]
    fn php_helpers_match() {
        assert_eq!(levenshtein("envlist", "env:list"), 1);
        assert_eq!(
            natural_case_cmp("Activity:list", "activities:list"),
            Ordering::Greater
        );
        assert_eq!(natural_case_cmp("a2", "a10"), Ordering::Less);
    }
}
