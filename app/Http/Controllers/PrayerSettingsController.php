<?php

namespace App\Http\Controllers;

use App\Models\PrayerAdhan;
use App\Models\PrayerMessage;
use App\Models\User;
use App\Services\Prayer\PrayerService;
use App\Services\Prayer\PrayerTimes;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Prayer Times > Settings: switch the module on, place and calculation method, who is locked, the alert and lock minutes, the adhan, the verses. */
class PrayerSettingsController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function allow(Request $request): void
    {
        // Super Admin, or a role that was given "manage-prayer-times" in the Roles page (nobody else, and nobody has it until it is given)
        abort_unless($request->user()->hasRole('Super Admin') || $request->user()->can('manage-prayer-times'), 403);
    }

    /** The schedule for the signed-in person (the pages ask for it every minute so a settings change shows up without a refresh). */
    public function state(Request $request)
    {
        return response()->json(PrayerService::share($request->user()));
    }

    public function show(Request $request)
    {
        $this->allow($request);
        $c = PrayerService::config();
        $now = PrayerService::now();

        return Inertia::render('Prayer/Settings', [
            'config' => $c,
            'methods' => collect(PrayerTimes::METHODS)->map(fn ($m, $k) => ['value' => $k, 'label' => $m['label'].' (Fajr '.$m['fajr'].'°, Isha '.(is_string($m['isha']) ? $m['isha'] : $m['isha'].'°').')'])->values(),
            'adhans' => collect(PrayerService::adhans())->map(fn ($a, $k) => ['value' => $k, 'label' => $a['label'], 'url' => $a['file'], 'note' => $a['note'], 'custom' => $a['custom'], 'id' => $a['id'] ?? null])->values(),
            'today' => collect(PrayerService::timesFor($now->startOfDay()))->map(fn ($t, $k) => ['key' => $k, 'label' => $k === 'sunrise' ? 'Sunrise' : PrayerService::PRAYERS[$k], 'time' => $t->format('h:i A')])->values(),
            'phase' => collect(PrayerService::phase($now))->only(['phase', 'label'])->all(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'username'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'username' => $u->username]),
            'messages' => PrayerMessage::query()->orderBy('id')->get(),
            // choices for the Place box: each city fills in its coordinates and time zone (and can still be changed by hand)
            'timezones' => collect([
                // Zanzibar keeps the same clock as the mainland (UTC+3, no daylight saving); PHP files it under Dar es Salaam
                'Africa/Dar_es_Salaam' => 'Zanzibar / Dar es Salaam (UTC+3)', 'Africa/Nairobi' => 'Nairobi (UTC+3)',
                'Africa/Kampala' => 'Kampala (UTC+3)', 'Africa/Mogadishu' => 'Mogadishu (UTC+3)', 'Africa/Addis_Ababa' => 'Addis Ababa (UTC+3)',
                'Africa/Kigali' => 'Kigali (UTC+2)', 'Africa/Lusaka' => 'Lusaka (UTC+2)', 'Africa/Maputo' => 'Maputo (UTC+2)',
                'Africa/Johannesburg' => 'Johannesburg (UTC+2)', 'Africa/Cairo' => 'Cairo (UTC+2/+3)', 'Asia/Riyadh' => 'Riyadh / Makkah (UTC+3)',
                'Asia/Dubai' => 'Dubai (UTC+4)', 'Asia/Muscat' => 'Muscat (UTC+4)', 'Europe/London' => 'London (UK Time)', 'UTC' => 'UTC (Universal Time)',
            ])->map(fn ($label, $zone) => ['value' => $zone, 'label' => $label])->values(),
            'cities' => [
                ['label' => 'Zanzibar (Stone Town)', 'value' => 'Zanzibar', 'lat' => -6.1659, 'lng' => 39.2026, 'tz' => 'Africa/Dar_es_Salaam'],
                ['label' => 'Pemba (Chake Chake)', 'value' => 'Pemba', 'lat' => -5.2434, 'lng' => 39.7656, 'tz' => 'Africa/Dar_es_Salaam'],
                ['label' => 'Dar es Salaam', 'value' => 'Dar es Salaam', 'lat' => -6.7924, 'lng' => 39.2083, 'tz' => 'Africa/Dar_es_Salaam'],
                ['label' => 'Dodoma', 'value' => 'Dodoma', 'lat' => -6.1630, 'lng' => 35.7516, 'tz' => 'Africa/Dar_es_Salaam'],
                ['label' => 'Arusha', 'value' => 'Arusha', 'lat' => -3.3869, 'lng' => 36.6830, 'tz' => 'Africa/Dar_es_Salaam'],
                ['label' => 'Mwanza', 'value' => 'Mwanza', 'lat' => -2.5164, 'lng' => 32.9175, 'tz' => 'Africa/Dar_es_Salaam'],
                ['label' => 'Tanga', 'value' => 'Tanga', 'lat' => -5.0689, 'lng' => 39.0988, 'tz' => 'Africa/Dar_es_Salaam'],
                ['label' => 'Mbeya', 'value' => 'Mbeya', 'lat' => -8.9094, 'lng' => 33.4608, 'tz' => 'Africa/Dar_es_Salaam'],
                ['label' => 'Mombasa', 'value' => 'Mombasa', 'lat' => -4.0435, 'lng' => 39.6682, 'tz' => 'Africa/Nairobi'],
                ['label' => 'Nairobi', 'value' => 'Nairobi', 'lat' => -1.2921, 'lng' => 36.8219, 'tz' => 'Africa/Nairobi'],
                ['label' => 'Kampala', 'value' => 'Kampala', 'lat' => 0.3476, 'lng' => 32.5825, 'tz' => 'Africa/Kampala'],
                ['label' => 'Kigali', 'value' => 'Kigali', 'lat' => -1.9441, 'lng' => 30.0619, 'tz' => 'Africa/Kigali'],
                ['label' => 'Makkah', 'value' => 'Makkah', 'lat' => 21.4225, 'lng' => 39.8262, 'tz' => 'Asia/Riyadh'],
                ['label' => 'Madinah', 'value' => 'Madinah', 'lat' => 24.4672, 'lng' => 39.6111, 'tz' => 'Asia/Riyadh'],
            ],
            'serverNow' => $now->toIso8601String(),
        ]);
    }

    public function update(Request $request)
    {
        $this->allow($request);
        $data = $request->validate([
            'enabled' => 'boolean', 'city' => 'required|string|max:80',
            'lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180', 'elevation' => 'nullable|numeric|between:0,5000',
            'timezone' => ['required', Rule::in(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC))],
            'method' => ['required', Rule::in(array_keys(PrayerTimes::METHODS))], 'asr' => 'required|in:1,2',
            'adjust' => 'array', 'adjust.*' => 'integer|between:-60,60',
            'prayers' => 'array', 'prayers.*' => 'boolean',
            'alert_before_min' => 'required|integer|between:1,120', 'notice_before_min' => 'required|integer|min:0|lte:alert_before_min', 'lock_minutes' => 'required|integer|between:1,180', 'shutdown' => 'boolean',
            'all_users' => 'boolean', 'user_ids' => 'array', 'user_ids.*' => 'integer|exists:users,id', 'excluded_user_ids' => 'array', 'excluded_user_ids.*' => 'integer|exists:users,id',
            'exempt_super_admins' => 'boolean',
            'adhan_enabled' => 'boolean', 'adhan' => ['required', Rule::in(array_keys(PrayerService::adhans()))], 'adhan_volume' => 'required|integer|between:0,100',
            'show_in_topbar' => 'boolean', 'show_on_dashboard' => 'boolean',
        ]);
        $data['lock_start_after_min'] = 0; // WIMS locks when the countdown ends, at the prayer time
        PrayerService::save($data, (int) $request->user()->account_id);
        log_activity(__('Prayer Times settings saved.'), ['enabled' => (bool) ($data['enabled'] ?? false)], $request->user(), 'Settings');

        return back()->with('message', __('Prayer Times settings saved.'));
    }

    /** Adds an adhan recording: a name and an audio file. It is listed with the others and can be chosen straight away. */
    public function adhanStore(Request $request)
    {
        $this->allow($request);
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'file' => 'required|file|mimes:mp3,mpga,wav,ogg,oga,m4a,aac,webm|max:12288',
        ], [
            'file.mimes' => __('Choose an audio file (MP3, WAV, OGG, M4A or AAC).'), 'file.max' => __('The audio file is too big. The most allowed is 12 MB.'),
        ]);
        $file = $request->file('file');
        $dir = public_path('audio/adhan/custom');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // Never trust the name the browser sent: only a known audio extension is ever written to the public folder.
        $allowed = ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'webm'];
        $ext = strtolower((string) $file->guessExtension());
        $ext = $ext === 'mpga' ? 'mp3' : $ext;
        if (! in_array($ext, $allowed, true)) {
            $client = strtolower($file->getClientOriginalExtension());
            $ext = in_array($client, $allowed, true) ? $client : 'mp3';
        }
        $stored = Str::random(24).'.'.$ext;
        $file->move($dir, $stored);
        $adhan = PrayerAdhan::create([
            'name' => trim($data['name']), 'file' => 'audio/adhan/custom/'.$stored, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 190),
            'mime' => $file->getClientMimeType(), 'size' => (int) @filesize($dir.'/'.$stored), 'uploaded_by' => $request->user()->id,
        ]);
        log_activity(__('Adhan added: :n', ['n' => $adhan->name]), ['adhan_id' => $adhan->id], $request->user(), 'Settings');

        return back()->with('message', __('The adhan was added. Select it and press Save to use it.'));
    }

    /** Deletes an uploaded adhan. If it was the active one, Sabah Fakhri becomes active. The two built-in adhans cannot be deleted. */
    public function adhanDestroy(Request $request, PrayerAdhan $adhan)
    {
        $this->allow($request);
        $key = $adhan->key();
        $saved = json_decode((string) \App\Models\Setting::query()->where('tec_key', PrayerService::KEY)->where('account_id', (int) $request->user()->account_id)->value('tec_value'), true);
        $wasActive = ($saved['adhan'] ?? null) === $key;
        $path = public_path($adhan->file);
        $name = $adhan->name;
        $adhan->delete();
        if (is_file($path) && str_starts_with(realpath($path) ?: '', realpath(public_path('audio/adhan/custom')) ?: "\0")) {
            @unlink($path);
        }
        if ($wasActive) {
            PrayerService::save(['adhan' => PrayerService::FALLBACK_ADHAN], (int) $request->user()->account_id);
        }
        log_activity(__('Adhan deleted: :n', ['n' => $name]), ['adhan_id' => $adhan->id, 'was_active' => $wasActive], $request->user(), 'Settings');

        return back()->with('message', $wasActive ? __('The adhan was deleted. Adhan, Sabah Fakhri is active now.') : __('The adhan was deleted.'));
    }

    public function messageStore(Request $request)
    {
        $this->allow($request);
        $data = $this->messageData($request);
        PrayerMessage::create($data + ['is_custom' => true]);

        return back()->with('message', __('Message added.'));
    }

    public function messageUpdate(Request $request, PrayerMessage $message)
    {
        $this->allow($request);
        $message->update($request->has('only_active') ? ['is_active' => (bool) $request->input('is_active')] : $this->messageData($request));

        return back()->with('message', __('Message saved.'));
    }

    public function messageDestroy(Request $request, PrayerMessage $message)
    {
        $this->allow($request);
        abort_unless($message->is_custom, 422, 'The built-in verses can be switched off but not deleted.');
        $message->delete();

        return back()->with('message', __('Message deleted.'));
    }

    private function messageData(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', Rule::in(['ayah', 'hadith'])], 'prayer' => ['required', Rule::in(['any', 'fajr', 'dhuhr', 'asr', 'maghrib', 'isha'])],
            'arabic' => 'nullable|string|max:2000', 'text_en' => 'required|string|max:2000', 'text_sw' => 'nullable|string|max:2000', 'reference' => 'nullable|string|max:190', 'is_active' => 'boolean',
        ]);
    }
}
