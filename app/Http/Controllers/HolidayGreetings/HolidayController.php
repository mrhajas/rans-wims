<?php

namespace App\Http\Controllers\HolidayGreetings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HolidayGreetings\Concerns\Guards;
use App\Models\HolidayBundle;
use App\Models\HolidayDay;
use App\Services\HolidayGreetings\HolidaySettings;
use App\Services\HolidayGreetings\GreetingSender;
use App\Services\HolidayGreetings\GreetingTemplates;
use App\Services\HolidayGreetings\GreetingThemes;
use App\Services\HolidayGreetings\HolidayCalendar;
use App\Services\HolidayGreetings\HolidayNotifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** One holiday: its page (message, who receives it, what was sent) and the changes to the list of holidays. */
class HolidayController extends Controller
{
    use Guards;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function show(Request $request, int $holiday)
    {
        $this->need('read-holiday-greetings');
        $h = $this->holiday($holiday);
        $me = $request->user();
        $lists = GreetingSender::lists($h);
        $summary = GreetingSender::summary($h);
        $tpl = GreetingTemplates::effective($h);
        $sample = collect($lists['email'])->first(fn ($r) => ! $r['excluded']) ?: collect($lists['sms'])->first(fn ($r) => ! $r['excluded']);
        $theme = GreetingThemes::get($h->theme);
        $row = $this->holidayRow($h, ['state' => $summary['state'], 'sent' => $summary['sent'], 'failed' => $summary['failed'], 'pending' => $summary['pending'], 'total' => $summary['total']]);

        // a "partial" reload (the page polling while messages go out) only needs the lists and the figures
        return Inertia::render('HolidayGreetings/Holiday', [
            'holiday' => $row + ['kindLabel' => __(HolidayDay::KINDS[$h->kind] ?? $h->kind), 'themeLabel' => $theme['label'], 'note' => $h->note, 'sentBy' => optional($h->sentBy)->name ?: ($h->send_started_at ? __('Scheduled sending') : null), 'sendStarted' => optional($h->send_started_at)->toIso8601String(),
                'edited' => GreetingTemplates::edited($h), 'reminderDueText' => $h->reminderDueAt()->locale('en')->translatedFormat('D j M Y, H:i')],
            'template' => $tpl, 'defaults' => GreetingTemplates::defaults($h), 'placeholders' => GreetingTemplates::PLACEHOLDERS,
            'sample' => ['title' => $sample['title'] ?? 'Dr.', 'name' => $sample['name'] ?? 'Amina Juma'],
            'photo' => PhotoController::cards((int) $h->account_id, array_key_exists((string) $h->theme, GreetingThemes::all()) ? (string) $h->theme : 'generic'),
            'bundles' => GreetingSender::bundleCounts($h), 'auto' => $this->autoCard($h), 'sentTo' => $h->deliveries()->whereNotNull('bundle_name')->distinct()->pluck('bundle_name')->values(),
            'lists' => $lists, 'summary' => $summary, 'smsReady' => $this->smsReady(), 'themes' => GreetingThemes::choices(), 'kinds' => collect(HolidayDay::KINDS)->map(fn ($l) => __($l))->all(),
            'can' => ['manage' => $me->can('manage-holidays'), 'prepare' => $me->can('prepare-holiday-greetings'), 'send' => $me->can('send-holiday-greetings'), 'print' => $me->can('print-holiday-greetings'), 'photos' => $me->can('manage-holiday-photos')],
        ]);
    }

    private function autoCard(HolidayDay $h): array
    {
        $p = $h->autoPlan();
        $def = HolidaySettings::autoDefaults();
        $name = fn (?int $id) => $id ? (HolidayBundle::query()->whereKey($id)->value('name') ?: __('(removed bundle)')) : __('The whole greeting list');

        return $p + ['at' => $h->autoSendAt()->format('Y-m-d H:i'), 'atText' => $h->autoSendAt()->locale('en')->translatedFormat('l j F Y, H:i'), 'bundleName' => $name($p['bundle_id']), 'attempted' => (bool) $h->auto_attempted_at,
            'default' => $def + ['bundleName' => $name($def['bundle_id'])], 'date' => $h->date->format('Y-m-d')];
    }

    public function store(Request $request)
    {
        $this->need('manage-holidays');
        $d = $request->validate(['name' => 'required|string|max:160', 'date' => 'required|date_format:Y-m-d', 'theme' => ['nullable', 'string', Rule::in(array_keys(GreetingThemes::all()))], 'force_include' => 'nullable|boolean', 'note' => 'nullable|string|max:500'],
            ['name.required' => __('Enter the name of the holiday.'), 'date.required' => __('Choose the date of the holiday.'), 'date.date_format' => __('Choose the date of the holiday.')]);
        $date = Carbon::createFromFormat('Y-m-d', $d['date'], config('app.timezone'));
        $account = (int) $request->user()->account_id;
        $dup = HolidayDay::query()->whereDate('date', $date->toDateString())->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($d['name']))])->exists();
        if ($dup) {
            return back()->withErrors(['name' => __('That holiday is already on the list for that date.')]);
        }
        $h = HolidayDay::create(['account_id' => $account, 'year' => (int) $date->year, 'key' => 'custom-' . Str::lower(Str::random(8)), 'name' => trim($d['name']), 'theme' => $d['theme'] ?? 'generic', 'kind' => 'custom',
            'date' => $date->toDateString(), 'date_confirmed' => true, 'is_enabled' => true, 'force_include' => (bool) ($d['force_include'] ?? false), 'note' => $d['note'] ?? null, 'created_by_user_id' => $request->user()->id]);
        log_activity('Holiday added', ['name' => $h->name, 'date' => $h->date->format('Y-m-d'), 'theme' => $h->theme], $h, 'Holiday Greetings');

        // a day of the company's own: straight to its page, where the RANS greeting is ready to read, change and send
        return redirect()->route('holiday-greetings.holidays.show', $h->id)->with('message', __(':n was added. The RANS greeting is ready: read it, change it if you like, and send.', ['n' => $h->name]));
    }

    public function update(Request $request, int $holiday)
    {
        $this->need('manage-holidays');
        $h = $this->holiday($holiday);
        $d = $request->validate([
            'name' => 'required|string|max:160', 'date' => 'required|date_format:Y-m-d', 'theme' => ['required', 'string', Rule::in(array_keys(GreetingThemes::all()))],
            'is_enabled' => 'required|boolean', 'force_include' => 'required|boolean', 'date_confirmed' => 'required|boolean', 'note' => 'nullable|string|max:500',
        ], ['name.required' => __('Enter the name of the holiday.'), 'date.required' => __('Choose the date of the holiday.')]);
        $date = Carbon::createFromFormat('Y-m-d', $d['date'], config('app.timezone'));
        if ($h->locked() && $date->toDateString() !== $h->date->format('Y-m-d')) {
            return back()->withErrors(['date' => __('The greetings of this holiday have been sent, so its date cannot change any more.')]);
        }
        if ((int) $date->year !== (int) $h->year && HolidayDay::query()->where('year', $date->year)->where('key', $h->key)->where('id', '!=', $h->id)->exists()) {
            return back()->withErrors(['date' => __('This holiday already exists in :y.', ['y' => $date->year])]);
        }
        $before = $h->only(['name', 'date', 'theme', 'is_enabled', 'force_include', 'date_confirmed']);
        $moved = $date->toDateString() !== $h->date->format('Y-m-d');
        $h->fill(['name' => trim($d['name']), 'date' => $date->toDateString(), 'year' => (int) $date->year, 'theme' => $d['theme'], 'is_enabled' => (bool) $d['is_enabled'], 'force_include' => (bool) $d['force_include'],
            'date_confirmed' => (bool) $d['date_confirmed'], 'note' => $d['note'] ?? null]);
        if ($moved && ! $h->locked()) {
            $h->reminder_sent_at = null; // the new date has its own reminder
        }
        $h->save();
        log_activity('Holiday changed', ['before' => $before, 'after' => $h->only(['name', 'date', 'theme', 'is_enabled', 'force_include', 'date_confirmed'])], $h, 'Holiday Greetings');

        return back()->with('message', __('The holiday was saved.'));
    }

    public function destroy(Request $request, int $holiday)
    {
        $this->need('manage-holidays');
        $h = $this->holiday($holiday);
        if ($h->locked()) {
            return back()->withErrors(['holiday' => __('The greetings of this holiday were sent, so its record cannot be removed.')]);
        }
        log_activity('Holiday removed', ['name' => $h->name, 'date' => $h->date->format('Y-m-d')], $h, 'Holiday Greetings');
        $h->delete();

        return redirect()->route('holiday-greetings.dashboard', ['year' => $h->year])->with('message', __(':n was removed from the holidays.', ['n' => $h->name]));
    }

    /** Sends the reminder for this holiday to the Super Admins now (instead of waiting for the hour). */
    public function remindNow(Request $request, int $holiday)
    {
        $this->need('manage-holidays');
        $h = $this->holiday($holiday);
        if (! $h->counts()) {
            return back()->withErrors(['holiday' => __('This holiday does not count (it is switched off, or it falls on a weekend).')]);
        }
        $r = HolidayNotifier::remind($h);
        $ok = collect($r)->where('ok', true)->count();

        return back()->with('message', __('The reminder was sent (:n messages).', ['n' => $ok]));
    }
}
