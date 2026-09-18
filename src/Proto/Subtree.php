<?php

declare(strict_types=1);

namespace EUI\Proto;

use EUI\DecodeException;

/**
 * A subtree, stored pre-order.
 *
 * Reconstructing the shape needs nothing but `childCount`: a node's first
 * child is the next entry. Decoding is iterative, so a hostile 10 000-deep
 * tree costs a bounds check rather than the stack.
 */
final class Subtree
{
    /** @var list<FlatNode> */
    public array $nodes = [];
    /** @var list<array{int,Value}> */
    public array $props = [];
    /** @var list<array{int,Handler}> */
    public array $handlers = [];

    public function root(): ?FlatNode
    {
        return $this->nodes[0] ?? null;
    }

    /** @return list<array{int,Value}> */
    public function propsOf(FlatNode $node): array
    {
        return \array_slice($this->props, $node->props[0], $node->props[1]);
    }

    /** @return list<array{int,Handler}> */
    public function handlersOf(FlatNode $node): array
    {
        return \array_slice($this->handlers, $node->handlers[0], $node->handlers[1]);
    }

    /**
     * @param list<array{int,Value}>   $props
     * @param list<array{int,Handler}> $handlers
     */
    public function push(
        int $kind,
        int $id,
        int $style,
        int $key = 0,
        ?TextRef $text = null,
        array $props = [],
        array $handlers = [],
        int $childCount = 0,
    ): self {
        $propStart = \count($this->props);
        foreach ($props as $pair) {
            $this->props[] = $pair;
        }
        $handlerStart = \count($this->handlers);
        foreach ($handlers as $pair) {
            $this->handlers[] = $pair;
        }
        $this->nodes[] = new FlatNode(
            $kind, $id, $style, $key, $text,
            [$propStart, \count($props)], [$handlerStart, \count($handlers)], $childCount
        );
        return $this;
    }

    public static function decode(Reader $r): self
    {
        $out = new self();
        $pending = [];
        while (true) {
            if (\count($out->nodes) >= Limits::MAX_NODES) {
                throw new DecodeException('node count');
            }
            $childCount = $out->decodeOne($r);
            if ($childCount > 0) {
                if (\count($pending) >= Limits::MAX_TREE_DEPTH) {
                    throw new DecodeException('tree depth');
                }
                $pending[] = $childCount;
            } else {
                while ($pending !== []) {
                    $last = \count($pending) - 1;
                    $pending[$last]--;
                    if ($pending[$last] > 0) {
                        break;
                    }
                    array_pop($pending);
                }
            }
            if ($pending === []) {
                return $out;
            }
        }
    }

    private function decodeOne(Reader $r): int
    {
        $kind = $r->u8();
        NodeKind::name($kind);
        $flags = $r->u8();
        if (($flags & 0xF0) !== 0) {
            throw new DecodeException('reserved node flags set');
        }
        $id = $r->varint32();
        if ($id === 0) {
            throw new DecodeException('node id must be non-zero');
        }
        $style = $r->varint32();
        $key = ($flags & 0x01) !== 0 ? $r->varint32() : 0;
        $text = ($flags & 0x02) !== 0 ? TextRef::decode($r) : null;

        $props = [];
        if (($flags & 0x04) !== 0) {
            $count = $r->varint32Max(Limits::MAX_PROPS, 'props per node');
            for ($i = 0; $i < $count; $i++) {
                $props[] = [$r->varint32(), Value::decode($r)];
            }
        }

        $handlers = [];
        if (($flags & 0x08) !== 0) {
            $count = $r->varint32Max(Limits::MAX_HANDLERS, 'handlers per node');
            for ($i = 0; $i < $count; $i++) {
                $event = $r->u8();
                EventKind::name($event); // refuse one this revision does not define
                $handlers[] = [$event, Handler::decode($r)];
            }
        }

        if (NodeKind::isInert($kind) && ($text !== null || $props !== [] || $handlers !== [])) {
            throw new DecodeException('inert node kind carries content');
        }

        $childCount = $r->varint32Max(Limits::MAX_CHILDREN, 'children per node');
        if (NodeKind::isLeaf($kind) && $childCount > 0) {
            throw new DecodeException('a leaf kind carries children');
        }

        $this->push($kind, $id, $style, $key, $text, $props, $handlers, $childCount);
        return $childCount;
    }

    public function encode(Writer $w): void
    {
        foreach ($this->nodes as $node) {
            $props = $this->propsOf($node);
            $handlers = $this->handlersOf($node);

            $flags = 0;
            if ($node->key !== 0) {
                $flags |= 0x01;
            }
            if ($node->text !== null) {
                $flags |= 0x02;
            }
            if ($props !== []) {
                $flags |= 0x04;
            }
            if ($handlers !== []) {
                $flags |= 0x08;
            }

            $w->u8($node->kind)->u8($flags)->varint($node->id)->varint($node->style);
            if ($node->key !== 0) {
                $w->varint($node->key);
            }
            $node->text?->encode($w);
            if ($props !== []) {
                $w->varint(\count($props));
                foreach ($props as [$atom, $value]) {
                    $w->varint($atom);
                    $value->encode($w);
                }
            }
            if ($handlers !== []) {
                $w->varint(\count($handlers));
                foreach ($handlers as [$event, $handler]) {
                    $w->u8($event);
                    $handler->encode($w);
                }
            }
            $w->varint($node->childCount);
        }
    }
}
