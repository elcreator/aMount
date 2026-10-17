# Plugin-specific smoke assertions for aMount. Sourced by ci/smoke.sh (after the
# migrations check) with $evo_dir, $here, step() and fail() already defined.
# This is the one place, besides plugin.env, that differs between plugins.

step "aMount tables and permissions exist"
php -r '
$evo = $argv[1];
$dbs = array_merge(glob($evo . "/core/database/*.sqlite") ?: [], glob($evo . "/database/*.sqlite") ?: []);
if (!$dbs) { fwrite(STDERR, "no sqlite database\n"); exit(1); }
$pdo = new PDO("sqlite:" . $dbs[0], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = \"table\"")->fetchAll(PDO::FETCH_COLUMN);
$bad = 0;
foreach (["amount_mounts", "amount_rules", "amount_files", "amount_runs"] as $want) {
    $hit = array_filter($tables, fn($t) => substr($t, -strlen($want)) === $want);
    printf("%s table %s\n", $hit ? "ok  " : "MISS", $want);
    $bad += $hit ? 0 : 1;
}
$prefix = "";
foreach ($tables as $t) { if (substr($t, -13) === "amount_mounts") { $prefix = substr($t, 0, -13); } }
$perm = [$prefix . "permissions"];
if (!in_array($perm[0], $tables, true)) { fwrite(STDERR, "no permissions table
"); exit(1); }
$keys = $pdo->query("SELECT \"key\" FROM \"" . $perm[0] . "\"")->fetchAll(PDO::FETCH_COLUMN);
foreach (["amount", "amount_manage"] as $want) {
    printf("%s permission %s\n", in_array($want, $keys, true) ? "ok  " : "MISS", $want);
    $bad += in_array($want, $keys, true) ? 0 : 1;
}
exit($bad ? 1 : 0);
' "$evo_dir" || fail "aMount tables or permissions are missing"

step "amount:drivers"
# The command arrives with the driver registry; until it exists this is a
# warning, not a failure.
if ( cd "$evo_dir" && php core/artisan list --raw 2>/dev/null | grep -q '^amount:drivers' ); then
    ( cd "$evo_dir" && php core/artisan amount:drivers ) || fail "php core/artisan amount:drivers exited non-zero"
else
    echo "WARNING: artisan command amount:drivers is not registered (yet) - skipped" >&2
fi
