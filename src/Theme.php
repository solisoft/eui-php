<?php

declare(strict_types=1);

namespace EUI;

/**
 * What a server is allowed to say about colour and size (`spec/05-theme.md`).
 *
 * The server never sends a colour. It sends a *role* and a *scale index*, and
 * the client resolves both against the active theme and the viewer's own
 * mode, density and font scale. Dark mode costs zero bytes, and this process
 * never learns which one somebody is in.
 */
final class Theme
{
    /**
     * The 33 colour roles, numbered once and for good. Ids 34.. are reserved
     * and a client rejects them.
     */
    public const ROLES = [
        'surface.base' => 1, 'surface.raised' => 2, 'surface.sunken' => 3, 'surface.overlay' => 4,
        'text.default' => 5, 'text.muted' => 6, 'text.inverted' => 7, 'text.disabled' => 8,
        'accent.base' => 9, 'accent.hover' => 10, 'accent.active' => 11, 'accent.on' => 12,
        'success.base' => 13, 'success.subtle' => 14, 'success.on' => 15,
        'warning.base' => 16, 'warning.subtle' => 17, 'warning.on' => 18,
        'danger.base' => 19, 'danger.subtle' => 20, 'danger.on' => 21,
        'info.base' => 22, 'info.subtle' => 23, 'info.on' => 24,
        'border.subtle' => 25, 'border.default' => 26, 'border.strong' => 27,
        'focus.ring' => 28,
        'series.1' => 29, 'series.2' => 30, 'series.3' => 31, 'series.4' => 32, 'series.5' => 33,
    ];

    /** `space`, in device-independent pixels at cozy density. */
    public const SPACE = [0, 2, 4, 8, 12, 16, 20, 24, 32, 40, 48, 64, 96];

    /**
     * `text`. The scale stops at index 7, 38 px, and the theme cannot move
     * it: there is no hero type in this protocol.
     */
    public const TEXT = ['xs' => 0, 'sm' => 1, 'base' => 2, 'lg' => 3, 'xl' => 4, '2xl' => 5, '3xl' => 6, '4xl' => 7];
    public const TEXT_PX = [[11, 16], [13, 18], [15, 22], [17, 24], [20, 28], [24, 32], [30, 38], [38, 46]];

    public const RADIUS = ['none' => 0, 'sm' => 1, 'md' => 2, 'lg' => 3, 'full' => 4];
    public const SHADOW = ['none' => 0, 'sm' => 1, 'md' => 2, 'lg' => 3];

    /**
     * `motion`, in milliseconds. `transition` carries the index + 1, which is
     * why `none` is a name here rather than a hole in the scale.
     */
    public const MOTION_MS = ['fast' => 100, 'base' => 180, 'slow' => 320, 'slower' => 560, 'slowest' => 1000];

    /** Control heights, for the widgets composed on top of the primitives. */
    public const CONTROL = ['sm' => 28, 'md' => 36, 'lg' => 44];

    public static function role(string $name): int
    {
        if (!isset(self::ROLES[$name])) {
            throw new ViewException("unknown colour role '{$name}'");
        }
        return self::ROLES[$name];
    }

    public static function isRole(string $name): bool
    {
        return isset(self::ROLES[$name]);
    }

    /**
     * The pixel value of a `space` index, for a view doing its own
     * arithmetic — a measure derived from the viewport, say.
     */
    public static function space(int $index): int
    {
        if (!isset(self::SPACE[$index])) {
            throw new ViewException("space index {$index} is past the end of the scale");
        }
        return self::SPACE[$index];
    }

    public static function textSize(int|string $nameOrIndex): int
    {
        if (\is_int($nameOrIndex)) {
            $index = $nameOrIndex;
        } elseif (isset(self::TEXT[$nameOrIndex])) {
            $index = self::TEXT[$nameOrIndex];
        } else {
            throw new ViewException("unknown text scale '{$nameOrIndex}'");
        }
        if ($index > 7) {
            throw new ViewException("text index {$index} is past the end of the scale");
        }
        return $index;
    }
}
