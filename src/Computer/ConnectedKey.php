<?php

namespace Elcreator\aMount\Computer;

use Elcreator\aMount\Support\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * The LendSpare key a manager got with "Connect LendSpare" ({@see Connect}), one per manager, kept
 * encrypted in `amount_state`. Connecting again replaces it (LendSpare replaces the site's key too).
 */
final class ConnectedKey
{
    private const PREFIX = 'computers_key_';

    public static function get(?int $userId): ?string
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }
        $stored = DB::table('amount_state')->where('key', self::PREFIX . $userId)->value('value');
        $key = Crypt::decrypt(is_string($stored) ? $stored : null);

        return $key !== null && $key !== '' ? $key : null;
    }

    public static function put(int $userId, string $key): void
    {
        DB::table('amount_state')->updateOrInsert(['key' => self::PREFIX . $userId],
            ['value' => Crypt::encrypt($key), 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public static function forget(int $userId): void
    {
        DB::table('amount_state')->where('key', self::PREFIX . $userId)->delete();
    }
}
