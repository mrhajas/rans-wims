<?php

namespace App\Services\Simulation;

use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;
use App\Support\Simulation\Environment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Tells the Super Admins (bell, email, SMS: set on the Simulation Alerts page) that somebody signed in to Simulation WIMS.
 * The notice is sent from the PRODUCTION side, after the simulation request has finished and its session is saved, so it can never write into the simulation
 * and uses the real people and gateways of production. While the feature is being tested, SIMULATION_ALERT_ONLY=username[,username] limits who is told.
 */
class SimulationLoginAlert
{
    public static function signedIn(User $user, Request $request): void
    {
        self::defer('simulation_login', [
            'simuser' => (string) $user->username, 'simname' => (string) $user->name, 'simip' => (string) $request->ip(), 'simdevice' => self::device($request), 'simtime' => now()->format('d M Y, H:i'),
        ]);
    }

    /** the Nth refused attempt from one address within a few minutes, once */
    public static function refused(string $username, Request $request): void
    {
        $limit = max(2, (int) config('simulation.refused_alert_after', 3));
        $key = 'sim-refused:' . $request->ip();
        $n = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $n, now()->addMinutes(15));
        if ($n === $limit) {
            self::defer('simulation_login_failed', ['simuser' => $username, 'simname' => '', 'simip' => (string) $request->ip(), 'simdevice' => self::device($request), 'simtime' => now()->format('d M Y, H:i'), 'simattempts' => (string) $n]);
        }
    }

    private static function device(Request $request): string
    {
        try {
            $a = user_agent_info((string) $request->userAgent());

            return trim(($a['browser'] ?? '') . ' on ' . ($a['os'] ?? ''), ' ');
        } catch (\Throwable) {
            return '';
        }
    }

    /** run after the simulation request is over (its session is saved), and then on the production side */
    private static function defer(string $event, array $ctx): void
    {
        $send = fn () => Environment::runInProduction(fn () => self::dispatch($event, $ctx));
        if (app()->runningUnitTests() || app()->runningInConsole()) {
            $send();

            return;
        }
        app()->terminating($send);
    }

    /** @internal runs on the production side */
    public static function dispatch(string $event, array $ctx): void
    {
        try {
            $only = array_values(array_filter(array_map('trim', (array) config('simulation.alert_only_usernames', []))));
            $candidates = $only ? User::whereIn('username', $only)->where('is_active', true)->get() : null;
            $title = $event === 'simulation_login' ? __('Simulation sign-in') : __('Simulation sign-in refused');
            $message = $event === 'simulation_login'
                ? __(':n (:u) signed in to Simulation WIMS at :t from :ip (:d).', ['n' => $ctx['simname'], 'u' => $ctx['simuser'], 't' => $ctx['simtime'], 'ip' => $ctx['simip'], 'd' => $ctx['simdevice']])
                : __(':a sign-in attempts to Simulation WIMS were refused for ":u" from :ip.', ['a' => $ctx['simattempts'] ?? '', 'u' => $ctx['simuser'], 'ip' => $ctx['simip']]);
            // written straight into the production log: this runs with nobody signed in, so the usual helper (which reads the signed-in person) cannot be used
            \Illuminate\Support\Facades\DB::table('activity_log')->insert(['account_id' => 1, 'log_name' => 'Simulation', 'description' => $event === 'simulation_login' ? 'Simulation sign-in' : 'Simulation sign-in refused', 'properties' => json_encode($ctx), 'created_at' => now(), 'updated_at' => now()]);
            if ($only && ! $candidates?->count()) {
                return;   // a testing limit that names nobody who exists: tell nobody rather than everybody
            }
            WorkflowMessenger::dispatch('simulation', $event, null, null, [
                'account_id' => 1, 'title' => $title, 'message' => $message, 'url' => route('simulation.accounts.index'), 'dedupe' => 'sim-' . $event . '-' . $ctx['simuser'] . '-' . now()->format('YmdHis'),
                'values' => $ctx, 'details' => array_filter([__('User') => $ctx['simuser'], __('Name') => $ctx['simname'], __('Time') => $ctx['simtime'], __('Address') => $ctx['simip'], __('Device') => $ctx['simdevice']]),
                'candidates' => $candidates ? $candidates->all() : [], 'include_actor' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Simulation sign-in alert failed', ['error' => $e->getMessage()]);   // an alert problem never stops a sign-in
        }
    }
}
