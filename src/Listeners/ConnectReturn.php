<?php

namespace Elcreator\aMount\Listeners;

use Elcreator\aMount\Computer\Connect;

/**
 * `OnPageNotFound`: GET /amount/lendspare-return?m=...&code=...&state=... - the browser coming back
 * from LendSpare's consent page ({@see Connect::relayUri()}). Front end: no session, no database, no
 * key; it only forwards LendSpare's answer to the manager callback, which proves the state.
 */
final class ConnectReturn
{
    public static function handle($params = []): void
    {
        $page = self::page((string) ($_SERVER['REQUEST_URI'] ?? ''), $_GET);
        if ($page === null) {
            return;
        }
        if (!headers_sent()) {
            http_response_code($page[0]);
            header('Content-Type: text/html; charset=utf-8');
            header('Referrer-Policy: no-referrer');
            header('Cache-Control: no-store');
        }
        echo $page[1];
        exit;
    }

    /**
     * The status and page for a request, or null when it is not the relay's. Testable: nothing is sent.
     *
     * @param array<string, mixed> $query
     * @return array{0: int, 1: string}|null
     */
    public static function page(string $requestUri, array $query): ?array
    {
        if (!str_ends_with(rtrim((string) parse_url($requestUri, PHP_URL_PATH), '/'), Connect::RELAY_PATH)) {
            return null;
        }
        $target = Connect::relayTarget($query);

        return $target === null
            ? [400, '<!DOCTYPE html><title>aMount</title><p>Bad request.</p>']
            : [200, Connect::relayPage($target)];
    }
}
