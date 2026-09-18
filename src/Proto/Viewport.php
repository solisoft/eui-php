<?php

declare(strict_types=1);

namespace EUI\Proto;

use EUI\DecodeException;

/** The viewer's presentation state. */
final class Viewport
{
    public const THEME_MODES = [0 => 'light', 1 => 'dark', 2 => 'high_contrast'];
    public const DENSITIES = [0 => 'compact', 1 => 'cozy', 2 => 'comfortable'];

    public function __construct(
        public int $width = 0,
        public int $height = 0,
        public int $scale = 100,
        public int $mode = 0,
        public int $density = 1,
        public int $fontScale = 100,
    ) {
    }

    public static function decode(Reader $r): self
    {
        $width = $r->varint32();
        $height = $r->varint32();
        $scale = $r->u16();
        $mode = $r->u8();
        if (!isset(self::THEME_MODES[$mode])) {
            throw new DecodeException("unknown theme mode {$mode}");
        }
        $density = $r->u8();
        if (!isset(self::DENSITIES[$density])) {
            throw new DecodeException("unknown density {$density}");
        }
        return new self($width, $height, $scale, $mode, $density, $r->u16());
    }

    public function encode(Writer $w): void
    {
        $w->varint($this->width)->varint($this->height)->u16($this->scale)
          ->u8($this->mode)->u8($this->density)->u16($this->fontScale);
    }

    /** What a view sees: pixels, a ratio, and names rather than codes. */
    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'scale' => $this->scale / 100.0,
            'mode' => self::THEME_MODES[$this->mode],
            'density' => self::DENSITIES[$this->density],
            'font_scale' => $this->fontScale / 100.0,
        ];
    }
}
