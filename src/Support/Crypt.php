<?php

namespace Elcreator\aMount\Support;

use RuntimeException;

/**
 * Encryption at rest for mount credentials: AES-256-GCM.
 *
 * Evolution CMS has no application key, so the secret comes from
 * `AMOUNT_SECRET` when set, otherwise from a generated file next to the site's
 * other local overrides (`core/custom/amount.secret`, mode 0600). Credentials
 * are never stored unencrypted: if neither source is usable, saving fails
 * loudly instead of falling back to plain text.
 *
 * Rotation: put the old secret in `AMOUNT_SECRET_PREVIOUS` (comma or space
 * separated) and set the new one; values still readable under an old secret
 * are rewritten under the current one on first use (`needsRotation()`).
 */
final class Crypt
{
    private const PREFIX = 'amnt1:';
    private const CIPHER = 'aes-256-gcm';

    private static ?string $secret = null;

    public static function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::secret(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new RuntimeException('aMount could not encrypt the mount credentials.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /** Null for empty input or a value no known secret opens. */
    public static function decrypt(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || !str_starts_with($value, self::PREFIX)) {
            return null;
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        foreach (array_merge([self::secret()], self::previousSecrets()) as $secret) {
            $plaintext = self::open($raw, $secret);

            if ($plaintext !== null) {
                return $plaintext;
            }
        }

        return null;
    }

    /** Readable only with a previous secret: should be written again. */
    public static function needsRotation(?string $value): bool
    {
        $value = trim((string) $value);
        $raw = str_starts_with($value, self::PREFIX) ? base64_decode(substr($value, strlen(self::PREFIX)), true) : false;

        if ($raw === false || strlen($raw) < 29 || self::open($raw, self::secret()) !== null) {
            return false;
        }

        foreach (self::previousSecrets() as $secret) {
            if (self::open($raw, $secret) !== null) {
                return true;
            }
        }

        return false;
    }

    public static function flush(): void
    {
        self::$secret = null;
    }

    private static function open(string $raw, string $secret): ?string
    {
        $plaintext = openssl_decrypt(substr($raw, 28), self::CIPHER, $secret, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $plaintext === false ? null : $plaintext;
    }

    /** @return string[] */
    private static function previousSecrets(): array
    {
        $secrets = [];

        foreach (preg_split('/[\s,]+/', (string) getenv('AMOUNT_SECRET_PREVIOUS'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $old) {
            $secrets[] = hash('sha256', $old, true);
        }

        // Moving from the generated file to AMOUNT_SECRET keeps the file as a
        // readable previous secret.
        if (trim((string) (getenv('AMOUNT_SECRET') ?: '')) !== '' && is_file(self::secretFile())) {
            $fileSecret = trim((string) file_get_contents(self::secretFile()));

            if ($fileSecret !== '') {
                $secrets[] = hash('sha256', $fileSecret, true);
            }
        }

        return array_values(array_unique($secrets));
    }

    private static function secretFile(): string
    {
        return (defined('EVO_CORE_PATH') ? rtrim(EVO_CORE_PATH, '/\\') . '/custom' : sys_get_temp_dir()) . '/amount.secret';
    }

    private static function secret(): string
    {
        if (self::$secret !== null) {
            return self::$secret;
        }

        $configured = trim((string) (getenv('AMOUNT_SECRET') ?: ''));

        return self::$secret = hash('sha256', $configured !== '' ? $configured : self::fileSecret(), true);
    }

    private static function fileSecret(): string
    {
        $file = self::secretFile();
        $dir = dirname($file);

        if (is_file($file)) {
            $secret = trim((string) file_get_contents($file));

            if ($secret !== '') {
                return $secret;
            }
        }

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('aMount cannot create ' . $dir . ' to hold its encryption secret.');
        }

        $secret = bin2hex(random_bytes(32));

        if (@file_put_contents($file, $secret, LOCK_EX) === false) {
            throw new RuntimeException(
                'aMount cannot write ' . $file . '. Set the AMOUNT_SECRET environment variable instead, '
                . 'or make that directory writable: credentials are not stored unencrypted.'
            );
        }

        @chmod($file, 0600);

        return $secret;
    }
}
