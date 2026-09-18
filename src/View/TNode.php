<?php

declare(strict_types=1);

namespace EUI\View;

use EUI\Proto\TextRef;

/**
 * A node on its way to the wire: the view's array with every string
 * interned, every style resolved to a table id, and an id of its own once
 * the diff has settled one.
 */
final class TNode
{
    public function __construct(
        public int $id,
        public int $kind,
        public int $style,
        public ?string $key = null,
        public int $keyAtom = 0,
        public ?TextRef $text = null,
        /** @var list<array{int,\EUI\Proto\Value}> */
        public array $props = [],
        /** @var list<array{int,\EUI\Proto\Handler}> */
        public array $handlers = [],
        /** @var list<TNode> */
        public array $children = [],
        /** @var array{int,int}|null */
        public ?array $scrollTo = null,
        public bool $focusTo = false,
    ) {
    }

    public function size(): int
    {
        $total = 1;
        foreach ($this->children as $child) {
            $total += $child->size();
        }
        return $total;
    }
}
