<?php

namespace App\Http\Controllers\VisitorLogs\Concerns;

use Carbon\Carbon;

/** Shared by the Visitor Logs controllers: permission checks done by name (every action is checked, never left to a route prefix) and the server-time helpers. */
trait Guards
{
    protected function need(string $ability): void
    {
        abort_unless(auth()->user()->can($ability), 403);
    }

    /** At least one of these abilities. */
    protected function needAny(array $abilities): void
    {
        $u = auth()->user();
        abort_unless(collect($abilities)->contains(fn ($a) => $u->can($a)), 403);
    }

    protected function like(string $v): string
    {
        return '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';
    }

    /** "2026-10-22 09:15" typed by someone allowed to correct a time, read in the WIMS timezone; null when it is not a valid date and time. */
    protected function parseMoment(?string $v): ?Carbon
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        foreach (['Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d\\TH:i', 'Y-m-d\\TH:i:s'] as $f) {
            try {
                $c = Carbon::createFromFormat($f, $v, config('app.timezone'));
                if ($c && $c->format($f) === $v) {
                    return $c;
                }
            } catch (\Throwable $e) {
                // try the next shape
            }
        }

        return null;
    }
}
