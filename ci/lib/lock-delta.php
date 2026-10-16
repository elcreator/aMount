<?php

/**
 * Report what installing the plugin changed in the CMS's composer.lock.
 *
 * The whole promise of this pipeline is that the published image is the
 * upstream Evolution CMS image plus one plugin. That only holds if adding the
 * plugin did not also drag the CMS's own dependencies to different versions,
 * so the build prints the difference rather than asking anyone to take it on
 * trust.
 *
 * Informational by design: a plugin is *allowed* to bring dependencies of its
 * own, and that is exactly what shows up here as "added". A core package
 * appearing under "changed" is the interesting case - with a partial update it
 * should not happen, and if it ever does, this is where it becomes visible.
 *
 * Usage: php lock-delta.php <lock-before> <lock-after>
 */

$before = $argv[1] ?? '';
$after = $argv[2] ?? '';

if ($before === '' || $after === '') {
    fwrite(STDERR, "usage: php lock-delta.php <lock-before> <lock-after>\n");
    exit(2);
}

/** @return array<string, string> package name => version */
$read = static function (string $path): array {
    $data = json_decode((string) file_get_contents($path), true);

    if (!is_array($data)) {
        fwrite(STDERR, "lock-delta.php: {$path} is not valid JSON\n");
        exit(1);
    }

    $out = [];

    foreach (array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $package) {
        $out[$package['name']] = $package['version'];
    }

    ksort($out);

    return $out;
};

$old = $read($before);
$new = $read($after);

$added = array_diff_key($new, $old);
$removed = array_diff_key($old, $new);
$changed = [];

foreach (array_intersect_key($old, $new) as $name => $version) {
    if ($new[$name] !== $version) {
        $changed[$name] = $version . ' => ' . $new[$name];
    }
}

foreach ($added as $name => $version) {
    printf("added    %s %s\n", $name, $version);
}

foreach ($removed as $name => $version) {
    printf("removed  %s %s\n", $name, $version);
}

foreach ($changed as $name => $move) {
    printf("CHANGED  %s %s\n", $name, $move);
}

printf(
    "\n%d added, %d removed, %d moved off the version the CMS pinned\n",
    count($added),
    count($removed),
    count($changed)
);

if ($changed) {
    fwrite(
        STDERR,
        "\nlock-delta.php: the build moved " . count($changed) . " package(s) the CMS had pinned.\n"
        . "This build is no longer 'the upstream tree plus a plugin'. Check the plugin's\n"
        . "version constraints against core/composer.json.\n"
    );
}

exit(0);
