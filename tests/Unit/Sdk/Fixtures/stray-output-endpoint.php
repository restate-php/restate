<?php

declare(strict_types=1);

/*
 * EndpointStrayOutputTest's endpoint, run in a child PHP process (CLI, or `php -S` as a router script) so that its
 * ini settings (display_errors, post_max_size, output_buffering) and what PHP prints before it are real.
 *
 * Serves Greeter/greet on a fake backend (tests/Support): the handler first does what STRAY_OUTPUT_HANDLER names
 * (`echo`, `user-warning`, `deprecation`, or nothing). STRAY_OUTPUT_BEFORE is echoed before the endpoint runs. Under
 * the CLI the request is a POST of "request-body" to /invoke/Greeter/greet. The endpoint's log records, and whether
 * the handler ran, are written as JSON to STRAY_OUTPUT_LOG.
 */

use Restate\Context;
use Restate\Endpoint;
use Restate\Internal\Backend\InvocationInput;
use Restate\Service;
use Restate\Tests\Support\FakeBackend;
use Restate\Tests\Support\RecordingLogger;
use Restate\Tests\Support\ScriptedInvocation;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$before = getenv('STRAY_OUTPUT_BEFORE');
if (is_string($before) && $before !== '') {
    echo $before;
}

$mode = (string) getenv('STRAY_OUTPUT_HANDLER');
$logFile = (string) getenv('STRAY_OUTPUT_LOG');
$logger = new RecordingLogger();
$handlerRan = false;
register_shutdown_function(static function () use ($logger, $logFile, &$handlerRan): void {
    file_put_contents($logFile, json_encode(['records' => $logger->records, 'handlerRan' => $handlerRan], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
});

$invocation = ScriptedInvocation::new();
$invocation->input = new InvocationInput('inv_1', 0, '', [], '"Mary"');
$endpoint = Endpoint::create(
    new FakeBackend($invocation),
    [Service::new('Greeter')->handler('greet', static function (Context $ctx, string $name) use ($mode, &$handlerRan): string {
        $handlerRan = true;
        if ($mode === 'echo') {
            echo "debug output from the handler\n";
        } elseif ($mode === 'user-warning') {
            trigger_error('a warning from the handler', E_USER_WARNING);
        } elseif ($mode === 'deprecation') {
            // An engine E_DEPRECATED at call time since PHP 8.4.
            lcg_value();
        }

        return "Hello {$name}";
    })],
    logger: $logger,
);

if (PHP_SAPI === 'cli') {
    $endpoint->handle([
        'REQUEST_URI' => '/invoke/Greeter/greet',
        'CONTENT_TYPE' => 'application/vnd.restate.invocation.v6',
        'HTTP_X_RESTATE_INVOCATION_ID' => 'inv_1',
    ], 'request-body');
} else {
    $endpoint->handle($_SERVER, (string) file_get_contents('php://input'));
}
