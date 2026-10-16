# aMount — design and contracts

Package `elcreator/amount`, namespace `Elcreator\aMount\` → `src/`, PHP 8.3+, an **Evolution CMS 3.5+** plugin
(`evolutioncms-plugin`). Read this before touching code: parallel authors rely on the contracts below.

Reference implementations to imitate (same author, same conventions — read, do not edit):
- `C:\projects\Opensource\evo\aIMage` — module page, service provider, `JobQueue`/`BatchHandler` on the CMS task
  queue, `ImageScope`, `tests/bootstrap.php` + `tests/helpers.php`, `ci/`.
- `C:\projects\Opensource\evo\sTask` — the alternative queue manager.
- CMS sources: `C:\projects\Opensource\evo\evolution` (see its `AGENTS.md`: file manager access rules, CSRF rules).

## What it does

A manager mounts remote storage (any Flysystem adapter) and picks folders ("rules") to offload. Big files
(backups, media) are uploaded **in the background**, later fetched **back** in the background (to restore a
backup), optionally deleted locally after upload (`mode=move`) and/or on the store (retention, mirror, "delete
after download"). Offloaded **public** files keep their URL (redirect/stream from the store when the local file
is gone); **private** ones are downloadable only by managers who may see the original.

## Hard rules (security / correctness)

1. **A manager can only offload what they can see in the file manager.** Every folder and file is checked by
   `Support\Scope` (root = `filemanager_path`, folders protected by permission, `file_groups` with `use_udperms`,
   no links). Checked when a rule is saved **and again on every run, per file**, for the rule's `owner_id`, as the
   account stands *now* (`Support\Principal::forUser()` reads the DB, never `$_SESSION`). UI and worker use the same code.
2. Personal mounts (`owner_id` set) are usable only by their owner. Their credentials never leave the server:
   API responses show secrets masked. Another administrator may delete a personal mount, never open it.
3. Site mounts (`owner_id` null) are usable by managers holding `amount` (and, when `amount_mount_groups` has rows,
   belonging to one of those user groups). A manager without `amount_manage` is jailed under the remote prefix
   `u<userId>/` on a site mount. Config-defined mounts (`options.source=config`) behave as site mounts and are read-only.
4. Permissions: `amount` (use), `amount_manage` (administer: site mounts, all rules, server config). Role 1 has both.
   A queued run re-checks the owner with `Principal::mayUse()` (exists, not blocked, role holds `amount`) before work.
5. Downloads back: destination must pass `Scope` (directory visible, `canCreateIn()`), the file name must not be an
   executable/server-config name unless the owner may run code, never overwrite unless asked **and** `canModify()`,
   write to `<name>.amount-part` then rename, verify size, `touch()` to the entry's mtime (so the next scan does not
   re-upload it). There is **no queued DB restore** (the CMS forbids it): a downloaded backup is restored with the
   CMS's own Backup manager (action 93).
6. CSRF: every non-GET route carries `X-CSRF-TOKEN`/`_token` (the `mgr` middleware fails closed).
7. Never put secrets in logs, responses or exception messages. Credentials are encrypted at rest (`Support\Crypt`).
8. A file's identity is `path_hash = sha1(site path)`; never index long paths. Site paths are validated by `Paths::clean()`.
9. A step must be safe to run twice (a worker can die anywhere): upload to the final key is idempotent; local deletion
   happens only after the remote size is verified AND the local file is unchanged (size+mtime) since it was read.

## Already written (do not rewrite; extend only if a contract below says so)

```
composer.json  config/aMount.php
database/migrations/…000001_create_amount_tables.php   amount_mounts, amount_mount_groups, amount_rules,
                                                       amount_files, amount_runs, amount_state
database/migrations/…000002_add_amount_permissions.php permissions amount / amount_manage
src/Support/  Config  Crypt  Paths  Principal  Scope
src/Models/   Mount  Rule  FileEntry  Run
src/Drivers/  Driver Drivers OAuthToken + S3 AsyncS3 Sftp Ftp WebDav Local Gcs Azure GoogleDrive Dropbox
              OneDrive Bunny GridFs Zip Aliyun Cos Qiniu drivers
```
Read these files first — their docblocks are the spec for behaviour. Notable APIs:
`Scope::for(Principal)`/`forUser(int)`: `denyDirectory()/denyFile()` (null = allowed, else reason code), `canModify()`,
`files($absDir, $recursive)` generator of `['path','size','mtime']`, `folders($absDir)` (site paths, visible only).
`Principal`: `mayUse() mayManage() can() isAdministrator() userGroups() documentGroups() active root`.
`Mount::credentials() setCredentials() filesystem() options() option() publicUrl() grantedGroups() grantGroups()`.
`Drivers::get($id)->filesystem($creds,$opts)`, `->writeConfig($opts,$visibility)`, `->describe()`, `->validate()`.
`Paths::clean() absolute() relative() remote() hash() matches() bytes()`.

## To build (contracts)

### Wave 1A — engine (queue, runs, syncer, transfers)  — files under `src/Queue`, `src/Services` (Runs, Syncer, Transfer), `src/Workers`, `src/Console`

- `Services\Runs` — `__construct()`. `start(Rule $rule, int $userId, string $trigger='manual'): Run` (refuses with
  `RuntimeException` if the rule has an active run, is disabled, or the user may not use the module; creates the
  `Run` and calls `Queue\Dispatcher::dispatch()`); `recall(array $entryIds, int $userId, array $options): Run`
  (options: `to` = site folder or null for the original place, `overwrite` bool, `delete_remote` bool; validates
  that the user may see the entries' rule folder/destination; creates a `direction=down` Run with `payload_json`);
  `cancel(Run $run): void` (sets status cancelled — cooperative, checked per file/per chunk by Syncer);
  `reapStale(): int` (active runs with `heartbeat_at` older than `limits.stale_after` → re-dispatch);
  `tick(): array` (due rules by `next_run_at` → `start(..., 'schedule')`, stale reaping, history purge by
  `limits.history_days`; returns counters). Computing `next_run_at = now + interval_minutes` happens when a run ends.
- `Queue\Queue` interface: `name(): string`, `available(): bool`, `health(): array{ok:bool,message:string}`,
  `dispatch(Run $run): void` (sets `runs.queue`, `runs.task_id`). Implementations:
  - `Queue\SystemQueue` — CMS task queue. Row in `system_cli_tasks` (see aIMage `Support\JobQueue`), type
    `amount.sync`, `target` = run uuid; successor slices as in aIMage `BatchHandler`.
    `Console\SyncHandler implements EvolutionCMS\Interfaces\SystemTaskHandlerInterface`, `TYPE = 'amount.sync'`.
    Registered by the provider (wave 2) with `SystemTaskRegistry::register(TYPE, SyncHandler::class,
    ['mode'=>CONCURRENT,'parallelism'=>Config::limit('parallelism',2),'permissions'=>['amount'],'label'=>'aMount transfer'])`.
  - `Queue\STaskQueue` — sTask (`Seiger\sTask`). Worker `Workers\SyncWorker extends Seiger\sTask\Workers\BaseWorker`,
    identifier `aMountSync`, action `make` (`taskMake`), **file guarded** like the CMS's own
    `core/src/Workers/DatabaseBackup.php` (`if (!class_exists(BaseWorker::class)) return;`), `createTask` override that
    creates the task with `meta = ['run_uuid' => …]` and sets `started_by`; `taskMake` runs the whole run in one task
    (no slicing: `work(null)`), re-checking the owner as the CMS worker does (`started_by` as the account stands now).
    sTask has no cancel and its duplicate guard would return the running task for identical meta, so cancellation and
    progress live in `amount_runs` and a run is never re-queued from inside itself. Register + activate the worker:
    `Queue\STaskQueue::ensureRegistered()` (idempotent; uses `Seiger\sTask\Services\WorkerDiscovery::registerWorker()`
    then sets `active=true` on `Seiger\sTask\Models\sWorker`).
  - `Queue\Dispatcher` — static `driver(): Queue` by `Config::queueDriver()`: `auto` = sTask when installed and
    usable, else system; `system`/`stask` force; `status(): array` for the UI (driver, health of both, hints such as
    "run `php core/artisan system:task-worker`" / the cron line for `schedule:run`).
- `Services\Syncer` — `__construct(Run $run, ?callable $report = null)`; `work(?float $budgetSeconds): bool`
  (true = finished: done/failed/cancelled; false = budget spent, more to do → caller queues a successor).
  Up runs: **scan** (once, phase `scanning`): `Scope::files()` over the rule folder (a rule `local_path` may also be a
  single file), globs via `Paths::matches()`, `min_age`/`min_size`/`max_size`, upsert `FileEntry` rows by
  `(rule_id,path_hash)`: new/changed (size or mtime differ) → `pending`, unchanged+uploaded → touch `seen_at`;
  `mirror_deletes`: uploaded entries whose local file vanished (and `local_removed=false`) → delete remote, `expired`;
  `retention_days`: uploaded entries older than N days → delete remote, `expired`. Totals into the Run.
  **upload** (phase `uploading`): pending/failed(attempts<max) entries in order; `Mount::filesystem()->writeStream()`
  with `Driver::writeConfig()`; stream wrapped in a progress filter (`Support\ProgressStream` or php_user_filter) that
  updates `bytes_done`, `current_file`, `heartbeat_at` (throttled ~2 s), calls `$report`, and **aborts when the run
  is cancelled**; verify with `fileSize()`; then, for `mode=move`, delete the local file only if
  `Scope::canModify()` and size+mtime unchanged, set `local_removed=true`. Failures: `attempts++`, `error`, continue.
  Check the owner (`Principal`) and mount status at start; fail the run with a clear message if the owner lost access.
  Remote key = `Paths::remote(prefix, below)` where the prefix is `mount option root` + rule `remote_prefix`
  (+ `u<id>/` jail for non-managers on site mounts — expose this as `Syncer::remotePrefix(Rule,Mount,Principal)` static,
  Wave 1B reuses it). Down runs: see `Services\Transfer`.
- `Services\Transfer` — the download-back worker used by `Syncer` for `direction=down`: per entry, stream remote →
  `<dest>.amount-part` → verify → rename → `touch`; honours rule 5 above; `delete_remote` removes the remote object and
  marks the entry `expired` after verification; progress/cancel as for uploads. Pure functions where possible so they
  are unit-testable with the `local` driver.
- `Console\TickCommand` — `amount:tick` (signature), scheduled every minute by the provider: `Runs::tick()`.

### Addendum — measuring usage (`direction = size`), added after Wave 1A/1B started

"How much space does this take?" is asked on demand, asynchronously, because listing a big bucket takes minutes
(the pattern comes from `C:\projects\bazadomu-com\modules\Admin\Controllers\BucketStatsController.php`: a cached
snapshot rendered instantly, a click starts the count, progress is persisted after every listing page, a run with no
heartbeat for too long is healed to `error`, the page polls).
- Third `Run.direction`: `size`. `payload_json = {target: 'mount'|'rule'|'local', mount: uuid, rule: uuid|null, path: ''}`.
  `Services\Runs::measure(Mount|Rule $target, int $userId, ?string $path = null): Run` (A). `Syncer::work()` runs it:
  `listContents($prefix, deep: true)` page by page, sums `fileSize`, groups by the first path segment below the prefix
  (rows: `{label, bytes, files, partial}`), writes live progress into `bytes_done/files_done/current_file/heartbeat_at`
  and the final `usage_json = {bytes, files, measured_at, rows[]}` on the mount (whole mount) or the rule (its remote
  prefix). A `local` target sums the rule folder through `Scope::files()` (so it counts exactly what the manager may see).
  Cheap answers need no run: `Files::indexTotals()` (B) sums `amount_files` for a rule/mount and is shown first; the click
  refines it with the store's real numbers (objects not created by aMount are counted too).
- A measurement never writes or deletes anything; it needs the same access as a read of the mount/rule.

### Wave 1B — access, API and serving — files under `src/Services` (Actor, Mounts, Rules, Files, Delivery, Serve, ServerConfig, AmountException), `src/Listeners`, `src/Mcp`, `src/Console`

- `Services\AmountException extends RuntimeException` — `error` code string, translated message (`__('aMount::global.…')`),
  HTTP `status`; static helpers `forbidden() notFound(string $what) invalid(string $key, array $replace=[])`.
- `Services\Actor` — `Actor::current()` (session user via `evo()->getLoginUserID('mgr')`) and `Actor::for(int $userId)`;
  `userId() principal() scope() mayUse() mayManage() authorize()`.
- `Services\Mounts(Actor)` — `visible(): Collection<Mount>` (own personal + site mounts the actor may use; managers see
  every site mount; config mounts included via `syncConfigMounts()` static which upserts metadata rows with
  `options.source=config`); `find(string $uuid): Mount` (throws not found/forbidden — never reveals a stranger's personal mount);
  `create(array $in): Mount`, `update(Mount,array)` (blank secret field keeps the stored value), `delete(Mount)`
  (refuses while rules use it unless `force`; removes its index rows; a non-owner administrator may delete personal ones);
  `test(Mount): array{ok:bool,message:string}` (writes/reads/deletes a probe key `.amount-probe-<rand>`, updates
  `status/last_error/last_checked_at`); `browse(Mount $m, string $path): array` (remote sub-folders only);
  `resolve(?string $hint): array{mount:?Mount,candidates:Mount[]}` (matches name/driver/slug case-insensitively, e.g. "google drive",
  "my dropbox"; one candidate wins, several → ambiguous, none → empty); `present(Mount): array` (API shape: uuid, name, driver,
  scope personal|site|config, status, options, config with secrets masked via `Driver::fields()` `secret` flags, `credentials_unreadable`).
- `Services\Rules(Actor)` — `list()`, `find($uuid)`, `create(array)`, `update(Rule,array)`, `delete(Rule)` and
  `validate(array): array` enforcing: mount usable by the actor; `local_path` is a clean site path that exists and passes
  `Scope::denyDirectory()` (or `allowsFile()` for a file); `mode=move`/`mirror_deletes` need `Scope::canModify()`-capable
  access (`file_manager`); public visibility only for paths under the web root; remote prefix cleaned; intervals ≥ 5 min or 0;
  `owner_id` = the actor (only `mayManage()` may set another). `presets(): array` — ready-made suggestions limited to
  what the actor may see: database backups (`snapshot_path` setting, needs `bk_manager`), `assets/images`, `assets/files`,
  `assets/media`… Saving a rule that has public visibility triggers `ServerConfig::refresh()`.
- `Services\Files(Actor)` — `entries(Rule $rule = null, array $filters, int $page): array` (paginated `FileEntry` for
  rules the actor owns / `mayManage()`), `delete(FileEntry)` (remote + row; needs rule ownership), `present()`.
- `Services\Delivery` (public API for other packages, e.g. **AIMage** "put the result on my Google Drive"):
  `Delivery::for(int $userId)` → `mounts(): array` (what this user can deliver to, with driver label/scope),
  `resolveMount(?string $hint)`, `browse(string $mountUuid, string $path)`, `deliver(string $localSitePath, ?string $mountHint = null,
  ?string $remoteFolder = null, array $options = []): array{run: string uuid, mount: string, remote: string}` — creates an
  `ephemeral` rule (`origin` from options, default `api`; `mode` copy unless `move` requested; visibility private unless
  `public`), validates with `Scope` for that user, starts the run through `Runs::start()`; ambiguous/unknown mount →
  `AmountException` carrying `candidates` so an AI can ask which one; `status(string $runUuid): array`; `fetch(string $mountHint,
  string $remotePath, string $toFolder, array $options)` (download back). Also static facade `Facades\aMount` proxying
  `Delivery::for(...)`.
- `Mcp\ToolProvider` + `Mcp\Tools\*` — optional eMCP integration exactly like aIMage's `src/Mcp` (soft dependency, only
  registered when eMCP classes exist): tools `amount.mounts`, `amount.browse`, `amount.deliver`, `amount.status`, `amount.fetch`.
- `Services\Serve` — resolves a request path for an offloaded file: `Serve::find(string $sitePath): ?FileEntry` (uploaded,
  rule enabled, mount ok, **public**), `Serve::publicResponse(FileEntry)` (redirect to `Mount::publicUrl()/<remote>` or the
  adapter's `publicUrl()`, else proxy-stream; `Config::serveMode()`; proper `Content-Type`, `ETag` from size+mtime, `Cache-Control`,
  **Range** for proxying), `Serve::privateResponse(FileEntry, Actor)` (downloads: needs the actor to pass
  `Scope` for the original local path and own the rule or `mayManage()`; temporary URL when the adapter supports it
  (`Filesystem::temporaryUrl`), else stream; `Content-Disposition: attachment`). Never serve a private entry from the
  NotFound listener.
- `Listeners\NotFound` — handler for the CMS event `evolution.OnPageNotFound` (registered by the provider):
  maps `REQUEST_URI` (minus the site base path, query dropped, `Paths::clean()`) to `Serve::find()`; answers and exits, or
  returns without touching anything; also answers the probe path `…/amount-probe-<token>.txt` (token from
  `amount_state`) with HTTP 200 and header `X-aMount: ok`, with no database lookup beyond the token.
- `Services\ServerConfig` — web-server integration so offloaded public files keep their URLs. `detect(): string`
  (`nginx|apache|frankenphp|unknown` from `SERVER_SOFTWARE`/`PHP_SAPI`), `directories(): array` (top-level directories of
  every enabled **public** rule, e.g. `assets`, `uploads`), `render(string $server, array $dirs): string` for
  nginx / apache / frankenphp (see below), `status(): array` (detected server, required dirs, fingerprint of what was last
  applied in `amount_state`, `drift` bool, include path, instructions), `write(string $server): array` (nginx include file
  and Caddy snippet into `Config::includeDir()`; apache: managed block between `# BEGIN aMount` / `# END aMount` in the
  docroot `.htaccess`, inserted before the line `RewriteRule ^(manager|assets|js|css|images|img)/.*$ - [L]`, backup
  `.htaccess.amount.bak`, idempotent), `refresh(): void` (called when public rules change; rewrites files when writable and
  records the new fingerprint), `probe(string $dir): array` (HTTP request to `<site_url>/<dir>/amount-probe-<token>.txt`
  with a short timeout; reports whether the server forwards missing files to the CMS).
  Facts about the servers (verified from the CMS sources):
  * **nginx** (`ng.inx`): static `location ~* \.(jpg|…|zip|gz|…)$` returns 404 for missing files *before* the CMS is
    asked, and `assets/(cache|backup|export|import)` and dump/archive extensions are denied. The snippet is a regex
    location listing the top-level directories — `location ~ ^/(assets/(?!cache/|backup/|export/|import/)|uploads/) { try_files $uri @rewrite; expires max; access_log off; }`
    (`@rewrite` already exists in `ng.inx`) — to be included **after the deny rules and before the static-files regex**.
  * **apache** (`ht.access`): `RewriteRule ^(manager|assets|js|css|images|img)/.*$ - [L]` stops friendly URLs for
    `/assets`; the block adds, before it, `RewriteCond %{REQUEST_FILENAME} !-f` + `RewriteRule ^(assets/(?!cache/|backup/|export/|import/)|uploads/).+$ index.php?q=$0 [L,QSA]`.
  * **frankenphp/Caddy** (Salo `Caddyfile`): `@notfile` + `rewrite @notfile /index.php?q={path}&{query}` already sends
    every missing path to the CMS: **nothing to change**; only the `@denied` matcher blocks `.php` under some `assets/*` folders.
  Private files (backups) are never served by the web server; they are denied by the existing rules and downloaded through the
  authenticated manager endpoint.
- `Console\ServerCommand` (`amount:server {server?} {--write} {--probe}`), `Console\DriversCommand` (`amount:drivers`: table of
  drivers, installed or not, with install command), `Console\DemoCommand` (`amount:demo`: seeds a local-driver demo mount "Demo cloud",
  sample files under `assets/amount-demo/`, one public rule, one backup-like private rule, optionally runs them synchronously
  for demo purposes — must be idempotent).
- `Listeners\Cleanup` — remove a deleted user's personal mounts/rules/index rows; remove a deleted user group from
  `amount_mount_groups`.

### Wave 2 — HTTP, UI, provider, CI (after wave 1)
- `aMountServiceProvider`, `routes.php` (manager module via `registerRoutingModule`), controllers (thin), Blade page
  with tabs *Mounts / Folders / Files & activity / Server*, JS, CSS, `lang/en/global.php` (English only; other locales are
  produced afterwards by a translator agent), docs, `ci/`, Docker demo.

## Tests

Pest, database-backed (SQLite in memory, schema from the package's **own migrations**), like aIMage. The tests borrow a
CMS core's autoloader: set `EVO_CORE_PATH_TEST` to a core with dev dependencies installed. A prepared copy lives at
`C:\Users\User1\AppData\Local\Temp\claude\C--projects-Opensource-evo-aMount\d852857d-f8ad-4821-81de-dd12b8eeaec3\scratchpad\evo-core`
(do **not** run composer in `C:\projects\Opensource\evo\evolution`; it has a committed vendor tree).
Run: `php <core>/vendor/pestphp/pest/bin/pest --no-coverage` from the package root. Use the `local` driver for transfer tests
(no network). Test the permission boundary hardest: invisible folders/files are never uploaded, a revoked permission stops
the next run, a personal mount is invisible to others, downloads never overwrite or write executables.

## Conventions
- Match the surrounding code: docblocks explain *why*; no commented-out code; English everywhere in code and comments.
- Use the editor/Write tools to create files — **never** build files with shell heredocs (this Windows shell breaks on them).
  Use absolute Windows paths. Do not `git commit`. Do not touch other repositories.
- User-visible strings go through `__('aMount::global.key')`; add every key you use to `lang/en/global.php` (append; keep it sorted by section).
- Evolution facts: use `evo()`, never `$modx`; manager routes live under the `mgr` middleware (CSRF fails closed).
