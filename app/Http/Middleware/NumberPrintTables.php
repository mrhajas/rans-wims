<?php

namespace App\Http\Middleware;

use App\Support\NumberTables;
use Closure;
use Illuminate\Http\Request;

/** Every printed page that is plain HTML (a report, a record, a list) gets a No column in its data tables. See App\Support\NumberTables. */
class NumberPrintTables
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if ($request->ajax() || $request->header('X-Inertia') || $response->getStatusCode() !== 200 || ! $response instanceof \Illuminate\Http\Response) {
            return $response;
        }
        $type = strtolower((string) $response->headers->get('Content-Type', ''));
        if ($type !== '' && ! str_contains($type, 'text/html')) {
            return $response;
        }
        $content = $response->getContent();
        if (! is_string($content) || ! str_contains($content, '<table') || str_contains($content, 'data-page=')) {
            return $response;
        }
        try {
            $response->setContent(NumberTables::inject($content));
        } catch (\Throwable $e) {
            report($e); // the page is shown as it was
        }

        return $response;
    }
}
