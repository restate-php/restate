<?php

declare(strict_types=1);

/*
 * Router script for the integration suite's endpoint (IntegrationEndpoint; served by the public test harness with the
 * extension loaded). It builds the endpoints for every request; the services and their configuration live in
 * services.php and IntegrationEndpoints, which a long-lived worker script shares.
 */

use Restate\Tests\Integration\Support\IntegrationEndpoints;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

new IntegrationEndpoints()->serve($_SERVER, (string) file_get_contents('php://input'));
