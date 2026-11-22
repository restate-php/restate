<?php

declare(strict_types=1);

namespace Restate\Tests\Extension;

use PHPUnit\Framework\TestCase;
use Restate\Extension\ExtensionException;
use Restate\Extension\Vm;
use Restate\Internal\Backend\AwaitResult;
use Restate\Internal\Backend\AwaitState;
use Restate\Internal\Backend\Extension\Backend;
use Restate\Internal\Backend\Extension\Invocation;
use Restate\Internal\Backend\NotificationResult;
use Restate\Internal\BackendException;
use Restate\Internal\CoreLogForwarder;
use Restate\Tests\Support\Protocol\FrameCodec;

/**
 * Tests in the `extension` suite run in a PHP process that has the built extension loaded, and drive the SDK's
 * extension backend (`Restate\Internal\Backend\Extension`) against a real shared-core VM with protocol frames built
 * by {@see FrameCodec}. Without the extension they fail; they never skip.
 *
 * `tests/Unit/Sdk/Backend/ExtensionSuiteTest.php` (unit suite, `extension` group) runs this suite in a child process; to
 * run it directly: `php -d extension=extension/target/debug/restate.so vendor/bin/phpunit --testsuite extension`.
 */
abstract class ExtensionTestCase extends TestCase
{
    protected const string V5 = 'application/vnd.restate.invocation.v5';

    protected const string V6 = 'application/vnd.restate.invocation.v6';

    protected const string V7 = 'application/vnd.restate.invocation.v7';

    protected const string INVOCATION_ID = 'inv-1';

    #[\Override]
    protected function setUp(): void
    {
        self::assertTrue(
            Backend::isLoaded(),
            'The extension suite needs the Restate extension loaded: run it with `php -d extension=<path> vendor/bin/phpunit --testsuite extension`, or through the unit suite (`composer test`).',
        );
    }

    protected static function newInvocation(string $contentType = self::V5, ?CoreLogForwarder $coreLogs = null): Invocation
    {
        $invocation = new Backend()->newInvocation([['content-type', $contentType]], $coreLogs);
        self::assertInstanceOf(Invocation::class, $invocation);

        return $invocation;
    }

    /**
     * An invocation that received a start message, the input command and $journal, with the handler running (it read
     * its input). The request body stays open with $open, so completions can be fed while the handler runs.
     *
     * @param list<string> $journal frames after the input command
     * @param array<string, string> $state eager state
     */
    protected static function running(
        array $journal = [],
        bool $open = false,
        string $key = '',
        array $state = [],
        bool $lazyState = false,
        string $contentType = self::V5,
        ?CoreLogForwarder $coreLogs = null,
    ): Invocation {
        $invocation = self::newInvocation($contentType, $coreLogs);
        $invocation->notifyInput(FrameCodec::startFrame(self::INVOCATION_ID, 1 + count($journal), $key, $state, $lazyState));
        $invocation->notifyInput(FrameCodec::inputCommandFrame('"Mary"'));
        foreach ($journal as $frame) {
            $invocation->notifyInput($frame);
        }
        if (!$open) {
            $invocation->notifyInputClosed();
        }
        self::assertTrue($invocation->isReadyToExecute());
        $invocation->input();

        return $invocation;
    }

    /** Everything the VM wrote since the last call. */
    protected static function written(Invocation $invocation): string
    {
        $output = '';
        while (($chunk = $invocation->takeOutput()) !== null && $chunk !== '') {
            $output .= $chunk;
        }

        return $output;
    }

    protected static function completed(Invocation $invocation, int $handle): NotificationResult
    {
        self::assertEquals(AwaitResult::completed(), $invocation->doAwait($handle));

        return $invocation->takeNotificationResult($handle);
    }

    /**
     * Awaits $handle while its completion has not arrived, feeds $completion, and takes the result.
     */
    protected static function completedAfter(Invocation $invocation, int $handle, string $completion): NotificationResult
    {
        self::assertEquals(AwaitResult::of(AwaitState::ReadFromInput), $invocation->doAwait($handle));
        $invocation->notifyInput($completion);

        return self::completed($invocation, $handle);
    }

    /**
     * running() on a bare `Restate\Extension\Vm`, for tests that check what the Extension Surface itself returns or
     * accepts: the arrays the typed contract is built from, and arguments the contract cannot express.
     *
     * @param list<string> $journal frames after the input command
     * @param array<string, string> $state eager state
     * @param 'error'|'warn'|'info'|'debug'|'trace'|null $logLevel capture shared-core's log events at this level
     */
    protected static function runningVm(
        array $journal = [],
        bool $open = false,
        string $key = '',
        array $state = [],
        string $contentType = self::V5,
        ?string $logLevel = null,
    ): Vm {
        $vm = new Vm([['content-type', $contentType]], $logLevel);
        $vm->notifyInput(FrameCodec::startFrame(self::INVOCATION_ID, 1 + count($journal), $key, $state));
        $vm->notifyInput(FrameCodec::inputCommandFrame('"Mary"'));
        foreach ($journal as $frame) {
            $vm->notifyInput($frame);
        }
        if (!$open) {
            $vm->notifyInputClosed();
        }
        self::assertTrue($vm->isReadyToExecute());
        $vm->input();

        return $vm;
    }

    /**
     * Runs a call on a bare Vm and rethrows a ExtensionException the way the extension backend does.
     *
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     *
     * @throws BackendException
     */
    protected static function vmCall(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (ExtensionException $e) {
            throw Invocation::backendException($e);
        }
    }
}
