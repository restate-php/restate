<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Sdk\Backend;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Restate\Tests\Support\ExtensionProcess;

/**
 * PHP strings need not be UTF-8; the protocol needs it. Failure messages and stack traces cross to the Rust core
 * as bytes and are converted there with U+FFFD for invalid sequences; failure metadata is substituted on the PHP side.
 * Neither may make the error path itself fail. Latin-1 "é" is the single byte "\xE9".
 */
#[Group('extension')]
final class ExtensionNonUtf8Test extends TestCase
{
    private const string CONTENT_TYPE = 'application/vnd.restate.invocation.v6';

    private const string REPLACEMENT = "\u{FFFD}";

    /** Runs code in a child with the extension; `$ready()` returns a Restate\Extension\Vm that is ready to execute. */
    private static function decoded(mixed $base64): string
    {
        self::assertIsString($base64);
        $decoded = base64_decode($base64, true);
        self::assertIsString($decoded);

        return $decoded;
    }

    private static function extension(string $code): mixed
    {
        return ExtensionProcess::runJson(<<<'PHP'
            use Restate\Tests\Support\Protocol\FrameCodec;
            $input = FrameCodec::startFrame('inv-1', 1) . FrameCodec::inputCommandFrame('"Mary"');
            $ready = static function () use ($input): Restate\Extension\Vm {
                $vm = new Restate\Extension\Vm([['content-type', 'CONTENT_TYPE']]);
                $vm->notifyInput($input);
                $vm->notifyInputClosed();
                $vm->input();
                return $vm;
            };
            $output = static function (Restate\Extension\Vm|Restate\Internal\Backend\Invocation $vm): string {
                $output = '';
                while (($chunk = $vm->takeOutput()) !== null && $chunk !== '') {
                    $output .= $chunk;
                }
                return base64_encode($output);
            };
            $attempt = static function (callable $call): ?string {
                try {
                    $call();
                    return null;
                } catch (Throwable $e) {
                    return $e::class . ': ' . $e->getMessage();
                }
            };
            PHP . $code, ['CONTENT_TYPE' => self::CONTENT_TYPE]);
    }

    public function testExtensionAcceptsATerminalMessageThatIsNotUtf8(): void
    {
        $result = self::extension(<<<'PHP'
            $vm = $ready();
            echo json_encode([
                'error' => $attempt(static function () use ($vm) { $vm->writeOutputFailure(402, "Carte refus\xE9e", []); $vm->end(); }),
                'output' => $output($vm),
            ]);
            PHP);

        self::assertIsArray($result);
        self::assertNull($result['error']);
        self::assertStringContainsString('Carte refus' . self::REPLACEMENT . 'e', self::decoded($result['output']));
    }

    public function testExtensionAcceptsARetryableMessageAndStackTraceThatAreNotUtf8(): void
    {
        $result = self::extension(<<<'PHP'
            $vm = $ready();
            $run = $ready();
            $handle = $run->sysRun('charge');
            $run->doAwait($handle);
            echo json_encode([
                'error' => $attempt(static fn () => $vm->notifyError(500, "caf\xE9 failed", "#0 h\xE9()")),
                'output' => $output($vm),
                'run' => $run->proposeRunRetryable($handle, 500, "timeout \xE9", [], 5, null, null),
                'runOutput' => $output($run),
            ]);
            PHP);

        self::assertIsArray($result);
        self::assertNull($result['error']);
        self::assertStringContainsString('caf' . self::REPLACEMENT . ' failed', self::decoded($result['output']));
        self::assertSame(['type' => 'attempt_ended'], $result['run'], 'the retry is still recognised after the substitution');
        self::assertStringContainsString('timeout ' . self::REPLACEMENT, self::decoded($result['runOutput']));
    }

    public function testExtensionBackendSubstitutesMetadataThatIsNotUtf8(): void
    {
        $result = self::extension(<<<'PHP'
            $invocation = (new Restate\Internal\Backend\Extension\Backend())->newInvocation([['content-type', 'CONTENT_TYPE']]);
            $invocation->notifyInput($input);
            $invocation->notifyInputClosed();
            $invocation->input();
            echo json_encode([
                'error' => $attempt(static function () use ($invocation) {
                    $invocation->writeOutputFailure(402, "refus\xE9", ['raison' => "vol\xE9e", "cl\xE9" => 'x', '404' => 'y']);
                    $invocation->end();
                }),
                'output' => $output($invocation),
            ]);
            PHP);

        self::assertIsArray($result);
        self::assertNull($result['error']);
        $output = base64_decode($result['output'], true);
        self::assertIsString($output);
        self::assertStringContainsString('refus' . self::REPLACEMENT, $output);
        self::assertStringContainsString('vol' . self::REPLACEMENT . 'e', $output);
        self::assertStringContainsString('cl' . self::REPLACEMENT, $output);
        self::assertStringContainsString('404', $output);
    }
}
