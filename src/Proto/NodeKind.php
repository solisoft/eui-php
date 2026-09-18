<?php

declare(strict_types=1);

namespace EUI\Proto;

use EUI\DecodeException;
use EUI\ViewException;

/**
 * The closed set of primitive node kinds (02 §4.1).
 *
 * Everything a person would call a widget — button, dialog, table, date
 * picker — is composed from these on the server, which is why the catalogue
 * grows without shipping a new client.
 */
final class NodeKind
{
    public const BY_NAME = [
        'box' => 0x01, 'text' => 0x02, 'image' => 0x03, 'icon' => 0x04,
        'input' => 0x05, 'textarea' => 0x06, 'scroll' => 0x07, 'list' => 0x08,
        'canvas' => 0x09, 'spacer' => 0x0A, 'divider' => 0x0B, 'overlay' => 0x0C,
        'slot' => 0x0D, 'sizer' => 0x0E, 'audio' => 0x0F, 'video' => 0x10,
        'scene' => 0x11,
    ];
    public const LEAF = ['text', 'icon', 'spacer', 'divider', 'audio', 'video', 'scene'];
    public const INERT = ['spacer', 'divider'];

    public static function code(string $name): int
    {
        if (!isset(self::BY_NAME[$name])) {
            throw new ViewException("unknown node kind '{$name}'");
        }
        return self::BY_NAME[$name];
    }

    public static function name(int $code): string
    {
        $name = array_search($code, self::BY_NAME, true);
        if ($name === false) {
            throw new DecodeException("unknown node kind {$code}");
        }
        return $name;
    }

    public static function isLeaf(int $code): bool
    {
        return \in_array(self::name($code), self::LEAF, true);
    }

    public static function isInert(int $code): bool
    {
        return \in_array(self::name($code), self::INERT, true);
    }
}
