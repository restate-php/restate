<?php

declare(strict_types=1);

use Restate\Endpoint;

require dirname(__DIR__) . '/vendor/autoload.php';

$services = [
    require __DIR__ . '/services/greeter.php',
    require __DIR__ . '/services/communicator.php',
    require __DIR__ . '/services/proxy.php',
    require __DIR__ . '/services/test_utils_service.php',
    require __DIR__ . '/services/failing.php',
    require __DIR__ . '/services/virtual_object_command_interpreter.php',
    require __DIR__ . '/services/map_object.php',
    require __DIR__ . '/services/list_object.php',
    require __DIR__ . '/services/awakeable_holder.php',
    require __DIR__ . '/services/kill_test_runner.php',
    require __DIR__ . '/services/kill_test_singleton.php',
    require __DIR__ . '/services/block_and_wait_workflow.php',
    require __DIR__ . '/services/cancel_test_runner.php',
    require __DIR__ . '/services/cancel_test_blocking_service.php',
    require __DIR__ . '/services/echo_workflow.php',
    require __DIR__ . '/services/payments.php',
    require __DIR__ . '/services/counter.php',
    require __DIR__ . '/services/non_deterministic.php',
    require __DIR__ . '/services/checkout.php',
];

// The suite's ProxyRequestSigning case sets E2E_REQUEST_SIGNING to the `publickeyv1_…` key of the Restate it starts
// with a signing key; split on commas as the TypeScript test services do.
$identityKeys = getenv('E2E_REQUEST_SIGNING');

// Through the Restate PHP extension; without it this throws BackendUnavailableException.
return Endpoint::fromServices($services, is_string($identityKeys) && $identityKeys !== '' ? explode(',', $identityKeys) : null);
