//! The 2.x payload options shared by commands that send workflow input:
//! `--input`, `--input-file` (`-` for stdin) and `--input-encoding`
//! (`json`, `raw` or `base64`), with 2.x's decoding and error messages.

use crate::cli::Input;
use crate::error::CliError;
use serde_json::Value;

const ENCODINGS: [&str; 3] = ["json", "raw", "base64"];

/// 2.x `parseInputOption()`: the caller's payload, or `None` when neither
/// option carries a value.
pub fn parse(input: &Input) -> Result<Option<Value>, CliError> {
    let Some((bytes, source)) = source(input)? else {
        // 2.x validates the encoding only when there is input to decode.
        return Ok(None);
    };
    let value = match encoding(input)? {
        "json" => json_decode(&bytes)
            .map_err(|message| invalid(format!("{source} must be valid JSON: {message}")))?,
        "raw" => text(bytes)?,
        _ => {
            let decoded = base64_decode(&bytes).ok_or_else(|| {
                invalid(format!(
                    "{source} must be valid base64 when --input-encoding=base64."
                ))
            })?;
            text(decoded)?
        }
    };
    Ok(Some(value))
}

/// 2.x `parseInputArgumentsOption()`: workflow argument lists. A scalar or
/// text payload becomes the one positional argument.
pub fn parse_arguments(input: &Input) -> Result<Option<Value>, CliError> {
    Ok(parse(input)?.map(|value| match value {
        Value::Array(_) | Value::Object(_) => value,
        scalar => Value::Array(vec![scalar]),
    }))
}

fn invalid(message: String) -> CliError {
    CliError::Invalid(message)
}

/// Payload text must be UTF-8 to travel in the JSON request body. 2.x fails
/// the same input when encoding the request.
fn text(bytes: Vec<u8>) -> Result<Value, CliError> {
    String::from_utf8(bytes).map(Value::String).map_err(|_| {
        CliError::Failure(
            "Invalid value for \"json\" option: Malformed UTF-8 characters, possibly incorrectly encoded."
                .into(),
        )
    })
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
        (Some(_), Some(_)) => Err(invalid(
            "Use either --input or --input-file, not both.".into(),
        )),
        (_, Some(path)) => Ok(Some((read_file(path)?, "--input-file"))),
        (Some(inline), None) => Ok(Some((inline.as_bytes().to_vec(), "--input"))),
        (None, None) => Ok(None),
    }
}

fn read_file(path: &str) -> Result<Vec<u8>, CliError> {
    use std::io::Read;
    if path == "-" {
        let mut contents = Vec::new();
        std::io::stdin()
            .read_to_end(&mut contents)
            .map_err(|_| invalid("--input-file could not read from stdin.".into()))?;
        return Ok(contents);
    }
    let readable = std::fs::metadata(path).is_ok_and(|metadata| metadata.is_file())
        && std::fs::File::open(path).is_ok();
    if !readable {
        return Err(invalid(format!(
            "--input-file must be a readable file: {path}"
        )));
    }
    std::fs::read(path).map_err(|_| invalid(format!("--input-file could not be read: {path}")))
}

fn encoding(input: &Input) -> Result<&str, CliError> {
    let encoding = input.opt("input-encoding").flatten().unwrap_or("json");
    if ENCODINGS.contains(&encoding) {
        Ok(encoding)
    } else {
        Err(invalid(format!(
            "--input-encoding must be one of: {}",
            ENCODINGS.join(", ")
        )))
    }
}

/// PHP `json_decode($text, true, 512, JSON_THROW_ON_ERROR)`, with PHP's
/// error messages. Integers beyond 64-bit signed range become floats, as in
/// PHP. Nesting is limited to serde_json's 128 levels, below PHP's 512.
pub fn json_decode(bytes: &[u8]) -> Result<Value, String> {
    match serde_json::from_slice::<Value>(bytes) {
        Ok(value) => Ok(php_numbers(value)),
        Err(error) => Err(json_error(bytes, &error)),
    }
}

fn json_error(bytes: &[u8], error: &serde_json::Error) -> String {
    let message = error.to_string();
    if message.starts_with("recursion limit exceeded") {
        return "Nesting deeper than 128 levels is not supported".into();
    }
    if message.contains("surrogate") || high_surrogate_before(bytes, error) {
        return "Single unpaired UTF-16 surrogate in unicode escape".into();
    }
    if message.starts_with("control character") || control_at(bytes, error) {
        return "Control character error, possibly incorrectly encoded".into();
    }
    if message.starts_with("invalid unicode code point") && std::str::from_utf8(bytes).is_err() {
        return "Malformed UTF-8 characters, possibly incorrectly encoded".into();
    }
    if message.starts_with("invalid unicode code point") {
        return "Single unpaired UTF-16 surrogate in unicode escape".into();
    }
    "Syntax error".into()
}

/// serde_json reports a high surrogate escape that no low surrogate follows
/// (`"\ud800"`) as an unexpected end of the hex escape.
fn high_surrogate_before(bytes: &[u8], error: &serde_json::Error) -> bool {
    let Some(offset) = offset(bytes, error) else {
        return false;
    };
    offset >= 6
        && offset <= bytes.len()
        && bytes[offset - 6..offset - 3].eq_ignore_ascii_case(b"\\ud")
        && matches!(bytes[offset - 3].to_ascii_lowercase(), b'8'..=b'9' | b'a'..=b'b')
}

/// The byte offset of serde_json's 1-based line and column.
fn offset(bytes: &[u8], error: &serde_json::Error) -> Option<usize> {
    let (line, column) = (error.line(), error.column());
    if line == 0 || column == 0 {
        return None;
    }
    let mut current = 1;
    let mut start = 0;
    for (index, byte) in bytes.iter().enumerate() {
        if current == line {
            break;
        }
        if *byte == b'\n' {
            current += 1;
            start = index + 1;
        }
    }
    Some(start + column - 1)
}

/// PHP reports a control character where its scanner stops, even outside a
/// string: `1\0` is a control character error, `1 x` a syntax error.
fn control_at(bytes: &[u8], error: &serde_json::Error) -> bool {
    offset(bytes, error)
        .and_then(|offset| bytes.get(offset))
        .is_some_and(|byte| *byte < 0x20 && !matches!(byte, b'\t' | b'\n' | b'\r'))
}

fn php_numbers(value: Value) -> Value {
    match value {
        Value::Number(number) if number.is_u64() && number.as_i64().is_none() => {
            serde_json::Number::from_f64(number.as_f64().unwrap_or(0.0))
                .map(Value::Number)
                .unwrap_or(Value::Null)
        }
        Value::Array(items) => Value::Array(items.into_iter().map(php_numbers).collect()),
        Value::Object(map) => Value::Object(
            map.into_iter()
                .map(|(key, item)| (key, php_numbers(item)))
                .collect(),
        ),
        other => other,
    }
}

/// PHP `base64_decode(trim($text), true)`: standard alphabet, whitespace
/// skipped, optional but well-formed padding, nothing after padding.
pub fn base64_decode(bytes: &[u8]) -> Option<Vec<u8>> {
    let trimmed = trim(bytes);
    let mut out = Vec::with_capacity(trimmed.len() * 3 / 4);
    let (mut count, mut padding, mut accumulator) = (0usize, 0usize, 0u32);
    for byte in trimmed {
        let value = match byte {
            b'A'..=b'Z' => byte - b'A',
            b'a'..=b'z' => byte - b'a' + 26,
            b'0'..=b'9' => byte - b'0' + 52,
            b'+' => 62,
            b'/' => 63,
            b'=' => {
                padding += 1;
                continue;
            }
            b'\t' | b'\n' | b'\r' | b' ' => continue,
            _ => return None,
        };
        if padding > 0 {
            return None;
        }
        accumulator = (accumulator << 6) | u32::from(value);
        count += 1;
        if count % 4 == 0 {
            out.extend_from_slice(&[
                (accumulator >> 16) as u8,
                (accumulator >> 8) as u8,
                accumulator as u8,
            ]);
            accumulator = 0;
        }
    }
    match count % 4 {
        1 => return None,
        2 => out.push((accumulator >> 4) as u8),
        3 => out.extend_from_slice(&[(accumulator >> 10) as u8, (accumulator >> 2) as u8]),
        _ => {}
    }
    if padding > 0 && (padding > 2 || (count + padding) % 4 != 0) {
        return None;
    }
    Some(out)
}

/// PHP `trim()`: space, tab, newline, carriage return, NUL and vertical tab.
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

#[cfg(test)]
mod tests {
    use super::*;

    fn vectors() -> Value {
        serde_json::from_str(include_str!("input/php-vectors.json")).unwrap()
    }

    fn base64(text: &str) -> Vec<u8> {
        base64_decode(text.as_bytes()).unwrap()
    }

    #[test]
    fn json_decode_matches_php() {
        for case in vectors()["json"].as_array().unwrap() {
            let bytes = base64(case["in"].as_str().unwrap());
            let shown = String::from_utf8_lossy(&bytes)
                .chars()
                .take(40)
                .collect::<String>();
            let actual = json_decode(&bytes);
            match (&case["ok"], &case["err"]) {
                // PHP decodes 1E400 to INF, which no JSON document can carry.
                (Value::Bool(false), _) => assert!(actual.is_err(), "{shown}"),
                // serde_json reads -0 as the float -0.0; PHP as the integer 0.
                (Value::String(_), _) if bytes == b"-0" => {
                    assert_eq!(
                        actual.map(|value| crate::php::json_encode(&value)),
                        Ok("-0".into())
                    );
                }
                (Value::String(_), _) if bytes.len() > 256 => {
                    // Between this build's 128 levels and PHP's 512.
                    assert_eq!(
                        actual,
                        Err("Nesting deeper than 128 levels is not supported".into()),
                        "{shown}"
                    );
                }
                (Value::String(expected), _) => {
                    let expected: Value = serde_json::from_str(expected).unwrap();
                    // Compared as 2.x re-encodes them: {} and [] are both PHP arrays.
                    assert_eq!(
                        actual.map(|value| crate::php::json_encode(&value)),
                        Ok(crate::php::json_encode(&expected)),
                        "{shown}"
                    );
                }
                (_, Value::String(message)) if bytes.len() > 1000 => {
                    // PHP's 512-level limit; this build stops at 128.
                    assert_eq!(message, "Maximum stack depth exceeded");
                    assert!(actual.is_err(), "{shown}");
                }
                (_, Value::String(message)) => {
                    assert_eq!(actual, Err(message.clone()), "{shown}")
                }
                other => panic!("bad vector {other:?}"),
            }
        }
    }

    #[test]
    fn base64_decode_matches_php_strict_mode() {
        for case in vectors()["base64"].as_array().unwrap() {
            let input = case["in"].as_str().unwrap();
            let expected = case["out"].as_str().map(base64);
            assert_eq!(base64_decode(input.as_bytes()), expected, "{input:?}");
        }
    }
}
