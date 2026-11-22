<?php

declare(strict_types=1);

/*
 * Router for WorkloadTest's php -S server: answers with what arrived (framing headers, a body digest) and whether the
 * serving process counts as a php -S worker for ProcessFaults.
 */

use Restate\Tests\Support\Faults\ProcessFaults;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$body = (string) file_get_contents('php://input');

header('content-type: application/json');
echo json_encode([
    'uri' => $_SERVER['REQUEST_URI'] ?? null,
    'contentType' => $_SERVER['CONTENT_TYPE'] ?? null,
    'contentLength' => $_SERVER['CONTENT_LENGTH'] ?? null,
    'transferEncoding' => $_SERVER['HTTP_TRANSFER_ENCODING'] ?? null,
    'invocationId' => $_SERVER['HTTP_X_RESTATE_INVOCATION_ID'] ?? null,
    'length' => strlen($body),
    'sha256' => hash('sha256', $body),
    'pid' => getmypid(),
    'worker' => ProcessFaults::isBuiltInServerWorker(),
], JSON_THROW_ON_ERROR);
