<?php

namespace App\Http\Controllers\SmartBrief;

use App\Http\Controllers\Controller;
use App\Services\SmartBrief\AiPolish;
use App\Services\SmartBrief\Access;
use App\Services\SmartBrief\Period;
use App\Services\SmartBrief\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Smart Brief > Settings: defaults and limits. */
class SmartBriefSettingsController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function guard(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->can('manage-smart-brief-settings') && Access::feature('smart_brief_settings', $user), 403);
    }

    public function index(Request $request)
    {
        $this->guard($request);
        $settings = Settings::get($request->user()->account_id);

        return Inertia::render('SmartBrief/Settings', [
            'prefs' => $settings,
            'periods' => collect(Period::KEYS)->reject(fn ($k) => $k === 'custom')->map(fn ($k) => ['key' => $k, 'label' => Period::resolve($k)->label])->values(),
            'aiAvailable' => AiPolish::available($settings),
            'defaults' => Settings::DEFAULTS,
        ]);
    }

    public function update(Request $request)
    {
        $this->guard($request);
        $data = $request->validate([
            'default_period' => ['required', 'in:' . implode(',', array_diff(Period::KEYS, ['custom']))],
            'max_range_days' => ['required', 'integer', 'min:1', 'max:366'],
            'timeline_limit' => ['required', 'integer', 'min:50', 'max:2000'],
            'show_line_items' => ['boolean'],
            'auto_clear_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'excluded_log_names' => ['nullable', 'array', 'max:50'],
            'excluded_log_names.*' => ['string', 'max:80'],
            'ai_enabled' => ['boolean'],
        ]);
        $before = Settings::get($request->user()->account_id);
        $after = Settings::save($data, $request->user()->account_id);
        log_activity('Smart Brief settings updated', ['before' => $before, 'after' => $after], null, 'Smart Brief');

        \App\Services\SmartBrief\Alerts::fire('settings_changed', $request->user(), null, __('Smart Brief settings changed'), __('The Smart Brief settings were changed by :n.', ['n' => $request->user()->name]), [], route('smart-brief.settings.index'));

        return back()->with('message', __('Smart Brief settings saved.'));
    }
}
