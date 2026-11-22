<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk\Backend;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Restate\Internal\Backend\Extension\Backend;
use Restate\Tests\Support\ExtensionProcess;

/**
 * The built extension's version handshake, VM ownership and exception, exercised in a child process that loads it.
 * Whole invocations are covered by the integration suite.
 */
#[Group('extension')]
final class ExtensionModuleTest extends TestCase
{
    private const string CONTENT_TYPE = 'application/vnd.restate.invocation.v5';

    public function testExtensionSpeaksThisPackagesContractVersion(): void
    {
        $result = ExtensionProcess::runJson(<<<'PHP'
            new Restate\Internal\Backend\Extension\Backend();
            echo json_encode([
                'contract' => Restate\Extension\contract_version(),
                'version' => Restate\Extension\version(),
                'module' => phpversion('restate'),
            ]);
            PHP);

        self::assertIsArray($result);
        self::assertSame(Backend::CONTRACT_VERSION, $result['contract']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $result['version']);
        self::assertSame($result['module'], $result['version']);
    }

    public function testExtensionFailuresAreExtensionExceptionsWithACategory(): void
    {
        $result = ExtensionProcess::runJson(<<<'PHP'
            $describe = static function (callable $call): array {
                try {
                    $call();
                } catch (Throwable $e) {
                    return [
                        'class' => $e::class,
                        'runtime' => $e instanceof RuntimeException,
                        'category' => $e->category ?? null,
                        'code' => $e->getCode(),
                        'message' => $e->getMessage(),
                        'line' => $e->getLine(),
                    ];
                }
                return ['class' => null];
            };
            $vm = new Restate\Extension\Vm([['content-type', 'CONTENT_TYPE']]);
            echo json_encode([
                'protocol' => $describe(static fn () => new Restate\Extension\Vm([])),
                'header' => $describe(static fn () => new Restate\Extension\Vm([['content-type']])),
                'handle' => $describe(static fn () => $vm->doAwait(-1)),
                'code' => $describe(static fn () => $vm->writeOutputFailure(200, 'ok?', [])),
                'kind' => $describe(static fn () => $vm->sysCall(['kind' => 'actor', 'service' => 'S', 'handler' => 'h', 'key' => null], '', null)),
            ]);
            PHP, ['CONTENT_TYPE' => self::CONTENT_TYPE]);

        self::assertIsArray($result);
        foreach ($result as $case => $failure) {
            self::assertSame('Restate\\Extension\\ExtensionException', $failure['class'], $case);
            self::assertTrue($failure['runtime'], $case);
            self::assertGreaterThan(0, $failure['line'], "{$case}: the exception records where PHP called the extension");
        }

        self::assertSame('protocol', $result['protocol']['category']);
        self::assertSame(415, $result['protocol']['code']);
        self::assertStringStartsWith('(415) ', $result['protocol']['message']);

        self::assertSame(['invalid_argument', 0, 'Header pair is missing its value.'], [$result['header']['category'], $result['header']['code'], $result['header']['message']]);
        self::assertSame(['invalid_argument', 'Handle is outside the u32 range.'], [$result['handle']['category'], $result['handle']['message']]);
        self::assertSame(['invalid_argument', 'Terminal failure code must be between 400 and 599.'], [$result['code']['category'], $result['code']['message']]);
        self::assertSame(['invalid_argument', 'unsupported target kind: actor'], [$result['kind']['category'], $result['kind']['message']]);
    }

    public function testBackendRethrowsExtensionFailuresAsBackendExceptions(): void
    {
        $result = ExtensionProcess::runJson(<<<'PHP'
            $backend = new Restate\Internal\Backend\Extension\Backend();
            try {
                $backend->newInvocation([['content-type', 'application/vnd.restate.invocation.v1']]);
            } catch (Restate\Internal\BackendException $e) {
                echo json_encode([
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'previous' => $e->getPrevious()::class,
                    'extensionCategory' => $e->getPrevious()->category,
                    'category' => $e->category()->value,
                ]);
            }
            PHP);

        self::assertIsArray($result);
        self::assertStringStartsWith('(415) ', $result['message']);
        self::assertSame(415, $result['code']);
        self::assertSame('Restate\\Extension\\ExtensionException', $result['previous']);
        self::assertSame('protocol', $result['extensionCategory']);
        self::assertSame('protocol', $result['category']);
    }

    public function testBackendExceptionsCarryTheExtensionCategoryOfEachFailure(): void
    {
        $result = ExtensionProcess::runJson(<<<'PHP'
            $backend = new Restate\Internal\Backend\Extension\Backend();
            $category = static function (callable $call): ?array {
                try {
                    $call();
                } catch (Restate\Internal\BackendException $e) {
                    return [$e->category()->value, $e->getCode()];
                }
                return null;
            };
            $invocation = $backend->newInvocation([['content-type', 'CONTENT_TYPE']]);
            echo json_encode([
                'invalid_argument' => $category(static fn () => $invocation->doAwait(-1)),
                // Reading the input before Restate sent any is a protocol violation in shared-core (571).
                'protocol' => $category(static fn () => $invocation->input()),
            ]);
            PHP, ['CONTENT_TYPE' => self::CONTENT_TYPE]);

        self::assertSame([
            'invalid_argument' => ['invalid_argument', 0],
            'protocol' => ['protocol', 571],
        ], $result);
    }

    public function testEachInvocationOwnsItsVm(): void
    {
        $result = ExtensionProcess::runJson(<<<'PHP'
            $backend = new Restate\Internal\Backend\Extension\Backend();
            $first = $backend->newInvocation([['content-type', 'CONTENT_TYPE']]);
            $second = $backend->newInvocation([['content-type', 'CONTENT_TYPE']]);
            $first->free();
            $afterFree = null;
            try {
                $first->isReadyToExecute();
            } catch (Restate\Internal\BackendException $e) {
                $afterFree = $e->getMessage();
            }
            $clone = null;
            try {
                clone new Restate\Extension\Vm([['content-type', 'CONTENT_TYPE']]);
            } catch (Error $e) {
                $clone = $e->getMessage();
            }
            echo json_encode([
                'afterFree' => $afterFree,
                'secondStillWorks' => $second->responseHead()->statusCode,
                'clone' => $clone,
            ]);
            PHP, ['CONTENT_TYPE' => self::CONTENT_TYPE]);

        self::assertSame([
            'afterFree' => 'Extension VM has already been freed.',
            'secondStillWorks' => 200,
            'clone' => 'Trying to clone an uncloneable object of class Restate\\Extension\\Vm',
        ], $result);
    }
}
