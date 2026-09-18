<?php

declare(strict_types=1);

/**
 * The wire format, against the numbers `spec/02-wire-format.md` pins.
 *
 * A second implementation written from that document alone has to check
 * itself against the same bytes, which is the whole reason they are written
 * down there rather than only in the reference crate.
 */

use EUI\DecodeException;
use EUI\Proto\Batch;
use EUI\Proto\ColorRef;
use EUI\Proto\EventKind;
use EUI\Proto\Frame;
use EUI\Proto\Handler;
use EUI\Proto\NodeKind;
use EUI\Proto\Op;
use EUI\Proto\Protocol;
use EUI\Proto\Reader;
use EUI\Proto\StyleEnum;
use EUI\Proto\StyleRecord;
use EUI\Proto\Subtree;
use EUI\Proto\TextRef;
use EUI\Proto\Value;
use EUI\Proto\Viewport;
use EUI\Proto\Writer;
use EUI\Proto\Caps;

$encode = static function (Op $op): string {
    $w = new Writer();
    $op->encode($w);
    return $w->toBytes();
};

return [
    'a varint is minimally encoded' => function (): void {
        $w = new Writer();
        foreach ([0, 1, 127, 128, 300] as $n) {
            $w->varint($n);
        }
        Assert::same('00017f8001ac02', bin2hex($w->toBytes()));

        $r = new Reader($w->toBytes());
        Assert::same([0, 1, 127, 128, 300], [$r->varint(), $r->varint(), $r->varint(), $r->varint(), $r->varint()]);
        Assert::true($r->eof());
    },

    // 0x80 0x00 is a two-byte spelling of zero. Normalising it is how one
    // implementation's signature check becomes another's bypass.
    'a non-minimal varint is refused' => function (): void {
        Assert::throws(DecodeException::class, static fn () => (new Reader("\x80\x00"))->varint32());
    },

    'svarint zigzags' => function (): void {
        $values = [0, -1, 1, -2, 2, 2 ** 40, -(2 ** 40)];
        $w = new Writer();
        foreach ($values as $n) {
            $w->svarint($n);
        }
        $r = new Reader($w->toBytes());
        $back = [];
        foreach ($values as $_) {
            $back[] = $r->svarint();
        }
        Assert::same($values, $back);
    },

    'the default style record, byte for byte' => function (): void {
        $raw = (new StyleRecord())->toBytes();
        Assert::same(64, \strlen($raw));
        $expected = array_merge(
            [0x00, 0x00, 0x00, 0x03, 0x05, 0x00, 0x01, 0x00],
            array_fill(0, 21, 0),      // the seven Dims
            array_fill(0, 8, 0),       // padding, margin
            array_fill(0, 6, 0),       // bg, fg, border_color
            array_fill(0, 4, 0),       // border_width
            [0x00, 0x00, 0xFF],        // radius, shadow, opacity
            [0x00, 0x02, 0x00, 0x00],  // font_family, size base, weight, align
            array_fill(0, 6, 0),       // clamp, decoration, overflow, position, z, cursor
            array_fill(0, 4, 0),       // transition, animation, blur, motion
        );
        Assert::same($expected, array_values(unpack('C*', $raw)));
    },

    // `spec/02-wire-format.md` §8, byte for byte: a column with "Hi" in it.
    'the worked example is 150 bytes' => function () use ($encode): void {
        $tree = new Subtree();
        $tree->push(NodeKind::code('box'), 1, 1, 0, null, [], [], 1);
        $tree->push(NodeKind::code('text'), 2, 2, 0, TextRef::ofAtom(1));

        $column = new StyleRecord();
        $column->display = StyleEnum::DISPLAY['column'];
        $column->padding = [4, 4, 4, 4];
        $column->bg = ColorRef::role(1);

        $label = new StyleRecord();
        $label->fontSize = 3;
        $label->fg = ColorRef::role(8);

        $body = $encode(Op::defAtom(1, 'Hi')) . $encode(Op::defStyle(1, $column))
            . $encode(Op::defStyle(2, $label)) . $encode(Op::mount($tree));

        Assert::same(150, \strlen($body), 'spec §8 body size');
        Assert::same('1001024869', bin2hex(substr($body, 0, 5)), 'DefAtom');
        Assert::same('1101', bin2hex(substr($body, 5, 2)), 'DefStyle 1 header');
        Assert::same('1102', bin2hex(substr($body, 71, 2)), 'DefStyle 2 header');
        Assert::same('20010001010102020202000100', bin2hex(substr($body, 137, 13)), 'Mount');
    },

    'a subtree round trips' => function (): void {
        $tree = new Subtree();
        $tree->push(NodeKind::code('box'), 1, 3, 7, null,
            [[2, Value::int(42)], [3, Value::list([Value::bool(true), Value::str('x')])]],
            [[EventKind::code('click'), Handler::server(9)]], 1);
        $tree->push(NodeKind::code('text'), 2, 0, 0, TextRef::ofInline('Hi'));

        $w = new Writer();
        $tree->encode($w);
        $back = Subtree::decode(new Reader($w->toBytes()));

        Assert::same(2, \count($back->nodes));
        Assert::same(7, $back->nodes[0]->key);
        Assert::same(2, \count($back->propsOf($back->nodes[0])));
        Assert::true($back->propsOf($back->nodes[0])[0][1]->equals(Value::int(42)));
        Assert::true($back->handlersOf($back->nodes[0])[0][1]->equals(Handler::server(9)));
        Assert::same('Hi', $back->nodes[1]->text->inline);
    },

    'a leaf with children is refused' => function (): void {
        $tree = new Subtree();
        $tree->push(NodeKind::code('text'), 1, 0, 0, null, [], [], 1);
        $tree->push(NodeKind::code('text'), 2, 0);
        $w = new Writer();
        $tree->encode($w);
        Assert::throws(DecodeException::class, static fn () => Subtree::decode(new Reader($w->toBytes())));
    },

    'frames round trip' => function (): void {
        $frames = [
            Frame::hello(4, new Viewport(1280, 900, 200, 1, 0, 125), Caps::mask(['fs.pick', 'net.open'])),
            Frame::welcome(4, str_repeat('x', 16), true),
            Frame::ack(9),
            Frame::ping('12345678'),
            Frame::pong('12345678'),
            Frame::error(400, 'no'),
            Frame::resync(),
            Frame::viewport(new Viewport()),
            Frame::event(3, EventKind::code('change'), 5, Value::str('typed')),
        ];
        foreach ($frames as $frame) {
            $raw = $frame->encode();
            $back = Frame::decode($raw);
            Assert::same($frame->kind, $back->kind);
            Assert::same(bin2hex($raw), bin2hex($back->encode()), "frame kind {$frame->kind}");
        }
    },

    'trailing bytes are an error' => function (): void {
        Assert::throws(DecodeException::class, static fn () => Frame::decode(Frame::ack(1)->encode() . 'x'));
    },

    'an unknown capability bit is refused' => function (): void {
        $raw = Frame::hello(4, new Viewport(), 1 << 20)->encode();
        Assert::throws(DecodeException::class, static fn () => Frame::decode($raw));
    },

    'a batch carries at most four notifications' => function (): void {
        $ops = [];
        for ($i = 0; $i < 5; $i++) {
            $ops[] = Op::notify('hi');
        }
        $raw = Frame::batch(new Batch(1, $ops))->encode();
        Assert::throws(DecodeException::class, static fn () => Frame::decode($raw));
    },

    'a style record rejects a motion with nothing to direct' => function (): void {
        $record = new StyleRecord();
        $record->motion = StyleEnum::MOTION['top'];
        Assert::throws(DecodeException::class, static fn () => $record->validate());
    },

    'the protocol version is the one the client negotiates' => function (): void {
        Assert::same(4, Protocol::VERSION);
    },
];
