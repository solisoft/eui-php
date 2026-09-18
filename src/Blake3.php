<?php

declare(strict_types=1);

namespace EUI;

/**
 * BLAKE3, in PHP, because an asset is named by the hash of its content and
 * the client recomputes it (01 §2.2).
 *
 * Only the plain hash: no keyed mode, no key derivation, no extendable
 * output past 32 bytes. That is every use the protocol has for it — an
 * asset's name — and each of the others is a footgun this library would
 * rather not carry.
 */
final class Blake3
{
    public const OUT_LEN = 32;
    public const BLOCK_LEN = 64;
    public const CHUNK_LEN = 1024;

    private const CHUNK_START = 1;
    private const CHUNK_END = 2;
    private const PARENT = 4;
    private const ROOT = 8;

    private const IV = [
        0x6A09E667, 0xBB67AE85, 0x3C6EF372, 0xA54FF53A,
        0x510E527F, 0x9B05688C, 0x1F83D9AB, 0x5BE0CD19,
    ];

    private const MSG_PERMUTATION = [2, 6, 3, 10, 7, 0, 4, 13, 1, 11, 12, 5, 9, 14, 15, 8];

    private const MASK = 0xFFFFFFFF;

    /** @var list<int> */
    private array $cv;
    private string $block = '';
    private int $blocksCompressed = 0;
    private int $chunkCounter = 0;
    /** @var list<list<int>> */
    private array $stack = [];

    public function __construct()
    {
        $this->cv = self::IV;
    }

    public static function hash(string $data): string
    {
        return (new self())->update($data)->digest();
    }

    public static function hex(string $data): string
    {
        return bin2hex(self::hash($data));
    }

    public static function file(string $path): string
    {
        $hasher = new self();
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new EUIException("cannot read {$path}");
        }
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $hasher->update($chunk);
            }
        } finally {
            fclose($handle);
        }
        return $hasher->digest();
    }

    public function update(string $data): self
    {
        $offset = 0;
        $length = \strlen($data);
        while ($offset < $length) {
            if ($this->chunkLength() === self::CHUNK_LEN) {
                $this->addChunk($this->chunkChainingValue(), $this->chunkCounter + 1);
                $this->chunkCounter++;
                $this->cv = self::IV;
                $this->block = '';
                $this->blocksCompressed = 0;
            }
            $take = min(self::CHUNK_LEN - $this->chunkLength(), $length - $offset);
            $this->updateChunk(substr($data, $offset, $take));
            $offset += $take;
        }
        return $this;
    }

    public function digest(int $length = self::OUT_LEN): string
    {
        [$cv, $blockWords, $counter, $blockLen, $flags] = $this->chunkOutput();
        for ($i = \count($this->stack) - 1; $i >= 0; $i--) {
            $right = self::compress($cv, $blockWords, $counter, $blockLen, $flags);
            $words = array_merge($this->stack[$i], \array_slice($right, 0, 8));
            [$cv, $blockWords, $counter, $blockLen, $flags] = [self::IV, $words, 0, self::BLOCK_LEN, self::PARENT];
        }

        $out = '';
        $counterOut = 0;
        while (\strlen($out) < $length) {
            $words = self::compress($cv, $blockWords, $counterOut, $blockLen, $flags | self::ROOT);
            foreach ($words as $word) {
                $out .= pack('V', $word);
            }
            $counterOut++;
        }
        return substr($out, 0, $length);
    }

    public function hexdigest(int $length = self::OUT_LEN): string
    {
        return bin2hex($this->digest($length));
    }

    // -- the chunk in progress --------------------------------------------
    private function chunkLength(): int
    {
        return self::BLOCK_LEN * $this->blocksCompressed + \strlen($this->block);
    }

    private function startFlag(): int
    {
        return $this->blocksCompressed === 0 ? self::CHUNK_START : 0;
    }

    private function updateChunk(string $data): void
    {
        $offset = 0;
        $length = \strlen($data);
        while ($offset < $length) {
            if (\strlen($this->block) === self::BLOCK_LEN) {
                $this->cv = \array_slice(self::compress(
                    $this->cv, self::words($this->block), $this->chunkCounter,
                    self::BLOCK_LEN, $this->startFlag()
                ), 0, 8);
                $this->blocksCompressed++;
                $this->block = '';
            }
            $take = min(self::BLOCK_LEN - \strlen($this->block), $length - $offset);
            $this->block .= substr($data, $offset, $take);
            $offset += $take;
        }
    }

    /** @return array{list<int>,list<int>,int,int,int} */
    private function chunkOutput(): array
    {
        return [$this->cv, self::words($this->block), $this->chunkCounter,
                \strlen($this->block), $this->startFlag() | self::CHUNK_END];
    }

    /** @return list<int> */
    private function chunkChainingValue(): array
    {
        [$cv, $words, $counter, $blockLen, $flags] = $this->chunkOutput();
        return \array_slice(self::compress($cv, $words, $counter, $blockLen, $flags), 0, 8);
    }

    /**
     * A chunk's chaining value joins the tree, merging with everything to
     * its left that is now complete — which is what the low bits of the
     * chunk count say.
     *
     * @param list<int> $cv
     */
    private function addChunk(array $cv, int $totalChunks): void
    {
        while (($totalChunks & 1) === 0) {
            $left = array_pop($this->stack);
            $words = array_merge($left, $cv);
            $cv = \array_slice(self::compress(self::IV, $words, 0, self::BLOCK_LEN, self::PARENT), 0, 8);
            $totalChunks >>= 1;
        }
        $this->stack[] = $cv;
    }

    // -- the primitive ----------------------------------------------------
    /** @return list<int> */
    private static function words(string $block): array
    {
        if (\strlen($block) < self::BLOCK_LEN) {
            $block = str_pad($block, self::BLOCK_LEN, "\0");
        }
        return array_values(unpack('V16', $block));
    }

    private static function rotr(int $x, int $n): int
    {
        return (($x >> $n) | ($x << (32 - $n))) & self::MASK;
    }

    /**
     * The one primitive: a chaining value and a block in, sixteen words out.
     * The first eight are the next chaining value; all sixteen are the
     * root's output.
     *
     * @param list<int> $cv
     * @param list<int> $block
     *
     * @return list<int>
     */
    public static function compress(array $cv, array $block, int $counter, int $blockLen, int $flags): array
    {
        $s = [
            $cv[0], $cv[1], $cv[2], $cv[3], $cv[4], $cv[5], $cv[6], $cv[7],
            self::IV[0], self::IV[1], self::IV[2], self::IV[3],
            $counter & self::MASK, ($counter >> 32) & self::MASK, $blockLen, $flags,
        ];
        $m = $block;
        for ($r = 0; $r < 7; $r++) {
            self::round($s, $m);
            if ($r < 6) {
                $permuted = [];
                foreach (self::MSG_PERMUTATION as $i) {
                    $permuted[] = $m[$i];
                }
                $m = $permuted;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $s[$i] ^= $s[$i + 8];
            $s[$i + 8] ^= $cv[$i];
        }
        return $s;
    }

    /**
     * @param list<int> $s
     * @param list<int> $m
     */
    private static function round(array &$s, array $m): void
    {
        self::g($s, 0, 4, 8, 12, $m[0], $m[1]);
        self::g($s, 1, 5, 9, 13, $m[2], $m[3]);
        self::g($s, 2, 6, 10, 14, $m[4], $m[5]);
        self::g($s, 3, 7, 11, 15, $m[6], $m[7]);
        self::g($s, 0, 5, 10, 15, $m[8], $m[9]);
        self::g($s, 1, 6, 11, 12, $m[10], $m[11]);
        self::g($s, 2, 7, 8, 13, $m[12], $m[13]);
        self::g($s, 3, 4, 9, 14, $m[14], $m[15]);
    }

    /** @param list<int> $s */
    private static function g(array &$s, int $a, int $b, int $c, int $d, int $mx, int $my): void
    {
        $s[$a] = ($s[$a] + $s[$b] + $mx) & self::MASK;
        $s[$d] = self::rotr($s[$d] ^ $s[$a], 16);
        $s[$c] = ($s[$c] + $s[$d]) & self::MASK;
        $s[$b] = self::rotr($s[$b] ^ $s[$c], 12);
        $s[$a] = ($s[$a] + $s[$b] + $my) & self::MASK;
        $s[$d] = self::rotr($s[$d] ^ $s[$a], 8);
        $s[$c] = ($s[$c] + $s[$d]) & self::MASK;
        $s[$b] = self::rotr($s[$b] ^ $s[$c], 7);
    }
}
