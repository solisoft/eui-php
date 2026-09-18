<?php

declare(strict_types=1);

/**
 * A loader for using this library without composer — the examples and the
 * tests do. `require 'src/autoload.php'` and every `EUI\…` class is there.
 */
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'EUI\\')) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require_once __DIR__ . '/Errors.php';
