//! Capturing shared-core's log events for one VM.
//!
//! shared-core logs through `tracing` (`debug!("Failed with error {e}")` on
//! a journal mismatch, `debug!("Suspending")`, a `warn!` for an unexpected
//! ephemeral notification, `trace` spans on every VM call). Without a
//! subscriber every event is dropped. A VM created with a [`LogLevel`] gets
//! its own [`LogCapture`]: a buffer for the events at or above that level,
//! which the caller drains with [`crate::Vm::take_logs`] (the PHP side
//! forwards them to the endpoint's PSR-3 logger). The official SDKs that use
//! shared-core do the same with a subscriber that forwards each formatted
//! line (TypeScript: one per VM; Java: one global, through an FFM upcall).
//!
//! **One subscriber, a capture per VM.** The first capture installs one
//! process-wide subscriber as `tracing`'s global default; there is never
//! another. It keeps an event only when the calling thread has an active
//! capture: [`LogCapture::enter`] (through [`crate::Vm::logged`]) pushes the
//! VM's capture on a thread-local stack while one of its operations runs,
//! and the subscriber hands each event to the capture on top, if the event
//! is at or above that capture's level. Captures share nothing, and a thread
//! only ever sees its own stack, so concurrent requests in a thread-safe PHP
//! build each capture their own events.
//!
//! The subscriber is registered once, not per VM. Registering a `tracing`
//! dispatcher takes `tracing-core`'s process-wide dispatcher lock and
//! re-evaluates every callsite against every live dispatcher, so one
//! dispatcher per VM made creating a VM cost time linear in the number of
//! live capturing VMs, under that lock. Now creating a capture allocates a
//! buffer, and takes the lock only the first time a capture asks for a more
//! verbose level than any before it (at most five times per process): the
//! subscriber's level hint is the most verbose level any capture asked for,
//! so events above it stay disabled at the callsite, as before.
//!
//! A process that never creates a capture installs nothing and pays nothing:
//! events are disabled before they are built. Should another global default
//! already be set (nothing in the extension sets one), captures fall back to
//! making the subscriber the thread's default while they are active.

use std::cell::RefCell;
use std::fmt::Debug;
use std::marker::PhantomData;
use std::sync::atomic::{AtomicU8, Ordering};
use std::sync::{Arc, Mutex, OnceLock, PoisonError};

use tracing::dispatcher::DefaultGuard;
use tracing::field::{Field, Visit};
use tracing::level_filters::LevelFilter;
use tracing::subscriber::Interest;
use tracing::{span, Dispatch, Event, Level, Metadata, Subscriber};

use crate::error::{Error, Result};

/// The most records a VM keeps between two drains. The PHP side drains after
/// every operation, so this only bounds a VM whose caller never does; past
/// it, records are counted and reported as one warning when drained.
pub const MAX_BUFFERED_RECORDS: usize = 1000;

/// A `tracing` level, by the names `tracing` uses.
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord)]
pub enum LogLevel {
    Error,
    Warn,
    Info,
    Debug,
    Trace,
}

impl LogLevel {
    pub fn parse(name: &str) -> Result<Self> {
        match name {
            "error" => Ok(Self::Error),
            "warn" => Ok(Self::Warn),
            "info" => Ok(Self::Info),
            "debug" => Ok(Self::Debug),
            "trace" => Ok(Self::Trace),
            _ => Err(Error::invalid_argument(format!(
                "Unknown log level '{name}'; expected error, warn, info, debug or trace."
            ))),
        }
    }

    pub fn as_str(self) -> &'static str {
        match self {
            Self::Error => "error",
            Self::Warn => "warn",
            Self::Info => "info",
            Self::Debug => "debug",
            Self::Trace => "trace",
        }
    }

    fn tracing(self) -> Level {
        match self {
            Self::Error => Level::ERROR,
            Self::Warn => Level::WARN,
            Self::Info => Level::INFO,
            Self::Debug => Level::DEBUG,
            Self::Trace => Level::TRACE,
        }
    }

    fn from_tracing(level: Level) -> Self {
        match level {
            Level::ERROR => Self::Error,
            Level::WARN => Self::Warn,
            Level::INFO => Self::Info,
            Level::DEBUG => Self::Debug,
            _ => Self::Trace,
        }
    }

    /// 1 (error) to 5 (trace); 0 is "off".
    fn rank(self) -> u8 {
        self as u8 + 1
    }

    fn from_rank(rank: u8) -> Option<Self> {
        [
            Self::Error,
            Self::Warn,
            Self::Info,
            Self::Debug,
            Self::Trace,
        ]
        .get(usize::from(rank).checked_sub(1)?)
        .copied()
    }
}

/// One captured event.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct LogRecord {
    pub level: LogLevel,
    /// The module that logged, e.g. `restate_sdk_shared_core::vm`.
    pub target: String,
    /// The formatted message.
    pub message: String,
    /// The event's other fields, `Debug`-formatted (strings as they are).
    pub fields: Vec<(String, String)>,
}

#[derive(Default)]
struct Buffer {
    records: Vec<LogRecord>,
    dropped: usize,
}

/// What one capture shares with the thread-local stack while it is active.
struct Capture {
    max_level: LevelFilter,
    buffer: Mutex<Buffer>,
}

impl Capture {
    fn keeps(&self, metadata: &Metadata<'_>) -> bool {
        metadata.is_event() && *metadata.level() <= self.max_level
    }

    fn push(&self, record: LogRecord) {
        let mut buffer = self.buffer.lock().unwrap_or_else(PoisonError::into_inner);
        if buffer.records.len() < MAX_BUFFERED_RECORDS {
            buffer.records.push(record);
        } else {
            buffer.dropped += 1;
        }
    }
}

thread_local! {
    /// The captures active on this thread, innermost last.
    static ACTIVE: RefCell<Vec<Arc<Capture>>> = const { RefCell::new(Vec::new()) };
}

/// The innermost capture active on this thread, if it keeps this event.
fn with_active(metadata: &Metadata<'_>, f: impl FnOnce(&Capture)) {
    // `try_with`: an event logged while the thread shuts down finds no stack.
    let _ = ACTIVE.try_with(|active| {
        if let Some(capture) = active.borrow().last() {
            if capture.keeps(metadata) {
                f(capture);
            }
        }
    });
}

/// The most verbose level any capture asked for so far, as a rank (0: none).
/// The subscriber's level hint and callsite interest follow it.
static REQUESTED: AtomicU8 = AtomicU8::new(0);

/// The rank up to which `tracing` has applied [`REQUESTED`] (the subscriber
/// is installed and its callsites re-evaluated); below it, creating a
/// capture takes no lock.
static READY: AtomicU8 = AtomicU8::new(0);

/// Serializes installing the subscriber and raising its level.
static RAISING: Mutex<()> = Mutex::new(());

/// The subscriber, once installed: `None` when it is the global default,
/// the dispatcher to scope with when another global default was already set.
static INSTALLED: OnceLock<Option<Dispatch>> = OnceLock::new();

/// Makes sure the subscriber exists and `tracing` lets events at `level`
/// through to it. Takes `tracing`'s dispatcher lock only when that changes
/// something: the first time, and whenever `level` is more verbose than
/// every level before.
fn ensure_level(level: LogLevel) {
    let rank = level.rank();
    if READY.load(Ordering::Acquire) >= rank {
        return;
    }
    let _raising = RAISING.lock().unwrap_or_else(PoisonError::into_inner);
    if READY.load(Ordering::Acquire) >= rank {
        return;
    }
    REQUESTED.store(rank, Ordering::Release);
    if INSTALLED.get().is_some() {
        // Re-asks every callsite and the level hint, under tracing's lock.
        tracing::callsite::rebuild_interest_cache();
    } else {
        // Registering the dispatcher evaluates the callsites with the level
        // just stored.
        let dispatch = Dispatch::new(CaptureSubscriber);
        let scoped = tracing::dispatcher::set_global_default(dispatch.clone())
            .err()
            .map(|_| dispatch);
        let _ = INSTALLED.set(scoped);
    }
    READY.store(rank, Ordering::Release);
}

/// One VM's capture: its level and its buffer.
pub(crate) struct LogCapture {
    capture: Arc<Capture>,
}

/// While alive, its capture is the thread's innermost one. Not `Send`: it
/// must be dropped on the thread that entered.
pub(crate) struct Active {
    /// The thread's default dispatcher, only when the subscriber is not the
    /// global default.
    _scoped: Option<DefaultGuard>,
    _not_send: PhantomData<*const ()>,
}

impl Drop for Active {
    fn drop(&mut self) {
        let _ = ACTIVE.try_with(|active| active.borrow_mut().pop());
    }
}

impl LogCapture {
    pub(crate) fn new(level: LogLevel) -> Self {
        ensure_level(level);
        Self {
            capture: Arc::new(Capture {
                max_level: LevelFilter::from_level(level.tracing()),
                buffer: Mutex::new(Buffer::default()),
            }),
        }
    }

    /// Makes this capture the thread's innermost one until the guard drops
    /// (also when unwinding from a panic).
    pub(crate) fn enter(&self) -> Active {
        let scoped = INSTALLED
            .get()
            .and_then(Option::as_ref)
            .map(tracing::dispatcher::set_default);
        ACTIVE.with(|active| active.borrow_mut().push(Arc::clone(&self.capture)));
        Active {
            _scoped: scoped,
            _not_send: PhantomData,
        }
    }

    /// Runs `operation` with this capture active.
    pub(crate) fn scope<T>(&self, operation: impl FnOnce() -> T) -> T {
        let _active = self.enter();
        operation()
    }

    /// The records captured since the last call, oldest first, followed by a
    /// warning when some were dropped.
    pub(crate) fn take(&self) -> Vec<LogRecord> {
        let mut buffer = self
            .capture
            .buffer
            .lock()
            .unwrap_or_else(PoisonError::into_inner);
        let mut records = std::mem::take(&mut buffer.records);
        let dropped = std::mem::take(&mut buffer.dropped);
        if dropped > 0 {
            records.push(LogRecord {
                level: LogLevel::Warn,
                target: module_path!().to_owned(),
                message: format!(
                    "Dropped {dropped} shared-core log records: more than \
                     {MAX_BUFFERED_RECORDS} were captured without being taken."
                ),
                fields: vec![],
            });
        }
        records
    }
}

/// The one subscriber: hands each event to the thread's innermost capture.
struct CaptureSubscriber;

impl CaptureSubscriber {
    fn requested() -> LevelFilter {
        LogLevel::from_rank(REQUESTED.load(Ordering::Acquire)).map_or(LevelFilter::OFF, |level| {
            LevelFilter::from_level(level.tracing())
        })
    }
}

impl Subscriber for CaptureSubscriber {
    fn register_callsite(&self, metadata: &'static Metadata<'static>) -> Interest {
        // Events up to the most verbose level asked for so far may be kept,
        // depending on the thread's capture: `sometimes` keeps asking
        // `enabled`. Spans never are (shared-core's spans are `trace`
        // instrumentation of every VM call, not log lines).
        if metadata.is_event() && *metadata.level() <= Self::requested() {
            Interest::sometimes()
        } else {
            Interest::never()
        }
    }

    fn max_level_hint(&self) -> Option<LevelFilter> {
        Some(Self::requested())
    }

    fn enabled(&self, metadata: &Metadata<'_>) -> bool {
        let mut enabled = false;
        with_active(metadata, |_| enabled = true);
        enabled
    }

    fn new_span(&self, _: &span::Attributes<'_>) -> span::Id {
        // Never called: `register_callsite` refuses every span.
        span::Id::from_u64(1)
    }

    fn record(&self, _: &span::Id, _: &span::Record<'_>) {}

    fn record_follows_from(&self, _: &span::Id, _: &span::Id) {}

    fn event(&self, event: &Event<'_>) {
        let metadata = event.metadata();
        with_active(metadata, |capture| {
            let mut visitor = FieldVisitor::default();
            event.record(&mut visitor);
            capture.push(LogRecord {
                level: LogLevel::from_tracing(*metadata.level()),
                target: metadata.target().to_owned(),
                message: visitor.message,
                fields: visitor.fields,
            });
        });
    }

    fn enter(&self, _: &span::Id) {}

    fn exit(&self, _: &span::Id) {}
}

#[derive(Default)]
struct FieldVisitor {
    message: String,
    fields: Vec<(String, String)>,
}

impl FieldVisitor {
    fn add(&mut self, field: &Field, value: String) {
        if field.name() == "message" {
            self.message = value;
        } else {
            self.fields.push((field.name().to_owned(), value));
        }
    }
}

impl Visit for FieldVisitor {
    fn record_str(&mut self, field: &Field, value: &str) {
        self.add(field, value.to_owned());
    }

    fn record_debug(&mut self, field: &Field, value: &dyn Debug) {
        // A message is `fmt::Arguments`, whose `Debug` is the formatted text.
        self.add(field, format!("{value:?}"));
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn captured(level: LogLevel, emit: impl FnOnce()) -> Vec<LogRecord> {
        let capture = LogCapture::new(level);
        capture.scope(emit);
        capture.take()
    }

    #[test]
    fn events_at_or_above_the_level_are_captured_with_their_fields() {
        let records = captured(LogLevel::Debug, || {
            tracing::trace!("too detailed");
            tracing::debug!(handle = 3, name = "charge", "Executing {}", "run");
            tracing::warn!("careful");
        });

        assert_eq!(
            records,
            vec![
                LogRecord {
                    level: LogLevel::Debug,
                    target: module_path!().to_owned(),
                    message: "Executing run".to_owned(),
                    fields: vec![
                        ("handle".to_owned(), "3".to_owned()),
                        ("name".to_owned(), "charge".to_owned()),
                    ],
                },
                LogRecord {
                    level: LogLevel::Warn,
                    target: module_path!().to_owned(),
                    message: "careful".to_owned(),
                    fields: vec![],
                },
            ]
        );
    }

    #[test]
    fn spans_are_not_captured() {
        let records = captured(LogLevel::Trace, || {
            let span = tracing::trace_span!("sys_run", name = "charge");
            let _entered = span.enter();
            tracing::trace!("inside");
        });

        assert_eq!(
            records
                .iter()
                .map(|r| r.message.as_str())
                .collect::<Vec<_>>(),
            vec!["inside"]
        );
    }

    #[test]
    fn take_drains_the_buffer() {
        let capture = LogCapture::new(LogLevel::Info);
        capture.scope(|| tracing::info!("one"));

        assert_eq!(capture.take().len(), 1);
        assert_eq!(capture.take(), vec![]);
    }

    #[test]
    fn events_outside_the_scope_are_not_captured() {
        let capture = LogCapture::new(LogLevel::Trace);
        tracing::warn!("not mine");
        capture.scope(|| tracing::warn!("mine"));

        assert_eq!(capture.take().len(), 1);
    }

    #[test]
    fn two_captures_on_one_thread_keep_their_own_events() {
        let debug = LogCapture::new(LogLevel::Debug);
        let warn = LogCapture::new(LogLevel::Warn);
        debug.scope(|| tracing::debug!("for debug"));
        warn.scope(|| {
            tracing::debug!("below warn");
            tracing::warn!("for warn");
        });

        assert_eq!(debug.take()[0].message, "for debug");
        assert_eq!(
            warn.take()
                .iter()
                .map(|r| r.message.as_str())
                .collect::<Vec<_>>(),
            vec!["for warn"]
        );
    }

    #[test]
    fn a_nested_capture_takes_over_until_it_ends() {
        let outer = LogCapture::new(LogLevel::Info);
        let inner = LogCapture::new(LogLevel::Info);
        outer.scope(|| {
            tracing::info!("outer before");
            inner.scope(|| tracing::info!("inner"));
            tracing::info!("outer after");
        });

        let messages = |capture: &LogCapture| {
            capture
                .take()
                .into_iter()
                .map(|r| r.message)
                .collect::<Vec<_>>()
        };
        assert_eq!(messages(&outer), vec!["outer before", "outer after"]);
        assert_eq!(messages(&inner), vec!["inner"]);
    }

    #[test]
    fn a_panic_inside_the_scope_ends_it() {
        let capture = LogCapture::new(LogLevel::Info);
        let unwound = std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
            capture.scope(|| panic!("inside"));
        }));
        assert!(unwound.is_err());

        tracing::info!("after the panic");

        assert_eq!(capture.take(), vec![]);
    }

    #[test]
    fn captures_on_other_threads_are_independent() {
        let handles: Vec<_> = (0..4)
            .map(|i| {
                std::thread::spawn(move || {
                    captured(LogLevel::Info, || {
                        for _ in 0..100 {
                            tracing::info!(thread = i, "event");
                        }
                    })
                })
            })
            .collect();

        for (i, handle) in handles.into_iter().enumerate() {
            let records = handle.join().unwrap();
            assert_eq!(records.len(), 100);
            assert!(records
                .iter()
                .all(|r| r.fields == vec![("thread".to_owned(), i.to_string())]));
        }
    }

    #[test]
    fn records_past_the_limit_are_dropped_and_reported() {
        let records = captured(LogLevel::Info, || {
            for _ in 0..MAX_BUFFERED_RECORDS + 5 {
                tracing::info!("event");
            }
        });

        assert_eq!(records.len(), MAX_BUFFERED_RECORDS + 1);
        let last = records.last().unwrap();
        assert_eq!(last.level, LogLevel::Warn);
        assert!(last
            .message
            .starts_with("Dropped 5 shared-core log records"));
    }

    #[test]
    fn level_names_parse_and_round_trip() {
        for level in [
            LogLevel::Error,
            LogLevel::Warn,
            LogLevel::Info,
            LogLevel::Debug,
            LogLevel::Trace,
        ] {
            assert_eq!(LogLevel::parse(level.as_str()).unwrap(), level);
        }
        assert!(LogLevel::parse("warning").is_err());
    }
}
