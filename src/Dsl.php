<?php

declare(strict_types=1);

namespace EUI;

/**
 * The view, written as PHP.
 *
 * Every helper answers a plain array — `['k' => …, 's' => …, 'c' => …]` — so
 * a view is data all the way down: printable, comparable, testable without a
 * socket. Style keys are the spec's own vocabulary; `on`, `props` and `key`
 * are the three that are not style.
 *
 *     Dsl::column([
 *         Dsl::text('Hello', ['size' => '2xl', 'weight' => 'bold']),
 *         Dsl::button('Increment', 'increment'),
 *     ], ['gap' => 4, 'pad' => 6, 'bg' => 'surface.base'])
 */
final class Dsl
{
    public const RESERVED = ['on', 'props', 'key', 'intern'];

    public static function node(string $kind, array $children = [], array $options = []): array
    {
        $style = array_diff_key($options, array_flip(self::RESERVED));
        unset($style['text']);
        $out = ['k' => $kind];
        if ($style !== []) {
            $out['s'] = $style;
        }
        if (isset($options['text'])) {
            $out['t'] = $options['text'];
        }
        if (isset($options['key'])) {
            $out['key'] = (string) $options['key'];
        }
        if (!empty($options['intern'])) {
            $out['intern'] = true;
        }
        if (!empty($options['props'])) {
            $out['p'] = $options['props'];
        }
        if (!empty($options['on'])) {
            $out['on'] = $options['on'];
        }
        $kids = array_values(array_filter($children, static fn ($child) => $child !== null));
        if ($kids !== []) {
            $out['c'] = $kids;
        }
        return $out;
    }

    // -- primitives -------------------------------------------------------
    public static function box(array $children = [], array $options = []): array
    {
        return self::node('box', $children, $options);
    }

    public static function row(array $children = [], array $options = []): array
    {
        return self::node('box', $children, ['display' => 'row'] + $options);
    }

    public static function column(array $children = [], array $options = []): array
    {
        return self::node('box', $children, ['display' => 'column'] + $options);
    }

    /** Children overlap, ordered by `z`: menus, tooltips, a badge on a corner. */
    public static function stack(array $children = [], array $options = []): array
    {
        return self::node('box', $children, ['display' => 'stack'] + $options);
    }

    public static function text(string $content, array $options = []): array
    {
        return self::node('text', [], ['text' => $content] + $options);
    }

    public static function image(mixed $src, array $options = []): array
    {
        $options['props'] = ['src' => $src] + ($options['props'] ?? []);
        return self::node('image', [], $options);
    }

    public static function icon(string $name, array $options = []): array
    {
        $options['props'] = ['name' => $name] + ($options['props'] ?? []);
        return self::node('icon', [], $options);
    }

    public static function input(string $value, ?string $onChange = null, array $options = []): array
    {
        $options['props'] = ['value' => $value] + ($options['props'] ?? []);
        if ($onChange !== null) {
            $options['on'] = ['change' => $onChange] + ($options['on'] ?? []);
        }
        return self::node('input', [], $options);
    }

    public static function textarea(string $value, ?string $onChange = null, array $options = []): array
    {
        $options['props'] = ['value' => $value] + ($options['props'] ?? []);
        if ($onChange !== null) {
            $options['on'] = ['change' => $onChange] + ($options['on'] ?? []);
        }
        return self::node('textarea', [], $options);
    }

    public static function scroll(array $children = [], array $options = []): array
    {
        return self::node('scroll', $children, $options);
    }

    /**
     * A virtualised list: only the window in view is laid out, and the
     * client asks for another range with a `window` event.
     */
    public static function list(array $children = [], array $options = []): array
    {
        return self::node('list', $children, $options);
    }

    public static function canvas(array $paths, array $options = []): array
    {
        $options['props'] = ['paths' => $paths] + ($options['props'] ?? []);
        return self::node('canvas', [], $options);
    }

    public static function overlay(array $children = [], array $options = []): array
    {
        return self::node('overlay', $children, $options);
    }

    public static function sizer(array $children = [], array $options = []): array
    {
        return self::node('sizer', $children, $options);
    }

    /** Flexible empty space. Inert: no text, no props, no handlers. */
    public static function spacer(array $options = []): array
    {
        return self::node('spacer', [], $options + ['grow' => 1]);
    }

    public static function divider(array $options = []): array
    {
        return self::node('divider', [], $options + ['height' => 1, 'bg' => 'border.subtle', 'width' => '100%']);
    }

    /**
     * Identity for reconciliation: a row that moved is a row that moved,
     * rather than every row below it having changed.
     */
    public static function keyed(string $key, array $node): array
    {
        $node['key'] = $key;
        return $node;
    }

    // -- widgets ----------------------------------------------------------
    // Composed from the primitives above and nothing else, which is the whole
    // reason the catalogue can grow without shipping a new client.
    public const TONES = [
        'accent' => ['accent.base', 'accent.on'],
        'danger' => ['danger.base', 'danger.on'],
        'success' => ['success.base', 'success.on'],
        'warning' => ['warning.base', 'warning.on'],
        'info' => ['info.base', 'info.on'],
        'quiet' => ['surface.raised', 'text.default'],
    ];

    public static function button(string $label, string $event, array $options = []): array
    {
        $tone = (string) ($options['tone'] ?? 'accent');
        $size = (string) ($options['size'] ?? 'md');
        unset($options['tone'], $options['size']);
        if (!isset(self::TONES[$tone])) {
            throw new ViewException("unknown button tone '{$tone}'");
        }
        [$bg, $fg] = self::TONES[$tone];
        $pad = ['sm' => [1, 3], 'md' => [2, 4], 'lg' => [3, 5]][$size] ?? [2, 4];
        $style = array_diff_key($options, array_flip(self::RESERVED)) + [
            'display' => 'row', 'justify' => 'center', 'align' => 'center',
            'bg' => $bg, 'radius' => 'md', 'pad' => $pad,
            'cursor' => 'pointer', 'transition' => 'fast',
        ];
        $style['on'] = ['click' => $event] + ($options['on'] ?? []);
        if (isset($options['key'])) {
            $style['key'] = $options['key'];
        }
        return self::box([self::text($label, ['fg' => $fg, 'weight' => 'medium'])], $style);
    }

    /** A surface a thing sits on: raised, padded, with a hairline. */
    public static function card(array $children = [], array $options = []): array
    {
        $style = array_diff_key($options, array_flip(self::RESERVED)) + [
            'display' => 'column', 'bg' => 'surface.raised', 'radius' => 'md',
            'pad' => 5, 'gap' => 4, 'border' => 1, 'border_color' => 'border.subtle',
        ];
        foreach (['on', 'key'] as $carry) {
            if (isset($options[$carry])) {
                $style[$carry] = $options[$carry];
            }
        }
        return self::box($children, $style);
    }

    /** A label above a field, the pair kept together. */
    public static function field(string $label, string $value, string $onChange, array $options = []): array
    {
        $style = array_diff_key($options, array_flip(self::RESERVED)) + ['gap' => 2];
        return self::column([
            self::text($label, ['size' => 'sm', 'fg' => 'text.muted']),
            self::input($value, $onChange, [
                'bg' => 'surface.sunken', 'radius' => 'sm', 'pad' => [2, 3],
                'border' => 1, 'border_color' => 'border.default',
            ]),
        ], $style);
    }
}
