<?php

namespace App\Http\Controllers\HolidayGreetings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HolidayGreetings\Concerns\Guards;
use App\Services\HolidayGreetings\HolidayNotifier;
use App\Services\HolidayGreetings\HolidaySettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Holiday Greetings settings: when the reminder goes out, whether weekend holidays count, which channels remind, and how the greetings are signed. */
class SettingsController extends Controller
{
    use Guards;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function show(Request $request)
    {
        $this->need('manage-holiday-settings');
        $people = HolidayNotifier::recipients((int) $request->user()->account_id)->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->hasRole('Super Admin') ? __('Super Admin') : __('Given the reminder permission')])->values();

        return Inertia::render('HolidayGreetings/Settings', ['prefs' => HolidaySettings::all(), 'people' => $people, 'smsReady' => $this->smsReady(), 'photos' => PhotoController::cards((int) $request->user()->account_id), 'canPhotos' => $request->user()->can('manage-holiday-photos'), 'assetBase' => HolidaySettings::assetBase(),
            'bundles' => \App\Models\HolidayBundle::query()->orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values()]);
    }

    public function update(Request $request)
    {
        $this->need('manage-holiday-settings');
        $d = $request->validate([
            'hours_before' => 'required|integer|min:1|max:72', 'skip_weekends' => 'required|boolean', 'remind_email' => 'required|boolean', 'remind_sms' => 'required|boolean', 'remind_app' => 'required|boolean',
            'signoff' => 'required|string|max:120', 'company' => 'required|string|max:120', 'asset_url' => 'nullable|url|max:190',
            'auto_enabled' => 'required|boolean', 'auto_minutes' => 'required|integer|min:0|max:' . HolidaySettings::AUTO_MAX_MINUTES, 'auto_bundle' => 'nullable|integer|min:0',
        ], ['hours_before.required' => __('Enter the hours (1 to 72).'), 'signoff.required' => __('Enter how the greetings are signed.'), 'company.required' => __('Enter the company name shown on the greetings.'), 'auto_minutes.max' => __('The greetings can be sent at most 7 days before the holiday.')]);
        $auto = (int) ($d['auto_bundle'] ?? 0);
        if ($auto && ! \App\Models\HolidayBundle::query()->whereKey($auto)->exists()) {
            return back()->withErrors(['auto_bundle' => __('Choose a greeting list bundle from the list.')]);
        }
        $values = ['holiday_hours_before' => (string) $d['hours_before'], 'holiday_skip_weekends' => $d['skip_weekends'] ? '1' : '0', 'holiday_remind_email' => $d['remind_email'] ? '1' : '0',
            'holiday_remind_sms' => $d['remind_sms'] ? '1' : '0', 'holiday_remind_app' => $d['remind_app'] ? '1' : '0', 'holiday_signoff' => trim($d['signoff']), 'holiday_company' => trim($d['company']), 'holiday_auto_enabled' => $d['auto_enabled'] ? '1' : '0', 'holiday_auto_minutes' => (string) $d['auto_minutes'], 'holiday_auto_bundle' => $auto ? (string) $auto : '', 'holiday_asset_url' => rtrim(trim((string) ($d['asset_url'] ?? '')), '/')];
        $before = HolidaySettings::all();
        HolidaySettings::save($values, (int) $request->user()->account_id);
        log_activity('Holiday Greetings settings saved', ['before' => $before, 'after' => $values], null, 'Holiday Greetings');

        return back()->with('message', __('The settings were saved.'));
    }
}
