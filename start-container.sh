#!/bin/bash
# Railpack runs this as the container's start command (it replaces Railpack's
# default start-container.sh). It ports the old Nixpacks start.sh: prepare
# Laravel, then hand PID 1 to supervisord, which runs FrankenPHP plus the
# queues, the Telegram bot, the scheduler and the SSR server
# (deploy/supervisord.conf).
set -e

cd /app

# The image renderer's Node interpreter (App\Support\TakumiRenderer runs
# scripts/takumi-render.mjs). `node` on PATH is a mise shim; the real binary
# skips the shim's lookup on every render. Exported BEFORE `php artisan
# optimize` below, which bakes it into the cached config.
NODE_BINARY=$(mise which node 2>/dev/null || command -v node)
export NODE_BINARY

# The only PATH the bot's sandboxed Java / Python runs see (App\Support\CodeSandbox).
# The JDK's real bin dir comes first: the mise shims need an environment the
# sandbox does not get. Baked into the cached config by `optimize` as well.
JAVA_BINARY=$(mise which java 2>/dev/null || command -v java)
SANDBOX_PATH="$(dirname "$JAVA_BINARY"):/usr/local/bin:/usr/bin:/bin"
export SANDBOX_PATH

# Laravel writable paths (the storage volume may be empty on first boot)
mkdir -p /app/storage/framework/{cache,sessions,views} \
         /app/storage/logs \
         /app/storage/app/public/screenshots \
         /app/bootstrap/cache

# Everything here runs as root; the only other uid is the code sandbox's.
# storage (Passport keys, logs, uploads) and bootstrap/cache (the cached
# config holds every secret) are root-only, and so is /app itself, so the
# sandbox cannot even list it. Caddy serves public/storage as root.
chmod -R u+rwX,go-rwx /app/storage /app/bootstrap/cache
chmod 0700 /root
chmod 0750 /app

php artisan storage:link || true

# If a previous build cached views with a missing dir, clear them safely
php artisan view:clear || true

# Re-cache config, routes, events and views against the live runtime env.
php artisan optimize
chmod -R go-rwx /app/bootstrap/cache /app/storage/framework

# Close every world-writable directory (sticky bit kept), so the code
# sandbox can write nowhere but its own run dir under /var/lib/code-sandbox.
for dir in /tmp /var/tmp /run/lock /dev/shm /dev/mqueue; do
  if [ -d "$dir" ]; then chmod o-rwx "$dir"; fi
done
if [ -d /var/www/html ]; then chmod 0755 /var/www/html; fi
mkdir -p /var/lib/code-sandbox
chmod 0711 /var/lib/code-sandbox
rm -rf /var/lib/code-sandbox/run-*

# The code sandbox gets NO network: every packet its uid sends is refused
# (TCP, UDP/DNS, loopback included), so user code cannot reach Redis,
# Postgres, the other containers or the cloud metadata service. Root — the
# app — keeps its network. Needs the container's NET_ADMIN capability
# (Coolify custom docker options: --cap-add=NET_ADMIN). If the rule cannot be
# installed the container still boots, and App\Support\SandboxEgressGuard
# keeps the bot's run-code commands switched off (fail closed). The rule
# must stay identical to SandboxEgressGuard::ruleSpec().
# Verify on a live container (both must print "blocked"/fail):
#   setpriv --reuid=47000 --regid=47000 --clear-groups python3 -c \
#     'import socket,os; socket.create_connection((os.environ.get("H","<REDIS_HOST>"),6379),3)'
#   setpriv --reuid=47000 --regid=47000 --clear-groups curl -m 3 https://example.com
SANDBOX_UID="${CODE_SANDBOX_UID:-47000}"
block_sandbox_egress() {
  "$1" -w 5 -C OUTPUT -m owner --uid-owner "$SANDBOX_UID" -j REJECT 2>/dev/null \
    || "$1" -w 5 -I OUTPUT 1 -m owner --uid-owner "$SANDBOX_UID" -j REJECT
}
if block_sandbox_egress iptables && block_sandbox_egress ip6tables; then
  echo "code sandbox: network blocked for uid $SANDBOX_UID"
else
  echo "code sandbox: WARNING — could not firewall uid $SANDBOX_UID (needs --cap-add=NET_ADMIN and iptables); run-code commands stay disabled" >&2
fi

# Run any outstanding migrations
php artisan migrate --force || true

# FrankenPHP worker mode (Laravel Octane) unless switched off in the env.
# The Caddyfile reads these at parse time; empty = classic mode, so rolling
# back is OCTANE_WORKERS=false in Coolify plus a restart, no code change.
# The worker pool is fixed: each worker keeps a booted app in memory, and
# the slow requests that would need more threads run on the classic pool
# (see the Caddyfile). A worker restarts after OCTANE_MAX_REQUESTS
# requests, which keeps its heap near the size of a fresh boot.
if [ "${OCTANE_WORKERS:-true}" != "false" ]; then
  OCTANE_WORKER_COUNT="${OCTANE_WORKER_COUNT:-2}"
  export FRANKENPHP_WORKER="worker {
		file /app/public/frankenphp-worker.php
		num ${OCTANE_WORKER_COUNT}
		max_threads ${OCTANE_WORKER_MAX_THREADS:-$OCTANE_WORKER_COUNT}
		env MAX_REQUESTS ${OCTANE_MAX_REQUESTS:-250}
	}"
  export PHP_ENTRY="frankenphp-worker.php"
else
  export FRANKENPHP_WORKER=""
  export PHP_ENTRY="index.php"
fi

exec supervisord -c /app/deploy/supervisord.conf -n
