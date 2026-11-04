//! Version handling of the 2.x CLI: `matchesVersionConstraint` (the
//! Server-advertised `supported_versions` window), PHP's `version_compare`
//! it falls back to for prereleases, and worker-protocol `major.minor`
//! compatibility.

use crate::php;

/// Whitespace PCRE's `\s` matches.
const PCRE_SPACE: &[char] = &[' ', '\t', '\n', '\x0B', '\x0C', '\r'];

/// `CompatibilityDiagnostics::matchesVersionConstraint`: `||` separates
/// alternatives, `,` joins requirements that must all hold.
pub fn matches_constraint(version: &str, constraint: &str) -> bool {
    let Some(parts) = version_parts(version) else {
        return false;
    };
    alternatives(php::trim(constraint))
        .into_iter()
        .filter(|alternative| !alternative.is_empty())
        .any(|alternative| alternative_matches(version, parts, alternative))
}

/// `preg_split('/\s*\|\|\s*/', $constraint)`.
fn alternatives(constraint: &str) -> Vec<&str> {
    let pieces: Vec<&str> = constraint.split("||").collect();
    let last = pieces.len() - 1;
    pieces
        .iter()
        .enumerate()
        .map(|(index, piece)| {
            let piece = if index > 0 {
                piece.trim_start_matches(PCRE_SPACE)
            } else {
                piece
            };
            if index < last {
                piece.trim_end_matches(PCRE_SPACE)
            } else {
                piece
            }
        })
        .collect()
}

fn alternative_matches(version: &str, parts: [i64; 3], alternative: &str) -> bool {
    let requirements: Vec<&str> = alternative
        .split(',')
        .map(php::trim)
        .filter(|part| !part.is_empty())
        .collect();
    !requirements.is_empty()
        && requirements
            .iter()
            .all(|requirement| single_requirement_matches(version, parts, requirement))
}

fn single_requirement_matches(version: &str, parts: [i64; 3], requirement: &str) -> bool {
    if requirement == "*" || requirement == "x" {
        return true;
    }
    if let Some(prefix) = wildcard_prefix(requirement) {
        return prefix.iter().zip(parts).all(|(want, have)| *want == have);
    }
    let Some(parsed) = Requirement::parse(requirement) else {
        return false;
    };
    let normalized = php::trim(version).trim_start_matches('v');
    if !parsed.prerelease.is_empty() || normalized.contains('-') {
        let required = format!(
            "{}.{}.{}{}",
            parsed.parts[0], parsed.parts[1], parsed.parts[2], parsed.prerelease
        );
        return operator_holds(parsed.operator, php_version_compare(normalized, &required));
    }
    operator_holds(parsed.operator, ordering_to_int(parts.cmp(&parsed.parts)))
}

fn operator_holds(operator: &str, comparison: i32) -> bool {
    match operator {
        "<" => comparison < 0,
        "<=" => comparison <= 0,
        ">" => comparison > 0,
        ">=" => comparison >= 0,
        _ => comparison == 0,
    }
}

fn ordering_to_int(ordering: std::cmp::Ordering) -> i32 {
    ordering as i32
}

/// `^v?(\d+)\.(\d+)\.(?:x|\*)$` or `^v?(\d+)\.(?:x|\*)$`: the numeric
/// components that must match exactly.
fn wildcard_prefix(requirement: &str) -> Option<Vec<i64>> {
    let body = requirement.strip_prefix('v').unwrap_or(requirement);
    let body = body
        .strip_suffix(".x")
        .or_else(|| body.strip_suffix(".*"))?;
    let components: Vec<&str> = body.split('.').collect();
    let valid = components.len() <= 2
        && components.iter().all(|component| {
            !component.is_empty() && component.bytes().all(|b| b.is_ascii_digit())
        });
    valid.then(|| {
        components
            .iter()
            .map(|digits| php::digits_to_int(digits))
            .collect()
    })
}

/// `^(<=|>=|<|>|=)?\s*v?(\d+)(?:\.(\d+))?(?:\.(\d+))?((?:-[0-9A-Za-z][0-9A-Za-z.-]*)?)$`
struct Requirement<'a> {
    operator: &'a str,
    parts: [i64; 3],
    prerelease: &'a str,
}

impl<'a> Requirement<'a> {
    fn parse(requirement: &'a str) -> Option<Self> {
        let operator = ["<=", ">=", "<", ">", "="]
            .into_iter()
            .find(|operator| requirement.starts_with(operator))
            .unwrap_or("");
        let rest = requirement[operator.len()..].trim_start_matches(PCRE_SPACE);
        let rest = rest.strip_prefix('v').unwrap_or(rest);
        let (parts, rest) = numeric_parts(rest)?;
        if !is_prerelease(rest) {
            return None;
        }
        Some(Self {
            operator: if operator.is_empty() { "=" } else { operator },
            parts,
            prerelease: rest,
        })
    }
}

/// `(\d+)(?:\.(\d+))?(?:\.(\d+))?`, missing components defaulting to 0.
fn numeric_parts(text: &str) -> Option<([i64; 3], &str)> {
    let mut parts = [0i64; 3];
    let mut rest = text;
    for (index, slot) in parts.iter_mut().enumerate() {
        let body = if index == 0 {
            rest
        } else {
            match rest.strip_prefix('.') {
                Some(body) if body.starts_with(|c: char| c.is_ascii_digit()) => body,
                _ => break,
            }
        };
        let len = body.bytes().take_while(u8::is_ascii_digit).count();
        if len == 0 {
            return None;
        }
        *slot = php::digits_to_int(&body[..len]);
        rest = &body[len..];
    }
    Some((parts, rest))
}

/// `(?:-[0-9A-Za-z][0-9A-Za-z.-]*)?` spanning the whole remainder.
fn is_prerelease(text: &str) -> bool {
    let Some(body) = text.strip_prefix('-') else {
        return text.is_empty();
    };
    let mut chars = body.chars();
    chars.next().is_some_and(|c| c.is_ascii_alphanumeric())
        && chars.all(|c| c.is_ascii_alphanumeric() || c == '.' || c == '-')
}

/// `^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?` as a prefix of the trimmed version.
fn version_parts(version: &str) -> Option<[i64; 3]> {
    let trimmed = php::trim(version);
    let body = trimmed.strip_prefix('v').unwrap_or(trimmed);
    numeric_parts(body).map(|(parts, _)| parts)
}

/// Port of PHP's `version_compare($a, $b)`, returning -1, 0 or 1.
pub fn php_version_compare(left: &str, right: &str) -> i32 {
    compare_bytes(until_nul(left.as_bytes()), until_nul(right.as_bytes()))
}

/// C strings end at the first NUL byte.
fn until_nul(bytes: &[u8]) -> &[u8] {
    let end = bytes.iter().position(|b| *b == 0).unwrap_or(bytes.len());
    &bytes[..end]
}

fn compare_bytes(left: &[u8], right: &[u8]) -> i32 {
    if left.is_empty() || right.is_empty() {
        return i32::from(!left.is_empty()) - i32::from(!right.is_empty());
    }
    let left = canonical_or_raw(left);
    let right = canonical_or_raw(right);
    compare_canonical(&left, &right)
}

fn canonical_or_raw(version: &[u8]) -> Vec<u8> {
    if version[0] == b'#' {
        version.to_vec()
    } else {
        canonicalize(version)
    }
}

/// `php_canonicalize_version`: `-`, `_`, `+` and other non-alphanumerics
/// become `.`, and a `.` separates digit/non-digit transitions.
fn canonicalize(version: &[u8]) -> Vec<u8> {
    let is_digit = |byte: u8| byte.is_ascii_digit();
    let is_non_digit = |byte: u8| !byte.is_ascii_digit() && byte != b'.';
    let mut out = vec![version[0]];
    let mut previous = version[0];
    for &byte in &version[1..] {
        let last = out[out.len() - 1];
        let special = matches!(byte, b'-' | b'_' | b'+');
        let transition = !special
            && ((is_non_digit(previous) && is_digit(byte))
                || (is_digit(previous) && is_non_digit(byte)));
        let alphanumeric = byte.is_ascii_alphanumeric();
        if (special || transition || !alphanumeric) && last != b'.' {
            out.push(b'.');
        }
        if !special && (transition || alphanumeric) {
            out.push(byte);
        }
        previous = byte;
    }
    // PHP drops an empty last component ("1." compares as "1").
    if out.last() == Some(&b'.') {
        out.pop();
    }
    out
}

/// The comparison loop of `php_version_compare` over canonical versions.
fn compare_canonical(left: &[u8], right: &[u8]) -> i32 {
    let mut cursor = (0usize, 0usize);
    let mut more = (true, true);
    while cursor.0 < left.len() && cursor.1 < right.len() && more.0 && more.1 {
        let (left_part, left_next) = part_at(left, cursor.0);
        let (right_part, right_next) = part_at(right, cursor.1);
        more = (left_next.is_some(), right_next.is_some());
        let comparison = compare_parts(left_part, right_part);
        if comparison != 0 {
            return comparison;
        }
        cursor = (
            left_next.unwrap_or(cursor.0),
            right_next.unwrap_or(cursor.1),
        );
    }
    if more.0 {
        trailing_compare(&left[cursor.0..], 1, |rest| compare_bytes(rest, b"#N#"))
    } else if more.1 {
        trailing_compare(&right[cursor.1..], -1, |rest| compare_bytes(b"#N#", rest))
    } else {
        0
    }
}

/// When one version has components left: a numeric one decides the result,
/// otherwise the rest is compared against a number (`#N#`).
fn trailing_compare(rest: &[u8], numeric_result: i32, compare: impl Fn(&[u8]) -> i32) -> i32 {
    if rest.first().is_some_and(u8::is_ascii_digit) {
        numeric_result
    } else {
        compare(rest)
    }
}

/// The part starting at `start` and the start of the next part, if a `.`
/// follows.
fn part_at(version: &[u8], start: usize) -> (&[u8], Option<usize>) {
    match version[start..].iter().position(|b| *b == b'.') {
        Some(offset) => (&version[start..start + offset], Some(start + offset + 1)),
        None => (&version[start..], None),
    }
}

fn compare_parts(left: &[u8], right: &[u8]) -> i32 {
    let left_numeric = left.first().is_some_and(u8::is_ascii_digit);
    let right_numeric = right.first().is_some_and(u8::is_ascii_digit);
    match (left_numeric, right_numeric) {
        (true, true) => ordering_to_int(strtol(left).cmp(&strtol(right))),
        (false, false) => compare_special_forms(left, right),
        (true, false) => compare_special_forms(b"#N#", right),
        (false, true) => compare_special_forms(left, b"#N#"),
    }
}

/// `strtol` of a part that starts with digits, saturating.
fn strtol(part: &[u8]) -> i64 {
    let len = part.iter().take_while(|b| b.is_ascii_digit()).count();
    std::str::from_utf8(&part[..len])
        .ok()
        .and_then(|digits| digits.parse().ok())
        .unwrap_or(i64::MAX)
}

/// `compare_special_version_forms`: prefix match against
/// dev < alpha = a < beta = b < RC = rc < # < pl = p; anything else sorts
/// below dev.
fn compare_special_forms(left: &[u8], right: &[u8]) -> i32 {
    ordering_to_int(special_form_order(left).cmp(&special_form_order(right)))
}

fn special_form_order(form: &[u8]) -> i32 {
    const FORMS: [(&[u8], i32); 10] = [
        (b"dev", 0),
        (b"alpha", 1),
        (b"a", 1),
        (b"beta", 2),
        (b"b", 2),
        (b"RC", 3),
        (b"rc", 3),
        (b"#", 4),
        (b"pl", 5),
        (b"p", 5),
    ];
    FORMS
        .iter()
        .find(|(name, _)| form.starts_with(name))
        .map_or(-1, |(_, order)| *order)
}

/// `ServerClient::isCompatibleWorkerProtocolVersion`: same major, and the
/// Server minor at least the client's. Non `major.minor` values must match
/// exactly.
pub fn is_compatible_worker_protocol(client: &str, server: &str) -> bool {
    match (split_major_minor(client), split_major_minor(server)) {
        (Some(client), Some(server)) => client.0 == server.0 && client.1 <= server.1,
        _ => client == server,
    }
}

/// `^\d+\.\d+$` on the trimmed value.
fn split_major_minor(value: &str) -> Option<(i64, i64)> {
    let (major, minor) = php::trim(value).split_once('.')?;
    let digits = |text: &str| !text.is_empty() && text.bytes().all(|b| b.is_ascii_digit());
    (digits(major) && digits(minor)).then(|| (php::digits_to_int(major), php::digits_to_int(minor)))
}
