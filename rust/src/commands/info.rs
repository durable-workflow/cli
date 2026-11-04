//! `server:info` human output, ported line for line from the 2.x CLI. Each
//! renderer appends lines; `render` returns the whole page.

use crate::compat::RequestContract;
use crate::php::{
    entries, is_array, is_list, non_empty_array, number_format, optional_bool, or, string_key_list,
    string_list, string_value, ucfirst, Key,
};
use serde_json::Value;

type Lines = Vec<String>;

fn get<'a>(value: &'a Value, key: &str) -> Option<&'a Value> {
    value.get(key).filter(|item| !item.is_null())
}

fn text_or_unknown(value: Option<&Value>) -> String {
    string_value(value).unwrap_or_else(|| "unknown".into())
}

/// `is_array($x[$key] ?? null) ? $x[$key] : []`.
fn array_field<'a>(value: &'a Value, key: &str) -> Option<&'a Value> {
    get(value, key).filter(|item| is_array(item))
}

pub fn render(info: &Value, request_contract: Option<&RequestContract>) -> String {
    let mut out = vec![
        "<info>Durable Workflow Server</info>".to_string(),
        format!("  Server ID: {}", or(get(info, "server_id"), "unknown")),
        format!("  Version: {}", or(get(info, "version"), "unknown")),
        format!(
            "  Default Namespace: {}",
            or(get(info, "default_namespace"), "default")
        ),
        String::new(),
        "Capabilities:".to_string(),
    ];
    if let Some(capabilities) = get(info, "capabilities") {
        for (key, value) in entries(capabilities) {
            render_capability(&mut out, &key_text(&key), value, 2);
        }
    }
    render_worker_fleet(&mut out, get(info, "worker_fleet"));
    render_limits(&mut out, get(info, "limits"));
    render_client_compatibility(&mut out, get(info, "client_compatibility"));
    render_topology(&mut out, get(info, "topology"));
    render_coordination_health(&mut out, get(info, "coordination_health"));
    render_control_plane(&mut out, get(info, "control_plane"), request_contract);
    render_worker_protocol(&mut out, get(info, "worker_protocol"));
    out.join("\n")
}

fn key_text(key: &Key) -> String {
    key.text()
}

fn is_plain_scalar(value: &Value) -> bool {
    !is_array(value)
}

fn render_capability(out: &mut Lines, name: &str, value: &Value, indent: usize) {
    let pad = " ".repeat(indent);
    if is_array(value) {
        if !non_empty_array(value) {
            out.push(format!("{pad}{name}: <comment>none</comment>"));
            return;
        }
        if is_list(value) && entries(value).iter().all(|(_, item)| is_plain_scalar(item)) {
            let items: Vec<String> = entries(value)
                .into_iter()
                .map(|(_, item)| match item {
                    Value::Bool(flag) => if *flag { "yes" } else { "no" }.to_string(),
                    other => crate::php::to_string(Some(other)),
                })
                .collect();
            out.push(format!("{pad}{name}: <info>{}</info>", items.join(", ")));
            return;
        }
        out.push(format!("{pad}{name}:"));
        for (key, child) in entries(value) {
            render_capability(out, &key_text(&key), child, indent + 2);
        }
        return;
    }
    match value {
        Value::Bool(flag) => out.push(format!(
            "{pad}{name}: {}",
            if *flag {
                "<info>yes</info>"
            } else {
                "<comment>no</comment>"
            }
        )),
        other => out.push(format!(
            "{pad}{name}: <info>{}</info>",
            crate::php::to_string(Some(other))
        )),
    }
}

fn render_worker_fleet(out: &mut Lines, fleet: Option<&Value>) {
    let Some(fleet) = fleet.filter(|fleet| non_empty_array(fleet)) else {
        return;
    };
    out.push(String::new());
    out.push("Worker Fleet:".into());
    out.push(format!(
        "  Namespace: {}",
        or(get(fleet, "namespace"), "unknown")
    ));
    out.push(format!(
        "  Active Workers: {}",
        or(get(fleet, "active_workers"), "0")
    ));
    out.push(format!(
        "  Active Scopes: {}",
        or(get(fleet, "active_worker_scopes"), "0")
    ));
    for (key, label) in [("build_ids", "Build IDs"), ("queues", "Queues")] {
        if let Some(items) = get(fleet, key).filter(|items| non_empty_array(items)) {
            let joined: Vec<String> = entries(items)
                .into_iter()
                .map(|(_, item)| crate::php::to_string(Some(item)))
                .collect();
            out.push(format!("  {label}: {}", joined.join(", ")));
        }
    }
}

fn format_bytes(bytes: i64) -> String {
    if bytes >= 1024 * 1024 {
        format!("{:.0} MB", bytes as f64 / (1024.0 * 1024.0))
    } else if bytes >= 1024 {
        format!("{:.0} KB", bytes as f64 / 1024.0)
    } else {
        format!("{bytes} B")
    }
}

fn render_limits(out: &mut Lines, limits: Option<&Value>) {
    let Some(limits) = limits.filter(|limits| non_empty_array(limits)) else {
        return;
    };
    out.push(String::new());
    out.push("Limits:".into());
    for (key, value) in entries(limits) {
        let name = key_text(&key);
        let label = ucfirst(&name).replace('_', " ");
        let is_int = crate::php::is_int(Some(value));
        let number = crate::php::int_cast(Some(value));
        if is_int && number >= 1024 && name.contains("bytes") {
            out.push(format!(
                "  {label}: {} ({})",
                number_format(number),
                format_bytes(number)
            ));
        } else {
            out.push(format!("  {label}: {}", number_format(number)));
        }
    }
}

fn render_client_compatibility(out: &mut Lines, compatibility: Option<&Value>) {
    let Some(compatibility) = compatibility.filter(|value| non_empty_array(value)) else {
        return;
    };
    out.push(String::new());
    out.push("Client Compatibility:".into());
    out.push(format!(
        "  Authority: {}",
        or(get(compatibility, "authority"), "unknown")
    ));
    out.push(format!(
        "  Top-level Version Role: {}",
        or(get(compatibility, "top_level_version_role"), "unknown")
    ));
    if let Some(fail_closed) = compatibility.get("fail_closed") {
        out.push(format!(
            "  Fail Closed: {}",
            if fail_closed == &Value::Bool(true) {
                "yes"
            } else {
                "no"
            }
        ));
    }
}

fn manifest_line(label: &str, manifest: &Value, suffix: Option<String>) -> String {
    let mut line = format!(
        "  {label}: {} v{}",
        text_or_unknown(get(manifest, "schema")),
        text_or_unknown(get(manifest, "version"))
    );
    if let Some(suffix) = suffix.filter(|suffix| !suffix.is_empty()) {
        line.push_str(&format!(" ({suffix})"));
    }
    line
}

/// The 2.x `fieldSummary()`: `label=value` for present scalar fields.
fn field_summary(source: Option<&Value>, fields: &[&str]) -> Vec<String> {
    let Some(source) = source else {
        return vec![];
    };
    fields
        .iter()
        .filter_map(|field| match source.get(*field)? {
            Value::Bool(flag) => Some(format!("{field}={}", if *flag { "yes" } else { "no" })),
            value => string_value(Some(value)).map(|text| format!("{field}={text}")),
        })
        .collect()
}

/// The 2.x `formatScalarMap()`.
fn scalar_map(value: Option<&Value>) -> Option<String> {
    let value = value.filter(|value| non_empty_array(value))?;
    let items: Vec<String> = if is_list(value) {
        string_list(Some(value))
    } else {
        entries(value)
            .into_iter()
            .filter_map(|(key, entry)| {
                let Key::Str(name) = key else {
                    return None;
                };
                match entry {
                    Value::Bool(flag) => {
                        Some(format!("{name}={}", if *flag { "yes" } else { "no" }))
                    }
                    other => string_value(Some(other)).map(|text| format!("{name}={text}")),
                }
            })
            .collect()
    };
    (!items.is_empty()).then(|| items.join(", "))
}

fn push_list(out: &mut Lines, label: &str, items: &[String]) {
    if !items.is_empty() {
        out.push(format!("  {label}: {}", items.join(", ")));
    }
}

fn push_value(out: &mut Lines, label: &str, value: Option<String>) {
    if let Some(value) = value {
        out.push(format!("  {label}: {value}"));
    }
}

fn push_block(out: &mut Lines, heading: &str, lines: Vec<String>) {
    if !lines.is_empty() {
        out.push(format!("  {heading}:"));
        out.extend(lines);
    }
}

fn render_topology(out: &mut Lines, topology: Option<&Value>) {
    let Some(topology) = topology.filter(|value| non_empty_array(value)) else {
        return;
    };
    out.push(String::new());
    out.push("Topology:".into());
    out.push(format!(
        "  Manifest: {} v{}",
        text_or_unknown(get(topology, "schema")),
        text_or_unknown(get(topology, "version"))
    ));
    push_list(
        out,
        "Supported Shapes",
        &string_list(get(topology, "supported_shapes")),
    );
    push_value(
        out,
        "Current Shape",
        string_value(get(topology, "current_shape")),
    );
    push_value(
        out,
        "Current Process Class",
        string_value(get(topology, "current_process_class")),
    );
    let roles = string_list(get(topology, "current_roles"));
    push_list(out, "Current Roles", &roles);
    push_value(
        out,
        "Execution Mode",
        string_value(get(topology, "execution_mode")),
    );
    if let Some(matching) = get(topology, "matching_role").filter(|value| is_array(value)) {
        render_matching_role(out, matching);
    }
    push_block(
        out,
        "Current Role Traits",
        role_trait_lines(array_field(topology, "role_catalog"), &roles),
    );
    push_block(
        out,
        "Current Write Boundaries",
        write_boundary_lines(array_field(topology, "authority_boundaries"), &roles),
    );
    push_block(
        out,
        "Scaling Boundaries",
        scaling_lines(array_field(topology, "scaling_boundaries")),
    );
    push_block(
        out,
        "Failure Domains",
        failure_lines(array_field(topology, "failure_domains")),
    );
}

fn render_matching_role(out: &mut Lines, matching: &Value) {
    out.push(format!(
        "  Matching Role: {} (queue_wake_enabled={}, wake_owner={}, task_dispatch_mode={})",
        text_or_unknown(get(matching, "shape")),
        optional_bool(get(matching, "queue_wake_enabled")),
        text_or_unknown(get(matching, "wake_owner")),
        text_or_unknown(get(matching, "task_dispatch_mode")),
    ));
    push_list(
        out,
        "Matching Partitions",
        &string_list(get(matching, "partition_primitives")),
    );
    push_value(
        out,
        "Matching Backpressure",
        string_value(get(matching, "backpressure_model")),
    );
}

/// The definition a PHP array holds under `$array[$role]`.
fn role_entry<'a>(map: Option<&'a Value>, role: &str) -> Option<&'a Value> {
    map?.get(role).filter(|value| is_array(value))
}

fn role_trait_lines(catalog: Option<&Value>, roles: &[String]) -> Vec<String> {
    roles
        .iter()
        .filter_map(|role| {
            let definition = role_entry(catalog, role)?;
            Some(format!(
                "    {role}: plane={}, external_http={}, runs_user_code={}, interface={}",
                text_or_unknown(get(definition, "plane")),
                optional_bool(get(definition, "accepts_external_http")),
                optional_bool(get(definition, "runs_user_code")),
                text_or_unknown(get(definition, "steady_state_interface")),
            ))
        })
        .collect()
}

fn write_boundary_lines(boundaries: Option<&Value>, roles: &[String]) -> Vec<String> {
    roles
        .iter()
        .filter_map(|role| {
            let writes = string_list(get(role_entry(boundaries, role)?, "writes"));
            (!writes.is_empty()).then(|| format!("    {role}: {}", writes.join(", ")))
        })
        .collect()
}

fn scaling_lines(boundaries: Option<&Value>) -> Vec<String> {
    boundaries
        .map(|boundaries| {
            entries(boundaries)
                .into_iter()
                .filter_map(|(key, boundary)| match key {
                    Key::Str(role) => {
                        string_value(Some(boundary)).map(|value| format!("    {role}: {value}"))
                    }
                    Key::Int(_) => None,
                })
                .collect()
        })
        .unwrap_or_default()
}

fn failure_lines(domains: Option<&Value>) -> Vec<String> {
    domains
        .map(|domains| {
            entries(domains)
                .into_iter()
                .filter_map(|(key, definition)| {
                    let Key::Str(failure) = key else {
                        return None;
                    };
                    if !is_array(definition) {
                        return None;
                    }
                    let mut parts = Vec::new();
                    if let Some(signal) = string_value(get(definition, "operator_signal")) {
                        parts.push(format!("signal={signal}"));
                    }
                    if let Some(effect) = string_value(get(definition, "effect")) {
                        parts.push(format!("effect={effect}"));
                    }
                    (!parts.is_empty()).then(|| format!("    {failure}: {}", parts.join(", ")))
                })
                .collect()
        })
        .unwrap_or_default()
}

fn render_coordination_health(out: &mut Lines, health: Option<&Value>) {
    let Some(health) = health.filter(|value| non_empty_array(value)) else {
        return;
    };
    out.push(String::new());
    out.push("Coordination Health:".into());
    out.push(format!(
        "  Manifest: {} v{}",
        text_or_unknown(get(health, "schema")),
        text_or_unknown(get(health, "version"))
    ));
    push_value(
        out,
        "Namespace Scope",
        string_value(get(health, "namespace_scope")),
    );
    out.push(format!(
        "  Status: {} (http {})",
        text_or_unknown(get(health, "status")),
        text_or_unknown(get(health, "http_status"))
    ));
    push_value(
        out,
        "Generated At",
        string_value(get(health, "generated_at")),
    );
    push_value(out, "Categories", scalar_map(get(health, "categories")));
    for (key, label) in [
        ("warning_checks", "Warning Checks"),
        ("error_checks", "Error Checks"),
    ] {
        let checks = string_list(get(health, key));
        out.push(format!(
            "  {label}: {}",
            if checks.is_empty() {
                "none".into()
            } else {
                checks.join(", ")
            }
        ));
    }
    let checks: Vec<String> = array_field(health, "checks")
        .map(|checks| {
            entries(checks)
                .into_iter()
                .filter(|(_, check)| is_array(check))
                .map(|(_, check)| check_line(check))
                .collect()
        })
        .unwrap_or_default();
    push_block(out, "Checks", checks);
}

fn check_line(check: &Value) -> String {
    let mut parts = vec![format!("status={}", text_or_unknown(get(check, "status")))];
    if let Some(category) = string_value(get(check, "category")) {
        parts.push(format!("category={category}"));
    }
    if let Some(message) = string_value(get(check, "message")) {
        parts.push(format!("message={message}"));
    }
    format!(
        "    {}: {}",
        text_or_unknown(get(check, "name")),
        parts.join(", ")
    )
}

fn render_control_plane(
    out: &mut Lines,
    control_plane: Option<&Value>,
    request_contract: Option<&RequestContract>,
) {
    let Some(control_plane) = control_plane.filter(|value| non_empty_array(value)) else {
        return;
    };
    out.push(String::new());
    out.push("Control Plane:".into());
    out.push(format!(
        "  Version: {}",
        or(get(control_plane, "version"), "unknown")
    ));
    out.push(format!(
        "  Header: {}",
        or(get(control_plane, "header"), "unknown")
    ));
    if let Some(response) = get(control_plane, "response_contract").filter(|value| is_array(value))
    {
        out.push(format!(
            "  Response Contract: {} v{}",
            or(get(response, "schema"), "unknown"),
            or(get(response, "version"), "unknown")
        ));
        if let Some(nested) = get(response, "contract").filter(|value| is_array(value)) {
            out.push(format!(
                "  Nested Contract: {} v{}",
                or(get(nested, "schema"), "unknown"),
                or(get(nested, "version"), "unknown")
            ));
            out.push(format!(
                "  Legacy Field Policy: {}",
                or(get(nested, "legacy_field_policy"), "unknown")
            ));
        }
    }
    match request_contract {
        Some(contract) => render_request_contract(out, contract),
        None if control_plane.get("request_contract").is_some() => {
            out.push("  Request Contract: incompatible".into());
        }
        None => {}
    }
}

/// Renders a request contract that already passed validation.
fn render_request_contract(out: &mut Lines, contract: &RequestContract) {
    out.push(format!(
        "  Request Contract: {} v{}",
        contract.schema(),
        contract.version()
    ));
    for (key, definition) in entries(contract.operations()) {
        let Key::Str(operation) = key else {
            continue;
        };
        if !is_array(definition) {
            continue;
        }
        if let Some(fields) = get(definition, "fields").filter(|value| is_array(value)) {
            render_operation_fields(out, operation, fields);
        }
        render_removed_fields(out, operation, get(definition, "removed_fields"));
    }
}

fn render_operation_fields(out: &mut Lines, operation: &str, fields: &Value) {
    for (key, definition) in entries(fields) {
        let Key::Str(field) = key else {
            continue;
        };
        if !is_array(definition) {
            continue;
        }
        if let Some(values) =
            get(definition, "canonical_values").filter(|value| non_empty_array(value))
        {
            let joined: Vec<String> = entries(values)
                .into_iter()
                .map(|(_, item)| crate::php::to_string(Some(item)))
                .collect();
            out.push(format!(
                "  {} {field}: {}",
                ucfirst(operation),
                joined.join(", ")
            ));
        }
        if let Some(aliases) =
            get(definition, "rejected_aliases").filter(|value| non_empty_array(value))
        {
            let pairs: Vec<String> = entries(aliases)
                .into_iter()
                .filter_map(|(alias, canonical)| match (alias, canonical) {
                    (Key::Str(alias), Value::String(canonical)) => {
                        Some(format!("{alias} -> {canonical}"))
                    }
                    _ => None,
                })
                .collect();
            if !pairs.is_empty() {
                out.push(format!(
                    "  {} rejected {field} aliases: {}",
                    ucfirst(operation),
                    pairs.join(", ")
                ));
            }
        }
    }
}

fn render_removed_fields(out: &mut Lines, operation: &str, removed: Option<&Value>) {
    let Some(removed) = removed.filter(|value| non_empty_array(value)) else {
        return;
    };
    let fields: Vec<String> = entries(removed)
        .into_iter()
        .filter_map(|(key, guidance)| {
            let Key::Str(field) = key else {
                return None;
            };
            if field.trim().is_empty() {
                return None;
            }
            Some(match guidance {
                Value::String(text) if !text.trim().is_empty() => format!("{field} ({text})"),
                _ => field.to_string(),
            })
        })
        .collect();
    if !fields.is_empty() {
        out.push(format!(
            "  Removed {operation} fields: {}",
            fields.join(", ")
        ));
    }
}

fn render_worker_protocol(out: &mut Lines, protocol: Option<&Value>) {
    let Some(protocol) = protocol.filter(|value| non_empty_array(value)) else {
        return;
    };
    out.push(String::new());
    out.push("Worker Protocol:".into());
    out.push(format!(
        "  Version: {}",
        text_or_unknown(get(protocol, "version"))
    ));
    if let Some(capabilities) = get(protocol, "server_capabilities").filter(|value| is_array(value))
    {
        render_server_capabilities(out, capabilities);
    }
    if let Some(contract) = array_field(protocol, "external_execution_surface_contract") {
        render_surface_contract(out, contract);
    }
    if let Some(contract) = array_field(protocol, "external_executor_config_contract") {
        render_executor_config_contract(out, contract);
    }
    if let Some(contract) = array_field(protocol, "invocable_carrier_contract") {
        render_invocable_contract(out, contract);
    }
    for (key, label) in [
        (
            "external_task_input_contract",
            "External Task Input Contract",
        ),
        (
            "external_task_result_contract",
            "External Task Result Contract",
        ),
    ] {
        if let Some(contract) = array_field(protocol, key) {
            render_external_task_contract(out, label, contract);
        }
    }
}

const CAPABILITY_FLAGS: &[(&str, &str)] = &[
    ("poll_status", "Poll Status"),
    ("query_tasks", "Query Tasks"),
    ("activity_retry_policy", "Activity Retry Policy"),
    ("activity_timeouts", "Activity Timeouts"),
    ("child_workflow_retry_policy", "Child Workflow Retry Policy"),
    ("child_workflow_timeouts", "Child Workflow Timeouts"),
    ("parent_close_policy", "Parent Close Policy"),
    ("non_retryable_failures", "Non-retryable Failures"),
];

fn render_server_capabilities(out: &mut Lines, caps: &Value) {
    out.push(format!(
        "  Long Poll Timeout: {}",
        text_or_unknown(get(caps, "long_poll_timeout"))
    ));
    push_list(
        out,
        "Workflow Task Commands",
        &string_list(get(caps, "supported_workflow_task_commands")),
    );
    if let Some(value) = caps.get("workflow_task_poll_request_idempotency") {
        out.push(format!(
            "  Workflow Task Poll Idempotency: {}",
            optional_bool(Some(value))
        ));
    }
    for (field, label) in CAPABILITY_FLAGS {
        if let Some(value) = caps.get(*field) {
            out.push(format!("  {label}: {}", optional_bool(Some(value))));
        }
    }
    for (field, label) in [
        ("history_page_size_default", "History Page Size (default)"),
        ("history_page_size_max", "History Page Size (max)"),
    ] {
        if caps.get(field).is_some() {
            out.push(format!("  {label}: {}", text_or_unknown(get(caps, field))));
        }
    }
    if caps.get("response_compression").is_some() {
        let compression = string_list(get(caps, "response_compression"));
        out.push(format!(
            "  Response Compression: {}",
            if compression.is_empty() {
                "disabled".into()
            } else {
                compression.join(", ")
            }
        ));
    }
    render_history_compression(out, get(caps, "history_compression"));
    render_local_activities(out, get(caps, "local_activities"));
    render_worker_sessions(
        out,
        get(caps, "worker_sessions"),
        string_list(get(caps, "worker_session_verbs")),
    );
    render_protocol_capabilities(out, caps);
}

fn render_protocol_capabilities(out: &mut Lines, caps: &Value) {
    if let Some(surface) = array_field(caps, "external_execution_surface") {
        let name = string_value(get(surface, "name")).map(|name| format!("name={name}"));
        out.push(manifest_line("External Execution Surface", surface, name));
    }
    if let Some(config) = array_field(caps, "external_executor_config") {
        let suffix = string_value(get(config, "config_schema")).map(|schema| {
            format!(
                "config={schema} v{}",
                text_or_unknown(get(config, "config_schema_version"))
            )
        });
        out.push(manifest_line("External Executor Config", config, suffix));
    }
    for (key, label) in [
        ("external_task_input", "External Task Input"),
        ("external_task_result", "External Task Result"),
    ] {
        if let Some(capability) = array_field(caps, key) {
            out.push(manifest_line(label, capability, None));
        }
    }
    if let Some(invocable) = array_field(caps, "invocable_carrier") {
        out.push(format!(
            "  Invocable Carrier: {} v{} ({})",
            text_or_unknown(get(invocable, "schema")),
            text_or_unknown(get(invocable, "version")),
            text_or_unknown(get(invocable, "carrier_type")),
        ));
    }
}

fn render_history_compression(out: &mut Lines, compression: Option<&Value>) {
    let Some(compression) = compression.filter(|value| is_array(value)) else {
        return;
    };
    let encodings = string_list(get(compression, "supported_encodings"));
    out.push(format!(
        "  History Compression: {}",
        if encodings.is_empty() {
            "disabled".into()
        } else {
            encodings.join(", ")
        }
    ));
    if let Some(threshold) = string_value(get(compression, "compression_threshold")) {
        out.push(format!(
            "  History Compression Threshold: {threshold} events"
        ));
    }
}

fn render_local_activities(out: &mut Lines, local: Option<&Value>) {
    let Some(local) = local.filter(|value| is_array(value)) else {
        return;
    };
    let suffix = local
        .get("supported")
        .map(|supported| format!("supported={}", optional_bool(Some(supported))));
    out.push(manifest_line("Local Activities", local, suffix));
    let execution = field_summary(
        array_field(local, "execution"),
        &["mode", "same_process", "ordinary_activity_task_created"],
    );
    push_list(out, "Local Activity Execution", &execution);
    let routing_source = array_field(local, "routing");
    let mut routing = field_summary(routing_source, &["admission", "queue_bypassed"]);
    let rejected = string_list(routing_source.and_then(|routing| get(routing, "rejected_options")));
    if !rejected.is_empty() {
        routing.push(format!("rejected_options={}", rejected.join(", ")));
    }
    push_list(out, "Local Activity Routing", &routing);
}

const SESSION_FIELDS: &[&str] = &[
    "feature",
    "contract_version",
    "supported",
    "minimum_protocol_version",
    "command_field",
    "activity_options_field",
    "ownership",
    "unavailable_reason",
];

fn render_worker_sessions(out: &mut Lines, sessions: Option<&Value>, mut verbs: Vec<String>) {
    let sessions = sessions.filter(|value| is_array(value));
    if sessions.is_none() && verbs.is_empty() {
        return;
    }
    if verbs.is_empty() {
        if let Some(sessions) = sessions {
            verbs = string_list(get(sessions, "verbs"));
        }
    }
    push_list(out, "Worker Session Verbs", &verbs);
    let Some(sessions) = sessions else {
        return;
    };
    push_list(
        out,
        "Worker Sessions",
        &field_summary(Some(sessions), SESSION_FIELDS),
    );
    push_value(
        out,
        "Worker Session Lifecycle",
        scalar_map(get(sessions, "lifecycle")),
    );
    push_value(
        out,
        "Worker Session Admission",
        scalar_map(get(sessions, "admission")),
    );
    push_value(
        out,
        "Worker Session Limits",
        scalar_map(get(sessions, "limits")),
    );
    push_value(
        out,
        "Worker Session Default Max Concurrent Activities",
        string_value(get(sessions, "default_max_concurrent_activities")),
    );
}

fn render_surface_contract(out: &mut Lines, contract: &Value) {
    out.push(manifest_line(
        "External Execution Surface Contract",
        contract,
        None,
    ));
    let boundary = array_field(contract, "product_boundary");
    push_value(
        out,
        "External Execution Surface Boundary",
        boundary.and_then(|boundary| string_value(get(boundary, "name"))),
    );
    push_list(
        out,
        "External Execution Surface Entries",
        &string_key_list(get(contract, "contract_seams")),
    );
}

fn render_executor_config_contract(out: &mut Lines, contract: &Value) {
    out.push(manifest_line(
        "External Executor Config Contract",
        contract,
        None,
    ));
    if let Some(schema) =
        array_field(contract, "config_schema").filter(|value| non_empty_array(value))
    {
        out.push(format!(
            "  External Executor Config Schema: {} v{}",
            text_or_unknown(get(schema, "schema")),
            text_or_unknown(get(schema, "version"))
        ));
    }
    push_value(
        out,
        "External Executor Config Surface",
        string_value(get(contract, "steady_state_surface")),
    );
    let runtime = field_summary(
        array_field(contract, "runtime"),
        &["configured", "status", "overlay"],
    );
    push_list(out, "External Executor Config Runtime", &runtime);
}

fn render_invocable_contract(out: &mut Lines, contract: &Value) {
    out.push(manifest_line("Invocable Carrier Contract", contract, None));
    out.push(format!(
        "  Invocable Carrier Type: {}",
        text_or_unknown(get(contract, "carrier_type"))
    ));
    let kinds = get(contract, "scope")
        .filter(|scope| is_array(scope))
        .and_then(|scope| get(scope, "task_kinds"))
        .filter(|kinds| is_array(kinds));
    push_list(out, "Invocable Task Kinds", &string_list(kinds));
    for (key, label) in [
        ("request", "Invocable Request Content-Type"),
        ("response", "Invocable Response Content-Type"),
    ] {
        if let Some(part) = array_field(contract, key) {
            out.push(format!(
                "  {label}: {}",
                text_or_unknown(get(part, "content_type"))
            ));
        }
    }
}

fn render_external_task_contract(out: &mut Lines, label: &str, contract: &Value) {
    out.push(manifest_line(label, contract, None));
    push_value(
        out,
        &format!("{label} Unknown Field Policy"),
        string_value(get(contract, "unknown_field_policy")),
    );
    let scopes: Vec<String> = array_field(contract, "scope")
        .map(|scope| {
            entries(scope)
                .into_iter()
                .filter_map(|(key, definition)| {
                    let Key::Str(name) = key else {
                        return None;
                    };
                    let kinds =
                        string_list(get(definition, "task_kinds").filter(|_| is_array(definition)));
                    (!kinds.is_empty()).then(|| format!("{name}={}", kinds.join("|")))
                })
                .collect()
        })
        .unwrap_or_default();
    push_list(out, &format!("{label} Scope"), &scopes);
    for (key, suffix) in [
        ("envelopes", "Envelopes"),
        ("fixtures", "Fixtures"),
        ("payload_support", "Payload Support"),
    ] {
        push_list(
            out,
            &format!("{label} {suffix}"),
            &string_key_list(get(contract, key)),
        );
    }
    push_value(
        out,
        &format!("{label} Stderr Policy"),
        string_value(get(contract, "stderr_policy")),
    );
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn renders_header_capabilities_and_limits() {
        let info = json!({
            "server_id": "srv-1",
            "version": "2.11.8",
            "capabilities": {"workflow_tasks": true, "codecs": ["avro"], "empty": [], "nested": {"a": 1}},
            "limits": {"max_payload_bytes": 2097152, "max_history_events": 50000},
        });
        let text = crate::formatter::format(&render(&info, None), false);
        assert!(text.starts_with("Durable Workflow Server\n  Server ID: srv-1\n  Version: 2.11.8\n  Default Namespace: default\n\nCapabilities:\n"));
        assert!(text.contains(
            "  workflow_tasks: yes\n  codecs: avro\n  empty: none\n  nested:\n    a: 1\n"
        ));
        assert!(text.contains(
            "Limits:\n  Max payload bytes: 2,097,152 (2 MB)\n  Max history events: 50,000"
        ));
    }

    #[test]
    fn renders_request_contract_operations() {
        let info = json!({"control_plane": {"version": "2", "request_contract": {
            "schema": "durable-workflow.v2.control-plane-request.contract",
            "version": 1,
            "operations": {"list": {"fields": {"status": {"canonical_values": ["running", "completed"], "rejected_aliases": {"open": "running"}}}, "removed_fields": {"wait_policy": "use wait_for"}}},
        }}});
        let contract = RequestContract::from_cluster_info(&info).expect("valid contract");
        let text = render(&info, Some(&contract));
        assert!(text.contains("  Request Contract: durable-workflow.v2.control-plane-request.contract v1\n  List status: running, completed\n  List rejected status aliases: open -> running\n  Removed list fields: wait_policy (use wait_for)"));
        let text = render(
            &json!({"control_plane": {"version": "2", "request_contract": {}}}),
            None,
        );
        assert!(text.ends_with("  Request Contract: incompatible"));
    }
}
