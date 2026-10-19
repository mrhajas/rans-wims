<?php

namespace App\Http\Controllers\Travels;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Travel;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Travels settings, soft-coded: the currencies offered, the kinds of travel, the home country (a travel elsewhere is International) and the days before departure on which a reminder goes out. */
class SettingsController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can('manage-travel-settings'), 403);

        return Inertia::render('Travels/Settings', [
            'values' => ['currencies' => implode(', ', array_map('strtoupper', array_values(array_filter(array_map('trim', explode(',', (string) (get_settings('travel_currencies') ?: Travel::DEFAULT_CURRENCIES))))))),
                'types' => implode(', ', Travel::travelTypes()), 'remind_days' => implode(', ', Travel::remindDays()), 'home_country' => Travel::homeCountry()],
            'defaults' => ['currencies' => Travel::DEFAULT_CURRENCIES, 'types' => Travel::DEFAULT_TYPES, 'remind_days' => '7, 1', 'home_country' => 'Tanzania'],
        ]);
    }

    public function update(Request $request)
    {
        abort_unless($request->user()->can('manage-travel-settings'), 403);
        $d = $request->validate(['currencies' => 'required|string|max:200', 'types' => 'required|string|max:1000', 'remind_days' => 'nullable|string|max:100', 'home_country' => 'required|string|max:100'], [
            'currencies.required' => __('List at least one currency, for example TZS, USD.'), 'types.required' => __('List at least one kind of travel.'), 'home_country.required' => __('Type the country the company is in, for example Tanzania.'),
        ]);
        $list = fn (string $v) => array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\n]+/', $v) ?: []), fn ($x) => $x !== '')));
        $cur = array_values(array_unique(array_map('strtoupper', $list($d['currencies']))));
        foreach ($cur as $c) {
            if (! preg_match('/^[A-Z]{3}$/', $c)) {
                return back()->withErrors(['currencies' => __('":c" is not a currency code. Use three letters, like TZS or USD.', ['c' => $c])]);
            }
        }
        $days = $list((string) ($d['remind_days'] ?? ''));
        foreach ($days as $n) {
            if (! ctype_digit($n) || (int) $n > 365) {
                return back()->withErrors(['remind_days' => __('Reminder days are whole numbers from 0 to 365, like 7, 1.')]);
            }
        }
        $values = ['travel_currencies' => implode(', ', $cur), 'travel_types' => implode(', ', $list($d['types'])), 'travel_remind_days' => implode(', ', $days), 'travel_home_country' => trim($d['home_country'])];
        foreach ($values as $k => $v) {
            Setting::updateOrCreate(['tec_key' => $k, 'account_id' => $request->user()->account_id], ['tec_value' => $v]);
        }
        log_activity('Travels settings saved', $values, null, 'Travels');

        return back()->with('message', __('Travels settings saved.'));
    }
}
