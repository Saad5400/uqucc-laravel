<?php

namespace App\Listeners;

/**
 * Gives every request served by an Octane (FrankenPHP worker-mode) worker the
 * php.ini `max_execution_time`, as classic mode does.
 *
 * In worker mode one PHP request spans the worker's whole life, so a
 * `set_time_limit()` made while serving one request (ai-kit's
 * SseStream::extendTimeLimit raises it to 210s; the streams run on classic
 * threads today, but nothing stops a worker request from doing the same) is
 * still in force for every later request on that worker. Measured on
 * FrankenPHP 1.13: after a request set 7s, a 10s request on the same worker
 * died with a 500, where classic mode served it. Re-arming the limit at the
 * start of each request restores the per-request ceiling and its clock.
 */
class RestoreRequestTimeLimit
{
    public function handle(object $event): void
    {
        $limit = (int) (ini_get_all('core')['max_execution_time']['global_value'] ?? 0);

        if ($limit > 0) {
            set_time_limit($limit);
        }
    }
}
