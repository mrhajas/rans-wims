<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Printing, PDF, Excel, CSV and download requests build a whole document in memory. On a server whose PHP allows only 128 MB a long report simply stops with a
 * 500, so these requests (and only these) are given more room, unless the server already allows more.
 */
class RaiseLimitsForDocuments
{
    private const WORDS = ['print', 'pdf', 'export', 'download', 'csv', 'xlsx', 'excel', 'report', 'statement', 'backup'];

    public function handle(Request $request, Closure $next)
    {
        $p = strtolower($request->path());
        foreach (self::WORDS as $w) {
            if (str_contains($p, $w)) {
                $this->raise();
                break;
            }
        }

        return $next($request);
    }

    private function raise(): void
    {
        $limit = ini_get('memory_limit');
        $bytes = $limit === '-1' ? PHP_INT_MAX : $this->bytes((string) $limit);
        if ($bytes < 768 * 1024 * 1024) {
            @ini_set('memory_limit', '768M');
        }
        if ((int) ini_get('max_execution_time') !== 0 && (int) ini_get('max_execution_time') < 300) {
            @set_time_limit(300);
        }
    }

    private function bytes(string $v): int
    {
        $n = (int) $v;

        return match (strtolower(substr(trim($v), -1))) { 'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n };
    }
}
