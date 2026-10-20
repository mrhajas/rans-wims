<?php

namespace App\Http\Controllers\WeeklyAdministration;

use App\Http\Controllers\Controller;
use App\Models\AdminDocument;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Administration settings, soft-coded: the department wording on the document, its currency and the wording of a charity line. */
class SettingsController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can('manage-administration-settings'), 403);

        return Inertia::render('WeeklyAdministration/Settings', [
            'values' => ['department' => AdminDocument::departmentName(), 'currency' => AdminDocument::currency(), 'charity_wording' => AdminDocument::charityWording()],
            'defaults' => ['department' => 'ADMINISTRATION', 'currency' => 'TZS', 'charity_wording' => 'CHARITY - :name FOR :month'],
        ]);
    }

    public function update(Request $request)
    {
        abort_unless($request->user()->can('manage-administration-settings'), 403);
        $d = $request->validate(['department' => 'required|string|max:120', 'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'], 'charity_wording' => 'required|string|max:190'], [
            'currency.regex' => __('Use three letters, like TZS or USD.'), 'charity_wording.required' => __('Write the wording, for example CHARITY - :name FOR :month.'),
        ]);
        if (! str_contains($d['charity_wording'], ':name')) {
            return back()->withErrors(['charity_wording' => __('The wording must contain :name so each line says who it is for.')]);
        }
        $values = ['admin_department_name' => trim($d['department']), 'admin_currency' => strtoupper($d['currency']), 'admin_charity_wording' => trim($d['charity_wording'])];
        foreach ($values as $k => $v) {
            Setting::updateOrCreate(['tec_key' => $k, 'account_id' => $request->user()->account_id], ['tec_value' => $v]);
        }
        log_activity('Administration settings saved', $values, null, 'Administration');

        return back()->with('message', __('Administration settings saved.'));
    }
}
