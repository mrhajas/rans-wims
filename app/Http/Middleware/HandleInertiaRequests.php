<?php

namespace App\Http\Middleware;

use Inertia\Middleware;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use App\Services\FeatureManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use App\Notifications\AppNotification;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request)
    {
        // The language list never changes between requests: read it once per PHP process, not on every visit.
        static $langFiles = null;
        $langFiles ??= json_decode(File::get(base_path('lang/languages.json')));

        return array_merge(parent::share($request), [
            'demo'             => demo(),
            // Simulation WIMS: the signed-in simulation user always sees which WIMS this is (decided by the server, from the session); guests on the login page
            // get the trigger settings only when the simulation is switched on, otherwise null and the trigger does nothing.
            'environment'      => fn () => \App\Support\Simulation\Environment::isSimulation() && $request->user() ? \App\Services\Simulation\SimulationScenarios::banner() : null,
            'ctx'              => fn () => ! $request->user() && ! \App\Support\Simulation\Environment::isSimulation() && \App\Support\Simulation\Environment::available() ? config('simulation.trigger') : null,
            'settings'         => get_settings(),
            'languages'        => $langFiles->available,
            // the project / warehouse dropdowns show Main Warehouses, Projects and Others in sections unless this is switched off in Settings
            'groupedWarehouseLists' => fn () => get_settings('grouped_warehouse_lists') !== '0',
            // warehouses whose stock movement is halted by an open Adjustment Session: id => who, when, which session (the dropdowns mark and refuse them)
            'stockHalts'       => fn () => $request->user() ? \App\Support\StockHalt::forPages() : (object) [],
            'myWarehouses'     => fn () => $this->managedWarehouses($request),
            'currentWarehouse' => fn () => $this->currentWarehouse($request),
            'enabledFeatures'  => fn () => FeatureManager::enabledMap(),
            // True when this person ticked "Remember me": the long-lived remember cookie came with the request. The
            // 30-minute idle auto-logout is skipped for them (it would sign them out and cancel the remembered login).
            'rememberedLogin'  => fn () => $request->user() ? $request->hasCookie(\Illuminate\Support\Facades\Auth::guard('web')->getRecallerName()) : false,
            // Auto logout: minutes of inactivity before sign-out for this person (0 = never), counted on the whole session, any tab.
            'idle'             => fn () => $request->user() ? ['minutes' => \App\Support\IdleTimeout::minutesFor($request->user(), $request->hasCookie(\Illuminate\Support\Facades\Auth::guard('web')->getRecallerName())), 'options' => \App\Support\IdleTimeout::OPTIONS, 'default' => \App\Support\IdleTimeout::DEFAULT_MINUTES, 'chosen' => $request->user()->idle_timeout_minutes] : null,
            'impersonation'    => fn () => \App\Support\Impersonation::shareData(),
            // WIMS Licence: status for the footer ("Licence Active") and whether this person is the Licence Manager.
            'licenceStatus'    => fn () => \App\Services\Licensing\LicenceService::summary(),
            'licenceManager'   => fn () => \App\Services\Licensing\LicenceService::isManager($request->user()),
            'licenceViewAllowed' => fn () => \App\Services\Licensing\LicenceService::viewAllowed($request->user()),
            // When this session's login happened — drives the Attendance
            // panel's "Logged In Time" clock (Navbar.vue). Set once per
            // login by CaptureSuccessfulLoginAttempt, not per-request, so it
            // stays fixed for the lifetime of this browser session.
            'sessionStartedAt' => fn () => session('current_login_at'),
            // Drives SecurityReminderPopup.vue — a real pop-up (not just the
            // passive bell dropdown) shown once per unread reminder. Mounted
            // once per Inertia session in AdminLayout.vue, so re-evaluating
            // this on every visit doesn't reopen it: the popup's own
            // `dismissed` flag stays true client-side once shown, and
            // server-side it stops appearing entirely once the notification
            // is marked read (either from the popup or the bell dropdown).
            'pendingSecurityReminder' => fn () => $this->pendingSecurityReminder($request),
            // counted from the notification table's JSON, which is slow for a person with thousands of them, so the number is kept for 10 seconds (the bell refreshes itself every 20)
            'materialRequestUnreadCount' => fn () => $request->user()
                ? \Illuminate\Support\Facades\Cache::remember('unread-alerts:' . $request->user()->id, 10, fn () => $request->user()->unreadNotifications()
                    ->whereIn('data->module', AppNotification::alertModules())
                    ->count())
                : 0,
            'sectionVisitCounts' => fn () => $request->user()
                ? DB::table('user_section_visits')->where('user_id', $request->user()->id)->pluck('visit_count', 'section_key')
                : (object) [],
            // Email/SMS two-factor status — only built for the Profile page that manages it.
            'twoFactorOtp' => fn () => $request->user() && $request->routeIs('profile.show')
                ? app(\App\Services\TwoFactorOtp::class)->settingsFor($request->user())
                : null,
            // the record whose page is open, when it can carry a QR code: drives the QR button in the layout (the code itself is fetched when the button is opened)
            'qrRecord' => fn () => $this->qrRecord($request),
            'prayer' => fn () => \App\Services\Prayer\PrayerService::share($request->user()),
            // today's prayers for the sign-in pages (people who are not signed in)
            'loginPrayer' => fn () => $request->user() ? null : \App\Services\Prayer\PrayerService::loginTimes(),
            'faceLogin' => fn () => $request->user() && $request->routeIs('profile.show')
                ? array_merge(\App\Services\Face\FaceService::statusFor($request->user()), ['enabled' => \App\Services\FeatureManager::isEnabled('face_login')])
                : null,
            // shown in the "WIMS Online" drawer: the address the server sees for this person (their public IP) and the device they use
            'clientInfo' => fn () => $request->user() ? array_merge(['ip' => (string) $request->ip()], array_intersect_key(user_agent_info((string) $request->userAgent()), array_flip(['device', 'os', 'browser']))) : null,
            // the sign-in page tells an address that is blocked right now how long it has to wait (nothing is shared with anyone else)
            'loginBlock' => fn () => ! $request->user() && $request->routeIs('login') ? \App\Services\Security\AccessGuard::loginBlock((string) $request->ip()) : null,
            // the SuperAdjustment ByPass card of the Profile page: Dr Hajas only
            'superAdjustmentBypass' => fn () => $request->user() && $request->routeIs('profile.show') && \App\Services\Licensing\LicenceService::isManager($request->user())
                ? array_merge(\App\Models\SuperAdjustment::bypass(), ['options' => \App\Models\User::role('Super Admin')->where('is_active', true)->where('id', '<>', $request->user()->id)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->values()])
                : null,
            'flash'     => [
                'error'   => session('error'),
                'message' => session('message'),
                'maintenance_notice' => session('maintenance_notice'),
                'licence_notice' => session('licence_notice'),
                'licence_result' => session('licence_result'),
                'prayer_notice' => session('prayer_notice'),
                'just_logged_in' => (bool) session('just_logged_in'),
            ],
        ]);
    }

    /**
     * Warehouses the authenticated user is allowed to manage and switch between.
     * Admins (or users without explicit assignments) get every active warehouse.
     */
    protected function managedWarehouses(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return collect();
        }

        // Shared as both `myWarehouses` and (derived from it) `currentWarehouse` on every page: ask the database once.
        if ($request->attributes->has('_managed_warehouses')) {
            return $request->attributes->get('_managed_warehouses');
        }

        // a Super Admin switches between EVERY active project, whatever list is on the person; an Admin without a list does too
        $assigned = $user->hasRole('Super Admin')
            ? collect()
            : $user->warehouses()->where('active', 1)->visible()->orderBy('name')->get(['warehouses.id', 'warehouses.name', 'warehouses.status', 'warehouses.completed_at']);

        if ($assigned->isEmpty() && $user->hasRole(['Super Admin', 'Admin'])) {
            $assigned = Warehouse::ofAccount()->active()->visible()->orderBy('name')->get(['id', 'name', 'status', 'completed_at']);
        }

        $request->attributes->set('_managed_warehouses', $assigned->values());

        return $request->attributes->get('_managed_warehouses');
    }

    /**
     * The warehouse the user is currently managing (from session, falling back
     * to the first one they are allowed to manage).
     */
    protected function currentWarehouse(Request $request)
    {
        $currentId = session('warehouse_id');
        if (! $currentId) {
            return null; // "All Warehouses" — no active scope.
        }

        return $this->managedWarehouses($request)->firstWhere('id', (int) $currentId) ?: null;
    }

    /**
     * The single most recent unread Security Reminder for this user, if
     * any — kept to one so a user who's been sent several never gets more
     * than one pop-up at a time; the rest are still visible in the bell
     * dropdown.
     */
    protected function pendingSecurityReminder(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        $notification = $user->unreadNotifications()
            ->where('data->module', 'security_reminders')
            ->latest()
            ->first();

        if (! $notification) {
            return null;
        }

        return [
            'id' => $notification->id,
            'title' => data_get($notification->data, 'title'),
            'message' => data_get($notification->data, 'message'),
            'url' => data_get($notification->data, 'url'),
        ];
    }

    public function version(Request $request)
    {
        return parent::version($request);
    }

    private function qrRecord(Request $request): ?array
    {
        $user = $request->user();
        $route = $request->route();
        if (! $user || ! $route || ! \App\Services\Qr\QrCodeService::enabled()) {
            return null;
        }
        $entry = \App\Support\Qr\QrRegistry::forRoute($route->getName());
        if (! $entry || ! \App\Support\Qr\QrRegistry::active($entry['type']) || ! $user->can('scan-qr-codes') || ! \App\Support\Qr\QrRegistry::canOpen($user, $entry)) {
            return null;
        }
        $param = $route->parameter($entry['param']);
        $id = $param instanceof \Illuminate\Database\Eloquent\Model ? $param->getKey() : $param;
        if (! is_numeric($id)) {
            return null;
        }

        return ['type' => $entry['type'], 'id' => (int) $id, 'label' => __($entry['label']), 'print' => $user->can('print-qr-codes')];
    }
}
