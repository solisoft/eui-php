<?php

declare(strict_types=1);

namespace EUI\View;

/**
 * How many of the rows still waiting sit ahead of this one.
 *
 * A plain array of counts would answer it in a scan; this answers it, and
 * takes a row out of the running, in log n.
 */
final class Fenwick
{
    /** @var list<int> */
    private array $tree;

    public function __construct(private readonly int $size)
    {
        $this->tree = array_fill(0, $size + 1, 0);
        for ($i = 1; $i <= $size; $i++) {
            $this->tree[$i]++;
            $parent = $i + ($i & -$i);
            if ($parent <= $size) {
                $this->tree[$parent] += $this->tree[$i];
            }
        }
    }

    /** Rows still waiting at slots `0...$slot`. */
    public function countBefore(int $slot): int
    {
        $total = 0;
        for ($i = $slot; $i > 0; $i -= $i & -$i) {
            $total += $this->tree[$i];
        }
        return $total;
    }

    public function place(int $slot): void
    {
        for ($i = $slot + 1; $i <= $this->size; $i += $i & -$i) {
            $this->tree[$i]--;
        }
    }
}
