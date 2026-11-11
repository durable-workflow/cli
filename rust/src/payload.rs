//! The 2.x `--input`, `--input-file` and `--input-encoding` options, and the
//! limits PHP's JSON encoder puts on the request body that carries them.

use crate::cli::Input;
use crate::error::CliError;
use crate::php_json;
use serde_json::Value;
use std::io::Read;

/// The deepest body nesting PHP's encoder sends.
const MAX_ENCODE_NESTING: usize = 512;

const MALFORMED_UTF8: &str = "Malformed UTF-8 characters, possibly incorrectly encoded";
const NON_FINITE: &str = "Inf and NaN cannot be JSON encoded";

/// A payload the caller supplied.
#[derive(Debug)]
pub struct Payload {
    pub value: Value,
    /// Why PHP could decode this value but not encode it into a request.
    unencodable: Option<&'static str>,
}

impl Payload {
    /// JSON `null`, for options 2.x sends as given even when null.
    pub fn null() -> Self {
        Payload {
            value: Value::Null,
            unencodable: None,
        }
    }

    fn text(bytes: Vec<u8>) -> Self {
        match String::from_utf8(bytes) {
            Ok(text) => Payload {
                value: Value::String(text),
                unencodable: None,
            },
            Err(error) => Payload {
                value: Value::String(String::from_utf8_lossy(error.as_bytes()).into_owned()),
                unencodable: Some(MALFORMED_UTF8),
            },
        }
    }
}

/// The 2.x `parseInputOption()`: the decoded payload, or `None` when no
/// input was given. The encoding is only checked when there is input.
pub fn parse(input: &Input) -> Result<Option<Payload>, CliError> {
    let Some((bytes, source)) = source(input)? else {
        return Ok(None);
    };
    let encoding = input.opt("input-encoding").flatten().unwrap_or("json");
    match encoding {
        "json" => {
            let decoded = php_json::decode(&bytes).map_err(|error| {
                CliError::Invalid(format!("{source} must be valid JSON: {}", error.message()))
            })?;
            // A JSON `null` is no input at all, as in 2.x. An infinite
            // number is held as null too, but it is input PHP cannot send.
            if decoded.value.is_null() && !decoded.non_finite {
                return Ok(None);
            }
            Ok(Some(Payload {
                value: decoded.value,
                unencodable: decoded.non_finite.then_some(NON_FINITE),
            }))
        }
        "raw" => Ok(Some(Payload::text(bytes))),
        "base64" => base64_decode(trim(&bytes))
            .map(|decoded| Some(Payload::text(decoded)))
            .ok_or_else(|| {
                CliError::Invalid(format!(
                    "{source} must be valid base64 when --input-encoding=base64."
                ))
            }),
        _ => Err(CliError::Invalid(
            "--input-encoding must be one of: json, raw, base64".into(),
        )),
    }
}

/// The 2.x `parseJsonOption()`: an option whose value is a JSON document,
/// such as `--memo`. Absent or empty means no value; so does JSON `null`.
pub fn parse_json_option(value: Option<&str>, option: &str) -> Result<Option<Payload>, CliError> {
    let Some(value) = value.filter(|value| !value.is_empty()) else {
        return Ok(None);
    };
    let decoded = php_json::decode(value.as_bytes()).map_err(|error| {
        CliError::Invalid(format!(
            "--{option} must be valid JSON: {}",
            error.message()
        ))
    })?;
    if decoded.value.is_null() && !decoded.non_finite {
        return Ok(None);
    }
    Ok(Some(Payload {
        value: decoded.value,
        unencodable: decoded.non_finite.then_some(NON_FINITE),
    }))
}

/// The 2.x `parseInputArgumentsOption()`: a workflow argument list. A value
/// that is not an array becomes the only argument.
pub fn parse_arguments(input: &Input) -> Result<Option<Payload>, CliError> {
    Ok(parse(input)?.map(|mut payload| {
        if !crate::php::is_array(&payload.value) {
            payload.value = Value::Array(vec![payload.value]);
        }
        payload
    }))
}

fn source(input: &Input) -> Result<Option<(Vec<u8>, &'static str)>, CliError> {
    let inline = input
        .opt("input")
        .flatten()
        .filter(|value| !value.is_empty());
    let file = input
        .opt("input-file")
        .flatten()
        .filter(|value| !value.is_empty());
    match (inline, file) {
        (Some(_), Some(_)) => Err(CliError::Invalid(
            "Use either --input or --input-file, not both.".into(),
        )),
        (_, Some(path)) => Ok(Some((read_file(path)?, "--input-file"))),
        (Some(inline), None) => Ok(Some((inline.as_bytes().to_vec(), "--input"))),
        (None, None) => Ok(None),
    }
}

fn read_file(path: &str) -> Result<Vec<u8>, CliError> {
    let mut contents = Vec::new();
    if path == "-" {
        return std::io::stdin()
            .read_to_end(&mut contents)
            .map(|_| contents)
            .map_err(|_| CliError::Invalid("--input-file could not read from stdin.".into()));
    }
    let unreadable = || CliError::Invalid(format!("--input-file must be a readable file: {path}"));
    if !std::fs::metadata(path).is_ok_and(|metadata| metadata.is_file()) {
        return Err(unreadable());
    }
    let mut file = std::fs::File::open(path).map_err(|_| unreadable())?;
    file.read_to_end(&mut contents)
        .map_err(|_| CliError::Invalid(format!("--input-file could not be read: {path}")))?;
    Ok(contents)
}

/// PHP `trim()` of bytes.
fn trim(bytes: &[u8]) -> &[u8] {
    let blank = |byte: &u8| matches!(byte, b' ' | b'\t' | b'\n' | b'\r' | 0 | 0x0b);
    let start = bytes
        .iter()
        .position(|byte| !blank(byte))
        .unwrap_or(bytes.len());
    let end = bytes
        .iter()
        .rposition(|byte| !blank(byte))
        .map_or(start, |end| end + 1);
    &bytes[start..end]
}

/// PHP `base64_decode($value, true)`: tabs, newlines, carriage returns and
/// spaces are skipped; padding is optional but must be complete when given,
/// and nothing may follow it.
fn base64_decode(text: &[u8]) -> Option<Vec<u8>> {
    let mut out = Vec::new();
    let (mut accumulator, mut count, mut padding) = (0u32, 0usize, 0usize);
    for &byte in text {
        let sextet = match byte {
            b'=' => {
                padding += 1;
                continue;
            }
            b'\t' | b'\n' | b'\r' | b' ' => continue,
            b'A'..=b'Z' => byte - b'A',
            b'a'..=b'z' => byte - b'a' + 26,
            b'0'..=b'9' => byte - b'0' + 52,
            b'+' => 62,
            b'/' => 63,
            _ => return None,
        };
        if padding > 0 {
            return None;
        }
        accumulator = (accumulator << 6) | u32::from(sextet);
        count += 1;
        if count % 4 == 0 {
            out.extend_from_slice(&accumulator.to_be_bytes()[1..]);
            accumulator = 0;
        }
    }
    match count % 4 {
        1 => return None,
        2 => out.push((accumulator >> 4) as u8),
        3 => out.extend_from_slice(&((accumulator >> 2) as u16).to_be_bytes()),
        _ => {}
    }
    if padding > 0 && (padding > 2 || (count + padding) % 4 != 0) {
        return None;
    }
    Some(out)
}

/// The request body PHP sends, or the refusal its JSON encoder raises while
/// sending it, reported as 2.x reports a request that never left: after the
/// compatibility preflight, as a transport failure.
pub fn request_body(body: Value, payloads: &[Option<&Payload>]) -> Result<Value, CliError> {
    let refusal = if php_json::nesting(&body) > MAX_ENCODE_NESTING {
        Some("Maximum stack depth exceeded")
    } else {
        payloads
            .iter()
            .flatten()
            .find_map(|payload| payload.unencodable)
    };
    match refusal {
        Some(reason) => Err(CliError::Network(format!(
            "Server unreachable: Invalid value for \"json\" option: {reason}"
        ))),
        None => Ok(php_json::php_array_shape(body)),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn decoded(text: &str) -> Option<String> {
        base64_decode(text.as_bytes()).map(|bytes| String::from_utf8_lossy(&bytes).into_owned())
    }

    #[test]
    fn base64_follows_php_strict_decoding() {
        for (text, expected) in [
            ("YWJj", Some("abc")),
            ("YWJ", Some("ab")),
            ("YWI=", Some("ab")),
            ("YQ", Some("a")),
            ("YW Jj", Some("abc")),
            ("YW\tJj\n", Some("abc")),
            ("", Some("")),
            ("Y", None),
            ("YW=j", None),
            ("YWI==", None),
            ("YQ=", None),
            ("YQ===", None),
            ("YWJj====", None),
            ("YW\x0bJj", None),
            ("YW_j", None),
            ("YWI=YWI=", None),
        ] {
            assert_eq!(decoded(text).as_deref(), expected, "{text:?}");
        }
    }

    #[test]
    fn request_body_reports_what_php_cannot_encode() {
        let deep = (0..511).fold(Value::Array(vec![]), |inner, _| Value::Array(vec![inner]));
        let body = serde_json::json!({"action": {"input": deep}});
        let error = request_body(body, &[]).unwrap_err();
        assert_eq!(error.exit_code(), 3);
        assert_eq!(
            error.message(),
            "Server unreachable: Invalid value for \"json\" option: Maximum stack depth exceeded"
        );
        let binary = Payload::text(vec![0xff]);
        assert!(request_body(serde_json::json!({}), &[Some(&binary)])
            .unwrap_err()
            .message()
            .ends_with(MALFORMED_UTF8));
        assert_eq!(
            request_body(serde_json::json!({"a": {}}), &[None]).unwrap(),
            serde_json::json!({"a": []})
        );
    }
}
