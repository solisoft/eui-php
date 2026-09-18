<?php

declare(strict_types=1);

require __DIR__ . '/Harness.php';

exit(eui_run_tests(__DIR__, $argv[1] ?? null));
