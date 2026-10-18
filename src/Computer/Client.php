<?php

namespace Elcreator\aMount\Computer;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One computer of the account, as this site sees it: found by id or by the name the account
 * gave it, its key pinned on first use, its folders read and written over {@see Link}.
 *
 * The pin is the guard against a compromised gateway handing out its own key: the first key
 * seen for a computer is kept in `amount_state`, and a different one later is refused until a
 * manager confirms it on the aMount page (`trustNewKey()`), e.g. after the computer's program
 * was reinstalled.
 */
class Client
{
    /** @var array<string, mixed>|null */
    private ?array $view = null;

    /**
     * The site folder the transfer running in this process writes into, with its quota and
     * use - set by Transfer for the file it is fetching, sent along with the read so the
     * computer knows this side's limit too.
     *
     * @var array{folder: string, quota: int, used: int}|null
     */
    public static ?array $destination = null;

    /**
     * @param array{relay_url?: ?string, site_mailbox?: bool, direct?: bool} $options
     */
    public function __construct(
        private readonly Gateway $gateway,
        private readonly string $computer,
        private readonly array $options = [],
        private readonly ?\Closure $log = null,
    ) {
    }

    /** @return array<string, mixed> the computer as GET /computers lists it */
    public function computer(): array
    {
        if ($this->view !== null) {
            return $this->view;
        }
        $wanted = mb_strtolower(trim($this->computer));
        foreach ($this->gateway->computers() as $c) {
            if ((string) $c['id'] === $wanted || mb_strtolower((string) $c['name']) === $wanted) {
                $this->check($c);

                return $this->view = $c;
            }
        }
        throw new ComputerException('computer_not_found', 'No computer called "' . $this->computer . '" in this ai.artur.work account (or this API key may not reach computers).');
    }

    /** @param array<string, mixed> $c */
    private function check(array $c): void
    {
        if (empty($c['files']) || (string) ($c['publicKey'] ?? '') === '') {
            throw new ComputerException('computer_files_off', 'The computer "' . $c['name'] . '" shares no folders (or its program is too old to).');
        }
        $pinned = self::pinned((int) $c['id']);
        if ($pinned === null) {
            self::pin((int) $c['id'], (string) $c['publicKey']);
        } elseif (!hash_equals($pinned, (string) $c['publicKey'])) {
            throw new ComputerException('key_changed', 'The key of the computer "' . $c['name'] . '" changed since this site first met it. '
                . 'If its program was reinstalled, confirm the new key on the aMount page; otherwise something is impersonating it.');
        }
    }

    public static function pinned(int $computerId): ?string
    {
        try {
            $v = DB::table('amount_state')->where('key', 'computer_pin_' . $computerId)->value('value');

            return is_string($v) && $v !== '' ? $v : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function pin(int $computerId, string $publicKey): void
    {
        DB::table('amount_state')->updateOrInsert(['key' => 'computer_pin_' . $computerId],
            ['value' => $publicKey, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /** A manager confirmed the computer's new key (its program was reinstalled). */
    public static function trustNewKey(int $computerId): void
    {
        DB::table('amount_state')->where('key', 'computer_pin_' . $computerId)->delete();
    }

    public function link(): Link
    {
        $mailbox = !empty($this->options['site_mailbox']) ? SiteAddress::publicUrl() : null;
        $relay = trim((string) ($this->options['relay_url'] ?? '')) ?: null;

        return new Link($this->gateway, $this->computer(), SiteIdentity::privateKey(), $mailbox, $relay, SiteAddress::label(),
            $this->log, ($this->options['direct'] ?? true) !== false);
    }

    /** @return array<int, array<string, mixed>> the shares, with this site's access, quota and use */
    public function shares(): array
    {
        return (array) ($this->link()->call('read', ['op' => 'shares'])['shares'] ?? []);
    }

    /** @return array{entries: array<int, array<string, mixed>>, truncated: bool} */
    public function list(string $share, string $path, bool $recursive = false, int $limit = 1000): array
    {
        $a = $this->link()->call('read', ['op' => 'list', 'share' => $share, 'path' => $path, 'recursive' => $recursive, 'limit' => $limit]);

        return ['entries' => (array) ($a['entries'] ?? []), 'truncated' => (bool) ($a['truncated'] ?? false)];
    }

    /** @return array{path: string, dir?: bool, size: int, mtime: int}|null */
    public function stat(string $share, string $path): ?array
    {
        try {
            return (array) ($this->link()->call('read', ['op' => 'stat', 'share' => $share, 'path' => $path])['entry'] ?? []);
        } catch (ComputerException $e) {
            if ($e->errorCode === 'not_found') {
                return null;
            }
            throw $e;
        }
    }

    /**
     * @param array{folder: string, quota: int, used: int}|null $dest the site folder it goes into, so the computer knows this side's quota
     * @return \Generator<int, string>
     */
    public function readChunks(string $share, string $path, ?array $dest = null): \Generator
    {
        $request = ['share' => $share, 'path' => $path];
        $dest ??= self::$destination;
        if ($dest !== null) {
            $request['dest'] = $dest;
        }

        return $this->link()->readChunks($request);
    }

    /**
     * @param resource $stream
     * @param string $onConflict "never" or "newer" - newer only when the caller may be destructive
     * @return array<string, mixed> written / replaced / unchanged
     */
    public function write(string $share, string $path, $stream, int $size, int $mtime, string $onConflict = 'never'): array
    {
        return $this->link()->write(['share' => $share, 'path' => $path, 'size' => $size, 'mtime' => $mtime,
            'onConflict' => $onConflict === 'newer' ? 'newer' : 'never'], static function () use ($stream): string {
                $piece = '';
                while (strlen($piece) < Seal::CHUNK && !feof($stream)) {
                    $read = fread($stream, Seal::CHUNK - strlen($piece));
                    if ($read === false || $read === '') {
                        break;
                    }
                    $piece .= $read;
                }

                return $piece;
            });
    }

    public function mkdir(string $share, string $path): void
    {
        $this->link()->call('write', ['op' => 'mkdir', 'share' => $share, 'path' => $path]);
    }

    public function delete(string $share, string $path): void
    {
        $this->link()->call('write', ['op' => 'delete', 'share' => $share, 'path' => $path]);
    }
}
