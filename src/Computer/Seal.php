<?php

namespace Elcreator\aMount\Computer;

use RuntimeException;

/**
 * The seal between this site and a computer's shared folders - the PHP mirror of the
 * daemon's `files_crypto.go`.
 *
 * The gateway in between relays bytes it can neither read nor forge. A request is sealed for
 * the computer's static X25519 key with two Diffie-Hellman results mixed in: a fresh
 * ephemeral key, and this site's own static key ({@see SiteIdentity}). Only this site can make
 * a request the computer accepts, which is what lets the person at the computer approve
 * *this site* by comparing its code.
 *
 *     envelope = 0x01 | ephPub | sitePub | GCM(kToComputer, "req\0"+0, aad "mesh-files v1 request")
 *     keys     = HKDF-SHA256(es || ss, salt ephPub || sitePub || computerPub, "mesh-files v1", 64)
 *     response = counter(8) | GCM(kToSite, "rsp\0"+counter, aad "mesh-files v1 response")
 *     chunk n  = GCM(key, "up\0\0"/"dn\0\0"+n, aad requestId(16) | n(8) | last(1))
 *
 * tests/fixtures/files-vector.json is the daemon's own vector: both sides are tested on it.
 */
final class Seal
{
    public const VERSION = 1;
    public const CHUNK = 4194304; // plaintext bytes per chunk, as the daemon reads them
    private const INFO = 'mesh-files v1';
    private const AAD_REQUEST = 'mesh-files v1 request';
    private const AAD_RESPONSE = 'mesh-files v1 response';

    private int $nextResponse = 0;

    private function __construct(
        private readonly string $toComputer,
        private readonly string $toSite,
        private readonly string $requestId,
    ) {
    }

    /**
     * Seal a request for a computer. `$request` gets its `id` and `ts` here.
     *
     * @return array{0: self, 1: string} the session's keys and the envelope (raw bytes)
     */
    public static function request(string $sitePrivate, string $computerPublic, array $request, ?string $ephPrivate = null): array
    {
        $request['id'] ??= bin2hex(random_bytes(16));
        $request['ts'] ??= time();
        $plain = (string) json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return self::sealPlain($sitePrivate, $computerPublic, $plain, (string) $request['id'], $ephPrivate);
    }

    /** @return array{0: self, 1: string} */
    public static function sealPlain(string $sitePrivate, string $computerPublic, string $plain, string $requestId, ?string $ephPrivate = null): array
    {
        $ephPrivate ??= random_bytes(32);
        $ephPublic = X25519::publicFrom($ephPrivate);
        $sitePublic = X25519::publicFrom($sitePrivate);
        $keys = self::derive(
            X25519::shared($ephPrivate, $computerPublic),
            X25519::shared($sitePrivate, $computerPublic),
            $ephPublic,
            $sitePublic,
            $computerPublic,
            $requestId
        );
        $sealed = self::gcmSeal($keys->toComputer, self::nonce("req\0", 0), $plain, self::AAD_REQUEST);

        return [$keys, chr(self::VERSION) . $ephPublic . $sitePublic . $sealed];
    }

    private static function derive(string $es, string $ss, string $ephPub, string $sitePub, string $computerPub, string $requestId): self
    {
        $okm = hash_hkdf('sha256', $es . $ss, 64, self::INFO, $ephPub . $sitePub . $computerPub);
        $id = hex2bin($requestId);

        if ($id === false || strlen($id) !== 16) {
            throw new RuntimeException('Bad request id.');
        }

        return new self(substr($okm, 0, 32), substr($okm, 32), $id);
    }

    /**
     * Open one of the computer's answers. Answers come in order, each with the next counter,
     * so an old one cannot be replayed in place of a newer one.
     *
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    public static function counterOf(string $sealed): int
    {
        return strlen($sealed) >= 8 ? (int) unpack('J', substr($sealed, 0, 8))[1] : -1;
    }

    public function nextResponse(): int
    {
        return $this->nextResponse;
    }

    public function openResponse(string $sealed): array
    {
        if (strlen($sealed) < 8 + 16) {
            throw new RuntimeException('The computer\'s answer is damaged.');
        }

        $counter = unpack('J', substr($sealed, 0, 8))[1];

        // Never an older one; an interim answer ("asking", "ready") may be skipped, since the
        // gateway keeps only the latest.
        if ($counter < $this->nextResponse) {
            throw new RuntimeException('The computer\'s answers arrived out of order.');
        }

        $plain = self::gcmOpen($this->toSite, self::nonce("rsp\0", $counter), substr($sealed, 8), self::AAD_RESPONSE);
        $this->nextResponse = $counter + 1;
        $data = json_decode($plain, true);

        if (!is_array($data)) {
            throw new RuntimeException('The computer\'s answer is not readable.');
        }

        return $data;
    }

    /** Seal chunk n of an upload to the computer. */
    public function sealUp(int $n, bool $last, string $plain): string
    {
        return self::gcmSeal($this->toComputer, self::nonce("up\0\0", $n), $plain, $this->chunkAad($n, $last));
    }

    /** Open chunk n of a download from the computer. @throws RuntimeException */
    public function openDown(int $n, bool $last, string $sealed): string
    {
        return self::gcmOpen($this->toSite, self::nonce("dn\0\0", $n), $sealed, $this->chunkAad($n, $last));
    }

    private function chunkAad(int $n, bool $last): string
    {
        return $this->requestId . pack('J', $n) . ($last ? "\1" : "\0");
    }

    private static function nonce(string $label, int $n): string
    {
        return $label . pack('J', $n);
    }

    private static function gcmSeal(string $key, string $nonce, string $plain, string $aad): string
    {
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);

        if ($ct === false) {
            throw new RuntimeException('Sealing failed.');
        }

        return $ct . $tag;
    }

    private static function gcmOpen(string $key, string $nonce, string $sealed, string $aad): string
    {
        if (strlen($sealed) < 16) {
            throw new RuntimeException('A sealed message is too short.');
        }

        $plain = openssl_decrypt(substr($sealed, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, substr($sealed, -16), $aad);

        if ($plain === false) {
            throw new RuntimeException('A message from the computer failed its integrity check.');
        }

        return $plain;
    }

    /**
     * What the person at the computer compares: the first 60 bits of sha256 of a public key,
     * base32, as XXXX-XXXX-XXXX.
     */
    public static function fingerprint(string $publicKey): string
    {
        $bits = '';

        foreach (str_split(hash('sha256', $publicKey, true)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';

        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[bindec(substr($bits, $i * 5, 5))];
        }

        return substr($out, 0, 4) . '-' . substr($out, 4, 4) . '-' . substr($out, 8, 4);
    }

    public static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function fromB64url(string $text): string
    {
        $raw = base64_decode(strtr($text, '-_', '+/'), true);

        return $raw === false ? '' : $raw;
    }

    // ------------------------------------------------------------------
    // The computer's half - for the tests and the shared vector only.
    // ------------------------------------------------------------------

    /** @return array{0: self, 1: string} keys and the request plaintext */
    public static function openAsComputer(string $computerPrivate, string $envelope): array
    {
        if (strlen($envelope) < 1 + 32 + 32 + 16 || ord($envelope[0]) !== self::VERSION) {
            throw new RuntimeException('Not a sealed request.');
        }

        $ephPub = substr($envelope, 1, 32);
        $sitePub = substr($envelope, 33, 32);
        $okm = hash_hkdf('sha256', X25519::shared($computerPrivate, $ephPub) . X25519::shared($computerPrivate, $sitePub), 64,
            self::INFO, $ephPub . $sitePub . X25519::publicFrom($computerPrivate));
        $plain = self::gcmOpen(substr($okm, 0, 32), self::nonce("req\0", 0), substr($envelope, 65), self::AAD_REQUEST);
        $id = (string) (json_decode($plain, true)['id'] ?? '');

        return [new self(substr($okm, 0, 32), substr($okm, 32), (string) hex2bin($id)), $plain];
    }

    public function sealResponseAsComputer(int $counter, array $answer): string
    {
        return pack('J', $counter) . self::gcmSeal($this->toSite, self::nonce("rsp\0", $counter),
            (string) json_encode($answer, JSON_UNESCAPED_SLASHES), self::AAD_RESPONSE);
    }

    public function sealDownAsComputer(int $n, bool $last, string $plain): string
    {
        return self::gcmSeal($this->toSite, self::nonce("dn\0\0", $n), $plain, $this->chunkAad($n, $last));
    }

    public function openUpAsComputer(int $n, bool $last, string $sealed): string
    {
        return self::gcmOpen($this->toComputer, self::nonce("up\0\0", $n), $sealed, $this->chunkAad($n, $last));
    }
}
