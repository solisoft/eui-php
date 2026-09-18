<?php

declare(strict_types=1);

/**
 * The client half of a session, in as little code as it takes: enough to
 * drive a server in a test, and a readable answer to "what does a client
 * actually do?".
 */

use EUI\Proto\Frame;
use EUI\Proto\Protocol;
use EUI\Proto\Value;
use EUI\Proto\Viewport;
use EUI\WebSocket;

final class TestClient
{
    /** @var resource */
    private $socket;

    public function __construct(int $port, string $path)
    {
        $key = base64_encode(random_bytes(16));
        $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 5);
        if ($socket === false) {
            throw new RuntimeException("cannot connect to {$port}: {$error}");
        }
        $this->socket = $socket;
        fwrite($socket,
            "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\n"
            . "Connection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n");

        $head = '';
        while (!str_contains($head, "\r\n\r\n")) {
            $chunk = fread($socket, 1024);
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('the server closed during the handshake');
            }
            $head .= $chunk;
        }
        if (!str_contains(explode("\r\n", $head)[0], '101')) {
            throw new RuntimeException('handshake: ' . explode("\r\n", $head)[0]);
        }
        foreach (explode("\r\n", $head) as $line) {
            if (stripos($line, 'sec-websocket-accept') === 0) {
                $accept = trim(explode(':', $line, 2)[1]);
                if ($accept !== WebSocket::acceptKey($key)) {
                    throw new RuntimeException('the accept key does not match');
                }
            }
        }
    }

    public function hello(int $width = 1000, int $height = 700, int $granted = 0): self
    {
        return $this->send(Frame::hello(
            Protocol::VERSION, new Viewport($width, $height, 100, 0, 1, 100), $granted
        ));
    }

    public function send(Frame $frame): self
    {
        $payload = $frame->encode();
        $header = \chr(0x82);
        $length = \strlen($payload);
        if ($length < 126) {
            $header .= \chr(0x80 | $length);
        } elseif ($length < 65536) {
            $header .= \chr(0x80 | 126) . pack('n', $length);
        } else {
            $header .= \chr(0x80 | 127) . pack('J', $length);
        }
        $mask = random_bytes(4);
        $masked = $payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length);
        fwrite($this->socket, $header . $mask . $masked);
        return $this;
    }

    /**
     * The next frame, or null once the server has gone quiet or gone away. A
     * Ping is answered here rather than surfaced: a client that does not
     * answer one is a client the server hangs up on after two.
     */
    public function recv(float $timeout = 3.0): ?Frame
    {
        while (true) {
            $head = $this->read(2, $timeout);
            if ($head === null) {
                return null;
            }
            $length = \ord($head[1]) & 0x7F;
            if ($length === 126) {
                $raw = $this->read(2, $timeout);
                if ($raw === null) {
                    return null;
                }
                $length = unpack('n', $raw)[1];
            } elseif ($length === 127) {
                $raw = $this->read(8, $timeout);
                if ($raw === null) {
                    return null;
                }
                $length = unpack('J', $raw)[1];
            }
            $payload = $length === 0 ? '' : $this->read($length, $timeout);
            if ($payload === null) {
                return null;
            }
            $frame = Frame::decode($payload);
            if ($frame->kind === Frame::PING) {
                $this->send(Frame::pong($frame->body));
                continue;
            }
            return $frame;
        }
    }

    public function click(int $node): self
    {
        return $this->send(Frame::event($node, \EUI\Proto\EventKind::code('click'), 0, Value::null()));
    }

    public function close(): void
    {
        if (\is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    private function read(int $count, float $timeout): ?string
    {
        $data = '';
        while (\strlen($data) < $count) {
            $read = [$this->socket];
            $write = null;
            $except = null;
            $seconds = (int) $timeout;
            if (!@stream_select($read, $write, $except, $seconds, (int) (($timeout - $seconds) * 1e6))) {
                return null;
            }
            $chunk = fread($this->socket, $count - \strlen($data));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $data .= $chunk;
        }
        return $data;
    }
}

/** A free port, and an application serving on it in a process of its own. */
function eui_serve(callable $buildApp): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $port = (int) explode(':', (string) stream_socket_get_name($probe, false))[1];
    fclose($probe);

    $pid = pcntl_fork();
    if ($pid === 0) {
        $app = $buildApp();
        $app->run(port: $port);
        exit(0);
    }

    $deadline = microtime(true) + 3;
    while (microtime(true) < $deadline) {
        $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.2);
        if ($socket !== false) {
            fclose($socket);
            return [$port, $pid];
        }
        usleep(20000);
    }
    throw new RuntimeException('the server never came up');
}

function eui_stop(int $pid): void
{
    posix_kill($pid, SIGTERM);
    pcntl_waitpid($pid, $status, WNOHANG);
}
