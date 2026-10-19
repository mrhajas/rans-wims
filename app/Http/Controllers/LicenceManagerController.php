<?php

namespace App\Http\Controllers;

use App\Models\Licence;
use App\Models\LicenceEvent;
use App\Services\Licensing\LicenceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Licence Manager: issue licences, see their whole history, download the encrypted licence file, deactivate / reactivate,
 * print the licence report. Reachable only by Dr Hajas (route middleware `licence.manager`).
 */
class LicenceManagerController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $licences = Licence::query()->with(['issuedBy:id,name', 'appliedBy:id,name', 'deactivatedBy:id,name'])->orderByDesc('id')->get();
        $current = LicenceService::current(true);

        return Inertia::render('Licence/Manager', [
            'current' => $current ? $this->present($current, true) : null,
            'licences' => $licences->map(fn (Licence $l): array => $this->present($l, true))->values(),
            'events' => LicenceEvent::query()->with(['user:id,name', 'licence:id,licence_number,licensee'])->latest('id')->limit(1000)->get()->map(fn (LicenceEvent $e): array => [
                'id' => $e->id, 'event' => $e->event, 'note' => $e->note, 'licence' => $e->licence?->licence_number, 'licensee' => $e->licence?->licensee, 'by' => $e->user?->name ?? 'System',
                'at' => $e->created_at?->toIso8601String(), 'ip' => $e->ip_address,
            ])->values(),
            'stats' => [
                'issued' => $licences->count(),
                'active' => $licences->filter(fn (Licence $l): bool => in_array($l->state, ['active', 'expiring'], true))->count(),
                'expired' => $licences->filter(fn (Licence $l): bool => $l->state === 'expired')->count(),
                'deactivated' => $licences->where('status', Licence::DEACTIVATED)->count(),
                'pending' => $licences->where('status', Licence::ISSUED)->count(),
            ],
            'defaults' => [
                'licensee' => (string) (get_settings('name') ?: 'RANS COMPANY LIMITED'),
                'starts_at' => today()->toDateString(),
                'duration' => 24,
                'edition' => 'Enterprise',
                'licence_type' => 'Subscription',
            ],
            'licenseeOptions' => $this->licenseeOptions(),
            'viewAccess' => $this->viewAccess($licences, $current),
            'editions' => Licence::EDITIONS,
            'types' => Licence::TYPES,
            'supportLevels' => ['Standard', 'Priority', 'Premium'],
            'moduleOptions' => $this->moduleOptions(),
            'contact' => LicenceService::CONTACT,
            'serverNow' => now()->toIso8601String(),
        ]);
    }

    /** One row per company ("Licensed to"): is the WIMS Licence icon and page switched on for them? */
    private function viewAccess($licences, $current): array
    {
        $map = LicenceService::viewAccessMap();

        return $licences->groupBy(fn (Licence $l) => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $l->licensee))))->map(function ($group, $key) use ($map, $current) {
            $first = $group->sortByDesc('id')->first();

            return [
                'licensee' => $first->licensee, 'allowed' => ($map[$key] ?? true) === true, 'licences' => $group->count(),
                'current' => $current && mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $current->licensee))) === $key,
            ];
        })->sortByDesc('current')->values()->all();
    }

    /** Switch the WIMS Licence icon and page on or off for one company. */
    public function viewAccessUpdate(Request $request)
    {
        $data = $request->validate(['licensee' => 'required|string|max:190', 'allowed' => 'required|boolean']);
        $match = Licence::query()->whereRaw('LOWER(TRIM(licensee)) = ?', [mb_strtolower(trim($data['licensee']))])->orderByDesc('id')->first();
        abort_unless($match, 404, 'No licence was issued to that company.');

        LicenceService::setViewAccess($match->licensee, (bool) $data['allowed'], (int) $request->user()->account_id);
        LicenceService::event($match, 'edited', $request->user(), $data['allowed'] ? __('Licence View Icon switched on: the company can open its Licence page.') : __('Licence View Icon switched off: the company cannot open its Licence page.'));
        log_activity('Licence view access '.($data['allowed'] ? 'allowed' : 'blocked'), ['licensee' => $match->licensee], $request->user(), 'Licence');

        return back()->with('message', $data['allowed'] ? __('Licence View Icon is on for :c.', ['c' => $match->licensee]) : __('Licence View Icon is off for :c.', ['c' => $match->licensee]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'licensee' => 'required|string|max:190',
            'edition' => ['required', Rule::in(Licence::EDITIONS)],
            'licence_type' => ['required', Rule::in(Licence::TYPES)],
            'starts_at' => 'required|date',
            'duration' => 'nullable|integer|min:1|max:120',
            'expires_at' => 'nullable|date|after_or_equal:starts_at',
            'max_users' => 'nullable|integer|min:1|max:100000',
            'support_level' => 'nullable|string|max:60',
            'modules' => 'nullable|string|max:190',
            'notes' => 'nullable|string|max:2000',
            'activate_now' => 'nullable|boolean',
        ]);
        if (empty($data['expires_at'])) {
            abort_if(empty($data['duration']), 422, 'Choose a duration or an end date.');
            $data['expires_at'] = Carbon::parse($data['starts_at'])->addMonths((int) $data['duration'])->toDateString();
        }

        $licence = LicenceService::issue($data, $request->user());
        if ($request->boolean('activate_now')) {
            LicenceService::activate($licence, $request->user());
        }
        log_activity(__('Licence :n issued.', ['n' => $licence->licence_number]), ['licensee' => $licence->licensee, 'from' => $licence->starts_at->toDateString(), 'to' => $licence->expires_at->toDateString(), 'activated' => $request->boolean('activate_now')], $request->user(), 'Licence');

        return back()->with('message', __('Licence :n issued, valid :from to :to.', ['n' => $licence->licence_number, 'from' => $licence->starts_at->format('d M Y'), 'to' => $licence->expires_at->format('d M Y')]));
    }

    public function update(Request $request, Licence $licence)
    {
        $data = $request->validate([
            'licensee' => 'required|string|max:190',
            'edition' => ['required', Rule::in(Licence::EDITIONS)],
            'licence_type' => ['required', Rule::in(Licence::TYPES)],
            'starts_at' => 'required|date',
            'expires_at' => 'required|date|after_or_equal:starts_at',
            'max_users' => 'nullable|integer|min:1|max:100000',
            'support_level' => 'nullable|string|max:60',
            'modules' => 'nullable|string|max:190',
            'notes' => 'nullable|string|max:2000',
        ]);
        $before = $licence->only(['licensee', 'edition', 'starts_at', 'expires_at']);
        $licence = LicenceService::update($licence, $data, $request->user());
        log_activity(__('Licence :n edited.', ['n' => $licence->licence_number]), ['before' => $before], $request->user(), 'Licence');

        return back()->with('message', __('Licence :n updated. The history keeps every change.', ['n' => $licence->licence_number]));
    }

    public function destroy(Request $request, Licence $licence)
    {
        $number = $licence->licence_number;
        $info = ['licensee' => $licence->licensee, 'from' => $licence->starts_at->toDateString(), 'to' => $licence->expires_at->toDateString(), 'status' => $licence->status];
        LicenceService::delete($licence, $request->user());
        log_activity(__('Licence :n deleted.', ['n' => $number]), $info, $request->user(), 'Licence');

        return back()->with('message', __('Licence :n deleted.', ['n' => $number]));
    }

    public function activate(Request $request, Licence $licence)
    {
        LicenceService::activate($licence, $request->user());
        log_activity(__('Licence :n activated.', ['n' => $licence->licence_number]), [], $request->user(), 'Licence');

        return back()->with('message', __('Licence :n is now the active licence.', ['n' => $licence->licence_number]));
    }

    public function deactivate(Request $request, Licence $licence)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500', 'password' => 'required|string']);
        $this->confirmPassword($request);
        abort_if($licence->status === Licence::DEACTIVATED, 422, 'This licence is already deactivated.');
        LicenceService::deactivate($licence, $request->user(), $data['reason'] ?? null);
        log_activity(__('Licence :n deactivated.', ['n' => $licence->licence_number]), ['reason' => $data['reason'] ?? null], $request->user(), 'Licence');

        return back()->with('message', __('Licence :n deactivated. Everyone except you is now blocked from signing in.', ['n' => $licence->licence_number]));
    }

    public function reactivate(Request $request, Licence $licence)
    {
        $request->validate(['password' => 'required|string']);
        $this->confirmPassword($request);
        abort_unless($licence->status === Licence::DEACTIVATED, 422, 'Only a deactivated licence can be reactivated.');
        abort_if($licence->expires_at->lt(today()), 422, 'This licence has expired. Issue a new licence instead.');
        LicenceService::reactivate($licence, $request->user());
        log_activity(__('Licence :n reactivated.', ['n' => $licence->licence_number]), [], $request->user(), 'Licence');

        return back()->with('message', __('Licence :n reactivated.', ['n' => $licence->licence_number]));
    }

    /** Deactivating or reactivating a licence affects every user, so the manager must type their own password again (5 tries a minute). */
    private function confirmPassword(Request $request): void
    {
        $key = 'licence-password|'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['password' => __('Too many wrong passwords. Try again in :s seconds.', ['s' => RateLimiter::availableIn($key)])]);
        }
        if (! Hash::check((string) $request->input('password'), (string) $request->user()->password)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['password' => __('That is not your password. Nothing was changed.')]);
        }
        RateLimiter::clear($key);
    }

    /** Download the encrypted licence file to send to the Super Admins. */
    public function download(Request $request, Licence $licence)
    {
        LicenceService::event($licence, 'file-downloaded', $request->user(), __('Encrypted licence file downloaded.'));

        return response(LicenceService::fileContents($licence), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$licence->licence_number.'.'.LicenceService::FILE_EXTENSION.'"',
        ]);
    }

    public function print(Request $request, Licence $licence)
    {
        LicenceService::event($licence, 'printed', $request->user(), __('Licence report printed.'));

        return view('licence-report', WimsLicenceController::reportData($licence, $request->user(), true));
    }

    /** @return array<string, mixed> */
    private function present(Licence $l, bool $withKey): array
    {
        return [
            'id' => $l->id, 'number' => $l->licence_number, 'licensee' => $l->licensee, 'edition' => $l->edition, 'type' => $l->licence_type,
            'status' => $l->status, 'state' => $l->state, 'starts_at' => $l->starts_at->toDateString(), 'expires_at' => $l->expires_at->toDateString(),
            'ends_at_moment' => $l->endsAtMoment()->toIso8601String(), 'days_left' => $l->days_left, 'duration' => $l->duration_label, 'elapsed' => $l->elapsed_percent, 'max_users' => $l->max_users,
            'support_level' => $l->meta['support_level'] ?? null, 'modules' => $l->meta['modules'] ?? null, 'notes' => $l->notes,
            'issued_at' => optional($l->issued_at)->toIso8601String(), 'issued_by' => $l->issuedBy?->name,
            'applied_at' => optional($l->applied_at)->toIso8601String(), 'applied_by' => $l->appliedBy?->name,
            'deactivated_at' => optional($l->deactivated_at)->toIso8601String(), 'deactivated_by' => $l->deactivatedBy?->name, 'deactivation_reason' => $l->deactivation_reason,
            'key' => $withKey ? $l->getRawOriginal('key') : null, 'key_blocks' => $withKey ? $l->keyBlocks() : null,
        ];
    }

    /** Common "Modules covered" values: the standard ones plus anything used on earlier licences. */
    private function moduleOptions(): array
    {
        $used = Licence::query()->pluck('meta')->map(fn ($m) => is_array($m) ? ($m['modules'] ?? null) : null);

        return collect(['All modules', 'Core modules', 'Inventory and Stores', 'Finance and Payments', 'Projects and Site Operations', 'Human Resources and Payroll', 'Compliance and Archive'])
            ->merge($used)->map(fn ($n) => trim((string) $n))->filter()->unique(fn ($n) => mb_strtolower($n))->values()->all();
    }

    /** Names that can be picked as "Licensed to": the company, everyone a licence was issued to before, and the people with a WIMS account. */
    private function licenseeOptions(): array
    {
        return collect([(string) get_settings('name')])
            ->merge(Licence::query()->distinct()->pluck('licensee'))
            ->merge(\App\Models\User::query()->where('is_active', true)->orderBy('name')->pluck('name'))
            ->map(fn ($n) => trim((string) $n))->filter()->unique(fn ($n) => mb_strtolower($n))->values()->all();
    }
}
