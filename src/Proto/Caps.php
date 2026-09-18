<?php

declare(strict_types=1);

namespace EUI\Proto;

use EUI\EUIException;

/**
 * Capability bits, as granted by the person and reported to the server
 * (01 §2.1). Nothing is granted by being asked for.
 */
final class Caps
{
    public const BY_NAME = [
        'camera' => 1, 'microphone' => 2, 'clipboard.read' => 4, 'clipboard.write' => 8,
        'notifications' => 16, 'location' => 32, 'fs.pick' => 64, 'fs.save' => 128,
        'nfc' => 256, 'scene' => 512, 'net.open' => 1024,
    ];

    /**
     * Every bit this revision defines. A bit outside it is a decode error: a
     * client that does not know what a bit means must not agree to it, and
     * neither must a server.
     */
    public const ALL = 0x7FF;

    public static function bit(string $name): int
    {
        if (!isset(self::BY_NAME[$name])) {
            throw new EUIException("unknown capability '{$name}'");
        }
        return self::BY_NAME[$name];
    }

    /** @param list<string>|int $names */
    public static function mask(array|int $names): int
    {
        if (\is_int($names)) {
            return $names;
        }
        $mask = 0;
        foreach ($names as $name) {
            $mask |= self::bit($name);
        }
        return $mask;
    }

    /** @return list<string> */
    public static function names(int $mask): array
    {
        $out = [];
        foreach (self::BY_NAME as $name => $bit) {
            if (($mask & $bit) !== 0) {
                $out[] = $name;
            }
        }
        return $out;
    }
}
