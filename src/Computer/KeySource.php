<?php

namespace Elcreator\aMount\Computer;

use Elcreator\aMount\Support\Config;

/**
 * Which ai.artur.work key a computer mount uses: its own, else the aIMage key of the mount's
 * owner (or of the site, for a site mount) when aIMage is installed, else
 * `cms.settings.aMount.computers.api_key` (env AMOUNT_GATEWAY_KEY). One "Connect" in aIMage
 * then serves both packages - its key still needs "Shared folders on your computers" allowed
 * on the ai.artur.work profile.
 */
final class KeySource
{
    public static function resolve(string $own, ?int $ownerId): ?string
    {
        if (trim($own) !== '') {
            return trim($own);
        }
        $aimage = \Elcreator\aIMage\Support\ApiKeys::class;
        if (class_exists($aimage)) {
            try {
                $key = $ownerId !== null && $ownerId > 0 ? $aimage::forUser($ownerId) : $aimage::siteKey();
                if (is_string($key) && $key !== '') {
                    return $key;
                }
            } catch (\Throwable) {
            }
        }
        $configured = (string) (Config::get('computers.api_key') ?? getenv('AMOUNT_GATEWAY_KEY') ?: '');

        return trim($configured) !== '' ? trim($configured) : null;
    }
}
