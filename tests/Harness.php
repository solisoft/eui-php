<?php

declare(strict_types=1);

/**
 * A test harness in sixty lines, because a library with no runtime
 * dependencies should not need one to be tested either.
 *
 * Each `tests/*_test.php` returns `['what it checks' => fn () => …]`, and a
 * check fails by throwing — which every assertion below does.
 */

require_once __DIR__ . '/../src/autoload.php';

final class Assert
{
    public static int $checks = 0;

    public static function same(mixed $expected, mixed $actual, string $what = ''): void
    {
        self::$checks++;
        if ($expected !== $actual) {
            throw new RuntimeException(sprintf(
                "%s\n      expected: %s\n      actual:   %s",
                $what ?: 'not the same',
                self::show($expected),
                self::show($actual),
            ));
        }
    }

    public static function equals(mixed $expected, mixed $actual, string $what = ''): void
    {
        self::$checks++;
        if ($expected != $actual) {
            throw new RuntimeException(sprintf(
                "%s\n      expected: %s\n      actual:   %s",
                $what ?: 'not equal',
                self::show($expected),
                self::show($actual),
            ));
        }
    }

    public static function true(mixed $value, string $what = ''): void
    {
        self::$checks++;
        if ($value !== true) {
            throw new RuntimeException($what ?: 'expected true, got ' . self::show($value));
        }
    }

    public static function false(mixed $value, string $what = ''): void
    {
        self::same(false, $value, $what);
    }

    public static function null(mixed $value, string $what = ''): void
    {
        self::same(null, $value, $what);
    }

    public static function contains(string $needle, string $haystack, string $what = ''): void
    {
        self::$checks++;
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException(($what ?: 'missing') . ": {$needle} not in {$haystack}");
        }
    }

    /** @param class-string<Throwable> $class */
    public static function throws(string $class, callable $body, string $what = ''): Throwable
    {
        self::$checks++;
        try {
            $body();
        } catch (Throwable $e) {
            if ($e instanceof $class) {
                return $e;
            }
            throw new RuntimeException(($what ?: 'wrong exception') . ': expected ' . $class . ', got ' . $e::class . ' — ' . $e->getMessage());
        }
        throw new RuntimeException(($what ?: 'nothing was thrown') . ': expected ' . $class);
    }

    private static function show(mixed $value): string
    {
        if (\is_string($value)) {
            return \strlen($value) > 120 ? substr($value, 0, 120) . '…' : $value;
        }
        return json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: get_debug_type($value);
    }
}

function eui_run_tests(string $directory, ?string $only = null): int
{
    $files = glob($directory . '/*_test.php') ?: [];
    sort($files);
    $failed = [];
    $ran = 0;

    foreach ($files as $file) {
        $name = basename($file, '_test.php');
        if ($only !== null && !str_contains($name, $only)) {
            continue;
        }
        $tests = require $file;
        echo str_pad($name, 12), ' ';
        foreach ($tests as $what => $test) {
            $ran++;
            try {
                $test();
                echo '.';
            } catch (Throwable $e) {
                echo 'F';
                $failed[] = "{$name}: {$what}\n    {$e->getMessage()}";
            }
        }
        echo "\n";
    }

    echo "\n";
    foreach ($failed as $failure) {
        echo "  ✗ {$failure}\n\n";
    }
    printf("%d tests, %d checks, %d failures\n", $ran, Assert::$checks, \count($failed));
    return $failed === [] ? 0 : 1;
}
