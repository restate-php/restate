<?php

declare(strict_types=1);

namespace Restate;

/**
 * Implemented by every exception the SDK throws for application code to handle: {@see TerminalError} (a failure that
 * is not retried), {@see RetryableError} (a failure worth retrying), {@see PauseError} (a failure that pauses the
 * invocation until someone resumes it), the SDK's own failures (an unavailable or failing
 * extension backend, JSON mapping and schema errors, {@see DurableFutureAggregateException}).
 *
 * `catch (\Restate\Exception $e)` catches all of them, but never a Suspension: the SDK ends a suspended attempt with
 * an internal `\Error` that deliberately does not implement this interface, so catching SDK exceptions cannot swallow
 * it (see docs/decisions/0015-exception-hierarchy.md). PHP's own exceptions for invalid arguments
 * (`\InvalidArgumentException`) and JSON encoding (`\JsonException`) are not wrapped.
 */
interface Exception extends \Throwable
{
}
