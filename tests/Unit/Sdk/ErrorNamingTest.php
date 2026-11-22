<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Restate\BackendUnavailableException;
use Restate\Context;
use Restate\DurableFutureAggregateException;
use Restate\DurablePromise;
use Restate\Exception;
use Restate\Internal\BackendErrorCategory;
use Restate\Internal\BackendException;
use Restate\Internal\SuspensionException;
use Restate\JsonMappingException;
use Restate\PauseError;
use Restate\RetryableError;
use Restate\TerminalError;
use Restate\UnsupportedJsonSchemaException;

final class ErrorNamingTest extends TestCase
{
    /** @return iterable<string, array{\Throwable}> */
    public static function sdkExceptions(): iterable
    {
        yield 'terminal error' => [new TerminalError('declined', 402)];
        yield 'retryable error' => [new RetryableError('try again')];
        yield 'pause error' => [new PauseError('needs a human')];
        yield 'backend exception' => [new BackendException('closed', 598, category: BackendErrorCategory::Closed)];
        yield 'backend unavailable' => [new BackendUnavailableException('not loaded')];
        yield 'json mapping' => [JsonMappingException::at('$.id', 'expected int')];
        yield 'unsupported json schema' => [new UnsupportedJsonSchemaException('unsupported')];
        yield 'durable future aggregate' => [new DurableFutureAggregateException([])];
    }

    #[DataProvider('sdkExceptions')]
    public function testEverySdkExceptionImplementsTheMarkerInterface(\Throwable $exception): void
    {
        self::assertInstanceOf(Exception::class, $exception);
        self::assertInstanceOf(\Exception::class, $exception);
    }

    public function testCatchingTheMarkerInterfaceDoesNotCatchASuspension(): void
    {
        $suspension = new SuspensionException('Sleep suspended.');
        self::assertNotContains(Exception::class, class_implements(SuspensionException::class));
        $throw = static function (\Throwable $e): never {
            throw $e;
        };

        $caught = null;
        try {
            try {
                $throw($suspension);
            } catch (Exception) {
                self::fail('catch (Restate\Exception) must not catch a suspension.');
            }
        } catch (SuspensionException $e) {
            $caught = $e;
        }

        self::assertSame($suspension, $caught);
    }

    public function testTerminalErrorCarriesStatusCodeAndMetadata(): void
    {
        $exception = new TerminalError('declined', 402, ['reason' => 'card']);

        self::assertSame(402, $exception->code());
        self::assertSame(402, $exception->statusCode());
        self::assertSame(['reason' => 'card'], $exception->metadata());
    }

    public function testRetryableErrorAllowsDefaultConstruction(): void
    {
        $exception = new RetryableError('');

        self::assertSame('', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertNull($exception->retryAfterMillis());
    }

    public function testRetryableErrorPreservesCodeAndPrevious(): void
    {
        $previous = new \RuntimeException('inner');
        $exception = new RetryableError('try again', previous: $previous, code: 123);

        self::assertSame('try again', $exception->getMessage());
        self::assertSame(123, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
        self::assertNull($exception->retryAfterMillis());
    }

    public function testRetryableErrorUsesRetryAfterMillisAndDefaultExceptionCode(): void
    {
        $exception = new RetryableError('try later', 250);

        self::assertSame('try later', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertSame(250, $exception->retryAfterMillis());
    }

    public function testRetryableErrorCarriesCodeAndMetadata(): void
    {
        $exception = new RetryableError('unavailable', code: 503, metadata: ['retry-reason' => 'maintenance', '503' => 'numeric key']);

        self::assertSame(503, $exception->getCode());
        self::assertSame(['retry-reason' => 'maintenance', '503' => 'numeric key'], $exception->metadata());
        self::assertSame([], new RetryableError('unavailable')->metadata());
    }

    public function testRetryableErrorRejectsMetadataThatIsNotAStringMap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Retryable error metadata must be a string-to-string map.');

        new RetryableError('unavailable', metadata: ['attempt' => 2]);
    }

    public function testRetryableErrorRejectsANegativeRetryAfter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry-after delay must be greater than or equal to 0 milliseconds.');

        new RetryableError('try later', -1);
    }

    public function testContextAwakeableRejectionAcceptsTerminalError(): void
    {
        $method = new ReflectionMethod(Context::class, 'rejectAwakeable');
        $parameters = $method->getParameters();

        self::assertSame(TerminalError::class, (string) $parameters[1]->getType());
    }

    public function testDurablePromiseRejectionAcceptsTerminalError(): void
    {
        $method = new ReflectionMethod(DurablePromise::class, 'reject');
        $parameters = $method->getParameters();

        self::assertSame(TerminalError::class, (string) $parameters[0]->getType());
    }
}
