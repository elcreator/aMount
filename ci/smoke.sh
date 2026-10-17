#!/usr/bin/env bash
#
# Check that a tree built by ci/compose-evo.sh --install actually serves, and
# that the plugin is part of what it serves.
#
# The unit suite proves the plugin's own logic. This proves the thing the unit
# suite deliberately does not: that the package resolves inside a real CMS,
# that its service provider is discovered, and that its migrations reached the
# database. A plugin can pass every unit test and still fail to install.
#
# Usage: ci/smoke.sh <evo-dir> [port]
#
set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=plugin.env
. "$here/plugin.env"

evo_dir=${1:?usage: ci/smoke.sh <evo-dir> [port]}
evo_dir=$(cd "$evo_dir" && pwd)
port=${2:-8899}

step() { printf '\n==> %s\n' "$*"; }
fail() { echo "smoke.sh: $*" >&2; exit 1; }

step "Package is installed"
[ -d "$evo_dir/core/vendor/$PLUGIN_PACKAGE" ] || fail "$PLUGIN_PACKAGE missing from core/vendor"

step "Service provider is discovered"
# `artisan package:discover` runs on every composer dump-autoload and drops one
# file per discovered provider into core/custom/config/app/providers/. That
# directory is what the CMS reads at boot, so a provider missing from it is a
# plugin that will never load, however correct its code is.
providers="$evo_dir/core/custom/config/app/providers"
grep -rqF "$PLUGIN_PROVIDER" "$providers" 2>/dev/null \
    || fail "$PLUGIN_PROVIDER was not registered in $providers"
ls "$providers"

step "Migrations ran"
# Every migration this plugin ships has to be recorded in the migrations table.
# One left pending means the built image would run it on first boot instead, in
# front of a user - or would not run it at all, if boot never reaches it.
php "$here/lib/check-migrations.php" \
    "$evo_dir/core/vendor/$PLUGIN_PACKAGE/database/migrations" \
    "$evo_dir" \
    || fail "the plugin's migrations are not all applied"

# Assertions that only make sense for one plugin live beside plugin.env.
if [ -f "$here/smoke-extra.sh" ]; then
    # shellcheck source=smoke-extra.sh
    . "$here/smoke-extra.sh"
fi

step "The build answers over HTTP"
php -S "127.0.0.1:$port" -t "$evo_dir" > /tmp/smoke-server.log 2>&1 &
server=$!
trap 'kill "$server" 2>/dev/null || true' EXIT

for _ in $(seq 1 15); do
    sleep 1
    curl -sf -o /dev/null "http://127.0.0.1:$port/" && break
done

front=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/")
# The manager answers 404 without an Accept-Language header by design.
manager=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept-Language: en-US,en;q=0.9' "http://127.0.0.1:$port/manager/index.php")
echo "front=$front manager=$manager"
tail -20 /tmp/smoke-server.log || true

[ "$front" = "200" ] || fail "front end answered $front"
[ "$manager" = "200" ] || fail "manager answered $manager"

step "Smoke test passed"
