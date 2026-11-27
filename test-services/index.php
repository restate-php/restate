<?php

declare(strict_types=1);

// Every request builds the endpoint afresh: php -S, php-fpm and FrankenPHP's classic mode.
(require __DIR__ . '/endpoint.php')->handle($_SERVER, (string) file_get_contents('php://input'));
