<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk\Backend;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Restate\Tests\Support\ExtensionProcess;

/**
 * The extension's panic guard, driven by the debug-only panic probe (`extension/extension/src/probe.rs`):
 * in a debug build the probe value panics when it is converted from a PHP array, or when it names a `sysRun()`.
 * The default test build (`composer build:extension`) is a debug build.
 */
#[Group('extension')]
final class ExtensionPanicTest extends TestCase
{
    private const string CONTENT_TYPE = 'application/vnd.restate.invocation.v5';

    private const string PROBE = '__restate_php_panic_probe__';

    private const string DESCRIBE = <<<'PHP'
        $describe = static function (callable $call): array {
            try {
                return ['returned' => $call()];
            } catch (Throwable $e) {
                return [
                    'class' => $e::class,
                    'category' => $e instanceof Restate\Internal\BackendException ? $e->category()->value : ($e->category ?? null),
                    'message' => $e->getMessage(),
                    'previous' => $e->getPrevious() === null ? null : $e->getPrevious()::class,
                ];
            }
        };
        PHP;

    public function testPanicInsideAnOperationIsAnExtensionExceptionAndPoisonsOnlyThatVm(): void
    {
        $result = ExtensionProcess::runJson(self::DESCRIBE . <<<'PHP'
            $vm = new Restate\Extension\Vm([['content-type', 'CONTENT_TYPE']]);
            $other = new Restate\Extension\Vm([['content-type', 'CONTENT_TYPE']]);
            echo json_encode([
                'panic' => $describe(static fn () => $vm->sysRun('PROBE')),
                'afterPanic' => $describe(static fn () => $vm->responseHead()['status_code']),
                'stillPoisoned' => $describe(static fn () => $vm->isReplaying()),
                'otherVm' => $describe(static fn () => $other->responseHead()['status_code']),
                'freshVm' => $describe(static fn () => (new Restate\Extension\Vm([['content-type', 'CONTENT_TYPE']]))->responseHead()['status_code']),
            ]);
            PHP, ['CONTENT_TYPE' => self::CONTENT_TYPE, 'PROBE' => self::PROBE]);

        self::assertIsArray($result);
        self::assertSame([
            'class' => 'Restate\\Extension\\ExtensionException',
            'category' => 'internal',
            'message' => 'Rust panic: restate panic probe',
            'previous' => null,
        ], $result['panic']);

        $poisoned = [
            'class' => 'Restate\\Extension\\ExtensionException',
            'category' => 'internal',
            'message' => 'Restate VM is unusable: an earlier call on it panicked (Rust panic: restate panic probe).',
            'previous' => null,
        ];
        self::assertSame($poisoned, $result['afterPanic']);
        self::assertSame($poisoned, $result['stillPoisoned']);

        self::assertSame(['returned' => 200], $result['otherVm']);
        self::assertSame(['returned' => 200], $result['freshVm']);
    }

    public function testPanicWhileConvertingArgumentsIsAnExtensionException(): void
    {
        $result = ExtensionProcess::runJson(self::DESCRIBE . <<<'PHP'
            $vm = new Restate\Extension\Vm([['content-type', 'CONTENT_TYPE']]);
            echo json_encode([
                'constructor' => $describe(static fn () => new Restate\Extension\Vm([['content-type', 'CONTENT_TYPE'], ['PROBE', 'x']])),
                'method' => $describe(static fn () => $vm->sysCall(['kind' => 'service', 'service' => 'PROBE', 'handler' => 'h'], '', null)),
                'afterPanic' => $describe(static fn () => $vm->responseHead()['status_code']),
                'freshVm' => $describe(static fn () => (new Restate\Extension\Vm([['content-type', 'CONTENT_TYPE']]))->responseHead()['status_code']),
            ]);
            PHP, ['CONTENT_TYPE' => self::CONTENT_TYPE, 'PROBE' => self::PROBE]);

        self::assertIsArray($result);
        $panic = [
            'class' => 'Restate\\Extension\\ExtensionException',
            'category' => 'internal',
            'message' => 'Rust panic: restate panic probe',
            'previous' => null,
        ];
        self::assertSame($panic, $result['constructor']);
        self::assertSame($panic, $result['method']);
        self::assertSame('Restate VM is unusable: an earlier call on it panicked (Rust panic: restate panic probe).', $result['afterPanic']['message'] ?? null);
        self::assertSame(['returned' => 200], $result['freshVm']);
    }

    public function testBackendReportsAPanicAsAnInternalBackendExceptionAndKeepsTheInvocationPoisoned(): void
    {
        $result = ExtensionProcess::runJson(self::DESCRIBE . <<<'PHP'
            $backend = new Restate\Internal\Backend\Extension\Backend();
            $invocation = $backend->newInvocation([['content-type', 'CONTENT_TYPE']]);
            echo json_encode([
                'panic' => $describe(static fn () => $invocation->sysRun('PROBE')),
                'afterPanic' => $describe(static fn () => $invocation->responseHead()->statusCode),
                'freshInvocation' => $describe(static fn () => $backend->newInvocation([['content-type', 'CONTENT_TYPE']])->responseHead()->statusCode),
            ]);
            PHP, ['CONTENT_TYPE' => self::CONTENT_TYPE, 'PROBE' => self::PROBE]);

        self::assertIsArray($result);
        self::assertSame([
            'class' => 'Restate\\Internal\\BackendException',
            'category' => 'internal',
            'message' => 'Rust panic: restate panic probe',
            'previous' => 'Restate\\Extension\\ExtensionException',
        ], $result['panic']);
        self::assertSame([
            'class' => 'Restate\\Internal\\BackendException',
            'category' => 'internal',
            'message' => 'Restate VM is unusable: an earlier call on it panicked (Rust panic: restate panic probe).',
            'previous' => 'Restate\\Extension\\ExtensionException',
        ], $result['afterPanic']);
        self::assertSame(['returned' => 200], $result['freshInvocation']);
    }
}
