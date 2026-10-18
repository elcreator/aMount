<?php

namespace Elcreator\aMount\Services;

use Elcreator\aMount\Computer\Client;
use Elcreator\aMount\Computer\ComputerException;
use Elcreator\aMount\Computer\Gateway;
use Elcreator\aMount\Computer\KeySource;
use Elcreator\aMount\Computer\SiteAddress;
use Elcreator\aMount\Computer\SiteIdentity;
use Elcreator\aMount\Drivers\ComputerDriver;
use Elcreator\aMount\Models\Mount;
use Throwable;

/**
 * The account's computers, as a manager (or an agent acting for one) sees them
 * which exist, which are online, what they
 * share with their quotas, and the code of this site the person at a computer compares when
 * asked to let it in. "From my computer mypc" needs no prepared mount: {@see mountFor()} makes
 * the manager's own one the first time.
 */
final class Computers
{
    public function __construct(private readonly Actor $actor)
    {
    }

    /** @return array<string, mixed> */
    public function overview(bool $withShares = true): array
    {
        $this->actor->authorize();
        $key = KeySource::resolve('', $this->actor->userId());
        $out = ['site' => ['code' => SiteIdentity::fingerprint(), 'name' => SiteAddress::label(), 'domains' => SiteAddress::domains(),
            'public_url' => SiteAddress::publicUrl()], 'computers' => []];
        if ($key === null) {
            $out['error'] = 'No ai.artur.work API key: connect aIMage, give a computer mount its own key, or set cms.settings.aMount.computers.api_key.';

            return $out;
        }
        try {
            $gateway = self::gateway($key);
            foreach ($gateway->computers() as $c) {
                $row = ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'own' => (bool) ($c['own'] ?? true),
                    'online' => (bool) $c['online'], 'shares' => (array) ($c['shares'] ?? []), 'mount' => $this->existingMount((int) $c['id'])?->uuid];
                if ($withShares && $c['online'] && !empty($c['files'])) {
                    try {
                        // The computer's own answer: this site's access per folder (none until approved).
                        $row['shares'] = (new Client($gateway, (string) $c['id']))->shares();
                    } catch (ComputerException $e) {
                        $row['problem'] = $e->getMessage();
                    }
                }
                $out['computers'][] = $row;
            }
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
    }

    private static function gateway(string $key): Gateway
    {
        return ComputerDriver::$gatewayFactory !== null ? (ComputerDriver::$gatewayFactory)($key, 'https://ai.artur.work/api/v1') : new Gateway($key);
    }

    /** The manager's mount for a computer, if they have one. */
    private function existingMount(int $computerId): ?Mount
    {
        foreach ((new Mounts($this->actor))->visible() as $m) {
            if ($m->driver === 'ai-computer' && (string) ($m->credentials()['computer'] ?? '') === (string) $computerId) {
                return $m;
            }
        }

        return null;
    }

    /**
     * The mount for the computer an agent named ("mypc"): the manager's existing one, or a new
     * personal one. Null when the account has no computer of that name.
     */
    public function mountFor(string $name): ?Mount
    {
        $key = KeySource::resolve('', $this->actor->userId());
        if ($key === null || trim($name) === '') {
            return null;
        }
        try {
            foreach ((self::gateway($key))->computers() as $c) {
                if (mb_strtolower((string) $c['name']) === mb_strtolower(trim($name)) || (string) $c['id'] === trim($name)) {
                    return $this->existingMount((int) $c['id']) ?? (new Mounts($this->actor))->create([
                        'name' => (string) $c['name'],
                        'driver' => (new ComputerDriver())->id(),
                        'scope' => 'personal',
                        'config' => ['computer' => (string) $c['id']],
                    ]);
                }
            }
        } catch (Throwable) {
        }

        return null;
    }
}
