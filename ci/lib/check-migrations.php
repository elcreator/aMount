<?php

/**
 * Assert that every migration the plugin ships is recorded as run.
 *
 * Asked of the database directly rather than through `artisan migrate:status`,
 * because that command's flags differ between the framework versions the build
 * matrix covers, and a smoke test that silently passes when its command is
 * unavailable is worse than no smoke test.
 *
 * Usage: php check-migrations.php <plugin-migrations-dir> <evo-dir>
 *
 * Exits 0 when the plugin ships no migrations - several of these plugins do
 * not, and that is not a failure.
 */

$migrationsDir = $argv[1] ?? '';
$evoDir = $argv[2] ?? '';

if ($migrationsDir === '' || $evoDir === '') {
    fwrite(STDERR, "usage: php check-migrations.php <plugin-migrations-dir> <evo-dir>\n");
    exit(2);
}

$files = is_dir($migrationsDir) ? glob($migrationsDir . '/*.php') : [];

if (!$files) {
    echo "no migrations shipped by this plugin - nothing to check\n";
    exit(0);
}

$expected = array_map(
    static fn(string $path): string => basename($path, '.php'),
    $files
);

// The installer puts it under core/, which is what the dirname(__DIR__, 3) in
// the connection config resolves to; a site configured by hand may keep it
// beside the docroot instead.
$dbs = array_merge(
    glob($evoDir . '/core/database/*.sqlite') ?: [],
    glob($evoDir . '/database/*.sqlite') ?: []
);

if (!$dbs) {
    fwrite(STDERR, "check-migrations.php: no sqlite database under {$evoDir}\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbs[0], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// The table is prefixed (evo_migrations by default), so it is looked up rather
// than named.
$table = $pdo->query(
    "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '%migrations'"
)->fetchColumn();

if (!$table) {
    fwrite(STDERR, "check-migrations.php: no migrations table in {$dbs[0]}\n");
    exit(1);
}

$ran = $pdo->query('SELECT migration FROM "' . $table . '"')->fetchAll(PDO::FETCH_COLUMN);
$missing = array_diff($expected, $ran);

foreach ($expected as $migration) {
    printf("%s %s\n", in_array($migration, $ran, true) ? 'ok  ' : 'MISS', $migration);
}

if ($missing) {
    fwrite(STDERR, "check-migrations.php: not applied: " . implode(', ', $missing) . "\n");
    exit(1);
}

exit(0);
