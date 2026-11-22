# Exception Hierarchy

## Status

Accepted. Builds on [Suspension Is Not an Exception](0013-suspension-is-not-an-exception.md).

## Decision

1. **One marker interface, `Restate\Exception`** (extends `\Throwable`). Every exception the SDK throws for application code to handle implements it:

   | Class | Base | Public | Meaning |
   | --- | --- | --- | --- |
   | `Restate\TerminalError` | `\RuntimeException` | yes | A failure that is not retried: thrown by a handler it fails the invocation; thrown by the SDK it is a failed call, awakeable, promise or `ctx.run`, or a cancellation (409). |
   | `Restate\RetryableError` | `\RuntimeException` | yes | A failure worth retrying, optionally with a retry-after delay, a status code and metadata. |
   | `Restate\PauseError` | `\RuntimeException` | yes | A failure that pauses the invocation until someone resumes it ([Pause](0021-pause.md)). |
   | `Restate\BackendUnavailableException` | `\RuntimeException` | yes | The extension is missing or speaks another contract version. |
   | `Restate\JsonMappingException`, `Restate\UnsupportedJsonSchemaException` | `\RuntimeException` | yes | JSON mapping and schema failures. |
   | `Restate\DurableFutureAggregateException` | `\RuntimeException` | yes | Every future passed to `Context::any()` failed. |
   | `Restate\Internal\BackendException` | `\RuntimeException` | internal | An extension backend failure, with a `BackendErrorCategory`. |

2. **One class per meaning, the same for the SDK and for handlers.** The SDK throws `TerminalError` itself (for example `TerminalError('Invocation cancelled', 409)`), and handlers throw `TerminalError` or `RetryableError`; there is no SDK-only subclass to catch instead and no second retryable class with another constructor.

3. **`Restate\Internal\SuspensionException` does not implement `Restate\Exception`.** It is an internal `final` `\Error`, as decided in [Suspension Is Not an Exception](0013-suspension-is-not-an-exception.md). A marker interface is something users catch (`catch (\Restate\Exception $e)` for "anything the SDK raised"); if a suspension matched it, that catch block would run on every suspension, which is exactly what making it an `\Error` prevented for `catch (\Exception)`. The interface is the reason this works: a class hierarchy rooted in `\Exception` could not include the `\Error`, an interface could, and leaving the suspension out is a choice, not a limitation. The official SDKs do the same: Python's `SdkInternalBaseException` derives from `BaseException`, not from `TerminalError`'s `Exception` root; Java's `AbortedExecutionException` is a bare `Throwable`.

4. PHP's own exceptions are not wrapped: argument validation throws `\InvalidArgumentException`, and a serializer's `\JsonException` propagates unwrapped.

## Consequences

- `catch (TerminalError)` catches every terminal failure, whether a handler or the SDK raised it.
- `tests/Unit/Sdk/ErrorNamingTest.php` pins the hierarchy, including that `catch (\Restate\Exception)` does not catch a suspension.
