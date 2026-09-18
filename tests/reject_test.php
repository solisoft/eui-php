<?php

declare(strict_types=1);

/**
 * What the decoder refuses.
 *
 * Every case here is bytes a hostile peer can send for free, and every one
 * of them has a tempting repair: clamp the enum, ignore the trailing bytes,
 * normalise the varint, truncate the string. The repair is the bug — it is
 * how two implementations come to disagree about what a frame said, which is
 * the whole of the attack surface on a protocol like this.
 */

use EUI\DecodeException;
use EUI\Proto\Frame;
use EUI\Proto\Limits;
use EUI\Proto\NodeKind;
use EUI\Proto\Op;
use EUI\Proto\StyleRecord;
use EUI\Proto\Value;
use EUI\Proto\Viewport;
use EUI\Proto\Writer;
use EUI\View\Encoder;
use EUI\ViewException;

/** A batch of one op, wrapped as the frame it would really arrive in. */
$batchFrame = static function (string $opBytes): string {
    $body = (new Writer())->varint(1)->varint(1)->raw($opBytes)->toBytes();
    return (new Writer())->u8(0x03)->varint(\strlen($body))->raw($body)->toBytes();
};

$styleBytes = static function (?int $offset = null, int $value = 0): string {
    $raw = (new StyleRecord())->toBytes();
    if ($offset !== null) {
        $raw[$offset] = \chr($value);
    }
    return $raw;
};

$refuse = static function (string $raw, string $what): void {
    Assert::throws(DecodeException::class, static fn () => Frame::decode($raw), $what);
};

return [
    'a truncated frame' => function () use ($refuse): void {
        $whole = Frame::ack(7)->encode();
        $refuse(substr($whole, 0, -1), 'a frame shorter than it declared');
    },

    'trailing bytes after the payload' => function () use ($refuse): void {
        $refuse(Frame::ack(7)->encode() . "\x00", 'trailing bytes are an error, not padding');
    },

    'a frame kind this revision does not define' => function () use ($refuse): void {
        $refuse("\x7F\x00", 'unknown kinds are not reserved for forward compatibility');
    },

    'a frame longer than the ceiling' => function () use ($refuse): void {
        $refuse((new Writer())->u8(0x03)->varint(Limits::MAX_FRAME_BYTES + 1)->toBytes(),
            'a declared length past MAX_FRAME_BYTES');
    },

    'a non-minimal varint inside a frame' => function () use ($refuse): void {
        $refuse((new Writer())->u8(0x05)->varint(2)->raw("\x80\x00")->toBytes(), 'two bytes spelling zero');
    },

    'a string that is not UTF-8' => function () use ($refuse): void {
        $body = (new Writer())->varint(400)->varint(2)->raw("\xC3\x28")->toBytes();
        $refuse((new Writer())->u8(0x08)->varint(\strlen($body))->raw($body)->toBytes(),
            'a lone continuation byte');
    },

    'an inline string past its ceiling' => function () use ($refuse, $batchFrame): void {
        $tooLong = Limits::MAX_INLINE_STR + 1;
        $op = (new Writer())->u8(Op::SET_TEXT)->varint(1)->u8(0x01)
            ->varint($tooLong)->raw(str_repeat('x', $tooLong))->toBytes();
        $refuse($batchFrame($op), 'an inline string of more than 4 KiB');
    },

    'a node id of zero' => function () use ($refuse, $batchFrame): void {
        $refuse($batchFrame((new Writer())->u8(Op::SET_STYLE)->varint(0)->varint(1)->toBytes()),
            'id 0 is always "none"');
    },

    'reserved node flags' => function () use ($refuse, $batchFrame): void {
        $op = (new Writer())->u8(Op::MOUNT)->u8(NodeKind::code('box'))->u8(0x10)
            ->varint(1)->varint(0)->varint(0)->toBytes();
        $refuse($batchFrame($op), 'a flag bit this revision does not define');
    },

    'a node kind and an event kind nobody defines' => function () use ($refuse, $batchFrame): void {
        $op = (new Writer())->u8(Op::MOUNT)->u8(0x7E)->u8(0x00)->varint(1)->varint(0)->varint(0)->toBytes();
        $refuse($batchFrame($op), 'kind 0x7E');

        $refuse($batchFrame((new Writer())->u8(Op::CLEAR_HANDLER)->varint(1)->u8(0x7E)->toBytes()), 'event 0x7E');
    },

    'a leaf kind given children' => function () use ($refuse, $batchFrame): void {
        $w = (new Writer())->u8(Op::MOUNT);
        $w->u8(NodeKind::code('text'))->u8(0x00)->varint(1)->varint(0)->varint(1);
        $w->u8(NodeKind::code('text'))->u8(0x00)->varint(2)->varint(0)->varint(0);
        $refuse($batchFrame($w->toBytes()), 'a text node with a child');
    },

    'an inert kind carrying content' => function () use ($refuse, $batchFrame): void {
        $w = (new Writer())->u8(Op::MOUNT);
        $w->u8(NodeKind::code('divider'))->u8(0x02)->varint(1)->varint(0);
        $w->u8(0x01)->str('no');
        $w->varint(0);
        $refuse($batchFrame($w->toBytes()), 'a divider with text');
    },

    'more props and handlers than a node may carry' => function () use ($refuse, $batchFrame): void {
        $w = (new Writer())->u8(Op::MOUNT)->u8(NodeKind::code('box'))->u8(0x04)->varint(1)->varint(0);
        $w->varint(Limits::MAX_PROPS + 1);
        $refuse($batchFrame($w->toBytes()), 'more props than the ceiling');

        $w = (new Writer())->u8(Op::MOUNT)->u8(NodeKind::code('box'))->u8(0x08)->varint(1)->varint(0);
        $w->varint(Limits::MAX_HANDLERS + 1);
        $refuse($batchFrame($w->toBytes()), 'more handlers than the ceiling');
    },

    'a value nested past its depth' => function () use ($refuse, $batchFrame): void {
        $w = (new Writer())->u8(Op::SET_PROP)->varint(1)->varint(1);
        for ($i = 0; $i < 5; $i++) {
            $w->u8(Value::LIST)->varint(1);
        }
        $w->u8(Value::NULL);
        $refuse($batchFrame($w->toBytes()), 'a list five deep');
    },

    'a bool that is neither' => function () use ($refuse, $batchFrame): void {
        $op = (new Writer())->u8(Op::SET_PROP)->varint(1)->varint(1)->u8(Value::BOOL)->u8(2)->toBytes();
        $refuse($batchFrame($op), 'a bool of 2');
    },

    'a float that is not finite' => function () use ($refuse, $batchFrame): void {
        foreach ([NAN, INF] as $value) {
            $op = (new Writer())->u8(Op::SET_PROP)->varint(1)->varint(1)->u8(Value::FLOAT)->f64($value)->toBytes();
            $refuse($batchFrame($op), 'a float that is not finite');
        }
    },

    'a style record with an enum outside its range' => function () use ($refuse, $batchFrame, $styleBytes): void {
        $op = (new Writer())->u8(Op::DEF_STYLE)->varint(1)->raw($styleBytes(0, 9))->toBytes();
        $refuse($batchFrame($op), 'display 9 is clamped by nobody');
    },

    'a style record whose auto carries a value' => function () use ($refuse, $batchFrame, $styleBytes): void {
        $op = (new Writer())->u8(Op::DEF_STYLE)->varint(1)->raw($styleBytes(9, 5))->toBytes();
        $refuse($batchFrame($op), 'Dim::auto with a value');
    },

    'a style record with bits this revision does not define' => function () use ($refuse, $batchFrame, $styleBytes): void {
        foreach ([[60, 9], [61, 0x40], [55, 0x04]] as [$offset, $value]) {
            $op = (new Writer())->u8(Op::DEF_STYLE)->varint(1)->raw($styleBytes($offset, $value))->toBytes();
            $refuse($batchFrame($op), "style byte {$offset} = {$value}");
        }
    },

    'a font role with no face, and a role past the table' => function () use ($refuse, $batchFrame): void {
        $refuse($batchFrame((new Writer())->u8(Op::DEF_FONT)->u8(2)->varint(0)->toBytes()),
            'a role bound to nothing');
        $op = (new Writer())->u8(Op::DEF_FONT)->u8(Limits::MAX_FONT_ROLE + 1)
            ->varint(1)->raw(str_repeat('x', 32))->toBytes();
        $refuse($batchFrame($op), 'role 10');
    },

    'an opcode nobody defines' => function () use ($refuse, $batchFrame): void {
        $refuse($batchFrame("\x7F"), 'opcode 0x7F');
    },

    'a resume offer that is neither yes nor no' => function () use ($refuse): void {
        $body = (new Writer())->varint(4);
        (new Viewport())->encode($body);
        $body->varint(0)->u8(2);
        $raw = $body->toBytes();
        $refuse((new Writer())->u8(0x01)->varint(\strlen($raw))->raw($raw)->toBytes(), 'resume tag 2');
    },

    'a transfer chunk past its ceiling' => function () use ($refuse): void {
        $body = (new Writer())->varint(1)->varint(0)->u8(0)
            ->varint(Limits::MAX_TRANSFER_CHUNK_BYTES + 1)->toBytes();
        $refuse((new Writer())->u8(0x0B)->varint(\strlen($body))->raw($body)->toBytes(),
            'a chunk of more than 256 KiB');
    },

    // Not a decode error: these never reach the wire at all, which is the
    // point — the author finds out while writing the view.
    'a view the protocol cannot carry fails at encode' => function (): void {
        $encoder = new Encoder();
        $views = [
            ['k' => 'box', 's' => ['size' => 300]],
            ['k' => 'nope'],
            ['k' => 'box', 'on' => ['clicked' => 'x']],
            ['k' => 'box', 'p' => ['a' => new stdClass()]],
            ['k' => 'text', 't' => str_repeat('x', 5000)],
            ['k' => 'box', 'p' => ['scroll_to' => [0, 1]]],
        ];
        foreach ($views as $view) {
            Assert::throws(ViewException::class, static fn () => $encoder->render($view));
        }
    },
];
