<?php

/**
 * Write an Evolution CMS core/custom/composer.json that installs this plugin.
 *
 * Called by ci/compose-evo.sh. Kept as a PHP file rather than a `php -r`
 * one-liner inside the shell script because the shape of the file matters and
 * a JSON document assembled in a quoted one-liner is unreadable and unfixable.
 *
 * The file is written from scratch, deliberately: a build is the CMS plus this
 * one plugin. When the CMS came from a developer's working checkout, whatever
 * else they had installed there is exactly what must not travel into a
 * published zip or image.
 *
 * Usage: PLUGIN_PACKAGE=... PLUGIN_SLUG=... php write-custom-composer.php <path>
 */

$custom = $argv[1] ?? '';
$package = getenv('PLUGIN_PACKAGE') ?: '';
$slug = getenv('PLUGIN_SLUG') ?: '';

if ($custom === '' || $package === '' || $slug === '') {
    fwrite(STDERR, "usage: PLUGIN_PACKAGE=... PLUGIN_SLUG=... php write-custom-composer.php <path>\n");
    exit(2);
}

// The same skeleton the CMS writes when it installs a package into a core that
// has never had one (InstallPackageRequireCommand::$composerArray).
$data = [
    'name' => 'evolutioncms/custom',
    'repositories' => [
        [
            'type' => 'path',
            // Relative to this file's own directory, so it reaches
            // core/custom/packages/<slug> with no absolute path baked into a
            // shipped build. wikimedia/composer-merge-plugin rebases the URLs
            // of a merged repository against the directory the merged file
            // lives in, which is what makes this the correct form - an
            // absolute path does not work here, because merge-plugin prefixes
            // that too and produces core/custom/<absolute path>.
            'url' => 'packages/' . $slug,
            // A mirror, not a symlink: the copy in core/vendor has to survive
            // being zipped up and unpacked wherever the build is deployed.
            'options' => ['symlink' => false],
        ],
    ],
    'require' => [$package => '@dev'],
    'autoload' => ['psr-4' => ['EvolutionCMS\\Custom\\' => 'src/']],
];

if (!is_dir(dirname($custom))) {
    mkdir(dirname($custom), 0775, true);
}

file_put_contents(
    $custom,
    json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
);
