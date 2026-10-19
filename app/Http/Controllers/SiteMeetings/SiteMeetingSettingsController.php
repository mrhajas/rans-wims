<?php

namespace App\Http\Controllers\SiteMeetings;

use App\Services\SiteMeetings\SiteMeetingLogger;
use App\Services\SiteMeetings\SiteMeetingNotifier;
use App\Services\SiteMeetings\SiteMeetingSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SiteMeetingSettingsController extends SiteMeetingBaseController
{
    private function guard(Request $request)
    {
        abort_unless($this->allows($request->user(), 'manage-site-meetings-settings'), 403);

        return $request->user();
    }

    public function show(Request $request)
    {
        $user = $this->guard($request);
        $s = $this->settings($user);

        return Inertia::render('SiteMeetings/Settings', [
            'config' => $s->all(),
            'styles' => $s->styles(),
            'template' => $s->template(),
            'rules' => $s->rules(),
            'events' => collect(SiteMeetingSettings::EVENTS)->map(fn ($e, $k) => ['key' => $k, 'label' => $e['label'], 'hint' => $e['hint']])->values(),
            'whoGroups' => collect(SiteMeetingSettings::WHO)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'canOpenWorkflow' => $user->hasRole('Super Admin'),
            'colors' => SiteMeetingSettings::COLORS,
            'columnTypes' => SiteMeetingSettings::COLUMN_TYPES,
            'weekdays' => [['v' => 1, 'l' => 'Mon'], ['v' => 2, 'l' => 'Tue'], ['v' => 3, 'l' => 'Wed'], ['v' => 4, 'l' => 'Thu'], ['v' => 5, 'l' => 'Fri'], ['v' => 6, 'l' => 'Sat'], ['v' => 0, 'l' => 'Sun']],
            'isDefaultTemplate' => ! \App\Models\Setting::query()->where('account_id', $user->account_id)->where('tec_key', SiteMeetingSettings::PREFIX.'template')->exists(),
        ]);
    }

    public function update(Request $request)
    {
        $user = $this->guard($request);
        $data = $request->validate([
            'week_start' => ['required', 'integer', 'between:0,6'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:0,6'],
            'default_start_time' => ['required', 'date_format:H:i'],
            'default_duration_min' => ['required', 'integer', 'between:15,720'],
            'default_venue' => ['nullable', 'string', 'max:190'],
            'title_prefix' => ['required', 'string', 'max:80'],
            'repeat_max_weeks' => ['required', 'integer', 'between:0,52'],
            'reminders_enabled' => ['boolean'],
            'reminder_days_before' => ['required', 'integer', 'between:0,14'],
            'history_view_minutes' => ['required', 'integer', 'between:1,1440'],
            'sign_left' => ['nullable', 'string', 'max:80'],
            'sign_right' => ['nullable', 'string', 'max:80'],
            'styles' => ['nullable', 'array'],
        ]);

        $s = $this->settings($user);
        $before = $s->all();
        foreach (array_keys(SiteMeetingSettings::DEFAULTS) as $key) {
            if ($key === 'weekdays') {
                $s->set('weekdays', implode(',', array_unique(array_map('intval', $data['weekdays']))));
            } elseif (array_key_exists($key, $data)) {
                $v = $data[$key];
                $s->set($key, is_bool($v) ? (int) $v : ($v ?? ''));
            }
        }
        if (isset($data['styles'])) {
            $s->saveStyles($data['styles']);
        }
        $after = $s->all();
        $changed = array_keys(array_filter($after, fn ($v, $k) => (string) ($before[$k] ?? '') !== (string) $v, ARRAY_FILTER_USE_BOTH));
        SiteMeetingLogger::log($user, null, 'SETTINGS_CHANGE', null, ['changed' => $changed]);
        if ($changed) {
            SiteMeetingNotifier::settingsChanged($user, str_replace('_', ' ', implode(', ', $changed)));
        }

        return back()->with('message', __('Settings saved.'));
    }

    /** Saves which people of the meeting each notice is for. Channels, extra roles / people and wording are on the Site Meetings workflow page. */
    public function saveNotifications(Request $request)
    {
        $user = $this->guard($request);
        $request->validate(['rules' => ['required', 'array']]);
        $s = $this->settings($user);
        $before = $s->rules();
        $after = $s->saveRules((array) $request->input('rules'));
        $changed = array_keys(array_filter($after, fn ($r, $k) => json_encode($r) !== json_encode($before[$k] ?? null), ARRAY_FILTER_USE_BOTH));
        SiteMeetingLogger::log($user, null, 'SETTINGS_CHANGE', null, ['changed' => array_map(fn ($k) => 'people of the meeting: '.$k, $changed)]);
        if ($changed) {
            SiteMeetingNotifier::settingsChanged($user, 'people of the meeting ('.implode(', ', $changed).')');
        }

        return back()->with('message', __('People of the meeting saved.'));
    }

    public function saveTemplate(Request $request)
    {
        $user = $this->guard($request);
        $request->validate(['sections' => ['required', 'array', 'min:1', 'max:30']]);
        $clean = $this->settings($user)->saveTemplate((array) $request->input('sections'));
        abort_if(! $clean, 422, __('The layout needs at least one section with a title.'));
        SiteMeetingLogger::log($user, null, 'TEMPLATE_CHANGE', null, ['sections' => array_column($clean, 'title')]);
        SiteMeetingNotifier::settingsChanged($user, 'minutes layout ('.count($clean).' sections)');

        return back()->with('message', __('Minutes layout saved. New meetings use it; existing meetings keep the layout they were created with.'));
    }

    public function resetTemplate(Request $request)
    {
        $user = $this->guard($request);
        $this->settings($user)->resetTemplate();
        SiteMeetingLogger::log($user, null, 'TEMPLATE_RESET');
        SiteMeetingNotifier::settingsChanged($user, 'minutes layout reset to the WIMS default');

        return back()->with('message', __('Minutes layout reset to the WIMS default.'));
    }
}
