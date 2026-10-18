<?php

namespace Elcreator\aMount\Computer;

use RuntimeException;

/**
 * X25519 with whatever this PHP has: ext-sodium (most builds), or OpenSSL's Curve25519 keys
 * (PHP 8.4+), so a Windows PHP without the sodium extension enabled still works. Both give
 * the same bytes (the daemon's Go `crypto/ecdh` too).
 */
final class X25519
{
    public static function available(): bool
    {
        return function_exists('sodium_crypto_scalarmult') || defined('OPENSSL_KEYTYPE_X25519');
    }

    public static function publicFrom(string $private): string
    {
        self::check($private);

        if (function_exists('sodium_crypto_scalarmult_base')) {
            return sodium_crypto_scalarmult_base($private);
        }

        $key = openssl_pkey_new(['x25519' => ['priv_key' => $private]]);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if (!is_array($details) || !isset($details['x25519']['pub_key'])) {
            throw new RuntimeException('X25519 is not available in this PHP (enable ext-sodium).');
        }

        return $details['x25519']['pub_key'];
    }

    public static function shared(string $private, string $public): string
    {
        self::check($private);
        self::check($public);

        if (function_exists('sodium_crypto_scalarmult')) {
            return sodium_crypto_scalarmult($private, $public);
        }

        $mine = openssl_pkey_new(['x25519' => ['priv_key' => $private]]);
        $theirs = openssl_pkey_new(['x25519' => ['pub_key' => $public]]);
        $secret = ($mine === false || $theirs === false) ? false : openssl_pkey_derive($theirs, $mine, 32);

        if (!is_string($secret) || strlen($secret) !== 32) {
            throw new RuntimeException('X25519 is not available in this PHP (enable ext-sodium).');
        }

        return $secret;
    }

    private static function check(string $key): void
    {
        if (strlen($key) !== 32) {
            throw new RuntimeException('An X25519 key is 32 bytes.');
        }
    }
}
