<?php

declare(strict_types=1);

/** The view layer: the style vocabulary, the tables, and what a view that
 * cannot be encoded does about it. */

use EUI\Blake3;
use EUI\Dsl;
use EUI\Proto\Dim;
use EUI\Proto\EventKind;
use EUI\Proto\Handler;
use EUI\Proto\Op;
use EUI\Proto\StyleEnum;
use EUI\Proto\Value;
use EUI\Theme;
use EUI\View\Compiler;
use EUI\View\Encoder;
use EUI\ViewException;

$compile = static fn (array $style) => (new Compiler())->record($style);

return [
    'the style vocabulary' => function () use ($compile): void {
        $r = $compile([
            'display' => 'column', 'justify' => 'between', 'align' => 'center',
            'gap' => 4, 'pad' => [2, 3], 'bg' => 'surface.raised', 'fg' => 'text.muted',
            'size' => 'lg', 'weight' => 'bold', 'radius' => 'md', 'shadow' => 'sm',
            'width' => '50%', 'height' => 240, 'max_width' => '1fr', 'basis' => 'sp:4',
            'underline' => true, 'cursor' => 'pointer', 'transition' => 'fast',
        ]);
        Assert::same(StyleEnum::DISPLAY['column'], $r->display);
        Assert::same(StyleEnum::JUSTIFY['between'], $r->justify);
        Assert::same([2, 3, 2, 3], $r->padding);
        Assert::same(Theme::role('surface.raised'), $r->bg->index());
        Assert::same(3, $r->fontSize, 'lg is index 3 on the text scale');
        Assert::true($r->width->equals(Dim::percent(5000)));
        Assert::true($r->height->equals(Dim::px(240)));
        Assert::true($r->maxWidth->equals(Dim::fr(100)));
        Assert::true($r->basis->equals(Dim::space(4)));
        Assert::same(1, $r->textDecoration);
        Assert::same(1, $r->transition, 'fast is motion index 0, carried as index + 1');
    },

    // Not a key that does nothing: a style silently dropped is a page that
    // is wrong for a day.
    'an unknown style key is an error' => function () use ($compile): void {
        $e = Assert::throws(ViewException::class, static fn () => $compile(['padding' => 4]));
        Assert::contains("unknown style key 'padding'", $e->getMessage());
    },

    'an unknown colour role is an error' => function () use ($compile): void {
        Assert::throws(ViewException::class, static fn () => $compile(['bg' => 'surface.fancy']));
    },

    'a literal colour needs a table' => function () use ($compile): void {
        Assert::throws(ViewException::class, static fn () => $compile(['bg' => '#ff8800']));
        $seen = [];
        $compiler = new Compiler(colors: static function (int $rgba) use (&$seen): int {
            $seen[] = $rgba;
            return 1;
        });
        $ref = $compiler->record(['bg' => '#ff8800'])->bg;
        Assert::same([0xFF8800FF], $seen);
        Assert::true($ref->isLiteral());
    },

    'tables are append-only and deduplicate' => function () use ($compile): void {
        $encoder = new Encoder();
        Assert::same(1, $encoder->atom('click'));
        Assert::same(1, $encoder->atom('click'), 'the same string is the same atom');
        Assert::same(2, $encoder->atom('value'));

        $first = $encoder->style($compile(['gap' => 2]));
        Assert::same($first, $encoder->style($compile(['gap' => 2])), 'equal records share an id');
        Assert::same(0, $encoder->style($compile([])), 'the default record is id 0');
    },

    'the definitions come before what uses them' => function (): void {
        $ops = (new Encoder())->render(Dsl::text('Hi', ['size' => 'lg']));
        Assert::same(Op::MOUNT, $ops[\count($ops) - 1]->opcode);
        foreach (\array_slice($ops, 0, -1) as $op) {
            Assert::true($op->opcode < 0x20, 'every definition precedes the Mount');
        }
    },

    'props and handlers reach the wire' => function (): void {
        $encoder = new Encoder();
        $ops = $encoder->render(Dsl::box([], ['props' => ['id' => 7], 'on' => ['click' => 'pick']]));
        $tree = $ops[\count($ops) - 1]->get('subtree');
        $node = $tree->nodes[0];
        Assert::same($encoder->atom('id'), $tree->propsOf($node)[0][0]);
        Assert::true($tree->propsOf($node)[0][1]->equals(Value::int(7)));
        Assert::same(EventKind::code('click'), $tree->handlersOf($node)[0][0]);
        Assert::true($tree->handlersOf($node)[0][1]->equals(Handler::server($encoder->atom('pick'))));
    },

    'an event arrives by the name the view gave it' => function (): void {
        $encoder = new Encoder();
        $encoder->render(Dsl::box([], ['props' => ['id' => 7], 'on' => ['wake' => 'tick']]));
        $node = $encoder->previous->id;
        [$name, $props] = $encoder->eventTarget($node, EventKind::code('wake'));
        Assert::same('tick', $name, 'the handler is named by the view, not by the event kind');
        Assert::same(['id' => 7], $props);
        Assert::null($encoder->eventTarget($node, EventKind::code('click')));
    },

    'a leaf cannot have children' => function (): void {
        Assert::throws(ViewException::class, static fn () => (new Encoder())->render(
            ['k' => 'text', 't' => 'x', 'c' => [['k' => 'box']]]
        ));
    },

    'an inert node carries nothing' => function (): void {
        Assert::throws(ViewException::class, static fn () => (new Encoder())->render(
            ['k' => 'divider', 'on' => ['click' => 'x']]
        ));
    },

    'a tree deeper than the client accepts is refused' => function (): void {
        $deep = ['k' => 'box'];
        for ($i = 0; $i < 300; $i++) {
            $deep = ['k' => 'box', 'c' => [$deep]];
        }
        Assert::throws(ViewException::class, static fn () => (new Encoder())->render($deep));
    },

    'a font nobody bound is an error' => function (): void {
        $e = Assert::throws(ViewException::class, static fn () => (new Encoder())->render(
            Dsl::text('x', ['font' => 'Space Grotesk'])
        ));
        Assert::contains('no font bound', $e->getMessage());
    },

    'a bound font becomes a role' => function (): void {
        $encoder = new Encoder();
        $encoder->font('Space Grotesk', [Blake3::hash('face')]);
        $ops = $encoder->render(Dsl::text('x', ['font' => 'Space Grotesk']));
        Assert::same(Op::DEF_FONT, $ops[0]->opcode);
        Assert::same(2, $ops[0]->get('role'), "roles 0 and 1 are the client's own sans and mono");
    },
];
