<?php

declare(strict_types=1);

namespace EUI\View;

use EUI\Proto\ColorRef;
use EUI\Proto\Dim;
use EUI\Proto\Limits;
use EUI\Proto\StyleEnum;
use EUI\Proto\StyleRecord;
use EUI\Theme;
use EUI\ViewException;

/**
 * A style array, in the spec's own vocabulary, compiled into the 64-byte
 * record the wire carries.
 *
 *     ['display' => 'column', 'gap' => 4, 'bg' => 'surface.base',
 *      'pad' => [4, 6, 4, 6], 'size' => 'lg', 'fg' => 'text.muted']
 *
 * An unknown key is an error rather than a key that does nothing: a style
 * that is silently dropped is a page that is wrong for a day.
 */
final class Compiler
{
    /**
     * `$colors` is asked for a literal `#RRGGBB` and answers the session
     * table index it interned it at; roles never reach it. `$fonts` is asked
     * for a family the application bound and answers its role.
     */
    public function __construct(
        private readonly mixed $colors = null,
        private readonly mixed $fonts = null,
    ) {
    }

    public function record(?array $style): StyleRecord
    {
        $r = new StyleRecord();
        if ($style === null) {
            return $r;
        }
        foreach ($style as $key => $value) {
            $this->apply($r, (string) $key, $value);
        }
        return $r->validate();
    }

    /** A colour as the wire carries it: a role, a literal, or nothing. */
    public function color(mixed $value): ColorRef
    {
        if ($value instanceof ColorRef) {
            return $value;
        }
        $name = (string) $value;
        if ($name === 'none') {
            return ColorRef::none();
        }
        if (str_starts_with($name, '#')) {
            if ($this->colors === null) {
                throw new ViewException("no colour table to intern '{$name}' into");
            }
            return ColorRef::literal(($this->colors)(self::rgba($name)));
        }
        return ColorRef::role(Theme::role($name));
    }

    /** `#RRGGBB` or `#RRGGBBAA` as `0xRRGGBBAA`. */
    public static function rgba(string $hex): int
    {
        $digits = ltrim($hex, '#');
        if (!ctype_xdigit($digits)) {
            throw new ViewException("bad hex colour '{$hex}'");
        }
        return match (\strlen($digits)) {
            6 => (hexdec($digits) << 8) | 0xFF,
            8 => (int) hexdec($digits),
            default => throw new ViewException("a hex colour is #RRGGBB or #RRGGBBAA, got '{$hex}'"),
        };
    }

    private function apply(StyleRecord $r, string $key, mixed $v): void
    {
        switch ($key) {
            case 'display': $r->display = $this->enum(StyleEnum::DISPLAY, $v, $key); break;
            case 'wrap': $r->wrap = $this->enum(StyleEnum::WRAP, $v, $key); break;
            case 'justify': $r->justify = $this->enum(StyleEnum::JUSTIFY, $v, $key); break;
            case 'align': $r->alignItems = $this->enum(StyleEnum::ALIGN_ITEMS, $v, $key); break;
            case 'self': $r->alignSelf = $this->enum(StyleEnum::ALIGN_SELF, $v, $key); break;
            case 'grow': $r->grow = $this->byte($v, $key); break;
            case 'shrink': $r->shrink = $this->byte($v, $key); break;
            case 'gap': $r->gap = $this->byte($v, $key); break;
            case 'basis': $r->basis = $this->dim($v); break;
            case 'width': $r->width = $this->dim($v); break;
            case 'height': $r->height = $this->dim($v); break;
            case 'min_width': $r->minWidth = $this->dim($v); break;
            case 'min_height': $r->minHeight = $this->dim($v); break;
            case 'max_width': $r->maxWidth = $this->dim($v); break;
            case 'max_height': $r->maxHeight = $this->dim($v); break;
            case 'pad': $r->padding = $this->edges($v); break;
            case 'margin': $r->margin = $this->edges($v); break;
            case 'bg': $r->bg = $this->color($v); break;
            case 'fg': $r->fg = $this->color($v); break;
            case 'border_color': $r->borderColor = $this->color($v); break;
            case 'border': $r->borderWidth = $this->edges($v); break;
            case 'radius': $r->radius = $this->scale(Theme::RADIUS, $v, $key); break;
            case 'shadow': $r->shadow = $this->scale(Theme::SHADOW, $v, $key); break;
            case 'opacity': $r->opacity = $this->byte($v, $key); break;
            case 'blur': $r->blur = $this->byte($v, $key); break;
            case 'font': $r->fontFamily = $this->font($v); break;
            case 'size': $r->fontSize = $this->scale(Theme::TEXT, $v, $key); break;
            case 'weight': $r->fontWeight = $this->enum(StyleEnum::FONT_WEIGHT, $v, $key); break;
            case 'text_align': $r->textAlign = $this->enum(StyleEnum::TEXT_ALIGN, $v, $key); break;
            case 'clamp': $r->lineClamp = $this->byte($v, $key); break;
            case 'underline': $r->textDecoration |= $v ? 1 : 0; break;
            case 'strike': $r->textDecoration |= $v ? 2 : 0; break;
            case 'overflow': $r->overflow = $this->enum(StyleEnum::OVERFLOW, $v, $key); break;
            case 'transition': $r->transition = $this->enum(StyleEnum::TRANSITION, $v, $key); break;
            case 'animation': $r->animation = $this->animation($v); break;
            case 'motion': $r->motion = $this->enum(StyleEnum::MOTION, $v, $key); break;
            case 'position': $r->position = $this->enum(StyleEnum::POSITION, $v, $key); break;
            case 'z': $r->z = $this->byte($v, $key); break;
            case 'cursor': $r->cursor = $this->enum(StyleEnum::CURSOR, $v, $key); break;
            default: throw new ViewException("unknown style key '{$key}'");
        }
    }

    /** @param array<string,int> $table */
    private function enum(array $table, mixed $value, string $key): int
    {
        $name = \is_string($value) ? $value : (string) $value;
        if (!isset($table[$name])) {
            throw new ViewException("unknown {$key} '{$name}'; it is one of " . implode(', ', array_keys($table)));
        }
        return $table[$name];
    }

    /**
     * A scale index, written as the index or as the name the spec gives it.
     *
     * @param array<string,int> $names
     */
    private function scale(array $names, mixed $value, string $key): int
    {
        if (\is_int($value)) {
            return $this->byte($value, $key);
        }
        $name = (string) $value;
        if (!isset($names[$name])) {
            throw new ViewException("unknown {$key} '{$name}'; it is an index or one of " . implode(', ', array_keys($names)));
        }
        return $names[$name];
    }

    private function byte(mixed $value, string $key): int
    {
        if (!\is_int($value) && !\is_float($value)) {
            throw new ViewException("{$key} is a number, got " . get_debug_type($value));
        }
        $n = (int) $value;
        if ($n < 0 || $n > 255) {
            throw new ViewException("{$key} is 0-255, got {$n}");
        }
        return $n;
    }

    /** `12` (px), `"auto"`, `"50%"`, `"1fr"`, `"sp:4"`. */
    private function dim(mixed $v): Dim
    {
        if (\is_int($v)) {
            if ($v < 0 || $v > 65535) {
                throw new ViewException("px is 0-65535, got {$v}");
            }
            return Dim::px($v);
        }
        if (\is_float($v)) {
            return Dim::px(max(0, min(65535, (int) round($v))));
        }
        if (\is_string($v)) {
            if ($v === 'auto') {
                return Dim::auto();
            }
            if (str_ends_with($v, '%')) {
                return Dim::percent(max(0, min(65535, (int) round(((float) substr($v, 0, -1)) * 100))));
            }
            if (str_ends_with($v, 'fr')) {
                return Dim::fr(max(0, min(65535, (int) round(((float) substr($v, 0, -2)) * 100))));
            }
            if (str_starts_with($v, 'sp:')) {
                return Dim::space((int) substr($v, 3));
            }
        }
        throw new ViewException('cannot read a length from ' . json_encode($v));
    }

    /**
     * One index for every side, `[y, x]`, or `[t, r, b, l]`.
     *
     * @return array{int,int,int,int}
     */
    private function edges(mixed $v): array
    {
        if (\is_int($v) || \is_float($v)) {
            $n = $this->byte($v, 'edge');
            return [$n, $n, $n, $n];
        }
        if (\is_array($v)) {
            $values = array_values($v);
            if (\count($values) === 4) {
                return [$this->byte($values[0], 'edge'), $this->byte($values[1], 'edge'),
                        $this->byte($values[2], 'edge'), $this->byte($values[3], 'edge')];
            }
            if (\count($values) === 2) {
                $y = $this->byte($values[0], 'edge');
                $x = $this->byte($values[1], 'edge');
                return [$y, $x, $y, $x];
            }
        }
        throw new ViewException('edges are one index, [y, x] or [t, r, b, l], got ' . json_encode($v));
    }

    /** `"sans"`, `"mono"`, or a family the application bound. */
    private function font(mixed $v): int
    {
        if (\is_int($v)) {
            if ($v > Limits::MAX_FONT_ROLE) {
                throw new ViewException("font role {$v} is above " . Limits::MAX_FONT_ROLE);
            }
            return $v;
        }
        $name = (string) $v;
        if ($name === 'sans') {
            return 0;
        }
        if ($name === 'mono') {
            return 1;
        }
        if ($this->fonts === null) {
            throw new ViewException('a font is "sans", "mono" or a family the application bound, got ' . json_encode($v));
        }
        return ($this->fonts)($name);
    }

    /**
     * One name, or several: a node has to say how it arrives *and* how it
     * leaves while it is still there to say it.
     */
    private function animation(mixed $v): int
    {
        $names = \is_array($v) ? $v : [$v];
        $mask = 0;
        foreach ($names as $name) {
            $mask |= $this->enum(StyleEnum::ANIMATION, $name, 'animation');
        }
        return $mask;
    }
}
