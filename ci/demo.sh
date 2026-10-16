#!/usr/bin/env bash
#
# Start the aMount demo stack (CMS + MinIO), seed demo data, print the URLs.
#
# Usage: ci/demo.sh [--no-build]
#
# Env: PORT (CMS, default 8080), MINIO_CONSOLE_PORT (9001), EVO_SRC, PHP_VERSION
#      WAIT_SECONDS (how long to wait for the CMS to be built, default 900)
#
set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
compose=(docker compose -f "$here/compose.demo.yaml")
port=${PORT:-8080}
console=${S3_PORT:-9090}
wait_seconds=${WAIT_SECONDS:-900}

step() { printf '\n==> %s\n' "$*"; }

step "Build and start the stack"
if [ "${1:-}" = "--no-build" ]; then
    "${compose[@]}" up -d
else
    "${compose[@]}" up -d --build
fi

step "Wait for the CMS (it is installed on first start)"
ready=0
for _ in $(seq 1 "$((wait_seconds / 3))"); do
    code=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept-Language: en-US,en;q=0.9' "http://localhost:$port/manager/index.php" || true)
    if [ "$code" = "200" ]; then ready=1; break; fi
    sleep 3
done
[ "$ready" = "1" ] || { echo "demo.sh: the CMS did not come up in ${wait_seconds}s; see: ${compose[*]} logs serve" >&2; exit 1; }

step "Seed demo data"
if "${compose[@]}" exec -T serve sh -c 'cd /build && php core/artisan list --raw | grep -q "^amount:demo"'; then
    "${compose[@]}" exec -T serve sh -c 'cd /build && php core/artisan amount:demo --s3'
else
    echo "amount:demo is not available in this build yet - nothing was seeded."
fi

cat <<EOF

aMount demo is up.

  Manager        http://localhost:$port/manager/      admin / Passw0rd123
  S3 (S3Mock)    http://localhost:$console/   any credentials, bucket amount-demo
  S3 endpoint    http://s3:9090 (inside the stack)

Stop and wipe:   docker compose -f ci/compose.demo.yaml down -v
EOF
