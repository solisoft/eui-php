<?php

declare(strict_types=1);

namespace EUI\Proto;

use EUI\DecodeException;

/** What an event does when it happens. */
final class Handler
{
    public const SERVER = 0x00;
    public const LOCAL = 0x01;
    public const BOTH = 0x02;

    private function __construct(
        public readonly int $kind,
        public readonly ?int $chunk = null,
        public readonly ?int $name = null,
    ) {
    }

    public static function server(int $atom): self
    {
        return new self(self::SERVER, null, $atom);
    }

    public static function local(int $chunk): self
    {
        return new self(self::LOCAL, $chunk, null);
    }

    public static function localThenServer(int $chunk, int $atom): self
    {
        return new self(self::BOTH, $chunk, $atom);
    }

    public static function decode(Reader $r): self
    {
        $tag = $r->u8();
        return match ($tag) {
            self::SERVER => self::server($r->varint32()),
            self::LOCAL => self::local($r->varint32()),
            self::BOTH => self::localThenServer($r->varint32(), $r->varint32()),
            default => throw new DecodeException("unknown Handler tag {$tag}"),
        };
    }

    public function encode(Writer $w): void
    {
        match ($this->kind) {
            self::SERVER => $w->u8(self::SERVER)->varint((int) $this->name),
            self::LOCAL => $w->u8(self::LOCAL)->varint((int) $this->chunk),
            default => $w->u8(self::BOTH)->varint((int) $this->chunk)->varint((int) $this->name),
        };
    }

    public function equals(?self $other): bool
    {
        return $other !== null && $other->kind === $this->kind
            && $other->chunk === $this->chunk && $other->name === $this->name;
    }
}
