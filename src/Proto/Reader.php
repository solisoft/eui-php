<?php

declare(strict_types=1);

namespace EUI\Proto;

use EUI\DecodeException;

/**
 * A cursor over bytes that refuses anything it was not promised.
 *
 * Two rules do most of the work: a varint must be minimally encoded, and a
 * frame must account for every byte it declared. Both are the classic route
 * to a parser disagreeing with itself.
 */
final class Reader
{
    private int $pos = 0;
    private int $size;

    public function __construct(private string $data)
    {
        $this->size = \strlen($data);
    }

    public function remaining(): int
    {
        return $this->size - $this->pos;
    }

    public function eof(): bool
    {
        return $this->pos >= $this->size;
    }

    public function take(int $count): string
    {
        if ($count > $this->remaining()) {
            throw new DecodeException("short read: wanted {$count}, {$this->remaining()} left");
        }
        $out = substr($this->data, $this->pos, $count);
        $this->pos += $count;
        return $out;
    }

    public function u8(): int
    {
        return \ord($this->take(1));
    }

    public function u16(): int
    {
        return unpack('v', $this->take(2))[1];
    }

    public function u32(): int
    {
        return unpack('V', $this->take(4))[1];
    }

    public function u64(): int
    {
        return unpack('P', $this->take(8))[1];
    }

    public function f64(): float
    {
        $value = unpack('e', $this->take(8))[1];
        if (is_nan($value) || is_infinite($value)) {
            throw new DecodeException('float must be finite');
        }
        return $value;
    }

    /**
     * LEB128, and only the minimal spelling of it: a multi-byte encoding
     * whose last byte is `0x00` is an error rather than a normalisation.
     */
    public function varint(int $maxBytes = 10): int
    {
        $value = 0;
        $shift = 0;
        $count = 0;
        while (true) {
            $byte = $this->u8();
            $count++;
            if ($count > $maxBytes) {
                throw new DecodeException('varint too long');
            }
            $value |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                break;
            }
            if ($count === $maxBytes) {
                throw new DecodeException('non-minimal varint');
            }
            $shift += 7;
        }
        if ($count > 1 && $value < (1 << (7 * ($count - 1)))) {
            throw new DecodeException('non-minimal varint');
        }
        return $value;
    }

    public function varint32(): int
    {
        $value = $this->varint(5);
        if ($value > 0xFFFFFFFF) {
            throw new DecodeException('varint overflows u32');
        }
        return $value;
    }

    public function varint32Max(int $max, string $what): int
    {
        $value = $this->varint32();
        if ($value > $max) {
            throw new DecodeException("{$what} above {$max}");
        }
        return $value;
    }

    public function svarint(): int
    {
        $raw = $this->varint(10);
        return ($raw >> 1) ^ -($raw & 1);
    }

    public function bytesField(int $max, string $what): string
    {
        $length = $this->varint32();
        if ($length > $max) {
            throw new DecodeException("{$what} of {$length} bytes, at most {$max}");
        }
        return $this->take($length);
    }

    public function str(int $max, string $what): string
    {
        $raw = $this->bytesField($max, $what);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            throw new DecodeException("{$what} is not valid UTF-8");
        }
        return $raw;
    }

    /** Trailing bytes are an error, not padding. */
    public function finish(): self
    {
        if (!$this->eof()) {
            throw new DecodeException("{$this->remaining()} trailing bytes");
        }
        return $this;
    }
}
