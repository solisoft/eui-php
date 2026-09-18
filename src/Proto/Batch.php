<?php

declare(strict_types=1);

namespace EUI\Proto;

use EUI\DecodeException;

/**
 * An ordered run of ops carrying a sequence number. The client acks the last
 * one it applied, and applies a batch all or nothing.
 */
final class Batch
{
    /** @param list<Op> $ops */
    public function __construct(public readonly int $seq, public readonly array $ops = [])
    {
    }

    public static function decode(Reader $r): self
    {
        $seq = $r->varint();
        $count = $r->varint32Max(Limits::MAX_OPS_PER_BATCH, 'ops per batch');
        $ops = [];
        $notifications = 0;
        for ($i = 0; $i < $count; $i++) {
            $op = Op::decode($r);
            if ($op->opcode === Op::NOTIFY) {
                $notifications++;
                if ($notifications > Limits::MAX_NOTIFY_PER_BATCH) {
                    throw new DecodeException('notifications per batch');
                }
            }
            $ops[] = $op;
        }
        return new self($seq, $ops);
    }

    public function encode(Writer $w): void
    {
        $w->varint($this->seq)->varint(\count($this->ops));
        foreach ($this->ops as $op) {
            $op->encode($w);
        }
    }
}
