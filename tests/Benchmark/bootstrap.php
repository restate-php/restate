<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

// Every iteration runs in a fresh process, so the autoloader is compiled each time; compiling Composer's class map
// (mostly dev dependencies such as PHPUnit) is a transient peak that would otherwise be part of each subject's
// mem_peak. What the autoloader keeps stays counted.
memory_reset_peak_usage();
