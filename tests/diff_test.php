<?php

declare(strict_types=1);

/**
 * What a change costs.
 *
 * The protocol's whole claim is in these numbers: a click that changes one
 * number is one op, and a thousand-row table reordered is *n* moves rather
 * than a rebuild.
 */

use EUI\Dsl;
use EUI\Proto\Op;
use EUI\Proto\Value;
use EUI\View\Encoder;

$opcodes = static fn (array $ops) => array_map(static fn (Op $op) => $op->opcode, $ops);
$rows = static fn (array $keys) => Dsl::column(array_map(
    static fn ($k) => Dsl::keyed((string) $k, Dsl::text((string) $k)), $keys
));

return [
    'the first render is a mount' => function () use ($opcodes): void {
        $ops = (new Encoder())->render(Dsl::column([Dsl::text('0')]));
        Assert::same(Op::MOUNT, end($ops)->opcode);
    },

    'one changed word is one op' => function () use ($opcodes): void {
        $e = new Encoder();
        $e->render(Dsl::column([Dsl::text('0'), Dsl::text('steady')]));
        $ops = $e->render(Dsl::column([Dsl::text('1'), Dsl::text('steady')]));
        Assert::same([Op::SET_TEXT], $opcodes($ops));
        Assert::same('1', $ops[0]->get('text')->inline);
    },

    'an unchanged view costs nothing' => function (): void {
        $e = new Encoder();
        $e->render(Dsl::column([Dsl::text('0')]));
        Assert::same([], $e->render(Dsl::column([Dsl::text('0')])));
    },

    'a changed style is one set_style' => function () use ($opcodes): void {
        $e = new Encoder();
        $e->render(Dsl::text('x', ['fg' => 'text.default']));
        // The record is new to the session, so it is defined before it is named.
        Assert::same([Op::DEF_STYLE, Op::SET_STYLE], $opcodes($e->render(Dsl::text('x', ['fg' => 'danger.base']))));
    },

    'a changed kind is a replace' => function () use ($opcodes): void {
        $e = new Encoder();
        $e->render(Dsl::column([Dsl::text('x')]));
        Assert::same([Op::REPLACE], $opcodes($e->render(Dsl::column([Dsl::box()]))));
    },

    'props that go away are set to null' => function () use ($opcodes): void {
        $e = new Encoder();
        $e->render(Dsl::box([], ['props' => ['a' => 1, 'b' => 2]]));
        $ops = $e->render(Dsl::box([], ['props' => ['a' => 3]]));
        Assert::same([Op::SET_PROP, Op::SET_PROP], $opcodes($ops));
        Assert::true($ops[0]->get('value')->equals(Value::int(3)));
        Assert::true($ops[1]->get('value')->equals(Value::null()));
    },

    'a handler removed is cleared' => function () use ($opcodes): void {
        $e = new Encoder();
        $e->render(Dsl::box([], ['on' => ['click' => 'a']]));
        Assert::same([Op::CLEAR_HANDLER], $opcodes($e->render(Dsl::box())));
    },

    'children appended and removed positionally' => function () use ($opcodes): void {
        $e = new Encoder();
        $e->render(Dsl::column([Dsl::text('a')]));
        Assert::same([Op::INSERT_CHILD], $opcodes($e->render(Dsl::column([Dsl::text('a'), Dsl::text('b')]))));

        $ops = $e->render(Dsl::column([Dsl::text('a')]));
        Assert::same([Op::REMOVE_CHILD], $opcodes($ops));
        Assert::same(1, $ops[0]->get('index'));
        Assert::same(1, $ops[0]->get('count'));
    },

    'a reordered keyed list is moves' => function () use ($opcodes, $rows): void {
        $e = new Encoder();
        $e->render($rows(['a', 'b', 'c']));
        $ops = $e->render($rows(['c', 'a', 'b']));
        Assert::same([Op::MOVE_CHILD], $opcodes($ops));
        Assert::same(2, $ops[0]->get('from'));
        Assert::same(0, $ops[0]->get('to'));
    },

    'a keyed row keeps its node id when it moves' => function () use ($rows): void {
        $e = new Encoder();
        $e->render($rows(['a', 'b', 'c']));
        $before = array_map(static fn ($child) => $child->id, $e->previous->children);
        $e->render($rows(['c', 'b', 'a']));
        $after = array_map(static fn ($child) => $child->id, $e->previous->children);
        Assert::same(array_reverse($before), $after, 'identity is the key, not the position');
    },

    'a run of removed rows is one op' => function () use ($opcodes, $rows): void {
        $e = new Encoder();
        $e->render($rows(['a', 'b', 'c', 'd', 'e']));
        $ops = $e->render($rows(['a', 'e']));
        Assert::same([Op::REMOVE_CHILD], $opcodes($ops));
        Assert::same(1, $ops[0]->get('index'));
        Assert::same(3, $ops[0]->get('count'));
    },

    'a new keyed row is inserted where it belongs' => function () use ($opcodes, $rows): void {
        $e = new Encoder();
        $e->render($rows(['a', 'c']));
        $ops = $e->render($rows(['a', 'b', 'c']));
        // The new key is a string this session had not interned yet, so its
        // definition rides ahead of the insertion that names it.
        Assert::same([Op::DEF_ATOM, Op::INSERT_CHILD], $opcodes($ops));
        Assert::same(1, end($ops)->get('index'));
    },

    'a resync sends the tree again but not the tables' => function () use ($opcodes, $rows): void {
        $e = new Encoder();
        $e->render($rows(['a', 'b']));
        $keys = [$e->atom('a'), $e->atom('b')];
        $e->forgetTree();
        Assert::same([Op::MOUNT], $opcodes($e->render($rows(['a', 'b']))), 'tables are never cleared');
        Assert::same($keys, [$e->atom('a'), $e->atom('b')], 'the atoms the session holds are still its own');
    },

    'scroll and focus are instructions, not props' => function () use ($opcodes): void {
        $e = new Encoder();
        $e->render(['k' => 'scroll', 'p' => ['scroll_to' => [0, 0]]]);
        $ops = $e->render(['k' => 'scroll', 'p' => ['scroll_to' => [0, 640]]]);
        Assert::same([Op::SCROLL_TO], $opcodes($ops));
        Assert::same(640, $ops[0]->get('y'));

        $e2 = new Encoder();
        $e2->render(Dsl::box([], ['props' => ['focus_to' => false]]));
        Assert::same([Op::FOCUS], $opcodes($e2->render(Dsl::box([], ['props' => ['focus_to' => true]]))));
    },
];
