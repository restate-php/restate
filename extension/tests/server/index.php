<?php

declare(strict_types=1);

// A request to PHP-FPM (flake.nix, checks.fpm): each runs an invocation, in one of the children FPM forks after it
// loaded the extension.

require __DIR__ . '/invoke.php';

echo restate_invoke($_GET['name'] ?? 'FPM');
