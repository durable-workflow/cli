//! PHP's `json_decode($text, true, 512, JSON_THROW_ON_ERROR)` for values
//! the caller supplies, such as `--input`, with PHP's error messages and
//! the order in which PHP reports them.
//!
//! Errors are reported in input order, as PHP's scanner and parser meet
//! them: a container nested more than 511 deep, a control character, a
//! malformed UTF-8 sequence, an unpaired UTF-16 surrogate escape, or any
//! other syntax error. Integers outside the 64-bit range decode as floats;
//! a float that overflows decodes as infinity, which PHP accepts here but
//! cannot encode again, so the result records it.

use serde_json::{Map, Number, Value};

/// The deepest container nesting PHP decodes with its default depth.
const MAX_NESTING: usize = 511;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum DecodeError {
    Depth,
    CtrlChar,
    Syntax,
    Utf8,
    Utf16,
}

impl DecodeError {
    /// `json_last_error_msg()`.
    pub fn message(self) -> &'static str {
        match self {
            DecodeError::Depth => "Maximum stack depth exceeded",
            DecodeError::CtrlChar => "Control character error, possibly incorrectly encoded",
            DecodeError::Syntax => "Syntax error",
            DecodeError::Utf8 => "Malformed UTF-8 characters, possibly incorrectly encoded",
            DecodeError::Utf16 => "Single unpaired UTF-16 surrogate in unicode escape",
        }
    }
}

#[derive(Debug, PartialEq)]
pub struct Decoded {
    /// The decoded value. An infinite float is stored as `null`.
    pub value: Value,
    /// True when a number overflowed to infinity.
    pub non_finite: bool,
}

#[derive(Debug, PartialEq)]
enum Token {
    BeginObject,
    EndObject,
    BeginArray,
    EndArray,
    Colon,
    Comma,
    String(String),
    Number(Value),
    Literal(Value),
    End,
}

struct Parser<'a> {
    bytes: &'a [u8],
    position: usize,
    depth: usize,
    non_finite: bool,
}

/// A container being decoded: an array, or an object with the key of the
/// member whose value comes next.
enum Frame {
    Array(Vec<Value>),
    Object(Map<String, Value>, String),
}

/// Decodes without recursion, so the deepest document PHP accepts needs no
/// more stack than a shallow one.
pub fn decode(bytes: &[u8]) -> Result<Decoded, DecodeError> {
    let mut parser = Parser {
        bytes,
        position: 0,
        depth: 0,
        non_finite: false,
    };
    let mut stack: Vec<Frame> = Vec::new();
    let mut token = parser.token()?;
    let value = 'value: loop {
        let mut value = match token {
            Token::BeginArray => {
                parser.enter()?;
                let next = parser.token()?;
                if next != Token::EndArray {
                    stack.push(Frame::Array(Vec::new()));
                    token = next;
                    continue;
                }
                parser.depth -= 1;
                Value::Array(Vec::new())
            }
            Token::BeginObject => {
                parser.enter()?;
                let next = parser.token()?;
                if next != Token::EndObject {
                    let key = parser.member_key(next)?;
                    stack.push(Frame::Object(Map::new(), key));
                    token = parser.token()?;
                    continue;
                }
                parser.depth -= 1;
                Value::Object(Map::new())
            }
            Token::String(text) => Value::String(text),
            Token::Number(number) => number,
            Token::Literal(literal) => literal,
            _ => return Err(DecodeError::Syntax),
        };
        // Add the finished value to its container, closing every container
        // that ends with it.
        loop {
            match stack.last_mut() {
                None => break 'value value,
                Some(Frame::Array(items)) => {
                    items.push(value);
                    match parser.token()? {
                        Token::Comma => {
                            token = parser.token()?;
                            continue 'value;
                        }
                        Token::EndArray => {}
                        _ => return Err(DecodeError::Syntax),
                    }
                }
                Some(Frame::Object(map, key)) => {
                    // The last duplicate wins, in the first one's place.
                    map.insert(std::mem::take(key), value);
                    match parser.token()? {
                        Token::Comma => {
                            let next = parser.token()?;
                            *key = parser.member_key(next)?;
                            token = parser.token()?;
                            continue 'value;
                        }
                        Token::EndObject => {}
                        _ => return Err(DecodeError::Syntax),
                    }
                }
            }
            parser.depth -= 1;
            value = match stack.pop().expect("an open container") {
                Frame::Array(items) => Value::Array(items),
                Frame::Object(map, _) => Value::Object(map),
            };
        }
    };
    match parser.token()? {
        Token::End => Ok(Decoded {
            value,
            non_finite: parser.non_finite,
        }),
        _ => Err(DecodeError::Syntax),
    }
}

impl Parser<'_> {
    /// A member name and the colon after it.
    fn member_key(&mut self, token: Token) -> Result<String, DecodeError> {
        let Token::String(key) = token else {
            return Err(DecodeError::Syntax);
        };
        if self.token()? != Token::Colon {
            return Err(DecodeError::Syntax);
        }
        Ok(key)
    }

    /// PHP checks the depth as it opens a container, before the next token.
    fn enter(&mut self) -> Result<(), DecodeError> {
        self.depth += 1;
        if self.depth > MAX_NESTING {
            return Err(DecodeError::Depth);
        }
        Ok(())
    }

    fn token(&mut self) -> Result<Token, DecodeError> {
        while let Some(b' ' | b'\t' | b'\n' | b'\r') = self.bytes.get(self.position) {
            self.position += 1;
        }
        let Some(&byte) = self.bytes.get(self.position) else {
            return Ok(Token::End);
        };
        let single = match byte {
            b'{' => Some(Token::BeginObject),
            b'}' => Some(Token::EndObject),
            b'[' => Some(Token::BeginArray),
            b']' => Some(Token::EndArray),
            b':' => Some(Token::Colon),
            b',' => Some(Token::Comma),
            _ => None,
        };
        if let Some(token) = single {
            self.position += 1;
            return Ok(token);
        }
        match byte {
            b'"' => {
                self.position += 1;
                self.string().map(Token::String)
            }
            b'-' | b'0'..=b'9' => self.number(),
            b't' | b'f' | b'n' => {
                for (word, literal) in [
                    (&b"true"[..], Value::Bool(true)),
                    (&b"false"[..], Value::Bool(false)),
                    (&b"null"[..], Value::Null),
                ] {
                    if self.bytes[self.position..].starts_with(word) {
                        self.position += word.len();
                        return Ok(Token::Literal(literal));
                    }
                }
                Err(DecodeError::Syntax)
            }
            0x00..=0x1f => Err(DecodeError::CtrlChar),
            0x80..=0xff => match utf8_len(&self.bytes[self.position..]) {
                Some(_) => Err(DecodeError::Syntax),
                None => Err(DecodeError::Utf8),
            },
            _ => Err(DecodeError::Syntax),
        }
    }

    /// The longest `-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?` match.
    /// A `-` with no digit after it is not a number.
    fn number(&mut self) -> Result<Token, DecodeError> {
        let bytes = self.bytes;
        let start = self.position;
        let mut end = start + usize::from(bytes[start] == b'-');
        let digits = |from: usize| {
            bytes[from..]
                .iter()
                .take_while(|b| b.is_ascii_digit())
                .count()
        };
        match bytes.get(end) {
            Some(b'0') => end += 1,
            Some(b'1'..=b'9') => end += digits(end),
            _ => return Err(DecodeError::Syntax),
        }
        let mut float = false;
        if bytes.get(end) == Some(&b'.') && digits(end + 1) > 0 {
            end += 1 + digits(end + 1);
            float = true;
        }
        if let Some(b'e' | b'E') = bytes.get(end) {
            let sign = usize::from(matches!(bytes.get(end + 1), Some(b'+' | b'-')));
            let exponent = digits(end + 1 + sign);
            if exponent > 0 {
                end += 1 + sign + exponent;
                float = true;
            }
        }
        self.position = end;
        let text = std::str::from_utf8(&bytes[start..end]).expect("ASCII number");
        if !float {
            if let Ok(int) = text.parse::<i64>() {
                return Ok(Token::Number(Value::from(int)));
            }
        }
        let parsed: f64 = text.parse().expect("valid JSON number");
        Ok(Token::Number(match Number::from_f64(parsed) {
            Some(number) => Value::Number(number),
            None => {
                self.non_finite = true;
                Value::Null
            }
        }))
    }

    fn string(&mut self) -> Result<String, DecodeError> {
        let mut out = Vec::new();
        loop {
            let Some(&byte) = self.bytes.get(self.position) else {
                // PHP meets the terminating NUL of an unterminated string.
                return Err(DecodeError::CtrlChar);
            };
            match byte {
                b'"' => {
                    self.position += 1;
                    return Ok(String::from_utf8(out).expect("validated UTF-8"));
                }
                b'\\' => self.escape(&mut out)?,
                0x00..=0x1f => return Err(DecodeError::CtrlChar),
                0x80..=0xff => {
                    let length = utf8_len(&self.bytes[self.position..]).ok_or(DecodeError::Utf8)?;
                    out.extend_from_slice(&self.bytes[self.position..self.position + length]);
                    self.position += length;
                }
                _ => {
                    out.push(byte);
                    self.position += 1;
                }
            }
        }
    }

    fn escape(&mut self, out: &mut Vec<u8>) -> Result<(), DecodeError> {
        let simple = match self.bytes.get(self.position + 1) {
            Some(b'"') => Some(b'"'),
            Some(b'\\') => Some(b'\\'),
            Some(b'/') => Some(b'/'),
            Some(b'b') => Some(0x08),
            Some(b'f') => Some(0x0c),
            Some(b'n') => Some(b'\n'),
            Some(b'r') => Some(b'\r'),
            Some(b't') => Some(b'\t'),
            _ => None,
        };
        if let Some(byte) = simple {
            out.push(byte);
            self.position += 2;
            return Ok(());
        }
        let unit = self
            .unicode_escape(self.position)
            .ok_or(DecodeError::Syntax)?;
        let character = match unit {
            0xd800..=0xdbff => {
                let low = self
                    .unicode_escape(self.position + 6)
                    .filter(|low| (0xdc00..=0xdfff).contains(low))
                    .ok_or(DecodeError::Utf16)?;
                self.position += 6;
                0x10000 + ((u32::from(unit) - 0xd800) << 10) + (u32::from(low) - 0xdc00)
            }
            0xdc00..=0xdfff => return Err(DecodeError::Utf16),
            _ => u32::from(unit),
        };
        self.position += 6;
        let character = char::from_u32(character).expect("valid scalar value");
        out.extend_from_slice(character.encode_utf8(&mut [0; 4]).as_bytes());
        Ok(())
    }

    /// The code unit of a `\uXXXX` escape at `at`.
    fn unicode_escape(&self, at: usize) -> Option<u16> {
        let escape = self.bytes.get(at..at + 6)?;
        if &escape[..2] != b"\\u" {
            return None;
        }
        let hex = std::str::from_utf8(&escape[2..]).ok()?;
        if !hex.bytes().all(|byte| byte.is_ascii_hexdigit()) {
            return None;
        }
        u16::from_str_radix(hex, 16).ok()
    }
}

/// The length of the well-formed UTF-8 sequence at the start of `bytes`.
fn utf8_len(bytes: &[u8]) -> Option<usize> {
    let length = match bytes.first()? {
        0x00..=0x7f => 1,
        0xc2..=0xdf => 2,
        0xe0..=0xef => 3,
        0xf0..=0xf4 => 4,
        _ => return None,
    };
    std::str::from_utf8(bytes.get(..length)?).ok()?;
    Some(length)
}

/// The deepest container nesting of a value, counting the value itself.
pub fn nesting(value: &Value) -> usize {
    match value {
        Value::Array(items) => 1 + items.iter().map(nesting).max().unwrap_or(0),
        Value::Object(map) => 1 + map.values().map(nesting).max().unwrap_or(0),
        _ => 0,
    }
}

/// A decoded value as `json_encode` sends it: PHP arrays whose keys are
/// `0..n` in order (including empty ones) become JSON lists.
pub fn php_array_shape(value: Value) -> Value {
    match value {
        Value::Array(items) => Value::Array(items.into_iter().map(php_array_shape).collect()),
        Value::Object(map) => {
            if is_list_map(&map) {
                Value::Array(
                    map.into_iter()
                        .map(|(_, item)| php_array_shape(item))
                        .collect(),
                )
            } else {
                Value::Object(
                    map.into_iter()
                        .map(|(key, item)| (key, php_array_shape(item)))
                        .collect(),
                )
            }
        }
        scalar => scalar,
    }
}

fn is_list_map(map: &Map<String, Value>) -> bool {
    map.keys()
        .enumerate()
        .all(|(index, key)| crate::php::int_key(key) == i64::try_from(index).ok())
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn ok(text: &[u8]) -> Value {
        decode(text).unwrap().value
    }

    fn err(text: &[u8]) -> DecodeError {
        decode(text).unwrap_err()
    }

    #[test]
    fn decodes_documents_like_php() {
        assert_eq!(
            ok(b" {\"a\":[1,2.5,\"x\"],\"b\":null} \n"),
            json!({"a":[1,2.5,"x"],"b":null})
        );
        assert_eq!(ok(b"\"\\u00e9\\ud83d\\ude00\\/\""), json!("é😀/"));
        assert_eq!(ok(b"{\"a\":1,\"b\":2,\"a\":3}"), json!({"a":3,"b":2}));
        assert_eq!(
            ok(b"[-0, 9223372036854775807, -9223372036854775808]"),
            json!([0, i64::MAX, i64::MIN])
        );
        assert_eq!(ok(b"9223372036854775808"), json!(9223372036854775808.0));
        assert_eq!(ok(b"[1e5, 1E-2, 0e0]"), json!([100000.0, 0.01, 0.0]));
        assert_eq!(ok(b"{\"\\u0000a\":1}"), json!({"\u{0}a":1}));
        let infinite = decode(b"[1e400]").unwrap();
        assert!(infinite.non_finite);
        assert_eq!(infinite.value, json!([null]));
    }

    #[test]
    fn reports_php_errors_in_input_order() {
        for (text, error) in [
            (&b""[..], DecodeError::Syntax),
            (b"TRUE", DecodeError::Syntax),
            (b"\xef\xbb\xbf[1]", DecodeError::Syntax),
            (b"[1]\xc3\xa9", DecodeError::Syntax),
            (b"[1]\xff", DecodeError::Utf8),
            (b"\x0c[1]", DecodeError::CtrlChar),
            (b"[1]\x00", DecodeError::CtrlChar),
            (b"\"abc", DecodeError::CtrlChar),
            (b"[1", DecodeError::Syntax),
            (b"{\"a\":1,}", DecodeError::Syntax),
            (b"01", DecodeError::Syntax),
            (b"1.", DecodeError::Syntax),
            (b"+1", DecodeError::Syntax),
            (b"\"\\x\"", DecodeError::Syntax),
            (b"\"\\u12\"", DecodeError::Syntax),
            (b"\"\\uZZZZ\"", DecodeError::Syntax),
            (b"\"\\ud800\\u0041\"", DecodeError::Utf16),
            (b"\"\\udc00\"", DecodeError::Utf16),
            (b"[\"\x01\xff\"]", DecodeError::CtrlChar),
            (b"[\"\xff\x01\"]", DecodeError::Utf8),
            (b"\"\xed\xa0\x80\"", DecodeError::Utf8),
            (b"\"\xc0\xaf\"", DecodeError::Utf8),
        ] {
            assert_eq!(err(text), error, "{}", String::from_utf8_lossy(text));
        }
    }

    #[test]
    fn nesting_limit_matches_php_and_precedes_the_next_token() {
        let nested = |depth: usize, inner: &[u8]| {
            let mut text = vec![b'['; depth];
            text.extend_from_slice(inner);
            text
        };
        let mut closed = nested(511, b"");
        closed.extend(vec![b']'; 511]);
        assert_eq!(nesting(&ok(&closed)), 511);
        assert_eq!(err(&nested(512, b"")), DecodeError::Depth);
        assert_eq!(err(&nested(512, b"\x01")), DecodeError::Depth);
        assert_eq!(err(&nested(600, b"\xff")), DecodeError::Depth);
    }

    #[test]
    fn php_array_shape_turns_sequential_keys_into_lists() {
        assert_eq!(
            php_array_shape(json!({"a":{}, "b":{"0":"x","1":"y"}, "c":{"1":"x","0":"y"}})),
            json!({"a":[], "b":["x","y"], "c":{"1":"x","0":"y"}})
        );
        assert_eq!(php_array_shape(json!({})), json!([]));
    }
}
