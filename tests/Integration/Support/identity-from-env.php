<?php

declare(strict_types=1);

/*
 * Router script for ServingHazardsTest's clear_env case: the identity key comes from the environment exactly as
 * docs/SERVING.md ("Request Identity Validation") shows, `[getenv('RESTATE_IDENTITY_KEY')]`. When php-fpm hides the
 * environment (clear_env = yes), getenv() returns false and building the endpoint throws instead of serving unsigned
 * requests.
 */

use Restate\Context;
use Restate\Endpoint;
use Restate\Service;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

Endpoint::serve(
    [Service::new('IdentityFromEnvironment')->handler('greet', static fn (Context $ctx, string $name): string => "Hello {$name}")->definition()],
    identityKeys: [getenv('RESTATE_IDENTITY_KEY')],
);
