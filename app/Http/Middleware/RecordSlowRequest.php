<?php

namespace App\Http\Middleware;

use App\Support\Performance\SlowLog;
use Closure;
use Illuminate\Http\Request;

/** Notes a page that took longer than the limit in Settings > Performance & Data (after the answer has been sent, so it never slows the page itself). */
class RecordSlowRequest
{
    public function handle(Request $request, Closure $next)
    {
        try {
            SlowLog::configure();
            \App\Support\Performance\QueueMode::apply();
        } catch (\Throwable $e) {
            // never break the page
        }

        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        try {
            SlowLog::request($request);
        } catch (\Throwable $e) {
            // never break the answer
        }
    }
}
