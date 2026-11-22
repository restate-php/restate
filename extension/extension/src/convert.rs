//! Conversions between PHP values and the core's types: PHP arrays and
//! scalars in, PHP arrays and binary strings out.
//!
//! The array shapes are the contract with
//! `Restate\Internal\Backend\Extension\Invocation`; `extension/restate.stub.php`
//! documents them for PHPStan.

use crate::exception::{ExtensionError, ExtensionResult};
use ext_php_rs::binary::Binary;
use ext_php_rs::boxed::ZBox;
use ext_php_rs::types::array::Iter;
use ext_php_rs::types::{ZendHashTable, Zval};
use restate_php_core::{
    validation, AttachTarget, AwaitTree, Awakeable, Bytes, CallTarget, Handle, InvocationInput,
    LogLevel, LogRecord, NotificationResult, OnMaxAttempts, Progress, ProtocolVersions,
    ResponseHead, RetryPolicy, RetryableFailure, RunRetryOutcome, TargetKind, TerminalFailure,
};

pub type Array = ZBox<ZendHashTable>;

// --- PHP values in

/// The entries of a PHP array as raw key and value. Unlike `ZendHashTable`'s
/// own iterators this never panics on a key that is not UTF-8.
fn entries(array: &ZendHashTable) -> impl Iterator<Item = (Zval, &Zval)> {
    let mut iter = Iter::new(array);
    std::iter::from_fn(move || iter.next_zval())
}

fn string_value(value: &Zval, what: &str) -> ExtensionResult<String> {
    let value = value
        .zend_str()
        .ok_or_else(|| ExtensionError::invalid_argument(format!("{what} must be a string.")))?
        .as_str()
        .map(str::to_string)
        .map_err(|_| ExtensionError::invalid_argument(format!("{what} must be valid UTF-8.")))?;
    crate::probe::trip(&value);
    Ok(value)
}

fn field<'a>(array: &'a ZendHashTable, name: &str, what: &str) -> ExtensionResult<&'a Zval> {
    array
        .get(name)
        .ok_or_else(|| ExtensionError::invalid_argument(format!("{what} is missing '{name}'.")))
}

fn string_field(array: &ZendHashTable, name: &str, what: &str) -> ExtensionResult<String> {
    string_value(field(array, name, what)?, &format!("{what} '{name}'"))
}

/// A field that may be missing or null.
fn optional<'a>(array: &'a ZendHashTable, name: &str) -> Option<&'a Zval> {
    array.get(name).filter(|value| !value.is_null())
}

fn optional_string_field(
    array: &ZendHashTable,
    name: &str,
    what: &str,
) -> ExtensionResult<Option<String>> {
    optional(array, name)
        .map(|value| string_value(value, &format!("{what} '{name}'")))
        .transpose()
}

fn optional_int_field(
    array: &ZendHashTable,
    name: &str,
    what: &str,
) -> ExtensionResult<Option<i64>> {
    optional(array, name)
        .map(|value| {
            value.long().ok_or_else(|| {
                ExtensionError::invalid_argument(format!("{what} '{name}' must be an integer."))
            })
        })
        .transpose()
}

fn optional_millis_field(
    array: &ZendHashTable,
    name: &str,
    what: &str,
) -> ExtensionResult<Option<std::time::Duration>> {
    optional_int_field(array, name, what)?
        .map(|millis| validation::non_negative_millis(millis, &format!("{what} '{name}'")))
        .transpose()
        .map_err(ExtensionError::from)
}

fn optional_attempts_field(
    array: &ZendHashTable,
    name: &str,
    what: &str,
) -> ExtensionResult<Option<u32>> {
    optional_int_field(array, name, what)?
        .map(|attempts| {
            u32::try_from(attempts).map_err(|_| {
                ExtensionError::invalid_argument(format!(
                    "{what} '{name}' is outside the u32 range."
                ))
            })
        })
        .transpose()
}

/// `[[name, value], …]`
pub fn headers(array: &ZendHashTable) -> ExtensionResult<Vec<(String, String)>> {
    entries(array)
        .map(|(_, pair)| {
            let pair = pair.array().ok_or_else(|| {
                ExtensionError::invalid_argument("Headers must be [name, value] pairs.")
            })?;
            let name = pair.get_index(0).ok_or_else(|| {
                ExtensionError::invalid_argument("Header pair is missing its name.")
            })?;
            let value = pair.get_index(1).ok_or_else(|| {
                ExtensionError::invalid_argument("Header pair is missing its value.")
            })?;
            Ok((
                string_value(name, "Header name")?,
                string_value(value, "Header value")?,
            ))
        })
        .collect()
}

/// Request identity keys, a list of strings.
pub fn identity_keys(array: &ZendHashTable) -> ExtensionResult<Vec<String>> {
    entries(array)
        .map(|(_, key)| string_value(key, "Request identity key"))
        .collect()
}

/// Terminal failure metadata, `[key => value, …]`. PHP stores numeric-string
/// keys as integers; they are read back as the strings they came from.
pub fn metadata(array: &ZendHashTable) -> ExtensionResult<Vec<(String, String)>> {
    entries(array)
        .map(|(key, value)| {
            let key = match key.long() {
                Some(index) => index.to_string(),
                None => string_value(&key, "Terminal metadata key")?,
            };
            let value = value.zend_str().ok_or_else(|| {
                ExtensionError::invalid_argument("terminal metadata must be a string-to-string map")
            })?;
            let value = value
                .as_str()
                .map_err(|_| {
                    ExtensionError::invalid_argument("Terminal metadata value must be valid UTF-8.")
                })?
                .to_string();
            Ok((key, value))
        })
        .collect()
}

/// A terminal failure the SDK writes. The message may be any bytes: the core
/// replaces what is not UTF-8.
pub fn terminal_failure(
    code: i64,
    message: Binary<u8>,
    metadata_array: &ZendHashTable,
) -> ExtensionResult<TerminalFailure> {
    Ok(TerminalFailure::new(
        code,
        Vec::from(message),
        metadata(metadata_array)?,
    )?)
}

/// A retryable run failure. Any code is accepted (the core submits codes
/// that cannot be failure codes as 500); the message may be any bytes.
pub fn retryable_failure(
    code: i64,
    message: Binary<u8>,
    metadata_array: &ZendHashTable,
) -> ExtensionResult<RetryableFailure> {
    Ok(RetryableFailure::new(
        code,
        Vec::from(message),
        metadata(metadata_array)?,
    ))
}

pub fn handle(value: i64) -> ExtensionResult<Handle> {
    Ok(validation::handle(value)?)
}

/// `[combinator, [child, …]]`, where the combinator is `'first_completed'`,
/// `'all_completed'`, `'first_succeeded_or_all_failed'` or
/// `'all_succeeded_or_first_failed'` and each child is a handle or another
/// such array. The core checks the rest (children present, depth).
pub fn await_tree(array: &ZendHashTable) -> ExtensionResult<AwaitTree> {
    await_tree_at(array, 0)
}

fn await_tree_at(array: &ZendHashTable, depth: usize) -> ExtensionResult<AwaitTree> {
    // Checked here as well as in the core: a PHP array can contain itself.
    if depth == AwaitTree::MAX_DEPTH {
        return Err(ExtensionError::invalid_argument(format!(
            "An await tree may nest at most {} combinators.",
            AwaitTree::MAX_DEPTH
        )));
    }
    let (Some(name), Some(children), 2) = (array.get_index(0), array.get_index(1), array.len())
    else {
        return Err(ExtensionError::invalid_argument(
            "An await tree must be [combinator, [child, …]].",
        ));
    };
    let name = string_value(name, "Await tree combinator")?;
    let children = children
        .array()
        .ok_or_else(|| ExtensionError::invalid_argument("Await tree children must be an array."))?;
    let children = entries(children)
        .map(|(_, child)| match (child.long(), child.array()) {
            (Some(value), _) => Ok(AwaitTree::Single(handle(value)?)),
            (None, Some(nested)) => await_tree_at(nested, depth + 1),
            (None, None) => Err(ExtensionError::invalid_argument(
                "An await tree child must be a handle or an await tree.",
            )),
        })
        .collect::<ExtensionResult<Vec<_>>>()?;
    Ok(AwaitTree::combinator(&name, children)?)
}

/// `['kind' => 'service'|'object'|'workflow', 'service' => …, 'handler' => …,
/// 'key' => ?string, 'headers' => [[name, value], …] (optional),
/// 'scope' => ?string (optional), 'limit_key' => ?string (optional)]`
pub fn call_target(array: &ZendHashTable) -> ExtensionResult<CallTarget> {
    const WHAT: &str = "Call target";
    Ok(CallTarget {
        kind: TargetKind::from_name(&string_field(array, "kind", WHAT)?)?,
        service: string_field(array, "service", WHAT)?,
        handler: string_field(array, "handler", WHAT)?,
        key: optional_string_field(array, "key", WHAT)?,
        headers: match optional(array, "headers") {
            Some(value) => headers(value.array().ok_or_else(|| {
                ExtensionError::invalid_argument("Call target 'headers' must be an array.")
            })?)?,
            None => vec![],
        },
        scope: optional_string_field(array, "scope", WHAT)?,
        limit_key: optional_string_field(array, "limit_key", WHAT)?,
    })
}

/// `['kind' => 'invocation', 'invocation_id' => …]`,
/// `['kind' => 'workflow', 'name' => …, 'key' => …, 'scope' => ?string (optional)]` or
/// `['kind' => 'idempotency', 'service_name' => …, 'service_key' => ?string,
/// 'handler_name' => …, 'idempotency_key' => …, 'scope' => ?string (optional)]`
pub fn attach_target(array: &ZendHashTable) -> ExtensionResult<AttachTarget> {
    const WHAT: &str = "Attach target";
    Ok(match string_field(array, "kind", WHAT)?.as_str() {
        "invocation" => AttachTarget::Invocation {
            invocation_id: string_field(array, "invocation_id", WHAT)?,
        },
        "workflow" => AttachTarget::Workflow {
            name: string_field(array, "name", WHAT)?,
            key: string_field(array, "key", WHAT)?,
            scope: optional_string_field(array, "scope", WHAT)?,
        },
        "idempotency" => AttachTarget::Idempotency {
            service_name: string_field(array, "service_name", WHAT)?,
            service_key: optional_string_field(array, "service_key", WHAT)?,
            handler_name: string_field(array, "handler_name", WHAT)?,
            idempotency_key: string_field(array, "idempotency_key", WHAT)?,
            scope: optional_string_field(array, "scope", WHAT)?,
        },
        kind => {
            return Err(ExtensionError::invalid_argument(format!(
                "unsupported attach target kind: {kind}"
            )))
        }
    })
}

/// `null` (infinite) or `['type' => 'infinite'|'none'|'fixed_delay'|'exponential', …]`
/// with the millisecond fields `RunRetryPolicy` produces; a fixed-delay or
/// exponential policy may say `'on_max_attempts' => 'fail'|'pause'` (missing
/// or null: fail).
pub fn retry_policy(array: Option<&ZendHashTable>) -> ExtensionResult<RetryPolicy> {
    const WHAT: &str = "Retry policy";
    let Some(array) = array else {
        return Ok(RetryPolicy::default());
    };

    Ok(match string_field(array, "type", WHAT)?.as_str() {
        "infinite" => RetryPolicy::Infinite,
        "none" => RetryPolicy::None,
        "fixed_delay" => RetryPolicy::FixedDelay {
            interval: optional_millis_field(array, "interval_millis", WHAT)?,
            max_attempts: optional_attempts_field(array, "max_attempts", WHAT)?,
            max_duration: optional_millis_field(array, "max_duration_millis", WHAT)?,
            on_max_attempts: on_max_attempts(array)?,
        },
        "exponential" => RetryPolicy::Exponential {
            initial_interval: optional_millis_field(array, "initial_interval_millis", WHAT)?
                .ok_or_else(|| {
                    ExtensionError::invalid_argument(
                        "Retry policy is missing 'initial_interval_millis'.",
                    )
                })?,
            factor: {
                let factor = field(array, "factor", WHAT)?;
                factor
                    .double()
                    .or_else(|| factor.long().map(|value| value as f64))
                    .ok_or_else(|| {
                        ExtensionError::invalid_argument("Retry policy 'factor' must be a number.")
                    })? as f32
            },
            max_interval: optional_millis_field(array, "max_interval_millis", WHAT)?,
            max_attempts: optional_attempts_field(array, "max_attempts", WHAT)?,
            max_duration: optional_millis_field(array, "max_duration_millis", WHAT)?,
            on_max_attempts: on_max_attempts(array)?,
        },
        kind => {
            return Err(ExtensionError::invalid_argument(format!(
                "unsupported retry policy type: {kind}"
            )))
        }
    })
}

pub fn bytes(binary: Binary<u8>) -> Bytes {
    Bytes::from(Vec::<u8>::from(binary))
}

// --- PHP values out

pub fn binary(bytes: &[u8]) -> Binary<u8> {
    Binary::new(bytes.to_vec())
}

fn typed(kind: &str) -> ExtensionResult<Array> {
    let mut array = ZendHashTable::new();
    array.insert("type", kind)?;
    Ok(array)
}

fn header_list(headers: &[(String, String)]) -> ExtensionResult<Array> {
    let mut list = ZendHashTable::with_capacity(u32::try_from(headers.len()).unwrap_or(0));
    for (name, value) in headers {
        let mut pair = ZendHashTable::with_capacity(2);
        pair.push(name.as_str())?;
        pair.push(value.as_str())?;
        list.push(pair)?;
    }
    Ok(list)
}

/// `['status_code' => int, 'headers' => [[name, value], …]]`
pub fn response_head(head: &ResponseHead) -> ExtensionResult<Array> {
    let mut array = ZendHashTable::new();
    array.insert("status_code", i64::from(head.status_code))?;
    array.insert("headers", header_list(&head.headers)?)?;
    Ok(array)
}

/// `['invocation_id' => …, 'random_seed' => int, 'key' => …, 'headers' => …,
/// 'input' => binary string, 'scope' => ?string, 'limit_key' => ?string,
/// 'idempotency_key' => ?string]`. The seed is the core's `u64` reinterpreted
/// as PHP's signed integer. The last three are `null` before protocol v7.
pub fn input(input: &InvocationInput) -> ExtensionResult<Array> {
    let mut array = ZendHashTable::new();
    array.insert("invocation_id", input.invocation_id.as_str())?;
    array.insert("random_seed", input.random_seed as i64)?;
    array.insert("key", input.key.as_str())?;
    array.insert("headers", header_list(&input.headers)?)?;
    array.insert("input", binary(&input.input))?;
    array.insert("scope", input.scope.as_deref())?;
    array.insert("limit_key", input.limit_key.as_deref())?;
    array.insert("idempotency_key", input.idempotency_key.as_deref())?;
    Ok(array)
}

/// `['min' => int, 'max' => int]`
pub fn protocol_versions(versions: ProtocolVersions) -> ExtensionResult<Array> {
    let mut array = ZendHashTable::new();
    array.insert("min", i64::from(versions.min))?;
    array.insert("max", i64::from(versions.max))?;
    Ok(array)
}

/// `['id' => string, 'handle' => int]`
pub fn awakeable(awakeable: &Awakeable) -> ExtensionResult<Array> {
    let mut array = ZendHashTable::new();
    array.insert("id", awakeable.id.as_str())?;
    array.insert("handle", i64::from(awakeable.handle))?;
    Ok(array)
}

/// Every progress state other than completion: `['type' => 'suspended']`,
/// `'read_from_input'`, `'waiting_pending_run'`, `'cancel_signal_received'`,
/// or `['type' => 'execute_run', 'handle' => int]`.
fn progress<T>(
    progress: Progress<T>,
    completed: impl FnOnce(T) -> ExtensionResult<Array>,
) -> ExtensionResult<Array> {
    match progress {
        Progress::Completed(value) => completed(value),
        Progress::Suspended => typed("suspended"),
        Progress::ReadFromInput => typed("read_from_input"),
        Progress::WaitingPendingRun => typed("waiting_pending_run"),
        Progress::CancelSignalReceived => typed("cancel_signal_received"),
        Progress::ExecuteRun(handle) => {
            let mut array = typed("execute_run")?;
            array.insert("handle", i64::from(handle))?;
            Ok(array)
        }
    }
}

/// `['type' => 'any_completed']` or a pending state.
pub fn await_outcome(outcome: Progress<()>) -> ExtensionResult<Array> {
    progress(outcome, |()| typed("any_completed"))
}

/// `['type' => 'any_completed', 'completed' => [handle, …]]` (the tree's
/// handles then completed, depth first) or a pending state.
pub fn await_tree_outcome(outcome: Progress<Vec<Handle>>) -> ExtensionResult<Array> {
    progress(outcome, |handles| {
        let mut completed = ZendHashTable::with_capacity(u32::try_from(handles.len()).unwrap_or(0));
        for handle in handles {
            completed.push(i64::from(handle))?;
        }
        let mut array = typed("any_completed")?;
        array.insert("completed", completed)?;
        Ok(array)
    })
}

fn success(bytes: &[u8]) -> ExtensionResult<Array> {
    let mut array = typed("success")?;
    array.insert("value", binary(bytes))?;
    Ok(array)
}

fn failure(failure: &TerminalFailure) -> ExtensionResult<Array> {
    let mut metadata = ZendHashTable::new();
    for (key, value) in failure.metadata() {
        metadata.insert(key.as_str(), value.as_str())?;
    }

    let mut array = typed("failure")?;
    array.insert("code", i64::from(failure.code()))?;
    array.insert("message", failure.message())?;
    array.insert("metadata", metadata)?;
    Ok(array)
}

/// `['type' => 'success', 'value' => binary string]`, `['type' => 'void']`,
/// `['type' => 'failure', 'code' => int, 'message' => string, 'metadata' => [key => value]]`,
/// `['type' => 'invocation_id', 'invocation_id' => string]` or
/// `['type' => 'state_keys', 'keys' => [string, …]]`.
pub fn notification_result(result: NotificationResult) -> ExtensionResult<Array> {
    match result {
        NotificationResult::Success(bytes) => success(&bytes),
        NotificationResult::Void => typed("void"),
        NotificationResult::Failure(terminal) => failure(&terminal),
        NotificationResult::InvocationId(invocation_id) => {
            let mut array = typed("invocation_id")?;
            array.insert("invocation_id", invocation_id)?;
            Ok(array)
        }
        NotificationResult::StateKeys(keys) => {
            let mut list = ZendHashTable::with_capacity(u32::try_from(keys.len()).unwrap_or(0));
            for key in keys {
                list.push(key)?;
            }
            let mut array = typed("state_keys")?;
            array.insert("keys", list)?;
            Ok(array)
        }
    }
}

fn on_max_attempts(array: &ZendHashTable) -> ExtensionResult<OnMaxAttempts> {
    Ok(
        match optional_string_field(array, "on_max_attempts", "Retry policy")?.as_deref() {
            None | Some("fail") => OnMaxAttempts::Fail,
            Some("pause") => OnMaxAttempts::Pause,
            Some(other) => {
                return Err(ExtensionError::invalid_argument(format!(
                    "unsupported retry policy 'on_max_attempts': {other}"
                )))
            }
        },
    )
}

/// `['type' => 'attempt_ended'|'completed']`
pub fn run_retry(outcome: RunRetryOutcome) -> ExtensionResult<Array> {
    typed(match outcome {
        RunRetryOutcome::AttemptEnded => "attempt_ended",
        RunRetryOutcome::Completed => "completed",
    })
}

/// The level to capture shared-core's log events at, or none.
pub fn log_level(name: Option<String>) -> ExtensionResult<Option<LogLevel>> {
    Ok(name.as_deref().map(LogLevel::parse).transpose()?)
}

/// `[['level' => 'error'|'warn'|'info'|'debug'|'trace', 'target' => string,
/// 'message' => string, 'fields' => [name => string]], …]`
pub fn log_records(records: Vec<LogRecord>) -> ExtensionResult<Array> {
    let mut list = ZendHashTable::with_capacity(u32::try_from(records.len()).unwrap_or(0));
    for record in records {
        let mut fields =
            ZendHashTable::with_capacity(u32::try_from(record.fields.len()).unwrap_or(0));
        for (name, value) in record.fields {
            fields.insert(name.as_str(), value)?;
        }
        let mut array = ZendHashTable::with_capacity(4);
        array.insert("level", record.level.as_str())?;
        array.insert("target", record.target)?;
        array.insert("message", record.message)?;
        array.insert("fields", fields)?;
        list.push(array)?;
    }
    Ok(list)
}
