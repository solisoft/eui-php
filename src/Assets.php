<?php

declare(strict_types=1);

namespace EUI;

/**
 * The content-addressed store behind `/_eui/asset/<blake3-hex>`.
 *
 * An asset is named by the hash of its bytes, so the name *is* the content:
 * the client recomputes it and discards a mismatch, a proxy may serve it to
 * anyone, and `immutable` is always the right cache header. A file somebody
 * uploaded is not an asset — that travels in the session (01 §6), because it
 * is one person's and not the same for everyone.
 */
final class Assets
{
    public const TYPES = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff2' => 'font/woff2',
        'wav' => 'audio/wav', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg',
        'mp4' => 'video/mp4', 'wgsl' => 'text/wgsl',
    ];

    private readonly string $root;
    /** @var array<string,array{bytes:string,type:string}> */
    private array $byHash = [];
    /** @var array<string,array{stamp:string,hash:string}> */
    private array $byPath = [];

    public function __construct(?string $root = null)
    {
        $this->root = realpath($root ?? getcwd()) ?: (string) getcwd();
    }

    /**
     * Take a file into the store and answer its hash. Cheap to call on every
     * render: a path whose mtime and size have not moved is not read again.
     */
    public function addFile(string $path): string
    {
        $full = realpath(str_starts_with($path, '/') ? $path : $this->root . '/' . $path);
        if ($full === false) {
            throw new ViewException("no such asset: {$path}");
        }
        if ($full !== $this->root && !str_starts_with($full, $this->root . '/')) {
            throw new ViewException("an asset must live under {$this->root}, got {$path}");
        }
        if (!is_file($full)) {
            throw new ViewException("no such asset: {$path}");
        }

        $stamp = filemtime($full) . ':' . filesize($full);
        if (isset($this->byPath[$full]) && $this->byPath[$full]['stamp'] === $stamp) {
            return $this->byPath[$full]['hash'];
        }

        $bytes = (string) file_get_contents($full);
        $hash = Blake3::hash($bytes);
        $extension = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $this->byHash[$hash] = ['bytes' => $bytes, 'type' => self::TYPES[$extension] ?? 'application/octet-stream'];
        $this->byPath[$full] = ['stamp' => $stamp, 'hash' => $hash];
        return $hash;
    }

    /**
     * Bytes that have no file — a picture out of a database, a chart this
     * process drew — reach a window the same way.
     */
    public function addBytes(string $bytes, string $contentType = 'application/octet-stream'): string
    {
        $hash = Blake3::hash($bytes);
        $this->byHash[$hash] = ['bytes' => $bytes, 'type' => $contentType];
        return $hash;
    }

    /** @return array{bytes:string,type:string}|null */
    public function fetch(string $hex): ?array
    {
        if (\strlen($hex) !== 64 || !ctype_xdigit($hex)) {
            return null;
        }
        return $this->byHash[(string) hex2bin($hex)] ?? null;
    }

    public function count(): int
    {
        return \count($this->byHash);
    }

    public static function hex(string $hash): string
    {
        return bin2hex($hash);
    }
}
