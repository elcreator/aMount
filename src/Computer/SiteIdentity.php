<?php

namespace Elcreator\aMount\Computer;

use Elcreator\aMount\Support\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * This site's own key for computers.
 *
 * One X25519 key per installation, made on first use and kept encrypted (`Support\Crypt`)
 * in `amount_state`. A computer knows a site only by it: the person at the computer approves
 * the site after comparing {@see fingerprint()} - shown on the aMount page - with the code
 * their own dialog shows. Losing it (a new database, a changed AMOUNT_SECRET) means every
 * computer asks again; that is the safe way round.
 */
final class SiteIdentity
{
    private const KEY = 'computer_site_key';

    private static ?string $private = null;

    public static function privateKey(): string
    {
        if (self::$private !== null) {
            return self::$private;
        }

        $stored = DB::table('amount_state')->where('key', self::KEY)->value('value');
        $raw = is_string($stored) ? Crypt::decrypt($stored) : null;

        if ($raw === null || strlen((string) base64_decode($raw, true)) !== 32) {
            $key = random_bytes(32);
            DB::table('amount_state')->updateOrInsert(['key' => self::KEY],
                ['value' => Crypt::encrypt(base64_encode($key)), 'updated_at' => date('Y-m-d H:i:s')]);
            $raw = base64_encode($key);
        }

        $private = base64_decode($raw, true);

        if ($private === false || strlen($private) !== 32) {
            throw new RuntimeException('The site key could not be made.');
        }

        return self::$private = $private;
    }

    public static function publicKey(): string
    {
        return X25519::publicFrom(self::privateKey());
    }

    /** The code the person at a computer compares: XXXX-XXXX-XXXX. */
    public static function fingerprint(): string
    {
        return Seal::fingerprint(self::publicKey());
    }

    /** For tests: forget the in-process copy. */
    public static function flush(): void
    {
        self::$private = null;
    }
}
