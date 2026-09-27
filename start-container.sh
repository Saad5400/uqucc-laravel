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

# Laravel writable paths (the storage volume may be empty on first boot)
mkdir -p /app/storage/framework/{cache,sessions,views} \
         /app/storage/logs \
         /app/storage/app/public/screenshots \
         /app/bootstrap/cache
chmod -R ug+rwX /app/storage /app/bootstrap/cache
# Rendered share cards are written here
chmod 777 /app/storage/app/public/screenshots

php artisan storage:link || true

# If a previous build cached views with a missing dir, clear them safely
php artisan view:clear || true

# Re-cache config, routes, events and views against the live runtime env.
php artisan optimize

# Run any outstanding migrations
php artisan migrate --force || true

exec supervisord -c /app/deploy/supervisord.conf -n
