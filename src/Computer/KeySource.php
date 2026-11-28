<?php

namespace Elcreator\aMount\Computer;

use Elcreator\aMount\Support\Config;

/**
 * Which LendSpare key a computer mount uses: its own, else the key its manager got with "Connect
 * LendSpare" ({@see ConnectedKey}), else `cms.settings.aMount.computers.api_key` (env
 * AMOUNT_GATEWAY_KEY). A pasted key needs "Shared folders on your computers" allowed on the
 * LendSpare profile. aIMage's key is not used: it is an ai.artur.work key.
 */
final class KeySource
{
    public static function resolve(string $own, ?int $ownerId): ?string
    {
        if (trim($own) !== '') {
            return trim($own);
        }
        $connected = ConnectedKey::get($ownerId);
        if ($connected !== null) {
            return $connected;
        }
        $configured = (string) (Config::get('computers.api_key') ?? getenv('AMOUNT_GATEWAY_KEY') ?: '');

        return trim($configured) !== '' ? trim($configured) : null;
    }
}
