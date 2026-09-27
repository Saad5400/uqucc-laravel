#!/bin/bash
# Runs `schedule:run` at the top of every minute, which is all `schedule:work`
# does internally, without keeping a ~65MB PHP process resident between runs.
# Each run is backgrounded, as schedule:work does, so a slow task never delays
# the next minute; tasks guard their own overlap with withoutOverlapping().
cd /app
while :; do
  sleep $(( 60 - 10#$(date +%S) ))
  php artisan schedule:run --no-interaction &
done
