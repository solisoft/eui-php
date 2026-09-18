<?php

declare(strict_types=1);

namespace EUI;

/**
 * The three endpoints an EUI application answers on (01 §2), and nothing
 * else:
 *
 *     GET /.well-known/eui          the signed manifest
 *     GET /_eui/asset/<blake3-hex>  a content-addressed asset
 *     WSS /_eui/session[/<name>]    the session
 *
 * One process per connection, forked: a session is a loop that blocks on its
 * own socket, and PHP has no threads to give it. TLS 1.3 is the protocol's
 * floor, and a release client refuses `ws://` outright; over loopback a
 * debug client can be told to come in the front door with
 * `EUI_ALLOW_INSECURE_LOOPBACK=1`.
 */
final class Server
{
    private const HEADER_LIMIT = 16 * 1024;
    private const REASONS = [200 => 'OK', 400 => 'Bad Request', 404 => 'Not Found', 405 => 'Method Not Allowed'];

    /** @var resource|null */
    private $listener = null;
    private readonly mixed $logger;

    public function __construct(
        private readonly App $app,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 5012,
        private readonly ?array $tls = null,
        ?callable $logger = null,
    ) {
        $this->logger = $logger ?? static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };
    }

    public function start(): void
    {
        $context = stream_context_create($this->contextOptions());
        $scheme = $this->tls !== null ? 'tls' : 'tcp';
        $listener = @stream_socket_server(
            "{$scheme}://{$this->host}:{$this->port}", $errno, $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context
        );
        if ($listener === false) {
            throw new EUIException("cannot listen on {$this->host}:{$this->port}: {$error}");
        }
        $this->listener = $listener;

        $web = $this->tls !== null ? 'https' : 'http';
        $ws = $this->tls !== null ? 'wss' : 'ws';
        $this->log("listening on {$web}://{$this->host}:{$this->port}");
        $this->log("  manifest  {$web}://{$this->host}:{$this->port}/.well-known/eui");
        foreach (array_keys($this->app->components) as $name) {
            $this->log("  session   {$ws}://{$this->host}:{$this->port}/_eui/session/{$name}");
        }

        // A child that ends is reaped by the kernel rather than waited for:
        // a server whose accept loop stops to bury its children is a server
        // that stops accepting.
        if (\function_exists('pcntl_signal')) {
            pcntl_signal(SIGCHLD, SIG_IGN);
        }

        while (true) {
            $client = @stream_socket_accept($listener, -1);
            if ($client === false) {
                if (!\is_resource($this->listener)) {
                    return;
                }
                continue;
            }
            $this->handOff($client);
        }
    }

    public function stop(): void
    {
        if (\is_resource($this->listener)) {
            fclose($this->listener);
        }
        $this->listener = null;
    }

    /** @param resource $client */
    private function handOff($client): void
    {
        if (\function_exists('pcntl_fork')) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                fclose($this->listener);
                $this->serve($client);
                exit(0);
            }
            if ($pid > 0) {
                fclose($client);
                return;
            }
            // Fork failed: better to serve it here than to drop it.
        }
        $this->serve($client);
    }

    /** @param resource $client */
    private function serve($client): void
    {
        try {
            $request = $this->readRequest($client);
            if ($request === null) {
                fclose($client);
                return;
            }
            [$method, $path, $headers] = $request;
            if ($method === 'GET' && strtolower($headers['upgrade'] ?? '') === 'websocket') {
                $this->session($client, $path, $headers);
                return;
            }
            $this->http($client, $method, $path);
            fclose($client);
        } catch (\Throwable $e) {
            $this->log($e::class . ': ' . $e->getMessage());
            if (\is_resource($client)) {
                fclose($client);
            }
        }
    }

    /** @param resource $client */
    private function session($client, string $path, array $headers): void
    {
        $name = str_starts_with($path, '/_eui/session')
            ? ltrim(substr($path, \strlen('/_eui/session')), '/')
            : '';
        $component = $this->app->componentFor($name === '' ? null : $name);
        if (!str_starts_with($path, '/_eui/session') || $component === null) {
            $this->respond($client, 404, 'text/plain', "no session at {$path}\n");
            fclose($client);
            return;
        }
        $ws = WebSocket::accept($client, $path, $headers);
        (new Session($ws, $component, $this->app, $this->logger))->run();
    }

    /** @param resource $client */
    private function http($client, string $method, string $path): void
    {
        if ($method !== 'GET') {
            $this->respond($client, 405, 'text/plain', "GET only\n");
            return;
        }
        if ($path === '/.well-known/eui') {
            $manifest = $this->app->manifest();
            if ($manifest === null) {
                $this->respond($client, 404, 'text/plain', "this application has no manifest\n");
                return;
            }
            $this->respond($client, 200, 'application/vnd.eui.manifest', $manifest->encode());
            return;
        }
        if (str_starts_with($path, '/_eui/asset/')) {
            $entry = $this->app->assets->fetch(substr($path, \strlen('/_eui/asset/')));
            // A hash this server does not hold is a 404 and never a
            // redirect: the name is the content, so there is nowhere else it
            // could be.
            if ($entry === null) {
                $this->respond($client, 404, 'text/plain', "no such asset\n");
                return;
            }
            $this->respond($client, 200, $entry['type'], $entry['bytes'],
                           ['Cache-Control' => 'public, max-age=31536000, immutable']);
            return;
        }
        if ($path === '/health') {
            $this->respond($client, 200, 'text/plain', "ok\n");
            return;
        }
        $this->respond($client, 404, 'text/plain', "not found\n");
    }

    /** @param resource $client */
    private function readRequest($client): ?array
    {
        $buffer = '';
        while (!str_contains($buffer, "\r\n\r\n")) {
            $chunk = @fread($client, 4096);
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $buffer .= $chunk;
            if (\strlen($buffer) > self::HEADER_LIMIT) {
                throw new EUIException('headers too long');
            }
        }
        $head = explode("\r\n\r\n", $buffer, 2)[0];
        $lines = explode("\r\n", $head);
        $parts = preg_split('/\s+/', array_shift($lines) ?? '');
        if ($parts === false || \count($parts) < 2) {
            return null;
        }
        $headers = [];
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        return [$parts[0], $parts[1], $headers];
    }

    /** @param resource $client */
    private function respond($client, int $status, string $type, string $body, array $extra = []): void
    {
        $head = "HTTP/1.1 {$status} " . (self::REASONS[$status] ?? 'OK') . "\r\n";
        $head .= "Content-Type: {$type}\r\n";
        $head .= 'Content-Length: ' . \strlen($body) . "\r\n";
        foreach ($extra as $key => $value) {
            $head .= "{$key}: {$value}\r\n";
        }
        $head .= "Connection: close\r\n\r\n";
        @fwrite($client, $head . $body);
    }

    private function contextOptions(): array
    {
        if ($this->tls === null) {
            return [];
        }
        return ['ssl' => [
            'local_cert' => $this->tls['cert'],
            'local_pk' => $this->tls['key'],
            // The protocol's floor, not a preference: a server answering
            // `wss://` with TLS 1.2 is refused by a conforming client rather
            // than accommodated.
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
        ]];
    }

    private function log(string $line): void
    {
        ($this->logger)(str_starts_with($line, '[EUI]') ? $line : "[EUI] {$line}");
    }
}
