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

# Run any outstanding migrations
php artisan migrate --force || true

exec supervisord -c /app/deploy/supervisord.conf -n
