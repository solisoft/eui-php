<?php

declare(strict_types=1);

namespace EUI;

use EUI\Proto\Caps;
use EUI\Proto\Protocol;
use EUI\Proto\Value;
use EUI\Proto\Writer;

/**
 * The application manifest served at `/.well-known/eui` (01 §2.1): an `EUIM`
 * record, signed by the publisher, that a client reads before it opens a
 * session.
 *
 * The signature is what makes trust-on-first-use mean anything: the client
 * pins `publisher_key` against `app_id` on first run and refuses a different
 * one later. So the key belongs to the *application*, not to a deployment —
 * keep the file, and keep it out of the repository.
 */
final class Manifest
{
    public const MAGIC = 'EUIM';
    public const RECORD_VERSION = 1;
    public const MAX_STR = 256;

    private const KEY_APP_ID = 0;
    private const KEY_NAME = 1;
    private const KEY_VERSION = 2;
    private const KEY_PROTOCOL_MIN = 3;
    private const KEY_PROTOCOL_MAX = 4;
    private const KEY_PUBLISHER_KEY = 5;
    private const KEY_CAPABILITIES = 6;
    private const KEY_THEME = 7;
    private const KEY_ENTRY = 8;
    private const KEY_ROTATION = 9;
    private const KEY_SIGNATURE = 10;

    private readonly int $capabilities;

    public function __construct(
        private readonly string $appId,
        private readonly string $name,
        private readonly \OpenSSLAsymmetricKey $key,
        private readonly string $version = '0.1.0',
        private readonly string $entry = '/_eui/session',
        array|int $capabilities = 0,
        private readonly ?string $theme = null,
        private readonly int $protocolMin = 1,
        private readonly int $protocolMax = Protocol::VERSION,
    ) {
        $this->capabilities = Caps::mask($capabilities);
    }

    /**
     * An Ed25519 key kept on disk as PKCS#8 PEM — the same file every one of
     * these libraries reads, so an application that changes language keeps
     * its identity and nobody's pin breaks. Generated on first use, and
     * never committed: whoever holds it can publish as this application.
     */
    public static function publisherKey(string $path): \OpenSSLAsymmetricKey
    {
        if (is_file($path)) {
            $key = openssl_pkey_get_private((string) file_get_contents($path));
            if ($key === false) {
                throw new EUIException("{$path} is not a private key this build can read");
            }
            return $key;
        }
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_ED25519]);
        if ($key === false) {
            throw new EUIException('this PHP cannot generate an Ed25519 key: ' . (string) openssl_error_string());
        }
        $directory = \dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0o700, true);
        }
        openssl_pkey_export($key, $pem);
        file_put_contents($path, $pem);
        chmod($path, 0o600);
        return $key;
    }

    public function publicKeyHex(): string
    {
        $details = openssl_pkey_get_details($this->key);
        if ($details === false || !isset($details['ed25519']['pub_key'])) {
            throw new EUIException('the publisher key is not an Ed25519 key');
        }
        return bin2hex($details['ed25519']['pub_key']);
    }

    /**
     * The bytes the publisher signs: every field but the signature. A
     * decoder rebuilds them exactly, because the order is fixed.
     */
    public function signedBytes(): string
    {
        return $this->write(null);
    }

    public function encode(): string
    {
        $signed = $this->signedBytes();
        if (!openssl_sign($signed, $signature, $this->key, 0)) {
            throw new EUIException('cannot sign the manifest: ' . (string) openssl_error_string());
        }
        return $this->write($signature);
    }

    /** @return list<string> */
    public function capabilityNames(): array
    {
        return Caps::names($this->capabilities);
    }

    private function write(?string $signature): string
    {
        $fields = [
            [self::KEY_APP_ID, Value::str($this->checked($this->appId, 'app_id'))],
            [self::KEY_NAME, Value::str($this->checked($this->name, 'name'))],
            [self::KEY_VERSION, Value::str($this->checked($this->version, 'version'))],
            [self::KEY_PROTOCOL_MIN, Value::int($this->protocolMin)],
            [self::KEY_PROTOCOL_MAX, Value::int($this->protocolMax)],
            [self::KEY_PUBLISHER_KEY, Value::str($this->publicKeyHex())],
            [self::KEY_CAPABILITIES, Value::int($this->capabilities)],
            [self::KEY_THEME, $this->theme !== null ? Value::str(bin2hex($this->theme)) : Value::null()],
            [self::KEY_ENTRY, Value::str($this->checked($this->entry, 'entry'))],
            // No rotation: this key has never been anything else. When one is
            // needed it is the previous key and its signature over the new
            // one, and the client's pin moves rather than the session failing.
            [self::KEY_ROTATION, Value::null()],
        ];
        if ($signature !== null) {
            $fields[] = [self::KEY_SIGNATURE, Value::str(bin2hex($signature))];
        }

        $w = new Writer();
        $w->raw(self::MAGIC)->u8(self::RECORD_VERSION)->varint(\count($fields));
        foreach ($fields as [$key, $value]) {
            $w->varint($key);
            $value->encode($w);
        }
        return $w->toBytes();
    }

    private function checked(string $value, string $what): string
    {
        if (\strlen($value) > self::MAX_STR) {
            throw new EUIException("manifest {$what} is at most " . self::MAX_STR . ' bytes');
        }
        return $value;
    }
}
