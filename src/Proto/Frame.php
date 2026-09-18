<?php

declare(strict_types=1);

namespace EUI\Proto;

use EUI\DecodeException;
use EUI\EUIException;

/**
 * A whole session message (01 §3). One frame per WebSocket binary message,
 * and a text frame ends the session.
 */
final class Frame
{
    public const HELLO = 0x01;
    public const WELCOME = 0x02;
    public const BATCH = 0x03;
    public const EVENT = 0x04;
    public const ACK = 0x05;
    public const PING = 0x06;
    public const PONG = 0x07;
    public const ERROR = 0x08;
    public const RESYNC = 0x09;
    public const VIEWPORT = 0x0A;
    public const UPLOAD = 0x0B;
    public const BLOB = 0x0C;

    public function __construct(public readonly int $kind, public readonly mixed $body = null)
    {
    }

    public static function hello(int $version, Viewport $viewport, int $granted = 0, ?array $resume = null): self
    {
        return new self(self::HELLO, ['version' => $version, 'viewport' => $viewport,
                                      'granted' => $granted, 'resume' => $resume]);
    }

    public static function welcome(int $version, string $session, bool $resumed): self
    {
        return new self(self::WELCOME, ['version' => $version, 'session' => $session, 'resumed' => $resumed]);
    }

    public static function batch(Batch $batch): self
    {
        return new self(self::BATCH, $batch);
    }

    public static function event(int $node, int $event, int $name, Value $payload): self
    {
        return new self(self::EVENT, ['node' => $node, 'event' => $event, 'name' => $name, 'payload' => $payload]);
    }

    public static function ack(int $seq): self
    {
        return new self(self::ACK, $seq);
    }

    public static function ping(string $nonce): self
    {
        return new self(self::PING, $nonce);
    }

    public static function pong(string $nonce): self
    {
        return new self(self::PONG, $nonce);
    }

    public static function error(int $code, string $message): self
    {
        return new self(self::ERROR, ['code' => $code, 'message' => $message]);
    }

    public static function resync(): self
    {
        return new self(self::RESYNC);
    }

    public static function viewport(Viewport $viewport): self
    {
        return new self(self::VIEWPORT, $viewport);
    }

    public static function transfer(int $kind, int $id, int $seq, int $flag, string $bytes): self
    {
        return new self($kind, ['id' => $id, 'seq' => $seq, 'flag' => $flag, 'bytes' => $bytes]);
    }

    /**
     * Trailing bytes are an error: a length that does not account for every
     * byte of the message is how one implementation's frame becomes
     * another's smuggling channel.
     */
    public static function decode(string $message): self
    {
        $r = new Reader($message);
        $kind = $r->u8();
        $length = $r->varint();
        if ($length > Limits::MAX_FRAME_BYTES) {
            throw new DecodeException('frame length');
        }
        $payload = $r->take($length);
        $r->finish();
        $p = new Reader($payload);

        switch ($kind) {
            case self::HELLO:
                $version = $p->varint32();
                $viewport = Viewport::decode($p);
                $granted = $p->varint32();
                if (($granted & ~Caps::ALL) !== 0) {
                    throw new DecodeException('unknown capability bit');
                }
                $tag = $p->u8();
                $resume = match ($tag) {
                    0 => null,
                    1 => ['session' => $p->take(16), 'acked' => $p->varint()],
                    default => throw new DecodeException("unknown resume tag {$tag}"),
                };
                $frame = self::hello($version, $viewport, $granted, $resume);
                break;
            case self::WELCOME:
                $version = $p->varint32();
                $session = $p->take(16);
                $resumed = $p->u8();
                if ($resumed > 1) {
                    throw new DecodeException('resumed must be 0 or 1');
                }
                $frame = self::welcome($version, $session, $resumed === 1);
                break;
            case self::BATCH:
                $frame = self::batch(Batch::decode($p));
                break;
            case self::EVENT:
                $node = $p->varint32();
                $event = $p->u8();
                EventKind::name($event);
                $frame = self::event($node, $event, $p->varint32(), Value::decode($p));
                break;
            case self::ACK:
                $frame = self::ack($p->varint());
                break;
            case self::PING:
                $frame = self::ping($p->take(8));
                break;
            case self::PONG:
                $frame = self::pong($p->take(8));
                break;
            case self::ERROR:
                $frame = self::error($p->varint32(), $p->str(Limits::MAX_INLINE_STR, 'error message'));
                break;
            case self::RESYNC:
                $frame = self::resync();
                break;
            case self::VIEWPORT:
                $frame = self::viewport(Viewport::decode($p));
                break;
            case self::UPLOAD:
            case self::BLOB:
                $id = $p->varint32();
                $seq = $p->varint32();
                $flag = $p->u8();
                if ($flag > 2) {
                    throw new DecodeException("unknown chunk flag {$flag}");
                }
                $cap = $flag === 2 ? Limits::MAX_ABORT_REASON : Limits::MAX_TRANSFER_CHUNK_BYTES;
                $frame = self::transfer($kind, $id, $seq, $flag, $p->bytesField($cap, 'transfer chunk'));
                break;
            default:
                throw new DecodeException("unknown frame kind {$kind}");
        }
        $p->finish();
        return $frame;
    }

    public function encode(): string
    {
        $body = new Writer();
        $b = $this->body;
        switch ($this->kind) {
            case self::HELLO:
                $body->varint($b['version']);
                $b['viewport']->encode($body);
                $body->varint($b['granted']);
                if ($b['resume'] === null) {
                    $body->u8(0);
                } else {
                    $body->u8(1)->raw($b['resume']['session'])->varint($b['resume']['acked']);
                }
                break;
            case self::WELCOME:
                $body->varint($b['version'])->raw($b['session'])->u8($b['resumed'] ? 1 : 0);
                break;
            case self::BATCH:
                $b->encode($body);
                break;
            case self::EVENT:
                $body->varint($b['node'])->u8($b['event'])->varint($b['name']);
                $b['payload']->encode($body);
                break;
            case self::ACK:
                $body->varint($b);
                break;
            case self::PING:
            case self::PONG:
                $body->raw($b);
                break;
            case self::ERROR:
                $body->varint($b['code'])->str($b['message']);
                break;
            case self::RESYNC:
                break;
            case self::VIEWPORT:
                $b->encode($body);
                break;
            case self::UPLOAD:
            case self::BLOB:
                $body->varint($b['id'])->varint($b['seq'])->u8($b['flag'])->bytes($b['bytes']);
                break;
            default:
                throw new EUIException("cannot encode frame kind {$this->kind}");
        }

        $payload = $body->toBytes();
        return (new Writer())->u8($this->kind)->varint(\strlen($payload))->raw($payload)->toBytes();
    }
}
