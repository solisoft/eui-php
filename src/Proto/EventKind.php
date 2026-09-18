<?php

declare(strict_types=1);

namespace EUI\Proto;

use EUI\DecodeException;
use EUI\ViewException;

/** Input and lifecycle events (`spec/06-events.md`). */
final class EventKind
{
    public const BY_NAME = [
        'click' => 0x01, 'double_click' => 0x02, 'pointer_down' => 0x03, 'pointer_up' => 0x04,
        'pointer_move' => 0x05, 'pointer_enter' => 0x06, 'pointer_leave' => 0x07,
        'key_down' => 0x08, 'key_up' => 0x09, 'text_input' => 0x0A, 'focus' => 0x0B,
        'blur' => 0x0C, 'change' => 0x0D, 'submit' => 0x0E, 'scroll' => 0x0F,
        'resize' => 0x10, 'context_menu' => 0x11, 'drag_start' => 0x12, 'drag_over' => 0x13,
        'drop' => 0x14, 'long_press' => 0x15, 'window' => 0x16, 'ended' => 0x17,
        'time_update' => 0x18, 'wake' => 0x19, 'file_pick' => 0x1A, 'file_save' => 0x1B,
        'location' => 0x1C, 'nfc_tag' => 0x1D, 'file_drag' => 0x1E, 'back' => 0x1F,
        'level' => 0x20,
    ];

    /**
     * The protocol version an event arrived in: one the client cannot decode
     * is left out at encode time rather than sent.
     */
    public const SINCE = [0x20 => 3];

    public static function code(string $name): int
    {
        if (!isset(self::BY_NAME[$name])) {
            throw new ViewException("unknown event '{$name}'");
        }
        return self::BY_NAME[$name];
    }

    public static function name(int $code): string
    {
        $name = array_search($code, self::BY_NAME, true);
        if ($name === false) {
            throw new DecodeException("unknown event kind {$code}");
        }
        return $name;
    }

    public static function since(int $code): int
    {
        return self::SINCE[$code] ?? 1;
    }
}
