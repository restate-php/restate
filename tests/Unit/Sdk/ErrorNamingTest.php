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
use Restate\JsonSchema\Generation\UnsupportedJsonSchemaTypeException;
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
        yield 'unsupported json schema type' => [UnsupportedJsonSchemaTypeException::forProperty('Order', 'items', 'array')];
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

        self::assertSame(402, $exception->statusCode());
        self::assertSame(['reason' => 'card'], $exception->metadata());
    }

    public function testRetryableErrorAllowsDefaultConstruction(): void
    {
        $exception = new RetryableError('');

        self::assertSame('', $exception->getMessage());
        self::assertSame(500, $exception->statusCode());
        self::assertSame(500, $exception->getCode());
        self::assertSame([], $exception->metadata());
        self::assertNull($exception->getPrevious());
        self::assertNull($exception->retryAfterMillis());
    }

    /** The constructor mirrors TerminalError's, with the retry-after delay last. */
    public function testRetryableErrorTakesItsArgumentsInTerminalErrorOrder(): void
    {
        $previous = new \RuntimeException('inner');
        $exception = new RetryableError('unavailable', 503, ['retry-reason' => 'maintenance', '503' => 'numeric key'], $previous, 250);

        self::assertSame('unavailable', $exception->getMessage());
        self::assertSame(503, $exception->statusCode());
        self::assertSame(503, $exception->getCode());
        self::assertSame(['retry-reason' => 'maintenance', '503' => 'numeric key'], $exception->metadata());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(250, $exception->retryAfterMillis());
    }

    public function testRetryableErrorTakesARetryAfterByName(): void
    {
        $exception = new RetryableError('try later', retryAfterMillis: 250);

        self::assertSame(500, $exception->statusCode());
        self::assertSame(250, $exception->retryAfterMillis());
    }

    /** @return iterable<string, array{int}> */
    public static function invalidRetryableStatusCodes(): iterable
    {
        yield 'zero' => [0];
        yield 'success' => [200];
        yield 'below the failure range' => [399];
        yield 'above the failure range' => [600];
    }

    #[DataProvider('invalidRetryableStatusCodes')]
    public function testRetryableErrorRejectsAStatusCodeThatIsNotAFailure(int $statusCode): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Retryable status code must be between 400 and 599.');

        new RetryableError('unavailable', $statusCode);
    }

    public function testRetryableErrorRejectsTheJournalMismatchStatusCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Retryable status code must not be 570, which Restate reserves for journal mismatches.');

        new RetryableError('unavailable', 570);
    }

    public function testRetryableErrorAcceptsTheBoundsOfTheFailureRange(): void
    {
        self::assertSame(400, new RetryableError('bad', 400)->statusCode());
        self::assertSame(599, new RetryableError('bad', 599)->statusCode());
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

        new RetryableError('try later', retryAfterMillis: -1);
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
