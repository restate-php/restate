//! `Restate\Extension\ExtensionException`: the one exception class the extension
//! throws, and the conversion of every failure into it.
//!
//! The class is registered with a plain [`ClassBuilder`] rather than
//! `#[php_class]`: a `#[php_class]` replaces the class's `create_object`
//! handler, so an exception built from it would not get the file, line and
//! trace PHP records for every other exception. Registered this way it is an
//! ordinary subclass of `\RuntimeException` with one extra public property,
//! `category`.

use ext_php_rs::args::ArgInfoTables;
use ext_php_rs::builders::{ClassBuilder, ClassProperty};
use ext_php_rs::convert::IntoZval;
use ext_php_rs::exception::PhpException;
use ext_php_rs::flags::{ClassFlags, PropertyFlags};
use ext_php_rs::types::ZendObject;
use ext_php_rs::zend::{ce, ClassEntry};
use restate_php_core::{Error, ErrorCategory};
use std::fmt::Display;
use std::ptr;
use std::sync::atomic::{AtomicPtr, Ordering};

pub const CLASS_NAME: &str = "Restate\\Extension\\ExtensionException";

static CLASS_ENTRY: AtomicPtr<ClassEntry> = AtomicPtr::new(ptr::null_mut());

extern "C" {
    /// `\RuntimeException`, exported by PHP (`ext/spl/spl_exceptions.h`). SPL
    /// is always compiled in and starts before any shared extension.
    static spl_ce_RuntimeException: *mut ClassEntry;
}

fn runtime_exception_entry() -> Option<&'static ClassEntry> {
    // SAFETY: PHP sets the pointer during SPL's module startup and never
    // changes it afterwards; the class entry lives as long as the process.
    unsafe { spl_ce_RuntimeException.as_ref() }
}

fn runtime_exception() -> &'static ClassEntry {
    // `register` checks that the class exists before the builder calls this.
    runtime_exception_entry().unwrap_or_else(ce::exception)
}

fn store_class_entry(class: &'static mut ClassEntry, _: ArgInfoTables) {
    CLASS_ENTRY.store(class, Ordering::Release);
}

fn class_entry() -> Option<&'static ClassEntry> {
    // SAFETY: the pointer is either null or the class entry PHP registered at
    // module startup, which lives as long as the process.
    unsafe { CLASS_ENTRY.load(Ordering::Acquire).as_ref() }
}

/// Registers the exception class. Runs from the module's startup function,
/// before the `#[php_class]` classes; returns PHP's `SUCCESS` (0) or
/// `FAILURE` (-1).
///
/// # Safety
///
/// PHP calls this during module startup (MINIT).
pub unsafe extern "C" fn register(_type: i32, _module_number: i32) -> i32 {
    if runtime_exception_entry().is_none() {
        return -1;
    }

    let result = ClassBuilder::new(CLASS_NAME)
        .extends((runtime_exception, "\\RuntimeException"))
        .flags(ClassFlags::Final)
        .property(ClassProperty {
            name: "category".to_string(),
            flags: PropertyFlags::Public,
            default: Some(Box::new(|| {
                ErrorCategory::Internal.as_str().into_zval(true)
            })),
            docs: &[],
            ty: None,
            nullable: false,
            readonly: false,
            default_stub: None,
        })
        .registration(store_class_entry)
        .register();

    if result.is_ok() {
        0
    } else {
        -1
    }
}

/// A failure on its way to PHP. Every exported function and method returns
/// `Result<T, ExtensionError>`, so every failure — a core error, or an
/// `ext-php-rs` conversion error — is thrown as `ExtensionException`.
#[derive(Debug)]
pub struct ExtensionError(Error);

impl ExtensionError {
    pub fn internal(message: impl Display) -> Self {
        Self(Error::internal(message.to_string()))
    }

    pub fn invalid_argument(message: impl Display) -> Self {
        Self(Error::invalid_argument(message.to_string()))
    }
}

impl From<Error> for ExtensionError {
    fn from(error: Error) -> Self {
        Self(error)
    }
}

impl From<ext_php_rs::error::Error> for ExtensionError {
    fn from(error: ext_php_rs::error::Error) -> Self {
        Self::internal(error)
    }
}

pub type ExtensionResult<T> = Result<T, ExtensionError>;

impl From<ExtensionError> for PhpException {
    fn from(ExtensionError(error): ExtensionError) -> Self {
        let message = error.message().to_string();
        let code = error.code().map_or(0, i64::from);

        let Some(class) = class_entry() else {
            // Unreachable once the module started, which requires the class.
            return PhpException::new(message, 0, runtime_exception());
        };

        // `ZendObject::new` runs the inherited `\Exception` object handler,
        // which records file, line and trace; the constructor sets message
        // and code as for any exception.
        let build = || -> ext_php_rs::error::Result<_> {
            let mut object = ZendObject::new(class);
            object.try_call_method("__construct", vec![&message, &code])?;
            object.set_property("category", error.category().as_str())?;
            object.into_zval(false)
        };

        match build() {
            Ok(zval) => PhpException::new(message, 0, class).with_object(zval),
            // Without the object, `throw` still raises the right class with
            // the message and code; only the category falls back.
            Err(_) => PhpException::new(message, i32::try_from(code).unwrap_or(0), class),
        }
    }
}
