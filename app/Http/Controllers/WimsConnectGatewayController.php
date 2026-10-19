<?php

namespace App\Http\Controllers;

use App\Models\SystemFeature;
use App\Models\WimsConnectGateway;
use App\Models\WimsConnectLog;
use App\Services\WimsConnect\GatewayManager;
use App\Support\WimsConnect\GatewayFieldRegistry;
use App\Support\WimsConnect\OutboundMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class WimsConnectGatewayController extends Controller
{
    protected const TITLES = [
        'whatsapp' => 'WhatsApp Gateway',
        'meta' => 'Meta Gateway',
        'sms' => 'SMS Gateway',
    ];

    public function __construct()
    {
        // Real, grantable permissions (read-wims-connect / manage-wims-connect)
        // rather than a hard-coded Super Admin check — the legacy auto-derived
        // ability (Authorizable::callAction()) would map show()/update() to
        // "read-wims-connect"/"update-wims-connect", the latter of which isn't
        // a real permission, so it's disabled here in favor of the explicit
        // checks below (same pattern as WimsConnectBulkConnectController).
        $this->setAbilities([]);
    }

    public function show(Request $request, string $driver)
    {
        abort_unless($request->user()->can('read-wims-connect'), 403);
        abort_unless(GatewayFieldRegistry::isValidDriver($driver), 404);

        $gateway = WimsConnectGateway::firstOrCreate(['driver' => $driver], ['status' => 'disconnected']);
        $feature = SystemFeature::where('key', "wims_connect_{$driver}")->first();
        $fields = GatewayFieldRegistry::fieldsFor($driver);
        $config = $gateway->config ?? [];

        $values = [];
        foreach ($fields as $key => $meta) {
            $raw = (string) ($config[$key] ?? '');
            $values[$key] = $meta['secret'] ? $this->mask($raw) : $raw;
        }

        return Inertia::render('WimsConnect/GatewaySettings', [
            'driver' => $driver,
            'title' => __(self::TITLES[$driver]),
            'fields' => $fields,
            'values' => $values,
            'feature' => $feature,
            'gateway' => $gateway->only(['status', 'last_success_at', 'last_failure_at', 'last_error']),
            'history' => $driver === 'sms' ? $this->smsHistory(15) : null,
        ]);
    }

    /**
     * SMS messages per day for the last $days days (today included), newest first. A message counts on the day it was sent,
     * or the day it was queued when it has not gone out yet. Sent = SENT + DELIVERED, Failed = FAILED, Pending = the rest.
     *
     * @return array{days: list<array<string,mixed>>, totals: array<string,int>}
     */
    private function smsHistory(int $days): array
    {
        $from = now()->startOfDay()->subDays($days - 1);
        $rows = \Illuminate\Support\Facades\DB::table('wims_connect_logs')
            ->where('channel', 'sms')
            ->whereRaw('COALESCE(sent_at, created_at) >= ?', [$from])
            ->selectRaw("DATE(COALESCE(sent_at, created_at)) as d, SUM(status IN ('SENT','DELIVERED')) as sent, SUM(status = 'FAILED') as failed, COUNT(*) as total")
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        $list = [];
        $totals = ['total' => 0, 'sent' => 0, 'failed' => 0];
        for ($i = 0; $i < $days; $i++) {
            $day = now()->startOfDay()->subDays($i);
            $r = $rows->get($day->toDateString());
            $sent = (int) ($r->sent ?? 0);
            $failed = (int) ($r->failed ?? 0);
            $total = (int) ($r->total ?? 0);
            $list[] = ['date' => $day->toDateString(), 'sent' => $sent, 'failed' => $failed, 'pending' => max(0, $total - $sent - $failed), 'total' => $total];
            $totals['total'] += $total;
            $totals['sent'] += $sent;
            $totals['failed'] += $failed;
        }

        return ['days' => $list, 'totals' => $totals];
    }

    /** How much SMS credit is left on the Beem Africa account (only for the SMS gateway when its provider is Beem). */
    public function balance(Request $request)
    {
        abort_unless($request->user()->can('read-wims-connect'), 403);

        $gateway = WimsConnectGateway::query()->where('driver', 'sms')->first();
        $config = $gateway->config ?? [];
        if (! Str::contains(Str::lower((string) ($config['provider'] ?? '')), 'beem')) {
            return response()->json(['supported' => false, 'ok' => false, 'error' => __('The balance can be read for Beem Africa only. Set the Provider to Beem in the connection settings.')]);
        }

        $key = 'wims-connect.beem-balance';
        if ($request->boolean('refresh')) {
            \Illuminate\Support\Facades\Cache::forget($key);
        }
        $result = \Illuminate\Support\Facades\Cache::remember($key, 45, function () use ($config) {
            return (new \App\Services\WimsConnect\Drivers\Sms\BeemSmsProvider())->balance($config) + ['checked_at' => now()->toIso8601String()];
        });
        if (! $result['ok']) {
            \Illuminate\Support\Facades\Cache::forget($key); // do not remember a failure
        }

        return response()->json(['supported' => true, 'provider' => 'Beem Africa'] + $result);
    }

    public function update(Request $request, string $driver)
    {
        abort_unless($request->user()->can('manage-wims-connect'), 403);
        abort_unless(GatewayFieldRegistry::isValidDriver($driver), 404);

        $fields = GatewayFieldRegistry::fieldsFor($driver);
        $rules = collect($fields)->mapWithKeys(fn ($meta, $key) => [$key => 'nullable|string|max:500'])->all();
        $data = $request->validate($rules);

        $gateway = WimsConnectGateway::firstOrCreate(['driver' => $driver]);
        $config = $gateway->config ?? [];

        foreach ($fields as $key => $meta) {
            $submitted = trim((string) ($data[$key] ?? ''));
            if ($submitted === '') {
                continue; // blank = keep the existing value, so secrets don't need retyping on every save
            }
            $config[$key] = $submitted;
        }

        $updates = ['config' => $config, 'updated_by' => $request->user()->id];
        if ($gateway->last_error) {
            // A stale failure describes the OLD config, not the one just saved — clear it so the
            // settings page doesn't keep showing an alarming error for a problem that may no longer
            // exist. The real, current status is only known again once a message is actually sent.
            $updates['last_error'] = null;
            if ($gateway->status === 'error') {
                $updates['status'] = 'disconnected';
            }
        }

        $gateway->update($updates);

        log_activity('WIMS Connect Gateway Settings Updated', ['driver' => $driver], $gateway, 'WIMS Connect');

        return back()->with('message', __(':name settings saved.', ['name' => __(self::TITLES[$driver])]));
    }

    public function test(Request $request, string $driver, GatewayManager $gatewayManager)
    {
        abort_unless($request->user()->can('manage-wims-connect'), 403);
        abort_unless(GatewayFieldRegistry::isValidDriver($driver), 404);

        $data = $request->validate(['address' => 'required|string|max:32']);

        $result = $gatewayManager->driver($driver)->send(
            $data['address'],
            new OutboundMessage(__('This is a test message from WIMS Connect (:driver Gateway). If you received this, the connection is working.', ['driver' => __(self::TITLES[$driver])]))
        );

        $gateway = WimsConnectGateway::firstOrCreate(['driver' => $driver]);
        $safeError = $result->error ? Str::limit(strip_tags($result->error), 300) : null;

        $gateway->update($result->success
            ? ['status' => 'connected', 'last_success_at' => now(), 'last_error' => null]
            : ['status' => 'error', 'last_failure_at' => now(), 'last_error' => $safeError]);

        WimsConnectLog::create([
            'idempotency_key' => "test:{$driver}:".Str::uuid(),
            'event_key' => 'test-message',
            'recipient_user_id' => $request->user()->id,
            'address' => $data['address'],
            'channel' => $driver,
            'message' => 'Test message',
            'status' => $result->success ? 'SENT' : 'FAILED',
            'error_message' => $safeError,
            'sent_at' => $result->success ? now() : null,
        ]);

        log_activity(
            $result->success ? 'WIMS Connect Test Message Sent' : 'WIMS Connect Test Message Failed',
            ['driver' => $driver, 'address' => $data['address'], 'error' => $safeError],
            $gateway,
            'WIMS Connect'
        );

        // Called via a plain axios POST from the settings page (not an Inertia
        // visit) so the test result can render inline without a full page
        // reload — back()->with(...) would 302 and confuse a bare axios call.
        return response()->json([
            'success' => $result->success,
            'message' => $result->success
                ? __('Test message sent successfully.')
                : __('Test message failed: :error', ['error' => $safeError]),
        ], $result->success ? 200 : 422);
    }

    protected function mask(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return strlen($value) <= 4 ? str_repeat('•', strlen($value)) : str_repeat('•', 8).substr($value, -4);
    }
}
