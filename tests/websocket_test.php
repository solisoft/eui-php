<?php

declare(strict_types=1);

/** RFC 6455, with only what a session needs. */

use EUI\EUIException;
use EUI\WebSocket;

$pair = static function (): array {
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    return [new WebSocket($sockets[0], '/x', []), $sockets[1]];
};

$masked = static function (string $payload, int $opcode = 0x2, bool $fin = true): string {
    $mask = "\x01\x02\x03\x04";
    $body = $payload ^ substr(str_repeat($mask, intdiv(\strlen($payload), 4) + 1), 0, \strlen($payload));
    return \chr(($fin ? 0x80 : 0x00) | $opcode) . \chr(0x80 | \strlen($payload)) . $mask . $body;
};

return [
    // The one number in this file worth pinning: an accept key that is wrong
    // by a character is a handshake every client refuses, and the error it
    // prints points at the server's key rather than at the GUID.
    'the accept key is the RFC example' => function (): void {
        Assert::same('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', WebSocket::acceptKey('dGhlIHNhbXBsZSBub25jZQ=='));
        Assert::same(base64_encode(sha1('k' . WebSocket::GUID, true)), WebSocket::acceptKey('k'));
    },

    'a masked binary message arrives whole' => function () use ($pair, $masked): void {
        [$ws, $client] = $pair();
        fwrite($client, $masked('hello'));
        Assert::same(['binary', 'hello'], $ws->recv(1.0));
    },

    'a fragmented message is reassembled' => function () use ($pair, $masked): void {
        [$ws, $client] = $pair();
        fwrite($client, $masked('he', 0x2, false));
        fwrite($client, $masked('llo', 0x0));
        Assert::same(['binary', 'hello'], $ws->recv(1.0));
    },

    'a ping is answered without surfacing' => function () use ($pair, $masked): void {
        [$ws, $client] = $pair();
        fwrite($client, $masked('ab', 0x9));
        fwrite($client, $masked('done'));
        Assert::same(['binary', 'done'], $ws->recv(1.0));
        Assert::same(0x8A, \ord(fread($client, 16)[0]), 'pong');
    },

    'an unmasked client frame is refused' => function () use ($pair): void {
        [$ws, $client] = $pair();
        fwrite($client, \chr(0x82) . \chr(0x01) . 'x');
        Assert::throws(EUIException::class, static fn () => $ws->recv(1.0));
    },

    'a close ends the stream' => function () use ($pair, $masked): void {
        [$ws, $client] = $pair();
        fwrite($client, $masked('', 0x8));
        Assert::null($ws->recv(1.0));
    },

    'what the server writes is not masked' => function () use ($pair): void {
        [$ws, $client] = $pair();
        $ws->sendBinary('hi');
        $frame = fread($client, 16);
        Assert::same(0x82, \ord($frame[0]));
        Assert::same(2, \ord($frame[1]), 'no mask bit, length 2');
        Assert::same('hi', substr($frame, 2, 2));
    },

    'a long message uses the extended length' => function () use ($pair): void {
        [$ws, $client] = $pair();
        $ws->sendBinary(str_repeat('x', 200));
        $frame = fread($client, 4);
        Assert::same(126, \ord($frame[1]));
        Assert::same(200, unpack('n', substr($frame, 2, 2))[1]);
    },
];
