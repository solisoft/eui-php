<?php

declare(strict_types=1);

namespace EUI;

use EUI\Proto\Limits;

/**
 * The server half of RFC 6455, with only what an EUI session needs.
 *
 * A session carries **binary** frames and nothing else: a text frame is not
 * a protocol extension point, it is a sign that something other than an EUI
 * client is talking, and the session ends (01 §2.3).
 */
final class WebSocket
{
    public const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    public const MAX_MESSAGE = Limits::MAX_FRAME_BYTES + 16;

    private const CONTINUATION = 0x0;
    private const TEXT = 0x1;
    private const BINARY = 0x2;
    private const CLOSE = 0x8;
    private const PING = 0x9;
    private const PONG = 0xA;

    public bool $closed = false;

    /** @param resource $socket */
    public function __construct(private $socket, public readonly string $path, public readonly array $headers)
    {
    }

    /**
     * The key is not a secret and proves nothing: it is there so that a
     * cache between the two ends cannot mistake this for a reply it may
     * serve to somebody else.
     */
    public static function acceptKey(string $key): string
    {
        return base64_encode(sha1($key . self::GUID, true));
    }

    /** @param resource $socket */
    public static function accept($socket, string $path, array $headers): self
    {
        $key = $headers['sec-websocket-key'] ?? null;
        if ($key === null) {
            throw new EUIException('no Sec-WebSocket-Key');
        }
        fwrite($socket,
            "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Accept: ' . self::acceptKey($key) . "\r\n\r\n");
        return new self($socket, $path, $headers);
    }

    /**
     * The next application message as `['binary'|'text', string]`, or null
     * once the peer has gone. Control frames are answered here and never
     * surface. `$timeout` is in seconds; null blocks.
     *
     * @return array{string,string}|null
     */
    public function recv(?float $timeout = null): ?array
    {
        $message = '';
        $kind = null;
        while (true) {
            if ($timeout !== null && !$this->waitReadable($timeout)) {
                return null;
            }
            $frame = $this->readFrame();
            if ($frame === null) {
                return null;
            }
            [$opcode, $payload, $fin] = $frame;
            if ($opcode === self::CLOSE) {
                $this->sendClose(1000);
                return null;
            }
            if ($opcode === self::PING) {
                $this->sendFrame(self::PONG, $payload);
                continue;
            }
            if ($opcode === self::PONG) {
                continue;
            }
            if ($opcode === self::TEXT || $opcode === self::BINARY) {
                if ($message !== '') {
                    throw new EUIException('a frame arrived inside a fragmented message');
                }
                $kind = $opcode;
                $message .= $payload;
            } elseif ($opcode === self::CONTINUATION) {
                if ($kind === null) {
                    throw new EUIException('a continuation with nothing to continue');
                }
                $message .= $payload;
            } else {
                throw new EUIException("unknown opcode {$opcode}");
            }
            if (\strlen($message) > self::MAX_MESSAGE) {
                throw new EUIException('message too large');
            }
            if ($fin) {
                return [$kind === self::TEXT ? 'text' : 'binary', $message];
            }
        }
    }

    public function sendBinary(string $data): void
    {
        $this->sendFrame(self::BINARY, $data);
    }

    public function sendClose(int $code = 1000, string $reason = ''): void
    {
        if ($this->closed) {
            return;
        }
        $this->sendFrame(self::CLOSE, pack('n', $code) . substr($reason, 0, 123));
        $this->closed = true;
    }

    public function close(): void
    {
        $this->sendClose();
        if (\is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    // -- the wire ---------------------------------------------------------
    private function waitReadable(float $timeout): bool
    {
        $read = [$this->socket];
        $write = null;
        $except = null;
        $seconds = (int) $timeout;
        $micro = (int) (($timeout - $seconds) * 1_000_000);
        return (bool) @stream_select($read, $write, $except, $seconds, $micro);
    }

    /** @return array{int,string,bool}|null */
    private function readFrame(): ?array
    {
        $head = $this->readExactly(2);
        if ($head === null) {
            return null;
        }
        $b0 = \ord($head[0]);
        $b1 = \ord($head[1]);
        $fin = ($b0 & 0x80) !== 0;
        if (($b0 & 0x70) !== 0) {
            throw new EUIException('reserved bits set');
        }
        $opcode = $b0 & 0x0F;
        // Every frame from a client is masked; one that is not is either a
        // proxy rewriting traffic or something that is not a browser stack.
        if (($b1 & 0x80) === 0) {
            throw new EUIException('a client frame must be masked');
        }
        $length = $b1 & 0x7F;
        if ($length === 126) {
            $raw = $this->readExactly(2);
            if ($raw === null) {
                return null;
            }
            $length = unpack('n', $raw)[1];
        } elseif ($length === 127) {
            $raw = $this->readExactly(8);
            if ($raw === null) {
                return null;
            }
            $length = unpack('J', $raw)[1];
        }
        if ($length > self::MAX_MESSAGE) {
            throw new EUIException('frame too large');
        }
        $mask = $this->readExactly(4);
        if ($mask === null) {
            return null;
        }
        $payload = $length === 0 ? '' : $this->readExactly($length);
        if ($payload === null) {
            return null;
        }
        return [$opcode, self::unmask($payload, $mask), $fin];
    }

    private function readExactly(int $count): ?string
    {
        $data = '';
        while (\strlen($data) < $count) {
            $chunk = @fread($this->socket, $count - \strlen($data));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function sendFrame(int $opcode, string $payload): void
    {
        $header = \chr(0x80 | $opcode);
        $length = \strlen($payload);
        if ($length < 126) {
            $header .= \chr($length);
        } elseif ($length < 65536) {
            $header .= \chr(126) . pack('n', $length);
        } else {
            $header .= \chr(127) . pack('J', $length);
        }
        $written = @fwrite($this->socket, $header . $payload);
        if ($written === false) {
            $this->closed = true;
            throw new EUIException('the socket is closed');
        }
    }

    private static function unmask(string $payload, string $mask): string
    {
        if ($payload === '') {
            return $payload;
        }
        $repeated = str_repeat($mask, intdiv(\strlen($payload), 4) + 1);
        return $payload ^ substr($repeated, 0, \strlen($payload));
    }
}
