<?php

declare(strict_types=1);

namespace EUI\Proto;

use EUI\DecodeException;
use EUI\ViewException;

/**
 * A property value (02 §4.4).
 *
 * Tagged rather than inferred from the PHP value: a string may be an atom or
 * an inline run, and 32 bytes may be an asset hash or a label.
 */
final class Value
{
    public const NULL = 0x00;
    public const BOOL = 0x01;
    public const INT = 0x02;
    public const FLOAT = 0x03;
    public const ATOM = 0x04;
    public const STR = 0x05;
    public const ASSET = 0x06;
    public const COLOR = 0x07;
    public const LIST = 0x08;

    private function __construct(public readonly int $tag, public readonly mixed $value = null)
    {
    }

    public static function null(): self
    {
        return new self(self::NULL);
    }

    public static function bool(bool $b): self
    {
        return new self(self::BOOL, $b);
    }

    public static function int(int $n): self
    {
        return new self(self::INT, $n);
    }

    public static function float(float $f): self
    {
        if (is_nan($f) || is_infinite($f)) {
            throw new ViewException('a float on the wire must be finite');
        }
        return new self(self::FLOAT, $f);
    }

    public static function atom(int $id): self
    {
        return new self(self::ATOM, $id);
    }

    public static function str(string $s): self
    {
        return new self(self::STR, $s);
    }

    public static function asset(string $digest): self
    {
        if (\strlen($digest) !== Limits::HASH_BYTES) {
            throw new ViewException('an asset is 32 bytes');
        }
        return new self(self::ASSET, $digest);
    }

    public static function color(ColorRef $ref): self
    {
        return new self(self::COLOR, $ref);
    }

    /** @param list<Value> $items */
    public static function list(array $items): self
    {
        return new self(self::LIST, $items);
    }

    /**
     * A plain PHP value as the wire would carry it. Strings go inline:
     * interning is the encoder's decision, not this one's.
     */
    public static function of(mixed $raw): self
    {
        if ($raw === null) {
            return self::null();
        }
        if ($raw instanceof self) {
            return $raw;
        }
        if (\is_bool($raw)) {
            return self::bool($raw);
        }
        if (\is_int($raw)) {
            return self::int($raw);
        }
        if (\is_float($raw)) {
            return self::float($raw);
        }
        if (\is_string($raw)) {
            return self::str($raw);
        }
        if ($raw instanceof ColorRef) {
            return self::color($raw);
        }
        if (\is_array($raw)) {
            return self::list(array_map(self::of(...), array_values($raw)));
        }
        throw new ViewException('a prop cannot carry ' . get_debug_type($raw));
    }

    public static function decode(Reader $r, int $depth = 1): self
    {
        if ($depth > Limits::MAX_VALUE_DEPTH) {
            throw new DecodeException('value nesting');
        }
        $tag = $r->u8();
        switch ($tag) {
            case self::NULL:
                return self::null();
            case self::BOOL:
                $b = $r->u8();
                if ($b > 1) {
                    throw new DecodeException('bool must be 0 or 1');
                }
                return self::bool($b === 1);
            case self::INT:
                return self::int($r->svarint());
            case self::FLOAT:
                return self::float($r->f64());
            case self::ATOM:
                return self::atom($r->varint32());
            case self::STR:
                return self::str($r->str(Limits::MAX_INLINE_STR, 'inline string'));
            case self::ASSET:
                return self::asset($r->take(Limits::HASH_BYTES));
            case self::COLOR:
                return self::color(new ColorRef($r->u16()));
            case self::LIST:
                $count = $r->varint32Max(Limits::MAX_VALUE_LIST, 'value list length');
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    $items[] = self::decode($r, $depth + 1);
                }
                return self::list($items);
            default:
                throw new DecodeException("unknown Value tag {$tag}");
        }
    }

    public function encode(Writer $w): void
    {
        switch ($this->tag) {
            case self::NULL:
                $w->u8(self::NULL);
                break;
            case self::BOOL:
                $w->u8(self::BOOL)->u8($this->value ? 1 : 0);
                break;
            case self::INT:
                $w->u8(self::INT)->svarint($this->value);
                break;
            case self::FLOAT:
                $w->u8(self::FLOAT)->f64($this->value);
                break;
            case self::ATOM:
                $w->u8(self::ATOM)->varint($this->value);
                break;
            case self::STR:
                $w->u8(self::STR)->str($this->value);
                break;
            case self::ASSET:
                $w->u8(self::ASSET)->raw($this->value);
                break;
            case self::COLOR:
                $w->u8(self::COLOR)->u16($this->value->bits);
                break;
            case self::LIST:
                $w->u8(self::LIST)->varint(\count($this->value));
                foreach ($this->value as $item) {
                    $item->encode($w);
                }
                break;
        }
    }

    /**
     * What a handler sees: plain PHP, with atoms left as their ids for the
     * session to resolve against its own table.
     */
    public function toPhp(): mixed
    {
        if ($this->tag === self::NULL) {
            return null;
        }
        if ($this->tag === self::LIST) {
            return array_map(static fn (self $item) => $item->toPhp(), $this->value);
        }
        return $this->value;
    }

    public function equals(?self $other): bool
    {
        if ($other === null || $other->tag !== $this->tag) {
            return false;
        }
        if ($this->tag === self::LIST) {
            if (\count($other->value) !== \count($this->value)) {
                return false;
            }
            foreach ($this->value as $i => $item) {
                if (!$item->equals($other->value[$i])) {
                    return false;
                }
            }
            return true;
        }
        if ($this->tag === self::COLOR) {
            return $this->value->bits === $other->value->bits;
        }
        return $this->value === $other->value;
    }
}
