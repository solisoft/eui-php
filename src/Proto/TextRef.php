<?php

declare(strict_types=1);

namespace EUI\Proto;

use EUI\DecodeException;

/** A string: interned in the session's atom table, or carried inline. */
final class TextRef
{
    private function __construct(public readonly ?int $atom, public readonly ?string $inline)
    {
    }

    public static function ofAtom(int $id): self
    {
        return new self($id, null);
    }

    public static function ofInline(string $text): self
    {
        return new self(null, $text);
    }

    public static function decode(Reader $r): self
    {
        $tag = $r->u8();
        return match ($tag) {
            0x00 => self::ofAtom($r->varint32()),
            0x01 => self::ofInline($r->str(Limits::MAX_INLINE_STR, 'inline string')),
            default => throw new DecodeException("unknown TextRef tag {$tag}"),
        };
    }

    public function encode(Writer $w): void
    {
        if ($this->atom !== null) {
            $w->u8(0x00)->varint($this->atom);
        } else {
            $w->u8(0x01)->str((string) $this->inline);
        }
    }

    public function equals(?self $other): bool
    {
        return $other !== null && $other->atom === $this->atom && $other->inline === $this->inline;
    }
}
