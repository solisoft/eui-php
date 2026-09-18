<?php

declare(strict_types=1);

namespace EUI\Proto;

use EUI\DecodeException;
use EUI\EUIException;

/**
 * One operation in a batch (02 §5).
 *
 * Structural only. Whether an op is *coherent* — that the node it names
 * exists, that the atom it references was defined — is session state and
 * belongs to the session, not here.
 */
final class Op
{
    public const DEF_ATOM = 0x10;
    public const DEF_STYLE = 0x11;
    public const DEF_COLOR = 0x12;
    public const DEF_CHUNK = 0x13;
    public const DEF_CHUNK_BYTES = 0x14;
    public const DEF_FONT = 0x15;
    public const MOUNT = 0x20;
    public const REPLACE = 0x21;
    public const SET_STYLE = 0x22;
    public const SET_TEXT = 0x23;
    public const SET_PROP = 0x24;
    public const INSERT_CHILD = 0x25;
    public const REMOVE_CHILD = 0x26;
    public const MOVE_CHILD = 0x27;
    public const SET_HANDLER = 0x28;
    public const CLEAR_HANDLER = 0x29;
    public const FOCUS = 0x2A;
    public const SCROLL_TO = 0x2B;
    public const NOTIFY = 0x2C;

    /** @param array<string,mixed> $fields */
    public function __construct(public readonly int $opcode, public readonly array $fields = [])
    {
    }

    public function get(string $name): mixed
    {
        return $this->fields[$name] ?? null;
    }

    public static function defAtom(int $id, string $value): self
    {
        return new self(self::DEF_ATOM, ['id' => $id, 'value' => $value]);
    }

    public static function defStyle(int $id, StyleRecord $record): self
    {
        return new self(self::DEF_STYLE, ['id' => $id, 'record' => $record]);
    }

    public static function defColor(int $id, int $rgba): self
    {
        return new self(self::DEF_COLOR, ['id' => $id, 'rgba' => $rgba]);
    }

    public static function defChunk(int $id, string $hash): self
    {
        return new self(self::DEF_CHUNK, ['id' => $id, 'hash' => $hash]);
    }

    public static function defChunkBytes(int $id, string $bytes): self
    {
        return new self(self::DEF_CHUNK_BYTES, ['id' => $id, 'bytes' => $bytes]);
    }

    /** @param list<string> $faces */
    public static function defFont(int $role, array $faces): self
    {
        return new self(self::DEF_FONT, ['role' => $role, 'faces' => $faces]);
    }

    public static function mount(Subtree $subtree): self
    {
        return new self(self::MOUNT, ['subtree' => $subtree]);
    }

    public static function replace(int $node, Subtree $subtree): self
    {
        return new self(self::REPLACE, ['node' => $node, 'subtree' => $subtree]);
    }

    public static function setStyle(int $node, int $style): self
    {
        return new self(self::SET_STYLE, ['node' => $node, 'style' => $style]);
    }

    public static function setText(int $node, TextRef $text): self
    {
        return new self(self::SET_TEXT, ['node' => $node, 'text' => $text]);
    }

    public static function setProp(int $node, int $prop, Value $value): self
    {
        return new self(self::SET_PROP, ['node' => $node, 'prop' => $prop, 'value' => $value]);
    }

    public static function insertChild(int $parent, int $index, Subtree $subtree): self
    {
        return new self(self::INSERT_CHILD, ['parent' => $parent, 'index' => $index, 'subtree' => $subtree]);
    }

    public static function removeChild(int $parent, int $index, int $count): self
    {
        return new self(self::REMOVE_CHILD, ['parent' => $parent, 'index' => $index, 'count' => $count]);
    }

    public static function moveChild(int $parent, int $from, int $to): self
    {
        return new self(self::MOVE_CHILD, ['parent' => $parent, 'from' => $from, 'to' => $to]);
    }

    public static function setHandler(int $node, int $event, Handler $handler): self
    {
        return new self(self::SET_HANDLER, ['node' => $node, 'event' => $event, 'handler' => $handler]);
    }

    public static function clearHandler(int $node, int $event): self
    {
        return new self(self::CLEAR_HANDLER, ['node' => $node, 'event' => $event]);
    }

    public static function focus(int $node): self
    {
        return new self(self::FOCUS, ['node' => $node]);
    }

    public static function scrollTo(int $node, int $x, int $y): self
    {
        return new self(self::SCROLL_TO, ['node' => $node, 'x' => $x, 'y' => $y]);
    }

    public static function notify(string $title, string $body = '', string $tag = ''): self
    {
        return new self(self::NOTIFY, ['title' => $title, 'body' => $body, 'tag' => $tag]);
    }

    private static function nonzero(int $value, string $what): int
    {
        if ($value === 0) {
            throw new DecodeException("{$what} must be non-zero");
        }
        return $value;
    }

    public static function decode(Reader $r): self
    {
        $opcode = $r->u8();
        switch ($opcode) {
            case self::DEF_ATOM:
                return self::defAtom(self::nonzero($r->varint32(), 'atom id'), $r->str(Limits::MAX_ATOM_BYTES, 'atom value'));
            case self::DEF_STYLE:
                return self::defStyle(self::nonzero($r->varint32(), 'style id'), StyleRecord::decode($r));
            case self::DEF_COLOR:
                return self::defColor(self::nonzero($r->varint32(), 'color id'), $r->u32());
            case self::DEF_CHUNK:
                return self::defChunk(self::nonzero($r->varint32(), 'chunk id'), $r->take(Limits::HASH_BYTES));
            case self::DEF_CHUNK_BYTES:
                return self::defChunkBytes(self::nonzero($r->varint32(), 'chunk id'), $r->bytesField(Limits::MAX_CHUNK_BYTES, 'chunk bytes'));
            case self::DEF_FONT:
                $role = $r->u8();
                if ($role > Limits::MAX_FONT_ROLE) {
                    throw new DecodeException('font role');
                }
                $count = $r->varint32();
                if ($count === 0) {
                    throw new DecodeException('a font role with no face');
                }
                if ($count > Limits::MAX_FACES_PER_ROLE) {
                    throw new DecodeException('font faces');
                }
                $faces = [];
                for ($i = 0; $i < $count; $i++) {
                    $faces[] = $r->take(Limits::HASH_BYTES);
                }
                return self::defFont($role, $faces);
            case self::MOUNT:
                return self::mount(Subtree::decode($r));
            case self::REPLACE:
                return self::replace(self::nonzero($r->varint32(), 'node id'), Subtree::decode($r));
            case self::SET_STYLE:
                return self::setStyle(self::nonzero($r->varint32(), 'node id'), $r->varint32());
            case self::SET_TEXT:
                return self::setText(self::nonzero($r->varint32(), 'node id'), TextRef::decode($r));
            case self::SET_PROP:
                return self::setProp(self::nonzero($r->varint32(), 'node id'), $r->varint32(), Value::decode($r));
            case self::INSERT_CHILD:
                return self::insertChild(self::nonzero($r->varint32(), 'node id'), $r->varint32(), Subtree::decode($r));
            case self::REMOVE_CHILD:
                return self::removeChild(self::nonzero($r->varint32(), 'node id'), $r->varint32(), $r->varint32());
            case self::MOVE_CHILD:
                return self::moveChild(self::nonzero($r->varint32(), 'node id'), $r->varint32(), $r->varint32());
            case self::SET_HANDLER:
                $node = self::nonzero($r->varint32(), 'node id');
                $event = $r->u8();
                EventKind::name($event);
                return self::setHandler($node, $event, Handler::decode($r));
            case self::CLEAR_HANDLER:
                $node = self::nonzero($r->varint32(), 'node id');
                $event = $r->u8();
                EventKind::name($event);
                return self::clearHandler($node, $event);
            case self::FOCUS:
                return self::focus(self::nonzero($r->varint32(), 'node id'));
            case self::SCROLL_TO:
                return self::scrollTo(self::nonzero($r->varint32(), 'node id'), $r->svarint(), $r->svarint());
            case self::NOTIFY:
                return self::notify(
                    $r->str(Limits::MAX_NOTIFY_TITLE, 'notification title'),
                    $r->str(Limits::MAX_NOTIFY_BODY, 'notification body'),
                    $r->str(Limits::MAX_NOTIFY_TAG, 'notification tag'),
                );
            default:
                throw new DecodeException("unknown opcode {$opcode}");
        }
    }

    public function encode(Writer $w): void
    {
        $f = $this->fields;
        $w->u8($this->opcode);
        switch ($this->opcode) {
            case self::DEF_ATOM:
                $w->varint($f['id'])->str($f['value']);
                break;
            case self::DEF_STYLE:
                $w->varint($f['id']);
                $f['record']->encode($w);
                break;
            case self::DEF_COLOR:
                $w->varint($f['id'])->u32($f['rgba']);
                break;
            case self::DEF_CHUNK:
                $w->varint($f['id'])->raw($f['hash']);
                break;
            case self::DEF_CHUNK_BYTES:
                $w->varint($f['id'])->bytes($f['bytes']);
                break;
            case self::DEF_FONT:
                $w->u8($f['role'])->varint(\count($f['faces']));
                foreach ($f['faces'] as $face) {
                    $w->raw($face);
                }
                break;
            case self::MOUNT:
                $f['subtree']->encode($w);
                break;
            case self::REPLACE:
                $w->varint($f['node']);
                $f['subtree']->encode($w);
                break;
            case self::SET_STYLE:
                $w->varint($f['node'])->varint($f['style']);
                break;
            case self::SET_TEXT:
                $w->varint($f['node']);
                $f['text']->encode($w);
                break;
            case self::SET_PROP:
                $w->varint($f['node'])->varint($f['prop']);
                $f['value']->encode($w);
                break;
            case self::INSERT_CHILD:
                $w->varint($f['parent'])->varint($f['index']);
                $f['subtree']->encode($w);
                break;
            case self::REMOVE_CHILD:
                $w->varint($f['parent'])->varint($f['index'])->varint($f['count']);
                break;
            case self::MOVE_CHILD:
                $w->varint($f['parent'])->varint($f['from'])->varint($f['to']);
                break;
            case self::SET_HANDLER:
                $w->varint($f['node'])->u8($f['event']);
                $f['handler']->encode($w);
                break;
            case self::CLEAR_HANDLER:
                $w->varint($f['node'])->u8($f['event']);
                break;
            case self::FOCUS:
                $w->varint($f['node']);
                break;
            case self::SCROLL_TO:
                $w->varint($f['node'])->svarint($f['x'])->svarint($f['y']);
                break;
            case self::NOTIFY:
                $w->str($f['title'])->str($f['body'])->str($f['tag']);
                break;
            default:
                throw new EUIException("cannot encode opcode {$this->opcode}");
        }
    }
}
