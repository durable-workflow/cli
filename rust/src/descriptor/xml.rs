//! Symfony's XmlDescriptor, serialized the way PHP's DOMDocument does with
//! `formatOutput = true`. The 2.x standalone binary cannot produce XML (its
//! PHP build has no DOM extension); the 2.x PHAR on a full PHP can, and this
//! output matches that.

use super::{ApplicationView, ArgumentView, CommandView, OptionView};
use crate::{build_info, lookup};
use serde_json::Value;

/// A minimal DOM: elements with attributes, and either text or children.
struct Element {
    name: &'static str,
    attributes: Vec<(&'static str, String)>,
    text: Option<String>,
    children: Vec<Element>,
}

impl Element {
    fn new(name: &'static str) -> Self {
        Self {
            name,
            attributes: vec![],
            text: None,
            children: vec![],
        }
    }

    fn attr(mut self, name: &'static str, value: impl Into<String>) -> Self {
        self.attributes.push((name, value.into()));
        self
    }

    fn text(mut self, text: impl Into<String>) -> Self {
        let text = text.into();
        // createTextNode('') adds no visible content.
        if !text.is_empty() {
            self.text = Some(text);
        }
        self
    }

    fn child(mut self, child: Element) -> Self {
        self.children.push(child);
        self
    }

    fn write(&self, out: &mut String, depth: usize) {
        let indent = "  ".repeat(depth);
        out.push_str(&format!("{indent}<{}", self.name));
        for (name, value) in &self.attributes {
            out.push_str(&format!(" {name}=\"{}\"", escape_attribute(value)));
        }
        match (&self.text, self.children.is_empty()) {
            (Some(text), _) => out.push_str(&format!(">{}</{}>\n", escape_text(text), self.name)),
            (None, true) => out.push_str("/>\n"),
            (None, false) => {
                out.push_str(">\n");
                for child in &self.children {
                    child.write(out, depth + 1);
                }
                out.push_str(&format!("{indent}</{}>\n", self.name));
            }
        }
    }
}

fn escape_text(text: &str) -> String {
    text.replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('\r', "&#13;")
}

fn escape_attribute(text: &str) -> String {
    escape_text(text)
        .replace('"', "&quot;")
        .replace('\n', "&#10;")
        .replace('\t', "&#9;")
}

fn document(root: Element) -> String {
    let mut out = String::from("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
    root.write(&mut out, 0);
    out
}

fn flag(value: bool) -> &'static str {
    if value {
        "1"
    } else {
        "0"
    }
}

/// `is_array($d) ? $d : (is_bool($d) ? [var_export($d)] : ($d ? [$d] : []))`.
fn defaults(value: &Value) -> Vec<String> {
    match value {
        Value::Array(items) => items
            .iter()
            .map(|item| crate::php::to_string(Some(item)))
            .collect(),
        Value::Bool(flag) => vec![flag.to_string()],
        Value::Null => vec![],
        Value::String(text) if text.is_empty() || text == "0" => vec![],
        Value::Number(number) if number.as_f64() == Some(0.0) => vec![],
        other => vec![crate::php::to_string(Some(other))],
    }
}

fn defaults_element(value: &Value) -> Element {
    defaults(value)
        .into_iter()
        .fold(Element::new("defaults"), |element, default| {
            element.child(Element::new("default").text(default))
        })
}

fn argument_element(argument: &ArgumentView) -> Element {
    Element::new("argument")
        .attr("name", argument.name.clone())
        .attr("is_required", flag(argument.required))
        .attr("is_array", flag(argument.array))
        .child(Element::new("description").text(argument.description.clone()))
        .child(defaults_element(&argument.default))
}

fn option_elements(option: &OptionView) -> Vec<Element> {
    let mut element = Element::new("option").attr("name", format!("--{}", option.name));
    element = match option.shortcut.split_once('|') {
        Some((first, _)) => element.attr("shortcut", format!("-{first}")).attr(
            "shortcuts",
            format!("-{}", option.shortcut.replace('|', "|-")),
        ),
        None if option.shortcut.is_empty() => element.attr("shortcut", ""),
        None => element.attr("shortcut", format!("-{}", option.shortcut)),
    };
    element = element
        .attr("accept_value", flag(option.accept_value))
        .attr("is_value_required", flag(option.value_required))
        .attr("is_multiple", flag(option.multiple))
        .child(Element::new("description").text(option.description.clone()));
    if option.accept_value {
        element = element.child(defaults_element(&option.default));
    }
    let mut elements = vec![element];
    if option.negatable {
        elements.push(
            Element::new("option")
                .attr("name", format!("--no-{}", option.name))
                .attr("shortcut", "")
                .attr("accept_value", "0")
                .attr("is_value_required", "0")
                .attr("is_multiple", "0")
                .child(
                    Element::new("description")
                        .text(format!("Negate the \"--{}\" option", option.name)),
                ),
        );
    }
    elements
}

fn command_element(view: &CommandView, short: bool) -> Element {
    let entry = view.entry;
    let usages: Vec<String> = if short {
        entry.aliases.clone()
    } else {
        std::iter::once(view.synopsis().to_string())
            .chain(entry.aliases.iter().cloned())
            .collect()
    };
    let mut element = Element::new("command")
        .attr("id", entry.name.clone())
        .attr("name", entry.name.clone())
        .attr("hidden", flag(entry.hidden))
        .child(
            usages
                .into_iter()
                .fold(Element::new("usages"), |usages, usage| {
                    usages.child(Element::new("usage").text(usage))
                }),
        )
        .child(Element::new("description").text(entry.description.replace('\n', "\n ")));
    if !short {
        element = element.child(Element::new("help").text(view.help.replace('\n', "\n ")));
        let arguments = view
            .arguments
            .iter()
            .map(argument_element)
            .fold(Element::new("arguments"), Element::child);
        let options = view
            .options
            .iter()
            .flat_map(option_elements)
            .fold(Element::new("options"), Element::child);
        element = element.child(arguments).child(options);
    }
    element
}

pub fn command(view: &CommandView) -> String {
    document(command_element(view, false))
}

pub fn application(described: Option<&str>, short: bool) -> String {
    let resolved = described.and_then(|namespace| lookup::find_namespace(namespace).ok());
    let view = ApplicationView::new(resolved.as_deref(), true);
    let line = build_info::version_line();
    let version = line.strip_prefix("dw ").unwrap_or(&line).to_string();
    let mut commands = Element::new("commands");
    if let Some(namespace) = described {
        commands = commands.attr("namespace", namespace);
    }
    for entry in &view.commands {
        commands = commands.child(command_element(&CommandView::new(entry), short));
    }
    let mut root = Element::new("symfony")
        .attr("name", "dw")
        .attr("version", version)
        .child(commands);
    if described.is_none() {
        let namespaces =
            view.namespaces
                .iter()
                .fold(Element::new("namespaces"), |namespaces, ns| {
                    namespaces.child(ns.names.iter().fold(
                        Element::new("namespace").attr("id", ns.id.clone()),
                        |element, name| element.child(Element::new("command").text(name.clone())),
                    ))
                });
        root = root.child(namespaces);
    }
    document(root)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::catalog;

    #[test]
    fn command_xml_uses_dom_layout() {
        let text = command(&CommandView::new(catalog::entry("env:use").unwrap()));
        assert!(text.starts_with("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<command id=\"env:use\" name=\"env:use\" hidden=\"0\">\n  <usages>\n    <usage>env:use [--output OUTPUT] [--json] [--] &lt;name&gt;</usage>\n  </usages>\n"));
        assert!(text.contains("    <argument name=\"name\" is_required=\"1\" is_array=\"0\">\n      <description>Profile name to use by default</description>\n      <defaults/>\n    </argument>\n"));
        assert!(
            text.contains("<option name=\"--verbose\" shortcut=\"-v\" shortcuts=\"-v|-vv|-vvv\"")
        );
        assert!(text.contains("<option name=\"--no-ansi\" shortcut=\"\" accept_value=\"0\""));
        assert!(text.ends_with("</command>\n"));
    }
}
