<?php

declare(strict_types=1);

namespace EUI\Proto;

use EUI\EUIException;

/**
 * An append-only byte buffer with the protocol's primitives on it (02 §1).
 * Every method returns `$this`, so an encoder reads as one sentence.
 */
final class Writer
{
    private string $buffer = '';

    public function u8(int $value): self
    {
        $this->buffer .= \chr($value & 0xFF);
        return $this;
    }

    public function u16(int $value): self
    {
        $this->buffer .= pack('v', $value & 0xFFFF);
        return $this;
    }

    public function u32(int $value): self
    {
        $this->buffer .= pack('V', $value & 0xFFFFFFFF);
        return $this;
    }

    public function u64(int $value): self
    {
        $this->buffer .= pack('P', $value);
        return $this;
    }

    public function f64(float $value): self
    {
        $this->buffer .= pack('e', $value);
        return $this;
    }

    /**
     * LEB128, minimally encoded. A decoder rejects any other spelling of the
     * same number, so there is only ever one.
     */
    public function varint(int $value): self
    {
        if ($value < 0) {
            throw new EUIException("varint cannot carry {$value}");
        }
        while (true) {
            $byte = $value & 0x7F;
            $value >>= 7;
            if ($value === 0) {
                $this->buffer .= \chr($byte);
                return $this;
            }
            $this->buffer .= \chr($byte | 0x80);
        }
    }

    /**
     * LEB128 over zigzag: the sign rides in the low bit, so a small negative
     * number costs one byte like a small positive one.
     */
    public function svarint(int $value): self
    {
        return $this->varint(($value << 1) ^ ($value >> 63));
    }

    public function bytes(string $raw): self
    {
        $this->varint(\strlen($raw));
        $this->buffer .= $raw;
        return $this;
    }

    public function str(string $value): self
    {
        return $this->bytes($value);
    }

    public function raw(string $raw): self
    {
        $this->buffer .= $raw;
        return $this;
    }

    public function length(): int
    {
        return \strlen($this->buffer);
    }

    public function toBytes(): string
    {
        return $this->buffer;
    }
}
