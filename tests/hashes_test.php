<?php

declare(strict_types=1);

/**
 * BLAKE3 and the publisher key, against the official vectors.
 *
 * An asset's name *is* its content and a manifest's signature is what makes
 * trust-on-first-use mean anything, so these are the two pieces of
 * arithmetic another implementation will disagree with loudly.
 */

use EUI\Blake3;
use EUI\EUIException;
use EUI\Manifest;
use EUI\Proto\Caps;
use EUI\Proto\Reader;
use EUI\Proto\Value;

// The input of length n is the bytes 0, 1, 2 … 250 repeating, which is what
// the BLAKE3 test vectors use.
$vectors = [
    0 => 'af1349b9f5f9a1a6a0404dea36dcc9499bcb25c9adc112b7cc9a93cae41f3262',
    63 => 'e9bc37a594daad83be9470df7f7b3798297c3d834ce80ba85d6e207627b7db7b',
    64 => '4eed7141ea4a5cd4b788606bd23f46e212af9cacebacdc7d1f4c6dc7f2511b98',
    65 => 'de1e5fa0be70df6d2be8fffd0e99ceaa8eb6e8c93a63f2d8d1c30ecb6b263dee',
    1023 => '10108970eeda3eb932baac1428c7a2163b0e924c9a9e25b35bba72b28f70bd11',
    1024 => '42214739f095a406f3fc83deb889744ac00df831c10daa55189b5d121c855af7',
    1025 => 'd00278ae47eb27b34faecf67b4fe263f82d5412916c1ffd97c8cb7fb814b8444',
    2048 => 'e776b6028c7cd22a4d0ba182a8bf62205d2ef576467e838ed6f2529b85fba24a',
    2049 => '5f4d72f40d7a5f82b15ca2b2e44b1de3c2ef86c426c95c1af0b6879522563030',
    3000 => '5fade288bf27444bee55ba2babb98c3c922c1e84c2e445e7d1f6da24756f5060',
    4096 => '015094013f57a5277b59d8475c0501042c0b642e531b0a1c8f58d2163229e969',
    10000 => '5f81f9e4ab67627b6b036d5d4e3bc40d9d3daa6fcc2b6dd07ab2bbf0a877da54',
    65536 => '68d647e619a930e7b1082f74f334b0c65a315725569bdc123f0ee11881717bfe',
];

$pattern = static function (int $length): string {
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= \chr($i % 251);
    }
    return $out;
};

$manifest = static function (array $overrides = []) {
    $key = Manifest::publisherKey(sys_get_temp_dir() . '/eui-php-test-key.pem');
    return new Manifest(
        appId: $overrides['appId'] ?? 'counter.test',
        name: $overrides['name'] ?? 'Counter',
        key: $key,
        entry: '/_eui/session/counter',
        capabilities: $overrides['capabilities'] ?? 0,
    );
};

return [
    'the BLAKE3 vectors' => function () use ($vectors, $pattern): void {
        foreach ($vectors as $length => $want) {
            Assert::same($want, Blake3::hex($pattern($length)), "length {$length}");
        }
    },

    'abc' => function (): void {
        Assert::same('6437b3ac38465133ffb63b75273a8db548c558465d79db03fd359c6cd5bd9d85', Blake3::hex('abc'));
    },

    // A chunk is 1024 bytes and a block is 64: fed in sevens, every boundary
    // falls somewhere awkward, which is where a streaming hash breaks.
    'streaming matches one shot' => function () use ($pattern): void {
        $data = $pattern(3000);
        $hasher = new Blake3();
        for ($i = 0; $i < \strlen($data); $i += 7) {
            $hasher->update(substr($data, $i, 7));
        }
        Assert::same(Blake3::hex($data), $hasher->hexdigest());
    },

    'a file hashes as its bytes' => function () use ($pattern): void {
        $path = tempnam(sys_get_temp_dir(), 'eui');
        file_put_contents($path, $pattern(5000));
        try {
            Assert::same(Blake3::hex($pattern(5000)), bin2hex(Blake3::file($path)));
        } finally {
            unlink($path);
        }
    },

    'the manifest is signed over everything but the signature' => function () use ($manifest): void {
        $m = $manifest();
        $raw = $m->encode();
        Assert::same('EUIM', substr($raw, 0, 4));
        Assert::same(1, \ord($raw[4]), 'record version');

        $signature = hex2bin(substr($raw, -128));
        $public = 'ed25519:' . $m->publicKeyHex();
        $key = openssl_pkey_get_public(openssl_pkey_get_details(
            Manifest::publisherKey(sys_get_temp_dir() . '/eui-php-test-key.pem')
        )['key']);
        Assert::same(1, openssl_verify($m->signedBytes(), $signature, $key, 0), $public);
        Assert::same(0, openssl_verify($m->signedBytes() . 'x', $signature, $key, 0));
    },

    'the manifest fields are in key order' => function () use ($manifest): void {
        $r = new Reader($manifest()->encode());
        $r->take(4);
        $r->u8();
        $count = $r->varint();
        Assert::same(11, $count, 'a manifest has exactly eleven fields');
        for ($expected = 0; $expected < $count; $expected++) {
            Assert::same($expected, $r->varint(), 'fields are in key order');
            Value::decode($r);
        }
        $r->finish();
    },

    'capabilities are a bitset of names' => function () use ($manifest): void {
        $m = $manifest(['capabilities' => ['net.open', 'notifications']]);
        Assert::same(['notifications', 'net.open'], $m->capabilityNames());
        Assert::same(Caps::mask(['net.open', 'notifications']), Caps::mask($m->capabilityNames()));
    },

    'a publisher key is made once and kept' => function (): void {
        $path = sys_get_temp_dir() . '/eui-php-key-' . getmypid() . '/config/eui_publisher.pem';
        @unlink($path);
        $first = Manifest::publisherKey($path);
        Assert::true(is_file($path));
        Assert::same('0600', substr(sprintf('%o', fileperms($path)), -4), 'whoever holds it can publish as this application');
        $again = Manifest::publisherKey($path);
        Assert::same(
            openssl_pkey_get_details($first)['ed25519']['pub_key'],
            openssl_pkey_get_details($again)['ed25519']['pub_key'],
        );
        unlink($path);
    },

    'a manifest string field is bounded' => function () use ($manifest): void {
        Assert::throws(EUIException::class, static fn () => $manifest(['name' => str_repeat('x', 300)])->encode());
    },
];
