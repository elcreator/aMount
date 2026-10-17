#!/usr/bin/env bash
#
# Assemble an Evolution CMS tree with this plugin installed into it.
#
# This is the one place that knows how a plugin gets into a CMS, and both the
# GitHub workflows and the local docker image call it, so what CI builds and
# what a developer builds on their machine are the same tree.
#
# The plugin is wired in the way the CMS itself does it - a require line in
# core/custom/composer.json, then a composer update in core/, then
# vendor:publish and migrate - rather than by copying files into place. See
# core/src/Console/Packages/InstallPackageRequireCommand.php upstream.
#
# Needs php, composer, git and tar on PATH. Nothing else.
#
# Usage:
#   ci/compose-evo.sh <target-dir> [--evo-src <path>] [--dev] [--install]
#
#   --evo-src <path>  build from a local Evolution checkout instead of cloning
#                     EVO_REPO@EVO_REF (its vendor tree is not copied - it may
#                     have been built on another OS - and is rebuilt instead)
#   --dev             install the core's dev dependencies too (Pest lives
#                     there; the test job needs it, a distributable build does
#                     not)
#   --install         run the sqlite installer, publish the plugin's assets and
#                     run its migrations, leaving a CMS that is ready to serve
#
set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
plugin_dir=$(cd "$here/.." && pwd)
# shellcheck source=plugin.env
. "$here/plugin.env"

# Credentials of the pre-installed demo build. They are published together with
# the artifact, so this is a testing account and nothing else.
DEMO_ADMIN="${DEMO_ADMIN:-admin}"
DEMO_EMAIL="${DEMO_EMAIL:-admin@evo.local}"
DEMO_PASSWORD="${DEMO_PASSWORD:-Passw0rd123}"
DEMO_DATABASE="${DEMO_DATABASE:-evolution}"
DEMO_PREFIX="${DEMO_PREFIX:-evo_}"

target=''
evo_src=''
with_dev=0
do_install=0

while [ $# -gt 0 ]; do
    case "$1" in
        --evo-src) evo_src=$2; shift 2 ;;
        --dev) with_dev=1; shift ;;
        --install) do_install=1; shift ;;
        -h|--help) sed -n '2,27p' "$0"; exit 0 ;;
        -*) echo "compose-evo.sh: unknown option: $1" >&2; exit 2 ;;
        *) target=$1; shift ;;
    esac
done

if [ -z "$target" ]; then
    echo "usage: ci/compose-evo.sh <target-dir> [--evo-src <path>] [--dev] [--install]" >&2
    exit 2
fi

mkdir -p "$target"
target=$(cd "$target" && pwd)

# Copying the plugin into a directory that lives inside the plugin would copy
# the copy, forever.
case "$target/" in
    "$plugin_dir"/*)
        echo "compose-evo.sh: <target-dir> must be outside $plugin_dir" >&2
        exit 2
        ;;
esac

step() { printf '\n==> %s\n' "$*"; }

# ---------------------------------------------------------------- the CMS ---

step "Evolution CMS source"
if [ -n "$evo_src" ]; then
    evo_src=$(cd "$evo_src" && pwd)
    echo "local checkout: $evo_src"

    # Only what the repository contains - tracked files plus new work that is
    # not ignored. A developer's checkout is also a *running site*: it has
    # their own packages in core/custom, their config, their caches, their
    # compiled provider manifest. Copying that wholesale produces a build that
    # boots someone else's installation and fails on a package that was never
    # meant to be in it. git already knows the difference, so it is asked.
    #
    # The vendor trees are excluded regardless - a working copy's may have been
    # installed on another OS, and composer rebuilds them below either way.
    if [ -d "$evo_src/.git" ]; then
        git -C "$evo_src" ls-files -co --exclude-standard -z \
            | grep -zZv -e '^vendor/' -e '^core/vendor/' \
            | tar -c -C "$evo_src" --no-recursion --null --files-from=- \
            | tar -x -C "$target"
    else
        echo "warning: $evo_src is not a git checkout; falling back to a copy" >&2
        echo "         with the known local-state directories excluded." >&2
        tar -c -C "$evo_src" \
            --exclude=./.git \
            --exclude=./vendor \
            --exclude=./core/vendor \
            --exclude=./core/custom/composer.json \
            --exclude=./core/custom/composer.lock \
            --exclude=./core/custom/config \
            --exclude=./core/custom/packages \
            --exclude=./core/storage/framework \
            --exclude=./core/cache \
            --exclude=./assets/cache \
            . | tar -x -C "$target"
    fi
else
    echo "clone: $EVO_REPO @ $EVO_REF"
    rm -rf "$target/.evo-clone"
    # core.longpaths: the CMS commits its vendor tree, and some of the paths in
    # it are past Windows' 260-character limit - without this the checkout dies
    # halfway through on a developer's machine. It is a no-op elsewhere.
    git -c core.longpaths=true clone --depth 1 --branch "$EVO_REF" "$EVO_REPO" "$target/.evo-clone"
    # The committed vendor trees are replaced by the composer run below, so
    # they are dropped here rather than copied around first.
    rm -rf "$target/.evo-clone/.git" "$target/.evo-clone/vendor" "$target/.evo-clone/core/vendor"
    tar -c -C "$target/.evo-clone" . | tar -x -C "$target"
    rm -rf "$target/.evo-clone"
fi

if [ ! -f "$target/core/composer.json" ]; then
    echo "compose-evo.sh: $target does not look like an Evolution CMS tree" >&2
    exit 1
fi

composer_args=(--no-interaction --no-progress --prefer-dist)
if [ "$with_dev" != 1 ]; then
    composer_args+=(--no-dev)
fi

# --------------------------------------------------- the core's own tree ---

step "composer install (core)"
# Deliberately `install` and deliberately *before* the plugin is wired in: with
# no core/custom/composer.json yet there is nothing for merge-plugin to fold
# into the root package, the committed core/composer.lock still describes the
# root exactly, and this resolves to the same tree the CMS's own build job
# produces. Every version in the image is then the version upstream pinned.
#
# COMPOSER_HOME is left alone. The CMS points it at core/composer when it
# installs a package into a live site, but a build wants the machine's shared
# cache - the directory the workflows hand to actions/cache and the local image
# keeps on a volume - and overriding it means re-downloading everything.
( cd "$target/core" && composer install "${composer_args[@]}" )

# ------------------------------------------------------------- the plugin ---

step "Plugin source -> core/custom/packages/$PLUGIN_SLUG"
pkg_dir="$target/core/custom/packages/$PLUGIN_SLUG"
rm -rf "$pkg_dir"
mkdir -p "$pkg_dir"
# vendor is excluded on purpose: a plugin that carries one carries a whole
# nested Evolution CMS inside it, and composer resolves this package's
# dependencies into core/vendor anyway.
tar -c -C "$plugin_dir" \
    --exclude=./.git \
    --exclude=./vendor \
    --exclude=./.idea \
    --exclude=./.phpunit.cache \
    --exclude=./node_modules \
    --exclude=./tmp \
    --exclude=./dist \
    . | tar -x -C "$pkg_dir"

# The plugin is shipped inside the build rather than only mirrored into
# core/vendor, so the result stays re-installable without network access - and
# so a `composer update` in the delivered tree resolves the plugin from the
# tree instead of trying to reach packagist for it.
step "core/custom/composer.json"
# Written from scratch, never merged into whatever was there. A build is the
# CMS plus this one plugin; a package that happened to be installed in the
# core this was built from has no business travelling into a published image.
custom="$target/core/custom/composer.json"
PLUGIN_PACKAGE="$PLUGIN_PACKAGE" PLUGIN_SLUG="$PLUGIN_SLUG" php "$here/lib/write-custom-composer.php" "$custom"
cat "$custom"

step "composer update $PLUGIN_PACKAGE (partial)"
# A *partial* update, naming the plugin. merge-plugin has now folded the
# requirement written above into the root package, and only that requirement is
# allowed to move: everything else stays at the version core/composer.lock
# pinned. The published image is then the CMS's own dependency tree plus one
# package, rather than whatever composer happened to resolve on the day it was
# built.
#
# --with-dependencies lets the plugin's own dependencies be installed. It stops
# short of packages the core itself requires, so a plugin needing a newer
# version of something the core pins fails here rather than silently bumping a
# CMS dependency underneath it - a conflict worth being told about.
#
# The CMS runs a full, unrestricted `composer update` in this situation
# (InstallPackageRequireCommand::runComposer). That is right for a live site
# being maintained and wrong for a build that has to be reproducible.
lock_before=$(mktemp)
cp "$target/core/composer.lock" "$lock_before"
( cd "$target/core" && composer update "${composer_args[@]}" --with-dependencies "$PLUGIN_PACKAGE" )

step "What the plugin changed in core/composer.lock"
php "$here/lib/lock-delta.php" "$lock_before" "$target/core/composer.lock"
rm -f "$lock_before"

if [ ! -d "$target/core/vendor/$PLUGIN_PACKAGE" ]; then
    echo "compose-evo.sh: $PLUGIN_PACKAGE is not in core/vendor after the update" >&2
    exit 1
fi

if [ "$do_install" = 1 ]; then
    # --------------------------------------------------------- the install ---

    step "Install Evolution CMS on sqlite"
    # --removeInstall=y drops install/ from the build, --skipComposer=y keeps
    # the dependencies installed above untouched.
    ( cd "$target/install" && php cli-install.php \
        --typeInstall=1 \
        --databaseType=sqlite \
        --database="$DEMO_DATABASE" \
        --tablePrefix="$DEMO_PREFIX" \
        --cmsAdmin="$DEMO_ADMIN" \
        --cmsAdminEmail="$DEMO_EMAIL" \
        --cmsPassword="$DEMO_PASSWORD" \
        --language=en \
        --removeInstall=y \
        --skipComposer=y < /dev/null )

    # The installer writes the absolute path of the build machine into the
    # connection config. Resolve it at runtime instead, so the tree keeps
    # working wherever it is unpacked.
    step "Make the sqlite path portable"
    config="$target/core/config/database/connections/default.php"
    sed -i.bak "s#'database' => env('DB_DATABASE', '.*'),#'database' => env('DB_DATABASE', dirname(__DIR__, 3) . '/database/${DEMO_DATABASE}.sqlite'),#" "$config"
    rm -f "$config.bak"
    grep -q "dirname(__DIR__, 3)" "$config"
    php -l "$config"

    # The two steps the CMS runs for any package it installs, in its order
    # (ExtrasCommand): assets first, then schema.
    step "artisan vendor:publish --provider=$PLUGIN_PROVIDER"
    ( cd "$target/core" && php artisan vendor:publish --provider="$PLUGIN_PROVIDER" --force --no-interaction )

    step "artisan migrate --force"
    ( cd "$target/core" && php artisan migrate --force --no-interaction )
fi

step "Done: $target"
