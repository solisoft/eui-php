<?php

declare(strict_types=1);

namespace EUI\Proto;

use EUI\DecodeException;

/**
 * The enumerated style fields. Each rejects a value it does not define
 * rather than clamping it: clamping is how two implementations quietly
 * disagree about a layout for a year.
 */
final class StyleEnum
{
    public const DISPLAY = ['row' => 0, 'column' => 1, 'stack' => 2, 'grid' => 3, 'none' => 4];
    public const WRAP = ['nowrap' => 0, 'wrap' => 1, 'wrap_reverse' => 2];
    public const JUSTIFY = ['start' => 0, 'center' => 1, 'end' => 2, 'between' => 3, 'around' => 4, 'evenly' => 5];
    public const ALIGN_ITEMS = ['start' => 0, 'center' => 1, 'end' => 2, 'stretch' => 3, 'baseline' => 4];
    public const ALIGN_SELF = ['start' => 0, 'center' => 1, 'end' => 2, 'stretch' => 3, 'baseline' => 4, 'auto' => 5];
    public const FONT_WEIGHT = ['regular' => 0, 'medium' => 1, 'semibold' => 2, 'bold' => 3];
    public const TEXT_ALIGN = ['start' => 0, 'center' => 1, 'end' => 2, 'justify' => 3];
    public const OVERFLOW = ['visible' => 0, 'clip' => 1, 'scroll' => 2];
    public const POSITION = ['flow' => 0, 'absolute' => 1, 'pointer' => 2];
    public const CURSOR = [
        'default' => 0, 'pointer' => 1, 'text' => 2, 'grab' => 3, 'grabbing' => 4,
        'resize_h' => 5, 'resize_v' => 6, 'wait' => 7, 'not_allowed' => 8,
    ];
    public const TRANSITION = ['none' => 0, 'fast' => 1, 'base' => 2, 'slow' => 3, 'slower' => 4, 'slowest' => 5];
    public const MOTION = ['fade' => 0, 'leading' => 1, 'trailing' => 2, 'top' => 3, 'bottom' => 4, 'scale' => 5, 'paired' => 6];

    /**
     * `animation` is a bit set rather than one name: a node has to say how
     * it arrives *and* how it leaves while it is still there to say it.
     */
    public const ANIMATION = ['none' => 0, 'spin' => 1, 'enter' => 2, 'exit' => 4];

    public const ANIMATION_SPIN = 1;
    public const ANIMATION_ENTER = 2;
    public const ANIMATION_EXIT = 4;
    public const ANIMATION_MASK = 7;

    /** @param array<string,int> $table */
    public static function checked(array $table, int $value, string $what): int
    {
        if (!\in_array($value, $table, true)) {
            throw new DecodeException("{$what} is outside the range this revision defines: {$value}");
        }
        return $value;
    }
}
