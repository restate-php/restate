<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Ingress;

use PHPUnit\Framework\TestCase;
use Restate\Internal\Backend\Extension\Backend;
use Restate\Tests\Support\Packages;

/**
 * The ingress client works in a plain PHP process: without the Restate extension, without php.ini (`php -n`), and
 * without loading any type of the restate/* packages but its own (restate/ingress) and restate/common, the package it
 * requires (docs/decisions/0029-package-split.md).
 */
final class IngressClientWithoutExtensionTest extends TestCase
{
    public function testEveryOperationWorksInAPlainPhpProcessAndLoadsOnlyIngressAndCommonTypes(): void
    {
        $output = self::runPlainPhp(__DIR__ . '/plain-process.php');

        self::assertNotContains(Backend::MODULE_NAME, $output['extensions']);
        self::assertSame([
            'call' => 'ok',
            'send' => ['inv_1abc', 'Accepted'],
            'attach' => 'ok',
            'output' => false,
            'idempotent' => 'ok',
            'submit' => 'inv_1abc',
            'workflowAttach' => 'ok',
            'workflowOutput' => false,
            'terminal' => [400, 'Payment declined'],
            'ingress' => [404, 'ingress'],
        ], $output['results']);
        self::assertSame([
            'POST /Greeter/greet',
            'POST /Counter/alice/add/send?delay=10ms',
            'GET /restate/invocation/inv_1abc/attach',
            'GET /restate/invocation/inv_1abc/output',
            'GET /restate/invocation/Greeter/greet/greet-1/attach',
            'POST /Checkout/order-1/run/send',
            'GET /restate/workflow/Checkout/order-1/attach',
            'GET /restate/workflow/Checkout/order-1/output',
            'POST /restate/awakeables/sign_1abc/resolve',
            'POST /restate/awakeables/sign_1abc/reject',
            'POST /Payments/charge',
            'POST /Nope/handler',
        ], $output['requests']);

        $outside = array_values(array_filter(
            $output['restateTypes'],
            static fn (string $type): bool => !in_array(Packages::of($type), ['ingress', 'common', null], true),
        ));
        self::assertSame([], $outside, 'The ingress client loaded types of packages it does not require.');
        self::assertContains('Restate\\Target', $output['restateTypes'], 'The check sees restate/common types.');
    }

    /** @return array{results: array<string, mixed>, requests: list<string>, restateTypes: list<string>, extensions: list<string>} */
    private static function runPlainPhp(string $script): array
    {
        $process = proc_open([PHP_BINARY, '-n', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), "php -n {$script} failed:\n{$stderr}\n{$stdout}");

        /** @var array{results: array<string, mixed>, requests: list<string>, restateTypes: list<string>, extensions: list<string>} */
        return json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    }
}
