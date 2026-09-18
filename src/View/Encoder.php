<?php

declare(strict_types=1);

namespace EUI\View;

use EUI\Assets;
use EUI\Proto\EventKind;
use EUI\Proto\Handler;
use EUI\Proto\Limits;
use EUI\Proto\NodeKind;
use EUI\Proto\Op;
use EUI\Proto\Protocol;
use EUI\Proto\StyleRecord;
use EUI\Proto\Subtree;
use EUI\Proto\TextRef;
use EUI\Proto\Value;
use EUI\ViewException;

/**
 * From the view's array to nodes, with the session's tables.
 *
 * The view returns plain data:
 *
 *     ['k' => 'box', 's' => ['display' => 'column', 'gap' => 4],
 *      'key' => 'row-12', 't' => 'text content', 'on' => ['click' => 'increment'],
 *      'p' => ['item_height' => 20], 'c' => [ ...children... ]]
 *
 * Tables are append-only and session-scoped, exactly as the wire format
 * requires: an atom interned for the first page is still there on the tenth,
 * which is why a second page costs no second `DefAtom`.
 */
final class Encoder
{
    public int $protocol = Protocol::VERSION;
    public ?TNode $previous = null;

    /** @var array<string,int> */
    private array $atoms = [];
    /** @var list<string> */
    private array $atomsById = [''];
    /** @var array<string,int> */
    private array $styles = [];
    /** @var array<string,int> */
    private array $styleCache = [];
    /** @var array<int,int> */
    private array $colors = [];
    /** @var array<string,int> */
    private array $fonts = [];
    /** @var list<Op> */
    private array $pending = [];
    private int $nextId = 1;
    /** @var array<int,TNode> */
    private array $nodesById = [];
    private Compiler $compiler;

    public function __construct(private readonly ?Assets $assets = null)
    {
        $this->compiler = new Compiler(
            colors: fn (int $rgba) => $this->colorLiteral($rgba),
            fonts: fn (string $family) => $this->fontRole($family),
        );
    }

    // -- tables -----------------------------------------------------------
    public function atom(string $string): int
    {
        if (isset($this->atoms[$string])) {
            return $this->atoms[$string];
        }
        $id = \count($this->atoms) + 1;
        if ($id > Limits::MAX_ATOMS) {
            throw new ViewException('more than ' . Limits::MAX_ATOMS . ' atoms in one session');
        }
        $this->atoms[$string] = $id;
        $this->atomsById[] = $string;
        $this->pending[] = Op::defAtom($id, $string);
        return $id;
    }

    public function atomValue(int $id): ?string
    {
        return $this->atomsById[$id] ?? null;
    }

    /** Style id 0 is the default record, which every session already has. */
    public function style(StyleRecord $record): int
    {
        $raw = $record->toBytes();
        if ($raw === (new StyleRecord())->toBytes()) {
            return 0;
        }
        if (isset($this->styles[$raw])) {
            return $this->styles[$raw];
        }
        $id = \count($this->styles) + 1;
        if ($id > Limits::MAX_STYLES) {
            throw new ViewException('more than ' . Limits::MAX_STYLES . ' styles in one session');
        }
        $this->styles[$raw] = $id;
        $this->pending[] = Op::defStyle($id, $record);
        return $id;
    }

    public function colorLiteral(int $rgba): int
    {
        if (isset($this->colors[$rgba])) {
            return $this->colors[$rgba];
        }
        $id = \count($this->colors) + 1;
        if ($id > Limits::MAX_COLORS) {
            throw new ViewException('more than ' . Limits::MAX_COLORS . ' literal colours');
        }
        $this->colors[$rgba] = $id;
        $this->pending[] = Op::defColor($id, $rgba);
        return $id;
    }

    /**
     * Bind a font role to its faces. Roles 0 and 1 are the client's own sans
     * and mono; binding one replaces it for this session only.
     *
     * @param list<string> $hashes
     */
    public function font(string $family, array $hashes): int
    {
        if (isset($this->fonts[$family])) {
            return $this->fonts[$family];
        }
        $role = \count($this->fonts) + 2;
        if ($role > Limits::MAX_FONT_ROLE) {
            throw new ViewException('a session binds at most ' . (Limits::MAX_FONT_ROLE - 1) . ' font families');
        }
        $this->fonts[$family] = $role;
        $this->pending[] = Op::defFont($role, $hashes);
        return $role;
    }

    /**
     * The role a view means when it names a family. A family nobody bound is
     * an error here rather than a silent fall back to `sans`.
     */
    public function fontRole(string $family): int
    {
        if (!isset($this->fonts[$family])) {
            throw new ViewException("no font bound for '{$family}'; call \$app->font('{$family}', [paths]) at boot");
        }
        return $this->fonts[$family];
    }

    public function nextId(): int
    {
        return $this->nextId++;
    }

    // -- rendering --------------------------------------------------------
    /**
     * The ops this view costs: the definitions it needed, then the patch
     * that takes the client's tree to it. Definitions come first because the
     * wire format requires it — a decoder rejects a forward reference.
     *
     * @return list<Op>
     */
    public function render(array $view, bool $full = false): array
    {
        $tree = $this->build($view);
        if ($full || $this->previous === null) {
            $this->assignIds($tree);
            $ops = [Op::mount($this->subtreeOf($tree))];
        } else {
            $ops = (new Diff($this))->ops($this->previous, $tree);
        }
        $this->previous = $tree;
        $this->index($tree);
        return array_merge($this->flush(), $ops);
    }

    /** @return list<Op> */
    public function flush(): array
    {
        $pending = $this->pending;
        $this->pending = [];
        return $pending;
    }

    /**
     * The node the client named, and what the server last rendered on it.
     *
     * An event on a node that carries no handler for it *now* is dropped.
     * Usually that is a race rather than an attack — a handler a render
     * removed is still in the client's tree for the one round trip it takes
     * the new one to arrive.
     *
     * @return array{string,array<string,mixed>}|null
     */
    public function eventTarget(int $nodeId, int $event): ?array
    {
        $node = $this->nodesById[$nodeId] ?? null;
        if ($node === null) {
            return null;
        }
        foreach ($node->handlers as [$kind, $handler]) {
            if ($kind === $event) {
                if ($handler->name === null) {
                    return null;
                }
                return [(string) $this->atomValue($handler->name), $this->propsOf($node)];
            }
        }
        return null;
    }

    public function node(int $nodeId): ?TNode
    {
        return $this->nodesById[$nodeId] ?? null;
    }

    /** Everything a session forgets when its client asks for a resync. */
    public function forgetTree(): void
    {
        $this->previous = null;
        $this->nodesById = [];
    }

    /** @return array<string,mixed> */
    public function propsOf(TNode $node): array
    {
        $out = [];
        foreach ($node->props as [$atom, $value]) {
            $out[(string) $this->atomValue($atom)] = $value->toPhp();
        }
        return $out;
    }

    // -- build ------------------------------------------------------------
    public function build(mixed $view, int $depth = 1): TNode
    {
        if (!\is_array($view)) {
            throw new ViewException('a view is an array');
        }
        if ($depth > Limits::MAX_TREE_DEPTH) {
            throw new ViewException('the tree is nested more than ' . Limits::MAX_TREE_DEPTH . ' deep');
        }

        $kindName = (string) ($view['k'] ?? 'box');
        $kind = NodeKind::code($kindName);
        $styleId = $this->styleFor($view['s'] ?? null);

        $key = isset($view['key']) ? (string) $view['key'] : null;
        $text = $this->buildText($view, $kind);
        [$props, $scrollTo, $focusTo] = $this->buildProps($view['p'] ?? null, $kind);
        $handlers = $this->buildHandlers($view['on'] ?? null);

        if (NodeKind::isInert($kind) && ($text !== null || $props !== [] || $handlers !== [])) {
            throw new ViewException("a {$kindName} carries nothing: no text, no props, no handlers");
        }

        $children = [];
        foreach (($view['c'] ?? []) as $child) {
            if ($child !== null) {
                $children[] = $this->build($child, $depth + 1);
            }
        }
        if (NodeKind::isLeaf($kind) && $children !== []) {
            throw new ViewException("a {$kindName} is a leaf and cannot have children");
        }
        if (\count($children) > Limits::MAX_CHILDREN) {
            throw new ViewException('more than ' . Limits::MAX_CHILDREN . ' children on one node');
        }

        return new TNode(
            0, $kind, $styleId, $key, $key !== null ? $this->atom($key) : 0,
            $text, $props, $handlers, $children, $scrollTo, $focusTo
        );
    }

    /** A subtree as the wire carries it, pre-order. */
    public function subtreeOf(TNode $node): Subtree
    {
        $out = new Subtree();
        $stack = [$node];
        while ($stack !== []) {
            $current = array_pop($stack);
            $out->push($current->kind, $current->id, $current->style, $current->keyAtom,
                       $current->text, $current->props, $current->handlers, \count($current->children));
            for ($i = \count($current->children) - 1; $i >= 0; $i--) {
                $stack[] = $current->children[$i];
            }
        }
        return $out;
    }

    public function assignIds(TNode $node): TNode
    {
        // Pre-order, like the subtree the wire carries.
        $stack = [$node];
        while ($stack !== []) {
            $current = array_pop($stack);
            if ($current->id === 0) {
                $current->id = $this->nextId();
            }
            for ($i = \count($current->children) - 1; $i >= 0; $i--) {
                $stack[] = $current->children[$i];
            }
        }
        return $node;
    }

    // -- the pieces of a node ---------------------------------------------
    /**
     * Two style arrays with the same contents are the same style, and a
     * table of ten thousand rows has three of them. One lookup instead of
     * compiling and encoding a 64-byte record per node.
     */
    private function styleFor(?array $style): int
    {
        if ($style === null || $style === []) {
            return 0;
        }
        $key = serialize($style);
        if (isset($this->styleCache[$key])) {
            return $this->styleCache[$key];
        }
        return $this->styleCache[$key] = $this->style($this->compiler->record($style));
    }

    private function buildText(array $view, int $kind): ?TextRef
    {
        if (!isset($view['t'])) {
            return null;
        }
        $string = (string) $view['t'];
        if (\strlen($string) > Limits::MAX_INLINE_STR) {
            throw new ViewException(
                'a text of ' . \strlen($string) . ' bytes; the client takes at most '
                . Limits::MAX_INLINE_STR . ' — split it into nodes'
            );
        }
        if (NodeKind::isInert($kind)) {
            throw new ViewException('a ' . NodeKind::name($kind) . ' carries no text');
        }
        // Interning is for what repeats. A unique cell value would be a
        // permanent entry in a table that is never cleared.
        if (($view['intern'] ?? false) && \strlen($string) <= 24) {
            return TextRef::ofAtom($this->atom($string));
        }
        return TextRef::ofInline($string);
    }

    /**
     * `scroll_to` and `focus_to` never reach the client as props: they are
     * instructions, done to a node once, and the diff turns a change of one
     * into its own op.
     *
     * @return array{list<array{int,Value}>,array{int,int}|null,bool}
     */
    private function buildProps(?array $props, int $kind): array
    {
        if ($props === null) {
            return [[], null, false];
        }
        $scrollTo = null;
        $focusTo = false;
        $out = [];
        foreach ($props as $name => $value) {
            $name = (string) $name;
            if ($name === 'scroll_to') {
                if (!\in_array(NodeKind::name($kind), ['scroll', 'list'], true)) {
                    throw new ViewException('scroll_to is for a scroll or a list, not a ' . NodeKind::name($kind));
                }
                if (!\is_array($value) || \count($value) !== 2) {
                    throw new ViewException('a scroll_to is [x, y] in pixels');
                }
                $scrollTo = [(int) round($value[0]), (int) round($value[1])];
                continue;
            }
            if ($name === 'focus_to') {
                $focusTo = $value === true;
                continue;
            }
            $out[] = [$this->atom($name), $this->propValue($name, $value, $kind)];
        }
        if (\count($out) > Limits::MAX_PROPS) {
            throw new ViewException('more than ' . Limits::MAX_PROPS . ' props on one node');
        }
        return [$out, $scrollTo, $focusTo];
    }

    /**
     * An image's `src` is a file in the application: it goes on the wire as
     * the hash of its bytes, served from `/_eui/asset`.
     */
    private function propValue(string $name, mixed $value, int $kind): Value
    {
        $kindName = NodeKind::name($kind);
        $isAsset = (\in_array($kindName, ['image', 'audio', 'video'], true) && $name === 'src')
            || ($kindName === 'scene' && \in_array($name, ['shader', 'mesh'], true));
        if ($isAsset) {
            return Value::asset($this->assetHash($name, $value));
        }
        return Value::of($value);
    }

    private function assetHash(string $name, mixed $value): string
    {
        if (\is_string($value)) {
            if ($this->assets === null) {
                throw new ViewException("no asset store to resolve {$name} '{$value}'");
            }
            return $this->assets->addFile($value);
        }
        if (\is_array($value) && isset($value['asset'])) {
            $hex = (string) $value['asset'];
            if (\strlen($hex) !== 64 || !ctype_xdigit($hex)) {
                throw new ViewException("an asset is 64 hex characters, got '{$hex}'");
            }
            return (string) hex2bin($hex);
        }
        throw new ViewException("a {$name} is a path or ['asset' => '<hash>']");
    }

    /** @return list<array{int,Handler}> */
    private function buildHandlers(?array $on): array
    {
        if ($on === null) {
            return [];
        }
        $out = [];
        foreach ($on as $event => $target) {
            $code = EventKind::code((string) $event);
            // An event the other end cannot decode is left out rather than
            // sent: the view still renders, the widget just never hears from
            // it. `level` arrived in version 3.
            if (EventKind::since($code) > $this->protocol) {
                continue;
            }
            if (!\is_string($target)) {
                throw new ViewException('a handler is a server event name; local handlers are not compiled yet');
            }
            $out[] = [$code, Handler::server($this->atom($target))];
        }
        if (\count($out) > Limits::MAX_HANDLERS) {
            throw new ViewException('more than ' . Limits::MAX_HANDLERS . ' handlers on one node');
        }
        return $out;
    }

    private function index(TNode $tree): void
    {
        $this->nodesById = [];
        $stack = [$tree];
        while ($stack !== []) {
            $node = array_pop($stack);
            $this->nodesById[$node->id] = $node;
            foreach ($node->children as $child) {
                $stack[] = $child;
            }
        }
    }
}
