<?php

declare(strict_types=1);

namespace EUI\Proto;

/**
 * One node, with its props and handlers held as ranges into the owning
 * subtree's side arrays.
 */
final class FlatNode
{
    public function __construct(
        public int $kind,
        public int $id,
        public int $style,
        public int $key = 0,
        public ?TextRef $text = null,
        /** @var array{int,int} */
        public array $props = [0, 0],
        /** @var array{int,int} */
        public array $handlers = [0, 0],
        public int $childCount = 0,
    ) {
    }
}
