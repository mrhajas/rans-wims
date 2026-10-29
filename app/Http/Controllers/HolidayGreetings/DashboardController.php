<?php

namespace App\Http\Controllers\HolidayGreetings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HolidayGreetings\Concerns\Guards;
use App\Models\HolidayContact;
use App\Models\HolidayDay;
use App\Services\HolidayGreetings\HolidayCalendar;
use App\Services\HolidayGreetings\HolidaySettings;
use App\Services\HolidayGreetings\GreetingThemes;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** The Holiday Greetings dashboard: the holidays of the year, the ones coming, and where each stands. */
class DashboardController extends Controller
{
    use Guards;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->need('read-holiday-greetings');
        $me = $request->user();
        $today = now(config('app.timezone'))->startOfDay();
        $thisYear = (int) $today->year;
        $year = max($thisYear - 3, min($thisYear + 2, (int) ($request->query('year') ?: $thisYear)));
        foreach (array_unique([$year, $thisYear, $thisYear + 1]) as $y) {
            HolidayCalendar::ensureYear((int) $me->account_id, $y);
        }

        $days = HolidayDay::query()->where('year', $year)->orderBy('date')->get();
        $counts = $this->countsFor($days->pluck('id'));
        $rows = $days->map(fn ($h) => $this->holidayRow($h, $counts[$h->id] ?? null))->values();

        // the next holidays that count, from today on (they can run into next year)
        $coming = HolidayDay::query()->where('is_enabled', true)->where('date', '>=', $today->toDateString())->orderBy('date')->limit(14)->get()->filter(fn ($h) => $h->counts())->take(4)->values();
        $comingCounts = $this->countsFor($coming->pluck('id'));
        $upcoming = $coming->map(fn ($h) => $this->holidayRow($h, $comingCounts[$h->id] ?? null) + ['reminderDue' => $h->reminderDueAt()->toIso8601String(), 'reminderDueText' => $h->reminderDueAt()->locale('en')->translatedFormat('D j M, H:i')])->values();

        $counting = $rows->where('counts', true);
        $sent = $counting->whereIn('phase', ['sent', 'partly', 'sending']);
        $totals = $rows->reduce(fn ($c, $r) => ['sent' => $c['sent'] + $r['sent'], 'failed' => $c['failed'] + $r['failed'], 'total' => $c['total'] + $r['total']], ['sent' => 0, 'failed' => 0, 'total' => 0]);
        $book = HolidayContact::query()->active();

        return Inertia::render('HolidayGreetings/Dashboard', [
            'year' => $year, 'thisYear' => $thisYear, 'today' => $today->toDateString(), 'holidays' => $rows, 'upcoming' => $upcoming,
            'stats' => ['holidays' => $counting->count(), 'remaining' => $counting->where('daysAway', '>=', 0)->whereNotIn('phase', ['sent', 'partly', 'sending'])->count(), 'sent' => $sent->count(), 'messages' => $totals['sent'], 'failed' => $totals['failed'],
                'people' => (clone $book)->count(), 'emails' => (clone $book)->whereNotNull('email')->distinct()->count('email'), 'mobiles' => (clone $book)->whereNotNull('mobile')->distinct()->count('mobile')],
            'hours' => HolidaySettings::hoursBefore(), 'skipWeekends' => HolidaySettings::skipWeekends(), 'themes' => GreetingThemes::choices(), 'smsReady' => $this->smsReady(),
            'can' => ['manage' => $me->can('manage-holidays'), 'contacts' => $me->can('read-holiday-contacts'), 'settings' => $me->can('manage-holiday-settings'), 'send' => $me->can('send-holiday-greetings')],
        ]);
    }
}
