//! The PHP value semantics the 2.x CLI applies to decoded JSON
//! (`json_decode($body, true)`), so messages that interpolate Server values
//! come out byte-identical.
//!
//! PHP decodes JSON objects and arrays into the same array type, turns
//! object keys that are canonical integers into integer keys, and decodes
//! integers beyond the 64-bit range as floats.

use serde_json::{Number, Value};

/// Characters PHP's `trim()` strips by default.
const TRIM_CHARS: &[char] = &[' ', '\t', '\n', '\r', '\0', '\x0B'];

/// PHP `trim($value)`.
pub fn trim(value: &str) -> &str {
    value.trim_matches(TRIM_CHARS)
}

/// The integer a PHP array key string becomes, if it is a canonical decimal
/// integer such as `0`, `12` or `-3` that fits in 64 bits.
pub fn int_key(key: &str) -> Option<i64> {
    let digits = key.strip_prefix('-').unwrap_or(key);
    let canonical = match digits.as_bytes() {
        [b'0'] => !key.starts_with('-'),
        [first, rest @ ..] => (b'1'..=b'9').contains(first) && rest.iter().all(u8::is_ascii_digit),
        [] => false,
    };
    if canonical {
        key.parse().ok()
    } else {
        None
    }
}

/// `$value[$key] ?? null`: `None` for a missing key, a null value, or a
/// value that is not an array.
pub fn get<'a>(value: &'a Value, key: &str) -> Option<&'a Value> {
    raw_get(value, key).filter(|item| !item.is_null())
}

/// `$value[$key]` for a key that exists, including a null value.
pub fn raw_get<'a>(value: &'a Value, key: &str) -> Option<&'a Value> {
    match value {
        Value::Object(map) => map.get(key),
        Value::Array(items) => int_key(key)
            .and_then(|index| usize::try_from(index).ok())
            .and_then(|index| items.get(index)),
        _ => None,
    }
}

/// `is_array($value) ? $value[$key] ?? null : null`, where the parent is
/// itself an optional lookup result.
pub fn array_get<'a>(value: Option<&'a Value>, key: &str) -> Option<&'a Value> {
    value
        .filter(|value| is_array(value))
        .and_then(|value| get(value, key))
}

/// PHP `array_key_exists($key, $value)` for an array `$value`.
pub fn has_key(value: &Value, key: &str) -> bool {
    raw_get(value, key).is_some()
}

/// PHP `is_array()`: true for decoded JSON objects and arrays.
pub fn is_array(value: &Value) -> bool {
    matches!(value, Value::Object(_) | Value::Array(_))
}

/// `is_array()` on an optional lookup result.
pub fn is_array_opt(value: Option<&Value>) -> bool {
    value.is_some_and(is_array)
}

/// PHP `is_scalar()`: strings, numbers and booleans, not null.
pub fn is_scalar(value: Option<&Value>) -> bool {
    matches!(
        value,
        Some(Value::String(_) | Value::Number(_) | Value::Bool(_))
    )
}

/// PHP `is_int()`.
pub fn is_int(value: Option<&Value>) -> bool {
    matches!(value, Some(Value::Number(number)) if number.is_i64())
}

/// PHP `is_string()` returning the string.
pub fn as_str(value: Option<&Value>) -> Option<&str> {
    match value {
        Some(Value::String(text)) => Some(text),
        _ => None,
    }
}

/// PHP `array_is_list()` for an array value.
pub fn is_list(value: &Value) -> bool {
    match value {
        Value::Array(_) => true,
        Value::Object(map) => map
            .keys()
            .enumerate()
            .all(|(index, key)| int_key(key) == i64::try_from(index).ok()),
        _ => false,
    }
}

/// The values of an array in order, as `foreach ($value as $item)` sees them.
pub fn values(value: &Value) -> Vec<&Value> {
    match value {
        Value::Object(map) => map.values().collect(),
        Value::Array(items) => items.iter().collect(),
        _ => Vec::new(),
    }
}

/// A decoded PHP array after a string-key assignment. Numeric list keys
/// keep their order alongside the appended string fields.
pub fn array_map(value: Value) -> serde_json::Map<String, Value> {
    match value {
        Value::Object(map) => map,
        Value::Array(items) => items
            .into_iter()
            .enumerate()
            .map(|(index, value)| (index.to_string(), value))
            .collect(),
        _ => serde_json::Map::new(),
    }
}

/// PHP `ctype_digit()` for a string argument.
pub fn ctype_digit(value: &str) -> bool {
    !value.is_empty() && value.bytes().all(|byte| byte.is_ascii_digit())
}

/// `is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v === $expected : false`,
/// the version check every 2.x manifest comparison uses.
pub fn version_matches(value: Option<&Value>, expected: i64) -> bool {
    let digits = as_str(value).is_some_and(ctype_digit);
    (is_int(value) || digits) && int_cast(value) == expected
}

/// `is_scalar($v) ? (string) $v : 'missing'`.
pub fn scalar_or_missing(value: Option<&Value>) -> String {
    if is_scalar(value) {
        to_string(value)
    } else {
        "missing".to_string()
    }
}

/// PHP `(string) $value`. Arrays become `Array`, as PHP does (with a notice).
pub fn to_string(value: Option<&Value>) -> String {
    match value {
        None | Some(Value::Null) => String::new(),
        Some(Value::Bool(true)) => "1".to_string(),
        Some(Value::Bool(false)) => String::new(),
        Some(Value::Number(number)) => number_to_string(number),
        Some(Value::String(text)) => text.clone(),
        Some(Value::Array(_) | Value::Object(_)) => "Array".to_string(),
    }
}

fn number_to_string(number: &Number) -> String {
    match number.as_i64() {
        Some(int) => int.to_string(),
        None => float_to_string(number.as_f64().unwrap_or(0.0)),
    }
}

/// PHP `(string) $float` with the default `precision=14`.
pub fn float_to_string(value: f64) -> String {
    let (digits, decpt) = rounded_digits(value, 14);
    gcvt(&digits, decpt, value.is_sign_negative(), 14, 'E')
}

/// A float as PHP `json_encode()` writes it (`serialize_precision=-1`).
fn float_to_json(value: f64) -> String {
    let (digits, decpt) = shortest_digits(value);
    gcvt(&digits, decpt, value.is_sign_negative(), 17, 'e')
}

/// Shortest round-trip digits and decimal-point position, like
/// `zend_dtoa(value, 0, ...)`. Rust's shortest formatter can pick either of
/// two equally short candidates when the value lies exactly halfway between
/// them; PHP takes the correctly rounded one, ties to even. So keep the
/// shortest length and use the correctly rounded digits at that length.
fn shortest_digits(value: f64) -> (String, i32) {
    let magnitude = value.abs();
    let (digits, decpt) = split_exponent(&format!("{magnitude:e}"));
    let rounded = format!("{:.*e}", digits.len().saturating_sub(1), magnitude);
    if rounded.parse::<f64>().ok() == Some(magnitude) {
        split_exponent(&rounded)
    } else {
        (digits, decpt)
    }
}

/// Digits rounded to `precision` significant places with trailing zeros
/// removed, like `zend_dtoa(value, 2, precision, ...)`.
fn rounded_digits(value: f64, precision: usize) -> (String, i32) {
    let formatted = format!("{:.*e}", precision - 1, value.abs());
    let (digits, decpt) = split_exponent(&formatted);
    let trimmed = digits.trim_end_matches('0');
    if trimmed.is_empty() {
        ("0".to_string(), decpt)
    } else {
        (trimmed.to_string(), decpt)
    }
}

fn split_exponent(formatted: &str) -> (String, i32) {
    let (mantissa, exponent) = formatted.split_once('e').unwrap_or((formatted, "0"));
    let digits: String = mantissa.chars().filter(char::is_ascii_digit).collect();
    let exponent: i32 = exponent.parse().unwrap_or(0);
    (digits, exponent + 1)
}

/// Port of `zend_gcvt`: lays out `0.digits × 10^decpt` in fixed or
/// exponential notation.
fn gcvt(digits: &str, decpt: i32, negative: bool, ndigit: i32, exp_char: char) -> String {
    let mut out = String::new();
    if negative {
        out.push('-');
    }
    if (decpt >= 0 && decpt > ndigit) || decpt < -3 {
        push_exponential(&mut out, digits, decpt - 1, exp_char);
    } else if decpt < 0 {
        out.push_str("0.");
        out.extend(std::iter::repeat_n('0', decpt.unsigned_abs() as usize));
        out.push_str(digits);
    } else {
        push_fixed(&mut out, digits, decpt.unsigned_abs() as usize);
    }
    out
}

fn push_exponential(out: &mut String, digits: &str, exponent: i32, exp_char: char) {
    let (first, rest) = digits.split_at(1);
    out.push_str(first);
    out.push('.');
    out.push_str(if rest.is_empty() { "0" } else { rest });
    out.push(exp_char);
    out.push(if exponent < 0 { '-' } else { '+' });
    out.push_str(&exponent.unsigned_abs().to_string());
}

fn push_fixed(out: &mut String, digits: &str, decpt: usize) {
    let integer_len = decpt.min(digits.len());
    out.push_str(&digits[..integer_len]);
    out.extend(std::iter::repeat_n('0', decpt - integer_len));
    let fraction = &digits[integer_len..];
    if !fraction.is_empty() {
        if integer_len == 0 {
            out.push('0');
        }
        out.push('.');
        out.push_str(fraction);
    }
}

/// PHP `(int) $value`.
pub fn int_cast(value: Option<&Value>) -> i64 {
    match value {
        None | Some(Value::Null) | Some(Value::Bool(false)) => 0,
        Some(Value::Bool(true)) => 1,
        Some(Value::Number(number)) => match number.as_i64() {
            Some(int) => int,
            None => float_to_int_modular(number.as_f64().unwrap_or(0.0)),
        },
        Some(Value::String(text)) => string_to_int(text),
        Some(Value::Array(items)) => i64::from(!items.is_empty()),
        Some(Value::Object(map)) => i64::from(!map.is_empty()),
    }
}

/// `zend_dval_to_lval`: out-of-range floats wrap modulo 2^64.
fn float_to_int_modular(value: f64) -> i64 {
    const TWO_POW_63: f64 = (1u64 << 63) as f64;
    const TWO_POW_64: f64 = 2.0 * TWO_POW_63;
    if !value.is_finite() {
        return 0;
    }
    if (-TWO_POW_63..TWO_POW_63).contains(&value) {
        return value as i64;
    }
    let mut wrapped = value % TWO_POW_64;
    if wrapped < 0.0 {
        wrapped += TWO_POW_64;
    }
    if wrapped >= TWO_POW_63 {
        wrapped -= TWO_POW_64;
    }
    wrapped as i64
}

/// `(int) "string"`: the leading numeric prefix, with floats and overflow
/// saturating (`zend_dval_to_lval_cap`).
pub fn string_to_int(text: &str) -> i64 {
    let text = text.trim_start_matches([' ', '\t', '\n', '\r', '\x0B', '\x0C']);
    let prefix = numeric_prefix(text);
    if prefix.is_empty() {
        return 0;
    }
    if let Ok(int) = prefix.parse::<i64>() {
        return int;
    }
    // Rust's saturating float-to-int cast matches the capped conversion.
    prefix
        .parse::<f64>()
        .map_or(0, |float| if float.is_finite() { float as i64 } else { 0 })
}

/// The longest prefix PHP's `is_numeric_string` accepts:
/// `[+-]?(digits[.digits]|.digits)([eE][+-]?digits)?`.
fn numeric_prefix(text: &str) -> &str {
    let bytes = text.as_bytes();
    let mut end = usize::from(matches!(bytes.first(), Some(b'+' | b'-')));
    let integer_digits = count_digits(&bytes[end..]);
    end += integer_digits;
    let mut fraction_digits = 0;
    if bytes.get(end) == Some(&b'.') {
        fraction_digits = count_digits(&bytes[end + 1..]);
        if integer_digits > 0 || fraction_digits > 0 {
            end += 1 + fraction_digits;
        }
    }
    if integer_digits == 0 && fraction_digits == 0 {
        return "";
    }
    end += exponent_len(&bytes[end..]);
    &text[..end]
}

fn exponent_len(bytes: &[u8]) -> usize {
    if !matches!(bytes.first(), Some(b'e' | b'E')) {
        return 0;
    }
    let sign = usize::from(matches!(bytes.get(1), Some(b'+' | b'-')));
    let digits = count_digits(&bytes[1 + sign..]);
    if digits == 0 {
        0
    } else {
        1 + sign + digits
    }
}

fn count_digits(bytes: &[u8]) -> usize {
    bytes
        .iter()
        .take_while(|byte| byte.is_ascii_digit())
        .count()
}

/// `(int) $digits` for a string of ASCII digits, saturating like PHP.
pub fn digits_to_int(digits: &str) -> i64 {
    string_to_int(digits)
}

/// PHP `json_encode($value, JSON_UNESCAPED_SLASHES)` of a decoded value.
pub fn json_encode(value: &Value) -> String {
    let mut out = String::new();
    encode_value(value, &mut out);
    out
}

fn encode_value(value: &Value, out: &mut String) {
    match value {
        Value::Null => out.push_str("null"),
        Value::Bool(flag) => out.push_str(if *flag { "true" } else { "false" }),
        Value::Number(number) => match number.as_i64() {
            Some(int) => out.push_str(&int.to_string()),
            None => out.push_str(&float_to_json(number.as_f64().unwrap_or(0.0))),
        },
        Value::String(text) => encode_string(text, out),
        Value::Array(items) => encode_list(items.iter(), out),
        Value::Object(map) if is_list(value) => encode_list(map.values(), out),
        Value::Object(map) => {
            out.push('{');
            for (index, (key, item)) in map.iter().enumerate() {
                if index > 0 {
                    out.push(',');
                }
                encode_string(key, out);
                out.push(':');
                encode_value(item, out);
            }
            out.push('}');
        }
    }
}

/// PHP `json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)` of a
/// decoded value: four-space indent, `"key": value`, and empty arrays (which
/// an empty JSON object decodes to) printed as `[]`.
pub fn json_encode_pretty(value: &Value) -> String {
    let mut out = String::new();
    encode_pretty(value, 0, &mut out);
    out
}

fn encode_pretty(value: &Value, depth: usize, out: &mut String) {
    let (entries, list): (Vec<(Option<&str>, &Value)>, bool) = match value {
        Value::Array(items) => (items.iter().map(|item| (None, item)).collect(), true),
        Value::Object(map) if is_list(value) => {
            (map.values().map(|item| (None, item)).collect(), true)
        }
        Value::Object(map) => (
            map.iter()
                .map(|(key, item)| (Some(key.as_str()), item))
                .collect(),
            false,
        ),
        scalar => return encode_value(scalar, out),
    };
    let (open, close) = if list { ('[', ']') } else { ('{', '}') };
    out.push(open);
    if entries.is_empty() {
        out.push(close);
        return;
    }
    let indent = "    ".repeat(depth + 1);
    for (index, (key, item)) in entries.iter().enumerate() {
        out.push_str(if index > 0 { ",\n" } else { "\n" });
        out.push_str(&indent);
        if let Some(key) = key {
            encode_string(key, out);
            out.push_str(": ");
        }
        encode_pretty(item, depth + 1, out);
    }
    out.push('\n');
    out.push_str(&"    ".repeat(depth));
    out.push(close);
}

fn encode_list<'a>(items: impl Iterator<Item = &'a Value>, out: &mut String) {
    out.push('[');
    for (index, item) in items.enumerate() {
        if index > 0 {
            out.push(',');
        }
        encode_value(item, out);
    }
    out.push(']');
}

/// String escaping of `php_json_escape_string` without
/// `JSON_UNESCAPED_UNICODE`: non-ASCII becomes lowercase `\uXXXX` UTF-16.
fn encode_string(text: &str, out: &mut String) {
    out.push('"');
    for character in text.chars() {
        match character {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            '\u{8}' => out.push_str("\\b"),
            '\u{c}' => out.push_str("\\f"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            ' '..='\u{7f}' => out.push(character),
            _ => {
                let mut units = [0u16; 2];
                for unit in character.encode_utf16(&mut units) {
                    out.push_str(&format!("\\u{unit:04x}"));
                }
            }
        }
    }
    out.push('"');
}

/// An array key after PHP's JSON decoding.
#[derive(Debug, PartialEq, Eq)]
pub enum Key<'a> {
    Int(i64),
    Str(&'a str),
}

impl Key<'_> {
    /// `(string) $key`.
    pub fn text(&self) -> String {
        match self {
            Key::Int(number) => number.to_string(),
            Key::Str(name) => (*name).to_string(),
        }
    }
}

/// `foreach ($value as $key => $item)` over a decoded array.
pub fn entries(value: &Value) -> Vec<(Key<'_>, &Value)> {
    match value {
        Value::Array(items) => items
            .iter()
            .enumerate()
            .map(|(index, item)| (Key::Int(index as i64), item))
            .collect(),
        Value::Object(map) => map
            .iter()
            .map(|(name, item)| (int_key(name).map_or(Key::Str(name), Key::Int), item))
            .collect(),
        _ => vec![],
    }
}

/// `is_array($value) && $value !== []`.
pub fn non_empty_array(value: &Value) -> bool {
    match value {
        Value::Array(items) => !items.is_empty(),
        Value::Object(map) => !map.is_empty(),
        _ => false,
    }
}

/// `($value ?? $default)` concatenated into text.
pub fn or(value: Option<&Value>, default: &str) -> String {
    match value {
        None | Some(Value::Null) => default.to_string(),
        some => to_string(some),
    }
}

/// The 2.x `stringValue()`: trimmed non-empty strings and numbers.
pub fn string_value(value: Option<&Value>) -> Option<String> {
    match value? {
        Value::String(text) => Some(trim(text).to_string()).filter(|text| !text.is_empty()),
        number @ Value::Number(_) => Some(to_string(Some(number))),
        _ => None,
    }
}

/// The 2.x `stringList()`.
pub fn string_list(value: Option<&Value>) -> Vec<String> {
    value
        .filter(|value| is_array(value))
        .map(|value| {
            values(value)
                .into_iter()
                .filter_map(|item| string_value(Some(item)))
                .collect()
        })
        .unwrap_or_default()
}

/// The 2.x `stringKeyList()`: string keys that are not blank.
pub fn string_key_list(value: Option<&Value>) -> Vec<String> {
    value
        .map(|value| {
            entries(value)
                .into_iter()
                .filter_map(|(key, _)| match key {
                    Key::Str(name) if !trim(name).is_empty() => Some(name.to_string()),
                    _ => None,
                })
                .collect()
        })
        .unwrap_or_default()
}

/// The 2.x `formatOptionalBool()`.
pub fn optional_bool(value: Option<&Value>) -> &'static str {
    match value {
        Some(Value::Bool(true)) => "yes",
        Some(Value::Bool(false)) => "no",
        _ => "unknown",
    }
}

/// PHP `number_format($int)`: thousands separated by commas.
pub fn number_format(number: i64) -> String {
    let digits = number.unsigned_abs().to_string();
    let mut grouped = String::new();
    for (index, digit) in digits.chars().enumerate() {
        if index > 0 && (digits.len() - index) % 3 == 0 {
            grouped.push(',');
        }
        grouped.push(digit);
    }
    if number < 0 {
        format!("-{grouped}")
    } else {
        grouped
    }
}

/// PHP `ucfirst()`.
pub fn ucfirst(text: &str) -> String {
    let mut chars = text.chars();
    match chars.next() {
        Some(first) => first.to_ascii_uppercase().to_string() + chars.as_str(),
        None => String::new(),
    }
}

#[cfg(test)]
mod float_tests {
    use super::*;

    #[test]
    fn halfway_values_round_to_even_like_php() {
        // 1149865714285714.2 is stored as ...714.25: .2 and .3 both round-trip.
        let value: Value = serde_json::from_str("[1149865714285714.2]").unwrap();
        assert_eq!(json_encode(&value), "[1149865714285714.2]");
        let value: Value = serde_json::from_str("[1.4820142857142857, 0.1, 1e300]").unwrap();
        assert_eq!(json_encode(&value), "[1.4820142857142857,0.1,1.0e+300]");
    }
}

#[cfg(test)]
mod render_helper_tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn integer_like_object_keys_become_integers() {
        assert!(is_list(&json!({"0": "a", "1": "b"})));
        assert!(!is_list(&json!({"1": "a"})));
        assert_eq!(
            string_key_list(Some(&json!({"7": 1, "name": 2, " ": 3, "07": 4}))),
            vec!["name", "07"]
        );
    }

    #[test]
    fn rendering_helpers_follow_php() {
        assert_eq!(or(Some(&Value::Null), "unknown"), "unknown");
        assert_eq!(or(Some(&json!(true)), "unknown"), "1");
        assert_eq!(string_value(Some(&json!("  x "))), Some("x".into()));
        assert_eq!(string_value(Some(&json!(true))), None);
        assert_eq!(number_format(1234567), "1,234,567");
        assert_eq!(number_format(-1000), "-1,000");
        assert_eq!(number_format(999), "999");
        assert_eq!(ucfirst("max_payload_bytes"), "Max_payload_bytes");
    }
}
