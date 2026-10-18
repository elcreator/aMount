<?php

/**
 * aMount configuration, merged under `cms.settings.aMount`.
 *
 * Override any key from `core/custom/config/cms/settings/aMount.php`. Secrets
 * belong in the environment (or in `mounts` below), never in a database dump
 * you hand to someone.
 */
return [
    'enable' => (bool) env('AMOUNT_ENABLE', true),

    /*
     * Which queue carries the uploads.
     *   auto    sTask when it is installed, otherwise the CMS's own system
     *           task queue
     *   system  always the CMS's system task queue (system:task-worker)
     *   stask   always sTask (stask:worker)
     */
    'queue' => [
        'driver' => env('AMOUNT_QUEUE', 'auto'),
    ],

    'limits' => [
        // Wall-clock budget of one slice on the system queue. A slice never
        // abandons a file mid-upload: it stops between files and queues its
        // successor. sTask runs a whole run in one task and ignores this.
        'slice_seconds' => 240,
        'parallelism' => 2,
        // A run whose heartbeat is older than this is considered abandoned
        // (killed worker, rebooted host) and is queued again by `amount:tick`.
        'stale_after' => 900,
        'max_attempts' => 5,
        'history_days' => 60,
    ],

    'serve' => [
        // Answer requests for offloaded public files that no longer exist on
        // disk (OnPageNotFound). Needs the web server to hand missing files to
        // the CMS: see the Server tab, or `php artisan amount:server`.
        'enabled' => true,
        // auto: redirect when the mount has a public base URL, otherwise proxy.
        'mode' => 'auto',
        'cache_seconds' => 3600,
        // Lifetime of a signed link to a private file.
        'temporary_url_ttl' => 600,
    ],

    /*
     * "My computer" (the ai-computer driver: folders shared from the person's own computers
     * through ai.artur.work).
     *   public_url  this site's address for a computer to connect to (so the bytes skip the
     *               relay); the CLI worker cannot see it otherwise. A string, or with
     *               sMultisite a map of site key => URL. Null: worked out (sMultisite, the
     *               address the aMount page was opened at, a pinned EVO_SITE_URL).
     *   api_key     the ai.artur.work key used when a mount has none and aIMage has none.
     */
    'computers' => [
        'public_url' => env('AMOUNT_PUBLIC_URL'),
        'api_key' => env('AMOUNT_GATEWAY_KEY'),
    ],

    'server' => [
        // Where `amount:server --write` puts generated include files. Inside
        // core/, so it is never web-accessible. Null = core/custom/amount.
        'include_dir' => null,
    ],

    /*
     * Mounts defined in code or environment. Read-only in the manager, usable
     * by everyone holding `amount` (or only by the listed user groups), and
     * the place for credentials that must not live in the database. Example:
     *
     * 'mounts' => [
     *     'offsite' => [
     *         'name'    => 'Off-site backups',
     *         'driver'  => 's3',
     *         'config'  => ['bucket' => env('S3_BUCKET'), 'region' => 'eu-central-1',
     *                       'key' => env('S3_KEY'), 'secret' => env('S3_SECRET')],
     *         'options' => ['public_url' => null],
     *         'groups'  => [],   // user group ids; empty = everyone with `amount`
     *     ],
     * ],
     */
    'mounts' => [],
];
