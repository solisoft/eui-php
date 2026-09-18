<?php

declare(strict_types=1);

namespace EUI\View;

use EUI\Proto\Op;
use EUI\Proto\Value;

/**
 * What it costs to go from the tree the client holds to the one the view
 * just returned.
 *
 * The point of the whole exercise: a click that changes one number is one
 * `SetText`, not a page. Keyed children reconcile by `MoveChild`, so
 * reordering a thousand-row table is *n* moves rather than a rebuild.
 */
final class Diff
{
    /** @var list<Op> */
    private array $ops = [];

    public function __construct(private readonly Encoder $encoder)
    {
    }

    /** @return list<Op> */
    public function ops(TNode $old, TNode $new): array
    {
        $this->ops = [];
        if ($this->replaceable($old, $new)) {
            $this->node($old, $new);
        } else {
            // The root changed kind: there is no parent to patch it in, so
            // the whole document is replaced. Tables are not cleared with it.
            $this->encoder->assignIds($new);
            $this->ops[] = Op::mount($this->encoder->subtreeOf($new));
        }
        return $this->ops;
    }

    private function replaceable(TNode $old, TNode $new): bool
    {
        return $old->kind === $new->kind && $old->key === $new->key;
    }

    private function node(TNode $old, TNode $new): void
    {
        $new->id = $old->id;
        if ($old->style !== $new->style) {
            $this->ops[] = Op::setStyle($new->id, $new->style);
        }
        if (!($old->text?->equals($new->text) ?? $new->text === null)) {
            $this->ops[] = Op::setText($new->id, $new->text);
        }
        $this->props($old, $new);
        $this->handlers($old, $new);
        $this->children($old, $new);
        // Instructions rather than state: a node is scrolled, or focused,
        // once — so what triggers the op is the view asking again, not the
        // client's own offset, which this server never learns.
        if ($new->scrollTo !== null && $new->scrollTo !== $old->scrollTo) {
            $this->ops[] = Op::scrollTo($new->id, $new->scrollTo[0], $new->scrollTo[1]);
        }
        if ($new->focusTo && !$old->focusTo) {
            $this->ops[] = Op::focus($new->id);
        }
    }

    private function props(TNode $old, TNode $new): void
    {
        $before = [];
        foreach ($old->props as [$atom, $value]) {
            $before[$atom] = $value;
        }
        $after = [];
        foreach ($new->props as [$atom, $value]) {
            $after[$atom] = $value;
            if (!isset($before[$atom]) || !$value->equals($before[$atom])) {
                $this->ops[] = Op::setProp($new->id, $atom, $value);
            }
        }
        // There is no op that removes a property, and a client that kept one
        // the view stopped sending would answer for a state nothing holds.
        // Null is how a prop goes away.
        foreach ($before as $atom => $_value) {
            if (!isset($after[$atom])) {
                $this->ops[] = Op::setProp($new->id, $atom, Value::null());
            }
        }
    }

    private function handlers(TNode $old, TNode $new): void
    {
        $before = [];
        foreach ($old->handlers as [$event, $handler]) {
            $before[$event] = $handler;
        }
        $after = [];
        foreach ($new->handlers as [$event, $handler]) {
            $after[$event] = $handler;
            if (!isset($before[$event]) || !$handler->equals($before[$event])) {
                $this->ops[] = Op::setHandler($new->id, $event, $handler);
            }
        }
        foreach ($before as $event => $_handler) {
            if (!isset($after[$event])) {
                $this->ops[] = Op::clearHandler($new->id, $event);
            }
        }
    }

    private function children(TNode $old, TNode $new): void
    {
        if ($old->children === [] && $new->children === []) {
            return;
        }
        if ($this->keyed($old->children) && $this->keyed($new->children)) {
            $this->keyedChildren($old, $new);
        } else {
            $this->positionalChildren($old, $new);
        }
    }

    /** @param list<TNode> $children */
    private function keyed(array $children): bool
    {
        if ($children === []) {
            return false;
        }
        foreach ($children as $child) {
            if ($child->key === null) {
                return false;
            }
        }
        return true;
    }

    /**
     * Position is identity: child *i* on one side is child *i* on the other.
     * Right for a view whose shape is fixed, wrong for a list — which is
     * what keys are for.
     */
    private function positionalChildren(TNode $old, TNode $new): void
    {
        $shared = min(\count($old->children), \count($new->children));
        for ($i = 0; $i < $shared; $i++) {
            $before = $old->children[$i];
            $after = $new->children[$i];
            if ($this->replaceable($before, $after)) {
                $this->node($before, $after);
            } else {
                $this->encoder->assignIds($after);
                $this->ops[] = Op::replace($before->id, $this->encoder->subtreeOf($after));
            }
        }

        if (\count($old->children) > $shared) {
            $this->ops[] = Op::removeChild($new->id, $shared, \count($old->children) - $shared);
        } elseif (\count($new->children) > $shared) {
            for ($i = $shared; $i < \count($new->children); $i++) {
                $child = $new->children[$i];
                $this->encoder->assignIds($child);
                $this->ops[] = Op::insertChild($new->id, $i, $this->encoder->subtreeOf($child));
            }
        }
    }

    /**
     * Identity is the key, so a row that moved is a row that moved rather
     * than every row below it having changed.
     *
     * The obvious way to write this is quadratic — scan the old children for
     * each new one — and a ten-thousand-row sort then costs fifty million
     * comparisons before a single byte is sent. What makes it `n log n`
     * instead is the observation that a `MoveChild` only ever pulls a row
     * *forward*: everything before `index` is already final, and the rest
     * keep their relative order. So a row's current position is `index` plus
     * however many rows ahead of it are still waiting, and a Fenwick tree
     * answers that in fourteen steps rather than ten thousand.
     */
    private function keyedChildren(TNode $old, TNode $new): void
    {
        $parent = $new->id;
        $wanted = [];
        foreach ($new->children as $child) {
            $wanted[$child->key] = true;
        }

        $cur = $old->children;
        $i = 0;
        while ($i < \count($cur)) {
            if (isset($wanted[$cur[$i]->key])) {
                $i++;
                continue;
            }
            $run = 1;
            while ($i + $run < \count($cur) && !isset($wanted[$cur[$i + $run]->key])) {
                $run++;
            }
            $this->ops[] = Op::removeChild($parent, $i, $run);
            array_splice($cur, $i, $run);
        }

        $at = [];
        foreach ($cur as $slot => $child) {
            $at[$child->key] = $slot;
        }
        $waiting = new Fenwick(\count($cur));

        foreach ($new->children as $index => $after) {
            $slot = $at[$after->key] ?? null;
            if ($slot === null) {
                $this->encoder->assignIds($after);
                $this->ops[] = Op::insertChild($parent, $index, $this->encoder->subtreeOf($after));
                continue;
            }

            $from = $index + $waiting->countBefore($slot);
            if ($from !== $index) {
                $this->ops[] = Op::moveChild($parent, $from, $index);
            }
            $waiting->place($slot);
            $this->reconcileKept($cur[$slot], $after);
        }
    }

    private function reconcileKept(TNode $before, TNode $after): void
    {
        if ($before->kind === $after->kind) {
            $this->node($before, $after);
        } else {
            $this->encoder->assignIds($after);
            $this->ops[] = Op::replace($before->id, $this->encoder->subtreeOf($after));
        }
    }
}
