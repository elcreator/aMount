<?php

use Elcreator\aMount\Drivers\Drivers;
use Elcreator\aMount\Models\FileEntry;
use Elcreator\aMount\Models\Mount;
use Elcreator\aMount\Models\Rule;
use Elcreator\aMount\Models\Run;
use Elcreator\aMount\Services\Syncer;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

/**
 * Shared setup for the database-backed suite. Helpers are prefixed `amount` so
 * they never collide with another package's, and are meant to be reused by every
 * test file.
 *
 * The aMount tables are built by running this package's **real migrations**;
 * the CMS tables it reads belong to the core, so only the columns aMount
 * touches are declared here.
 */

function amountBoot(): void
{
    static $booted = false;

    if ($booted) {
        return;
    }

    $booted = true;

    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    // Before any model boots: the models fill their uuid in a `creating` listener.
    $capsule->setEventDispatcher(new Illuminate\Events\Dispatcher(new Container()));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    Model::setConnectionResolver($capsule->getDatabaseManager());

    $container = new Container();
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->schema());
    // The CMS's own __() helper asks the container for a translator; the message
    // text is not what these tests are about, so the key is echoed back.
    $container->instance('translator', new class {
        public function get($key, array $replace = [], $locale = null)
        {
            return (string) $key;
        }
    });
    $container->bind('config', static fn () => AmountTestCore::config());
    Container::setInstance($container);
    Facade::setFacadeApplication($container);

    amountCreateCmsTables($capsule);

    $files = glob(dirname(__DIR__) . '/database/migrations/*.php') ?: [];
    sort($files);

    foreach ($files as $file) {
        (require $file)->up();
    }
}

/** The core tables aMount reads, reduced to the columns it uses. */
function amountCreateCmsTables(Capsule $capsule): void
{
    $schema = $capsule->schema();

    $schema->create('users', function (Blueprint $table) {
        $table->increments('id');
        $table->string('username', 100)->default('');
    });

    $schema->create('user_attributes', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('internalKey');
        $table->integer('role')->default(0);
        $table->string('fullname', 100)->default('');
        $table->integer('blocked')->default(0);
        $table->integer('blockeduntil')->default(0);
        $table->integer('blockedafter')->default(0);
    });

    $schema->create('user_settings', function (Blueprint $table) {
        $table->unsignedInteger('user');
        $table->string('setting_name', 50);
        $table->text('setting_value')->nullable();
    });

    $schema->create('system_settings', function (Blueprint $table) {
        $table->string('setting_name', 50)->primary();
        $table->text('setting_value')->nullable();
    });

    $schema->create('member_groups', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('user_group')->default(0);
        $table->unsignedInteger('member')->default(0);
    });

    $schema->create('membergroup_access', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('membergroup')->default(0);
        $table->unsignedInteger('documentgroup')->default(0);
    });

    $schema->create('permissions_groups', function (Blueprint $table) {
        $table->increments('id');
        $table->string('name', 100)->unique();
        $table->string('lang_key', 100)->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });

    $schema->create('permissions', function (Blueprint $table) {
        $table->increments('id');
        $table->string('key', 100)->unique();
        $table->string('name', 100)->nullable();
        $table->string('lang_key', 100)->nullable();
        $table->unsignedInteger('group_id')->nullable();
        $table->boolean('disabled')->default(false);
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });

    $schema->create('role_permissions', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('role_id');
        $table->string('permission', 100);
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });

    $schema->create('file_groups', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('document_group')->default(0);
        $table->string('file', 500)->default('');
    });

    $schema->create('system_cli_tasks', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid', 36)->unique();
        $table->string('type', 64)->default('');
        $table->string('target', 191)->default('');
        $table->string('requested_version', 191)->default('');
        $table->string('status', 32)->default('queued');
        $table->string('step', 64)->default('');
        $table->unsignedSmallInteger('progress')->default(0);
        $table->string('message', 255)->default('');
        $table->text('payload_json')->nullable();
        $table->text('result_json')->nullable();
        $table->unsignedInteger('created_by')->nullable();
        $table->string('locked_by', 191)->default('');
        $table->unsignedInteger('attempt_count')->default(0);
        $table->dateTime('lease_expires_at')->nullable();
        $table->string('worker_host', 191)->default('');
        $table->integer('worker_pid')->nullable();
        $table->string('error_code', 64)->default('');
        $table->string('catalog_snapshot_hash', 64)->default('');
        $table->text('requested_by_snapshot')->nullable();
        $table->dateTime('started_at')->nullable();
        $table->dateTime('heartbeat_at')->nullable();
        $table->dateTime('cancellation_requested_at')->nullable();
        $table->dateTime('finished_at')->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();
    });
}

/** Put the world back between tests. */
function amountReset(): void
{
    foreach ([
        'amount_files', 'amount_runs', 'amount_rules', 'amount_mount_groups', 'amount_mounts', 'amount_state',
        'users', 'user_attributes', 'user_settings', 'system_settings', 'member_groups', 'membergroup_access',
        'role_permissions', 'file_groups', 'system_cli_tasks',
    ] as $table) {
        if (Schema::hasTable($table)) {
            Capsule::table($table)->delete();
        }
    }

    AmountTestCore::reset();
    Drivers::reset();

    amountRemoveTree(AMOUNT_TEST_ROOT . '/assets');
    amountRemoveTree(AMOUNT_TEST_REMOTE);
    amountEnsureDir(AMOUNT_TEST_ROOT . '/assets/files');
    amountEnsureDir(AMOUNT_TEST_REMOTE);
}

function amountEnsureDir(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function amountRemoveTree(string $path): void
{
    if (is_link($path)) {
        // A Windows junction is a directory entry: rmdir removes the link, not its target.
        is_dir($path) && PHP_OS_FAMILY === 'Windows' ? rmdir($path) : unlink($path);

        return;
    }

    if (!is_dir($path)) {
        file_exists($path) && @unlink($path);

        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        amountRemoveTree($path . '/' . $entry);
    }

    @rmdir($path);
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/**
 * A manager with a role, the permissions that role holds, and optional
 * document groups. Role 1 is the super administrator (all permissions, exempt
 * from file groups); any other role gets exactly `$permissions`.
 *
 * @param string[] $permissions
 * @param int[] $documentGroups
 * @param string|null $root the user's own `filemanager_path`, relative to the site (e.g. `assets`)
 */
function amountUser(int $id, array $permissions = ['amount', 'file_manager'], array $documentGroups = [], ?int $role = null, ?string $root = null): int
{
    $role ??= 100 + $id;

    Capsule::table('users')->insert(['id' => $id, 'username' => 'user' . $id]);
    Capsule::table('user_attributes')->insert(['internalKey' => $id, 'role' => $role, 'fullname' => 'Manager ' . $id]);

    foreach ($permissions as $permission) {
        Capsule::table('role_permissions')->insert(['role_id' => $role, 'permission' => $permission]);
    }

    foreach ($documentGroups as $group) {
        $userGroup = 100 + $group;
        Capsule::table('member_groups')->insert(['user_group' => $userGroup, 'member' => $id]);
        Capsule::table('membergroup_access')->insert(['membergroup' => $userGroup, 'documentgroup' => $group]);
    }

    if ($root !== null) {
        Capsule::table('user_settings')->insert([
            'user' => $id,
            'setting_name' => 'filemanager_path',
            'setting_value' => '[(base_path)]' . trim($root, '/'),
        ]);
    }

    return $id;
}

/** An administrator (role 1). */
function amountAdmin(int $id = 1): int
{
    return amountUser($id, [], [], 1);
}

/** Take a permission away from a user's role, as an administrator would. */
function amountRevoke(int $userId, string $permission): void
{
    $role = (int) Capsule::table('user_attributes')->where('internalKey', $userId)->value('role');
    Capsule::table('role_permissions')->where('role_id', $role)->where('permission', $permission)->delete();
}

/** Restrict a site path to a document group, as the file manager's group editor does. */
function amountRestrict(string $relative, int $documentGroup): void
{
    Capsule::table('file_groups')->insert(['document_group' => $documentGroup, 'file' => trim($relative, '/')]);
}

/**
 * Write files into the fake site. Values are contents, or [contents, mtime].
 * Files are made an hour old unless told otherwise, so `min_age` rarely matters.
 *
 * @param array<string, string|array{0: string, 1: int}> $files site path => contents
 */
function amountSite(array $files): void
{
    foreach ($files as $path => $spec) {
        [$contents, $mtime] = is_array($spec) ? $spec : [$spec, time() - 3600];

        $absolute = AMOUNT_TEST_ROOT . '/' . ltrim($path, '/');
        amountEnsureDir(dirname($absolute));
        file_put_contents($absolute, $contents);
        touch($absolute, $mtime);
    }
}

function amountSitePath(string $relative = ''): string
{
    return rtrim(AMOUNT_TEST_ROOT . '/' . ltrim($relative, '/'), '/');
}

/** A mount on the local driver, backed by a fresh folder outside the site tree. */
function amountMount(?int $ownerId = null, array $options = [], string $name = 'Test mount'): Mount
{
    static $counter = 0;
    $counter++;

    $root = AMOUNT_TEST_REMOTE . '/mount' . $counter;
    amountEnsureDir($root);

    $mount = new Mount([
        'uuid' => Mount::newUuid(),
        'name' => $name,
        'driver' => 'local',
        'owner_id' => $ownerId,
        'options_json' => $options,
        'status' => Mount::STATUS_OK,
    ]);
    $mount->setCredentials(['root' => $root]);
    $mount->save();

    return $mount;
}

/** The folder a local-driver mount stores into. */
function amountRemoteRoot(Mount $mount): string
{
    return rtrim((string) ($mount->credentials()['root'] ?? ''), '/');
}

/** @return string[] remote keys (relative to the mount root), sorted */
function amountRemoteFiles(Mount $mount): array
{
    $root = amountRemoteRoot($mount);
    $found = [];

    if (!is_dir($root)) {
        return [];
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $found[] = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($root)), '/');
        }
    }

    sort($found);

    return $found;
}

/** A rule with test-friendly defaults: nothing has to age before it is offloaded. */
function amountRule(Mount $mount, int $ownerId, string $path = 'assets/files', array $attributes = []): Rule
{
    foreach (['include_json', 'exclude_json'] as $key) {
        if (isset($attributes[$key]) && is_array($attributes[$key])) {
            $attributes[$key] = json_encode($attributes[$key]);
        }
    }

    return Rule::query()->create($attributes + [
        'mount_id' => $mount->getKey(),
        'owner_id' => $ownerId,
        'name' => 'Rule ' . $path,
        'local_path' => $path,
        'remote_prefix' => '',
        'recursive' => true,
        'mode' => Rule::MODE_COPY,
        'visibility' => Rule::PRIVATE,
        'min_age' => 0,
        'enabled' => true,
    ]);
}

/** A queued run, not dispatched — for tests that drive the Syncer by hand. */
function amountRun(Rule $rule, ?int $startedBy = null, array $attributes = []): Run
{
    return Run::query()->create($attributes + [
        'rule_id' => $rule->getKey(),
        'started_by' => $startedBy ?? $rule->owner_id,
        'direction' => 'up',
        'status' => Run::QUEUED,
        'heartbeat_at' => now(),
    ]);
}

/** Run a rule to its end (or until the budget is spent) and return the refreshed run. */
function amountSync(Rule $rule, ?int $startedBy = null, ?float $budget = null, ?callable $report = null): Run
{
    $run = amountRun($rule, $startedBy);
    amountDrive($run, $budget, $report);

    return $run->refresh();
}

/** Drive an existing run through the Syncer; returns whether it finished. */
function amountDrive(Run $run, ?float $budget = null, ?callable $report = null): bool
{
    $syncer = new Syncer($run, $report);
    $syncer->heartbeatEvery = 0.0;

    return $syncer->work($budget);
}

function amountEntry(Rule $rule, string $sitePath): ?FileEntry
{
    return FileEntry::query()->where('rule_id', $rule->getKey())->where('path_hash', sha1($sitePath))->first();
}

// ---------------------------------------------------------------------------

amountBoot();
