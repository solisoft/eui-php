<?php

declare(strict_types=1);

namespace EUI\Proto;

use EUI\DecodeException;

/**
 * Computed style: the 64-byte record of `spec/02-wire-format.md` §3.
 *
 * Everything in it is already resolved. There is no cascade, no specificity,
 * no inheritance to walk: a client's whole styling cost is one indexed
 * lookup, and a thousand table rows share three ids.
 */
final class StyleRecord
{
    public int $display = 0;
    public int $wrap = 0;
    public int $justify = 0;
    public int $alignItems = 3;   // stretch
    public int $alignSelf = 5;    // auto
    public int $grow = 0;
    public int $shrink = 1;
    public int $gap = 0;
    public Dim $basis;
    public Dim $width;
    public Dim $height;
    public Dim $minWidth;
    public Dim $minHeight;
    public Dim $maxWidth;
    public Dim $maxHeight;
    /** @var array{int,int,int,int} */
    public array $padding = [0, 0, 0, 0];
    /** @var array{int,int,int,int} */
    public array $margin = [0, 0, 0, 0];
    public ColorRef $bg;
    public ColorRef $fg;
    public ColorRef $borderColor;
    /** @var array{int,int,int,int} */
    public array $borderWidth = [0, 0, 0, 0];
    public int $radius = 0;
    public int $shadow = 0;
    public int $opacity = 255;
    public int $fontFamily = 0;   // sans
    public int $fontSize = 2;     // `base` on the text scale; 0 would be `xs`
    public int $fontWeight = 0;
    public int $textAlign = 0;
    public int $lineClamp = 0;
    public int $textDecoration = 0;
    public int $overflow = 0;
    public int $position = 0;
    public int $z = 0;
    public int $cursor = 0;
    public int $transition = 0;
    public int $animation = 0;
    public int $blur = 0;
    public int $motion = 0;

    public function __construct()
    {
        $this->basis = Dim::auto();
        $this->width = Dim::auto();
        $this->height = Dim::auto();
        $this->minWidth = Dim::auto();
        $this->minHeight = Dim::auto();
        $this->maxWidth = Dim::auto();
        $this->maxHeight = Dim::auto();
        $this->bg = ColorRef::none();
        $this->fg = ColorRef::none();
        $this->borderColor = ColorRef::none();
    }

    public static function decode(Reader $reader): self
    {
        $raw = new Reader($reader->take(Limits::STYLE_RECORD_BYTES));
        $r = new self();
        $r->display = StyleEnum::checked(StyleEnum::DISPLAY, $raw->u8(), 'display');
        $r->wrap = StyleEnum::checked(StyleEnum::WRAP, $raw->u8(), 'wrap');
        $r->justify = StyleEnum::checked(StyleEnum::JUSTIFY, $raw->u8(), 'justify');
        $r->alignItems = StyleEnum::checked(StyleEnum::ALIGN_ITEMS, $raw->u8(), 'align_items');
        $r->alignSelf = StyleEnum::checked(StyleEnum::ALIGN_SELF, $raw->u8(), 'align_self');
        $r->grow = $raw->u8();
        $r->shrink = $raw->u8();
        $r->gap = $raw->u8();
        $r->basis = Dim::decode($raw);
        $r->width = Dim::decode($raw);
        $r->height = Dim::decode($raw);
        $r->minWidth = Dim::decode($raw);
        $r->minHeight = Dim::decode($raw);
        $r->maxWidth = Dim::decode($raw);
        $r->maxHeight = Dim::decode($raw);
        $r->padding = array_values(unpack('C4', $raw->take(4)));
        $r->margin = array_values(unpack('C4', $raw->take(4)));
        $r->bg = new ColorRef($raw->u16());
        $r->fg = new ColorRef($raw->u16());
        $r->borderColor = new ColorRef($raw->u16());
        $r->borderWidth = array_values(unpack('C4', $raw->take(4)));
        $r->radius = $raw->u8();
        $r->shadow = $raw->u8();
        $r->opacity = $raw->u8();
        $r->fontFamily = $raw->u8();
        $r->fontSize = $raw->u8();
        $r->fontWeight = StyleEnum::checked(StyleEnum::FONT_WEIGHT, $raw->u8(), 'font_weight');
        $r->textAlign = StyleEnum::checked(StyleEnum::TEXT_ALIGN, $raw->u8(), 'text_align');
        $r->lineClamp = $raw->u8();
        $r->textDecoration = $raw->u8();
        $r->overflow = StyleEnum::checked(StyleEnum::OVERFLOW, $raw->u8(), 'overflow');
        $r->position = StyleEnum::checked(StyleEnum::POSITION, $raw->u8(), 'position');
        $r->z = $raw->u8();
        $r->cursor = StyleEnum::checked(StyleEnum::CURSOR, $raw->u8(), 'cursor');
        $r->transition = $raw->u8();
        $r->animation = $raw->u8();
        $r->blur = $raw->u8();
        $r->motion = StyleEnum::checked(StyleEnum::MOTION, $raw->u8(), 'motion');
        $raw->finish();
        return $r->validate();
    }

    public function validate(): self
    {
        if ($this->transition > 5) {
            throw new DecodeException('transition is a motion index + 1, at most 5');
        }
        if (($this->animation & ~StyleEnum::ANIMATION_MASK) !== 0) {
            throw new DecodeException('animation is a bit set of 1, 2 and 4');
        }
        if (($this->textDecoration & ~0b11) !== 0) {
            throw new DecodeException('text_decoration has unknown bits');
        }
        // A direction with nothing going that way.
        if ($this->motion !== 0
            && ($this->animation & (StyleEnum::ANIMATION_ENTER | StyleEnum::ANIMATION_EXIT)) === 0) {
            throw new DecodeException('motion needs an entrance or an exit to belong to');
        }
        return $this;
    }

    public function encode(Writer $w): void
    {
        $w->u8($this->display)->u8($this->wrap)->u8($this->justify)
          ->u8($this->alignItems)->u8($this->alignSelf)
          ->u8($this->grow)->u8($this->shrink)->u8($this->gap);
        foreach ([$this->basis, $this->width, $this->height, $this->minWidth,
                  $this->minHeight, $this->maxWidth, $this->maxHeight] as $dim) {
            $dim->encode($w);
        }
        $w->raw(pack('C4', ...$this->padding))->raw(pack('C4', ...$this->margin));
        $w->u16($this->bg->bits)->u16($this->fg->bits)->u16($this->borderColor->bits);
        $w->raw(pack('C4', ...$this->borderWidth));
        $w->u8($this->radius)->u8($this->shadow)->u8($this->opacity);
        $w->u8($this->fontFamily)->u8($this->fontSize)->u8($this->fontWeight)->u8($this->textAlign);
        $w->u8($this->lineClamp)->u8($this->textDecoration)->u8($this->overflow);
        $w->u8($this->position)->u8($this->z)->u8($this->cursor);
        $w->u8($this->transition)->u8($this->animation)->u8($this->blur)->u8($this->motion);
    }

    public function toBytes(): string
    {
        $w = new Writer();
        $this->encode($w);
        return $w->toBytes();
    }
}
