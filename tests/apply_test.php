<?php

declare(strict_types=1);

/**
 * The test the diff is actually worth: a client, in forty lines, that
 * applies what the server sends — and then the two trees are compared node
 * by node.
 *
 * Everything else about a patch stream can look right and still be wrong. A
 * `MoveChild` with an index off by one, a `RemoveChild` that counts from the
 * list before the removal rather than after, an id quietly reused: each
 * produces ops that encode, decode and apply without complaint, and leaves
 * the window showing a tree nobody wrote. The only check that catches those
 * is to *be* the client.
 */

use EUI\Dsl;
use EUI\Proto\Op;
use EUI\Proto\Subtree;
use EUI\Proto\Value;
use EUI\View\Encoder;
use EUI\View\TNode;

/** What a client holds: the same fields the wire carries, and nothing else. */
final class ClientNode
{
    public array $props = [];
    public array $handlers = [];
    public array $children = [];

    public function __construct(
        public int $id,
        public int $kind,
        public int $style,
        public int $key,
        public mixed $text,
    ) {
    }

    public function shape(): array
    {
        $props = $this->props;
        ksort($props);
        $handlers = $this->handlers;
        ksort($handlers);
        return [
            $this->id, $this->kind, $this->style, $this->key,
            $this->text?->inline ?? $this->text?->atom,
            array_map(static fn ($v) => [$v->tag, \is_object($v->value) ? get_object_vars($v->value) : $v->value], $props),
            array_map(static fn ($h) => [$h->kind, $h->chunk, $h->name], $handlers),
            array_map(static fn (ClientNode $c) => $c->shape(), $this->children),
        ];
    }
}

final class FakeClient
{
    public ?ClientNode $root = null;

    public function apply(array $ops): self
    {
        foreach ($ops as $op) {
            $this->applyOne($op);
        }
        return $this;
    }

    public function find(int $id): ?ClientNode
    {
        $stack = $this->root !== null ? [$this->root] : [];
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node->id === $id) {
                return $node;
            }
            foreach ($node->children as $child) {
                $stack[] = $child;
            }
        }
        return null;
    }

    private function applyOne(Op $op): void
    {
        $f = $op->fields;
        switch ($op->opcode) {
            case Op::MOUNT:
                $this->root = $this->build($f['subtree']);
                break;
            case Op::REPLACE:
                $this->replace($f['node'], $this->build($f['subtree']));
                break;
            case Op::SET_STYLE:
                $this->node($f['node'])->style = $f['style'];
                break;
            case Op::SET_TEXT:
                $this->node($f['node'])->text = $f['text'];
                break;
            case Op::SET_PROP:
                $node = $this->node($f['node']);
                if ($f['value']->equals(Value::null())) {
                    unset($node->props[$f['prop']]);
                } else {
                    $node->props[$f['prop']] = $f['value'];
                }
                break;
            case Op::SET_HANDLER:
                $this->node($f['node'])->handlers[$f['event']] = $f['handler'];
                break;
            case Op::CLEAR_HANDLER:
                unset($this->node($f['node'])->handlers[$f['event']]);
                break;
            case Op::INSERT_CHILD:
                $node = $this->node($f['parent']);
                array_splice($node->children, $f['index'], 0, [$this->build($f['subtree'])]);
                break;
            case Op::REMOVE_CHILD:
                $node = $this->node($f['parent']);
                array_splice($node->children, $f['index'], $f['count']);
                break;
            case Op::MOVE_CHILD:
                $node = $this->node($f['parent']);
                $moved = array_splice($node->children, $f['from'], 1);
                array_splice($node->children, $f['to'], 0, $moved);
                break;
            case Op::DEF_ATOM:
            case Op::DEF_STYLE:
            case Op::DEF_COLOR:
            case Op::DEF_FONT:
            case Op::FOCUS:
            case Op::SCROLL_TO:
            case Op::NOTIFY:
                break;
            default:
                throw new RuntimeException('the client has no op ' . $op->opcode);
        }
    }

    private function node(int $id): ClientNode
    {
        $node = $this->find($id);
        if ($node === null) {
            throw new RuntimeException("op names node {$id}, which this client does not have");
        }
        return $node;
    }

    private function replace(int $id, ClientNode $fresh): void
    {
        if ($this->root->id === $id) {
            $this->root = $fresh;
            return;
        }
        $stack = [$this->root];
        while ($stack !== []) {
            $node = array_pop($stack);
            foreach ($node->children as $index => $child) {
                if ($child->id === $id) {
                    $node->children[$index] = $fresh;
                    return;
                }
                $stack[] = $child;
            }
        }
        throw new RuntimeException("replace names node {$id}, which this client does not have");
    }

    /** A subtree arrives pre-order, its shape carried by `childCount` alone. */
    private function build(Subtree $subtree): ClientNode
    {
        $position = 0;
        $take = function () use ($subtree, &$position, &$take): ClientNode {
            $flat = $subtree->nodes[$position++];
            $node = new ClientNode($flat->id, $flat->kind, $flat->style, $flat->key, $flat->text);
            foreach ($subtree->propsOf($flat) as [$atom, $value]) {
                $node->props[$atom] = $value;
            }
            foreach ($subtree->handlersOf($flat) as [$event, $handler]) {
                $node->handlers[$event] = $handler;
            }
            for ($i = 0; $i < $flat->childCount; $i++) {
                $node->children[] = $take();
            }
            return $node;
        };
        return $take();
    }
}

/** The server's own tree, in the same shape, so the two can be compared. */
function server_shape(TNode $node): array
{
    $props = [];
    foreach ($node->props as [$atom, $value]) {
        $props[$atom] = $value;
    }
    ksort($props);
    $handlers = [];
    foreach ($node->handlers as [$event, $handler]) {
        $handlers[$event] = $handler;
    }
    ksort($handlers);
    return [
        $node->id, $node->kind, $node->style, $node->keyAtom,
        $node->text?->inline ?? $node->text?->atom,
        array_map(static fn ($v) => [$v->tag, \is_object($v->value) ? get_object_vars($v->value) : $v->value], $props),
        array_map(static fn ($h) => [$h->kind, $h->chunk, $h->name], $handlers),
        array_map(server_shape(...), $node->children),
    ];
}

$keyedRows = static function (array $keys, array $marked = []): array {
    return Dsl::column(array_map(
        static fn ($k) => Dsl::keyed((string) $k, Dsl::text((string) $k, [
            'fg' => \in_array($k, $marked, true) ? 'danger.base' : 'text.default',
        ])),
        $keys
    ));
};

$assertSame = static function (Encoder $encoder, FakeClient $client, string $what): void {
    Assert::same(server_shape($encoder->previous), $client->root->shape(), $what);
};

return [
    'a sequence of edits leaves the client holding the same tree' => function () use ($assertSame): void {
        $encoder = new Encoder();
        $client = new FakeClient();
        $views = [
            Dsl::column([Dsl::text('a'), Dsl::text('b')]),
            Dsl::column([Dsl::text('a'), Dsl::text('B'), Dsl::text('c')]),
            Dsl::column([Dsl::text('a')]),
            Dsl::column([Dsl::text('a'), Dsl::box([], ['props' => ['id' => 1], 'on' => ['click' => 'go']])]),
            Dsl::column([Dsl::text('a'), Dsl::box([], ['props' => ['id' => 2]])]),
            Dsl::column([Dsl::box(), Dsl::text('a')]),
            Dsl::column([Dsl::text('a'), Dsl::text('b'), Dsl::text('c')]),
        ];
        foreach ($views as $index => $view) {
            $client->apply($encoder->render($view));
            $assertSame($encoder, $client, "after view {$index}");
        }
    },

    'every permutation of five keyed rows applies' => function () use ($keyedRows, $assertSame): void {
        $keys = ['a', 'b', 'c', 'd', 'e'];
        $permute = static function (array $items) use (&$permute): array {
            if (\count($items) <= 1) {
                return [$items];
            }
            $out = [];
            foreach ($items as $i => $item) {
                $rest = $items;
                array_splice($rest, $i, 1);
                foreach ($permute($rest) as $tail) {
                    $out[] = array_merge([$item], $tail);
                }
            }
            return $out;
        };
        foreach ($permute($keys) as $wanted) {
            $encoder = new Encoder();
            $client = new FakeClient();
            $client->apply($encoder->render($keyedRows($keys)));
            $client->apply($encoder->render($keyedRows($wanted)));
            $assertSame($encoder, $client, 'reordered to ' . implode('', $wanted));
        }
    },

    'rows added, removed and reordered at once' => function () use ($keyedRows, $assertSame): void {
        $encoder = new Encoder();
        $client = new FakeClient();
        $client->apply($encoder->render($keyedRows(['a', 'b', 'c', 'd', 'e'])));
        $client->apply($encoder->render($keyedRows(['e', 'x', 'b', 'z', 'a'], ['b'])));
        $assertSame($encoder, $client, 'three at once');
    },

    // The one that finds what hand-written cases do not: a thousand random
    // edits, each one applied and checked.
    'random edits applied over and over' => function () use ($keyedRows, $assertSame): void {
        mt_srand(20260918);
        $keys = range('a', 'l');

        for ($round = 0; $round < 40; $round++) {
            $encoder = new Encoder();
            $client = new FakeClient();
            $present = \array_slice($keys, 0, mt_rand(1, 6));
            shuffle($present);
            $client->apply($encoder->render($keyedRows($present)));
            $assertSame($encoder, $client, "round {$round} mount");

            for ($step = 0; $step < 12; $step++) {
                switch (mt_rand(0, 4)) {
                    case 0:
                        shuffle($present);
                        break;
                    case 1:
                        $candidate = $keys[array_rand($keys)];
                        if (!\in_array($candidate, $present, true)) {
                            $present[] = $candidate;
                        }
                        break;
                    case 2:
                        if (\count($present) > 1) {
                            array_splice($present, array_rand($present), 1);
                        }
                        break;
                    case 3:
                        $candidate = $keys[array_rand($keys)];
                        if (!\in_array($candidate, $present, true)) {
                            array_splice($present, mt_rand(0, \count($present)), 0, [$candidate]);
                        }
                        break;
                }
                $marked = array_values(array_filter($present, static fn () => mt_rand(0, 2) === 0));
                $client->apply($encoder->render($keyedRows($present, $marked)));
                $assertSame($encoder, $client, "round {$round} step {$step}: " . implode(',', $present));
            }
        }
    },

    'a nested keyed list inside a changing shell' => function () use ($keyedRows, $assertSame): void {
        $encoder = new Encoder();
        $client = new FakeClient();
        $shell = static fn (string $title, array $rows) => Dsl::column([Dsl::text($title), $keyedRows($rows)]);

        $client->apply($encoder->render($shell('one', ['a', 'b', 'c'])));
        $client->apply($encoder->render($shell('two', ['c', 'a'])));
        $assertSame($encoder, $client, 'nested');
        $client->apply($encoder->render($shell('two', ['c', 'a', 'b'])));
        $assertSame($encoder, $client, 'nested, grown');
    },

    'node ids are never reused while a node is alive' => function () use ($keyedRows): void {
        $encoder = new Encoder();
        $client = new FakeClient();
        for ($i = 0; $i < 10; $i++) {
            $all = ['a', 'b', 'c', 'd'];
            $rows = \array_slice(array_merge(\array_slice($all, $i % 4), \array_slice($all, 0, $i % 4)), 0, 3);
            $client->apply($encoder->render($keyedRows($rows)));
            $live = [];
            $stack = [$client->root];
            while ($stack !== []) {
                $node = array_pop($stack);
                Assert::false(isset($live[$node->id]), "node {$node->id} appears twice in one tree");
                $live[$node->id] = true;
                foreach ($node->children as $child) {
                    $stack[] = $child;
                }
            }
        }
    },
];
