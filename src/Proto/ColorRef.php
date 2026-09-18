<?php

declare(strict_types=1);

namespace EUI\Proto;

/**
 * A colour: a theme role, a literal from the session's table, or nothing.
 * Roles are strongly preferred — only a role follows the viewer's mode,
 * contrast and density.
 */
final class ColorRef
{
    public const LITERAL_BIT = 0x8000;

    public function __construct(public readonly int $bits = 0)
    {
    }

    public static function none(): self
    {
        return new self(0);
    }

    public static function role(int $id): self
    {
        return new self($id & 0x7FFF);
    }

    public static function literal(int $index): self
    {
        return new self(($index & 0x7FFF) | self::LITERAL_BIT);
    }

    public function isLiteral(): bool
    {
        return ($this->bits & self::LITERAL_BIT) !== 0;
    }

    public function isNone(): bool
    {
        return $this->bits === 0;
    }

    public function index(): int
    {
        return $this->bits & 0x7FFF;
    }
}
