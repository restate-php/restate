<?php

declare(strict_types=1);

/*
 * The services of the integration suite's endpoint: one list for every way it is served (the classic router
 * endpoint.php under php -S or FrankenPHP, and a long-lived worker script), built by IntegrationEndpoints.
 *
 * @return list<Restate\ServiceDefinition>
 */

use Restate\Tests\Support\Workload\WorkloadServices;

$root = dirname(__DIR__, 3);

return [
    require $root . '/test-services/services/greeter.php',
    require $root . '/test-services/services/communicator.php',
    require $root . '/test-services/services/echo_workflow.php',
    require $root . '/test-services/services/payments.php',
    require $root . '/test-services/services/counter.php',
    require $root . '/test-services/services/checkout.php',
    require $root . '/tests/Integration/Services/lazy_counter.php',
    require $root . '/tests/Integration/Services/retries.php',
    require $root . '/tests/Integration/Services/request_info.php',
    require $root . '/tests/Integration/Services/hooks.php',
    require $root . '/tests/Integration/Services/signals.php',
    require $root . '/tests/Integration/Services/invocation_outputs.php',
    require $root . '/tests/Integration/Services/pauses.php',
    require $root . '/tests/Integration/Services/logging.php',
    require $root . '/tests/Integration/Services/stray_output.php',
    ...require $root . '/tests/Integration/Services/ingress.php',
    ...require $root . '/tests/Integration/Services/scopes.php',
    // PayloadMappingTest: DTO, enum, binary and custom payloads (Payloads, PayloadCheckout).
    ...require $root . '/tests/Integration/Services/payloads.php',
    // Failure injection: Chaos, ChaosObject.
    ...require $root . '/tests/Integration/Services/chaos.php',
    // WorkerIsolationTest: what one invocation sees of another in a long-lived worker (WorkerIsolation,
    // InstanceBoundProbe; FactoryBoundProbe is bound in IntegrationEndpoints).
    ...require $root . '/tests/Integration/Services/worker_isolation.php',
    // The end-to-end soak through Restate (tests/Soak/RestateSoakTest.php): Soak, SoakCounter.
    ...require $root . '/tests/Integration/Services/soak.php',
    // LargeBodyTest: large inputs, outputs, run results and state (LargeBodies, LargeBodiesObject).
    ...require $root . '/tests/Integration/Services/large_bodies.php',
    // Scale tests (tests/Integration/Scale): LongJournal, FanOut, FanOutCallee, WideState, Ledger.
    ...require $root . '/tests/Integration/Services/scale.php',
    // The handlers the workload library's request bodies are journals of (Workload, WorkloadObject).
    ...WorkloadServices::definitions(),
];
