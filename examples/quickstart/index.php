<?php

declare(strict_types=1);

/*
 * The endpoint: every request Restate sends (discovery, invocations, /health) comes through this script.
 * PHP's built-in server runs it once per request; see php.ini next to it for the settings it needs.
 */

use App\Counter;
use App\Greeter;
use App\Notifications;
use App\Signup;
use App\StderrLogger;
use Restate\Endpoint;

require __DIR__ . '/vendor/autoload.php';

$level = getenv('LOG_LEVEL');
$logger = new StderrLogger($level !== false && $level !== '' ? $level : 'info');

Endpoint::serve([new Greeter(new Notifications()), new Counter(), new Signup()], logger: $logger);
