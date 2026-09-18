<?php

declare(strict_types=1);

namespace EUI\Proto;

use EUI\DecodeException;

/**
 * A length, in one of the five forms the layout algorithm understands
 * (02 §3.1). There is no `calc()`: a server that wants a computed length
 * computes it, and the wire carries the answer.
 */
final class Dim
{
    public const AUTO = 0;
    public const PX = 1;
    public const PERCENT = 2;
    public const FR = 3;
    public const SPACE = 4;

    public function __construct(public readonly int $tag = self::AUTO, public readonly int $value = 0)
    {
    }

    public static function auto(): self
    {
        return new self(self::AUTO, 0);
    }

    public static function px(int $value): self
    {
        return new self(self::PX, $value);
    }

    public static function percent(int $hundredths): self
    {
        return new self(self::PERCENT, $hundredths);
    }

    public static function fr(int $hundredths): self
    {
        return new self(self::FR, $hundredths);
    }

    public static function space(int $index): self
    {
        return new self(self::SPACE, $index);
    }

    public static function decode(Reader $r): self
    {
        $tag = $r->u8();
        $value = $r->u16();
        if ($tag === self::AUTO && $value !== 0) {
            throw new DecodeException('Dim::auto carries a value');
        }
        if ($tag > self::SPACE) {
            throw new DecodeException("unknown Dim tag {$tag}");
        }
        if ($tag === self::SPACE && $value > 255) {
            throw new DecodeException('space index above 255');
        }
        return new self($tag, $value);
    }

    public function encode(Writer $w): void
    {
        $w->u8($this->tag)->u16($this->value);
    }

    public function equals(self $other): bool
    {
        return $this->tag === $other->tag && $this->value === $other->value;
    }
}
