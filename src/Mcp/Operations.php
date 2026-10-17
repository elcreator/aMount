<?php

namespace Elcreator\aMount\Mcp;

use Elcreator\aMount\Services\AmountException;
use Elcreator\aMount\Services\Delivery;

/**
 * What every MCP tool does, as plain functions over {@see Delivery}.
 *
 * Kept free of Laravel MCP on purpose: the tool classes only map the protocol
 * (schema, request, response) onto these methods, so the behaviour that matters
 * to an agent's safety - argument checks, bounds, the shape of an ambiguity -
 * runs in the ordinary test suite where eMCP is not installed.
 *
 * Nothing here accepts or returns a user id: the acting manager is fixed by the
 * Delivery instance, which {@see AmountTool} builds from the identity eMCP
 * established for the request, never from an argument the agent could choose.
 */
final class Operations
{
    /** @param array<string, mixed> $a */
    public static function mounts(Delivery $d, array $a): array
    {
        $hint = self::text($a, 'name');

        return $hint === '' ? ['mounts' => $d->mounts()] : ['mount' => $d->resolveMount($hint)];
    }

    /** @param array<string, mixed> $a */
    public static function browse(Delivery $d, array $a): array
    {
        return $d->browse(self::required($a, 'mount'), self::text($a, 'path'));
    }

    /** @param array<string, mixed> $a */
    public static function deliver(Delivery $d, array $a): array
    {
        return $d->deliver(
            self::required($a, 'path'),
            self::text($a, 'mount') ?: null,
            self::text($a, 'folder') ?: null,
            ['move' => self::flag($a, 'move'), 'public' => self::flag($a, 'public'), 'origin' => 'mcp']
        );
    }

    /** @param array<string, mixed> $a */
    public static function fetch(Delivery $d, array $a): array
    {
        return $d->fetch(
            self::required($a, 'mount'),
            self::required($a, 'remote'),
            self::required($a, 'to'),
            ['overwrite' => self::flag($a, 'overwrite'), 'delete_remote' => self::flag($a, 'delete_remote'), 'origin' => 'mcp']
        );
    }

    /** @param array<string, mixed> $a */
    public static function status(Delivery $d, array $a): array
    {
        return $d->status(self::required($a, 'run'));
    }

    /** @param array<string, mixed> $a */
    public static function runs(Delivery $d, array $a): array
    {
        return $d->runs(self::flag($a, 'active'), self::text($a, 'rule') ?: null);
    }

    /** @param array<string, mixed> $a */
    public static function cancel(Delivery $d, array $a): array
    {
        return $d->cancel(self::required($a, 'run'));
    }

    /** @param array<string, mixed> $a */
    public static function rules(Delivery $d, array $a): array
    {
        return $d->rules();
    }

    /** @param array<string, mixed> $a */
    public static function files(Delivery $d, array $a): array
    {
        return $d->files(
            self::required($a, 'rule'),
            ['status' => self::text($a, 'status'), 'q' => self::text($a, 'q')],
            max(1, (int) ($a['page'] ?? 1))
        );
    }

    /** @param array<string, mixed> $a */
    public static function recall(Delivery $d, array $a): array
    {
        $ids = is_array($a['files'] ?? null) ? $a['files'] : [];

        return $d->recall($ids, self::text($a, 'to') ?: null, self::flag($a, 'overwrite'), self::flag($a, 'delete_remote'));
    }

    /** @param array<string, mixed> $a */
    public static function deleteRemote(Delivery $d, array $a): array
    {
        return $d->deleteRemote((int) ($a['file'] ?? 0), self::flag($a, 'confirm'));
    }

    /** @param array<string, mixed> $a */
    public static function measure(Delivery $d, array $a): array
    {
        return $d->measure(self::text($a, 'mount') ?: null, self::text($a, 'rule') ?: null, self::text($a, 'path') ?: null, self::flag($a, 'local'));
    }

    /**
     * The one-line error an agent sees. An ambiguity carries its candidates
     * (uuid, name, driver only) so the agent can ask the person which one was
     * meant and call again with the uuid; the structured copy would be lost in
     * the validation error the protocol layer turns this into.
     */
    public static function explain(AmountException $e): string
    {
        if ($e->candidates === []) {
            return $e->getMessage();
        }

        $list = array_map(static fn (array $c) => array_intersect_key($c, array_flip(['uuid', 'name', 'driver', 'scope'])), $e->candidates);

        return $e->getMessage() . ' candidates=' . json_encode(array_values($list), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $a */
    private static function text(array $a, string $key): string
    {
        $value = $a[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<string, mixed> $a */
    private static function required(array $a, string $key): string
    {
        $value = self::text($a, $key);

        if ($value === '') {
            throw AmountException::fields([$key => AmountException::text('aMount::global.error_field_required')]);
        }

        return $value;
    }

    /** @param array<string, mixed> $a */
    private static function flag(array $a, string $key): bool
    {
        return filter_var($a[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
