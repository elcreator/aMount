# AGENTS.md - aMount

Context file for AI coding agents. Package `elcreator/amount`, namespace `Elcreator\aMount\` -> `src/`, PHP 8.3+,
an **Evolution CMS 3.5+** plugin (`evolutioncms-plugin`). `docs/DESIGN.md` is the authoritative contract (hard security
rules, service APIs); `README.md` is the user-facing view. Read both before changing behaviour.

## What it is

A manager mounts remote storage (any Flysystem adapter), picks site folders ("rules") and files are uploaded in the
background, optionally deleted locally (`mode=move`), fetched back later, and kept reachable at their old URL when public.
Everything is bounded by what the manager may see in the file manager.

## Layout

```
config/aMount.php            merged under cms.settings.aMount
database/migrations/         tables amount_mounts|mount_groups|rules|files|runs|state; permissions amount, amount_manage
lang/en/global.php           the only locale edited by hand; other locales are produced by a translator afterwards
views/                       page.blade.php + partials (UI), denied.blade.php
src/aMountServiceProvider    register(): config + task type. boot(): migrations/lang/views, commands, scheduler, events, MCP, module
src/Http/routes.php          the route contract (relative to the module group; `mgr` middleware already applied)
src/Http/Controllers/        thin: request -> one service call -> JSON. Base Controller: respond()/fail()/ok()/presentRun()
src/Services/                Actor, Mounts, Rules, Files, Runs, Syncer, Transfer, Delivery, Serve, ServerConfig, AmountException
src/Queue/                   Dispatcher, SystemQueue (CMS task queue), STaskQueue (sTask)
src/Console/                 SyncHandler (task handler), TickCommand, ServerCommand, DriversCommand, DemoCommand
src/Workers/SyncWorker       sTask worker (file guarded: only loads when sTask exists)
src/Listeners/               NotFound (OnPageNotFound), Cleanup (OnManagerDeleteUser)
src/Drivers/                 one class per Flysystem adapter; Drivers::all()
src/Support/                 Config, Paths, Principal, Scope, Crypt, Lang, ProgressStream
src/Mcp/                     optional eMCP tools (soft dependency)
tests/                       Pest, in-memory SQLite built by the package's real migrations
ci/ .github/                 builds a full CMS with the plugin inside (Docker), smoke tests, zip
```

## Facts and traps

- **Permission boundary = `Support\Scope`**, built from `Support\Principal::forUser($id)` which reads the DB, never
  `$_SESSION` (the worker has none). The UI folder picker, `/local/check`, rule validation and the per-file check in the
  worker all use it. Never add a second implementation of "may this user see this path".
- **`/local/check` must not reveal existence outside the manager's root**: it runs `denyFile()` (no filesystem probing)
  first, and only then asks whether the path exists.
- **A stranger's personal mount is "not found", never "forbidden"** (and not even an administrator opens it);
  `Mounts::find()` enforces it, controllers must always go through it.
- **Secrets**: `Mounts::present()` masks them; `update()` keeps the stored value for a blank field. Controllers whitelist
  input keys and reject non-scalar values where scalars belong (`Controller::text()/flag()/listOf()/ids()`).
- **Task type is registered in `register()`**, not `boot()`: the consumer (`system:task-worker`) is a CLI process with
  no routes or manager. `mode = concurrent` on purpose (never block site updates). Guarded against double registration
  and a core without `SystemTaskRegistry`.
- **Scheduler**: `amount:tick` is added in `afterResolving(Schedule::class)` like the core's own tasks; the callback
  runs on every resolution, hence the "already scheduled" guard. `STaskQueue::ensureRegistered()` and
  `syncConfigMounts()` touch the DB, so they run only in console/manager requests, never on the front end.
- **`registerRoutingModule` refuses outside the backend**, so the routes exist only in the manager. The prefix is a hash
  of the module name: the page receives `baseUrl` and builds every URL from it; nothing may hardcode a module path.
- **CSRF**: every state change is a POST (also "delete"). The page gets `csrf_token` from `/bootstrap` and sends it as
  `X-CSRF-TOKEN`. `Controller::csrfToken()` returns '' when there is no session instead of throwing.
- **Never call `response()` in a controller**: the CMS defines its own helper that needs a booted container (null in
  tests). Use `new JsonResponse(...)`.
- **Translations outside a request**: use `Support\Lang::get($key, $replace)` (`AmountException::text()` wraps it). It
  falls back to the English file when the translator is missing or only echoes the key. Plain `__()` in a worker, a
  console command or a unit test breaks on an unbooted container. A test failure that once looked like
  "403 expected, 422 got" in `DeliveryTest` came from this class of problem.
- **Dynamic lang keys**: driver fields use `aMount::global.f_<field>` and `h_*`, drivers `driver_<id>_desc`; the
  translation test checks them against the drivers themselves. Add a key whenever a driver gains a field.
- **Backslashes**: never write a class name containing `\a` through a double-quoted shell/Python string (it becomes a
  BEL character). Use the editor tools. `composer.json` once shipped with invalid JSON for this reason.
- **No queued DB restore**: the CMS forbids it. A downloaded backup is restored by the CMS Backup manager.
- **Web-server rules** are generated by `Services\ServerConfig` (nginx include, Apache `# BEGIN aMount` block in
  `.htaccess`, nothing for FrankenPHP). `refresh()` only maintains what an administrator already applied.

## HTTP contract (JSON)

`{ok:true, ...}` or `{ok:false, error, message[, errors, candidates]}` with the refusal's HTTP status
(403 forbidden, 404 not found, 409 ambiguous/refused, 422 invalid with `errors` field => message, 502 store failure).
`GET /bootstrap` returns `drivers, mounts, foreign_mounts, rules, presets, runs, queue, server (administrators only, else
null), flags{may_manage,is_admin,use_udperms,serve_enabled}, user_id, groups (administrators only), limits, csrf_token`.
Runs are presented by `Controller::presentRun()`.

## Tests

The suite borrows an Evolution core's autoloader (no vendor dir here):

```
EVO_CORE_PATH_TEST=<core with dev deps> php $EVO_CORE_PATH_TEST/vendor/pestphp/pest/bin/pest --no-coverage
```

Never run composer in the CMS checkout. Helpers (`amountUser`, `amountMount`, `amountRule`, `amountSync`...) are in
`tests/helpers.php`; `tests/bootstrap.php` stubs `evo()` (`AmountTestCore::$loginUserId` is the manager session for
controller tests). Use the `local` driver for anything that moves bytes. `ControllerImportsTest` reads controller source
for un-imported classes; `TranslationsTest` checks every `aMount::global.*` key used under `src/` and `views/`.

## Conventions

English everywhere in code; docblocks say *why*; no commented-out code; `evo()` not `$modx`; user-visible strings go
through `aMount::global.*` and only `lang/en/global.php` is edited during implementation.
