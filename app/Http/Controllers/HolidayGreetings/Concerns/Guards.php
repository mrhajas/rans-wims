<?php

namespace App\Http\Controllers\HolidayGreetings\Concerns;

use App\Models\HolidayDay;
use App\Services\HolidayGreetings\GreetingSender;
use App\Services\HolidayGreetings\GreetingThemes;
use Carbon\Carbon;

/** Shared by the Holiday Greetings controllers: permission checks done by name (every action is checked, never left to a route prefix) and how a holiday is shown. */
trait Guards
{
    protected function need(string $ability): void
    {
        abort_unless(auth()->user()->can($ability), 403);
    }

    protected function holiday(int $id): HolidayDay
    {
        return HolidayDay::query()->findOrFail($id);
    }

    /** upcoming | reminded | sending | sent | partly | missed | weekend | off — what a holiday row says about where it is. */
    protected function phase(HolidayDay $h, ?string $state = null): string
    {
        if (! $h->is_enabled) {
            return 'off';
        }
        if (! $h->counts()) {
            return 'weekend';
        }
        $state ??= $h->sendState();
        if ($state !== 'not_sent') {
            return $state;
        }
        if ($h->date->lt(now(config('app.timezone'))->startOfDay())) {
            return 'missed';
        }

        return $h->reminder_sent_at ? 'reminded' : 'upcoming';
    }

    protected function holidayRow(HolidayDay $h, ?array $counts = null): array
    {
        $theme = GreetingThemes::get($h->theme);
        $date = Carbon::parse($h->date->format('Y-m-d'), config('app.timezone'));
        $today = now(config('app.timezone'))->startOfDay();

        return [
            'id' => $h->id, 'key' => $h->key, 'name' => $h->name, 'date' => $h->date->format('Y-m-d'), 'day' => $date->locale('en')->translatedFormat('l'), 'dateText' => $date->locale('en')->translatedFormat('j F Y'),
            'month' => $date->locale('en')->translatedFormat('M'), 'dom' => $date->format('j'), 'daysAway' => (int) $today->diffInDays($date, false), 'kind' => $h->kind, 'theme' => $h->theme, 'emoji' => $theme['emoji'], 'palette' => $theme['palette'],
            'confirmed' => (bool) $h->date_confirmed, 'enabled' => (bool) $h->is_enabled, 'weekend' => $h->isWeekend(), 'counts' => $h->counts(), 'forced' => (bool) $h->force_include,
            'phase' => $this->phase($h, $counts['state'] ?? null), 'sent' => $counts['sent'] ?? 0, 'failed' => $counts['failed'] ?? 0, 'pending' => $counts['pending'] ?? 0, 'total' => $counts['total'] ?? 0,
            'reminded_at' => optional($h->reminder_sent_at)->toIso8601String(), 'locked' => $h->locked(), 'past' => $h->isPast(),
        ];
    }

    /** Sent / failed / pending figures of several holidays in one query: holiday id => counts. */
    protected function countsFor($ids): array
    {
        $rows = \App\Models\HolidayDelivery::query()->whereIn('holiday_id', $ids)->selectRaw('holiday_id, status, COUNT(*) n')->groupBy('holiday_id', 'status')->get()->groupBy('holiday_id');
        $o = [];
        foreach ($rows as $id => $g) {
            $by = $g->pluck('n', 'status');
            $pending = (int) (($by['Queued'] ?? 0) + ($by['Sending'] ?? 0));
            $failed = (int) ($by['Failed'] ?? 0);
            $o[$id] = ['sent' => (int) ($by['Sent'] ?? 0), 'failed' => $failed, 'pending' => $pending, 'total' => (int) $by->sum(), 'state' => $pending ? 'sending' : ($failed ? 'partly' : 'sent')];
        }

        return $o;
    }

    protected function smsReady(): bool
    {
        return GreetingSender::smsReady();
    }
}
