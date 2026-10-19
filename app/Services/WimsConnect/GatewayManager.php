<?php

namespace App\Services\WimsConnect;

use App\Contracts\WimsConnect\GatewayDriver;
use App\Models\User;
use App\Models\WimsConnectGateway;
use App\Notifications\AppNotification;
use App\Services\WimsConnect\Drivers\MetaCloudApiDriver;
use App\Services\WimsConnect\Drivers\MockDriver;
use App\Services\WimsConnect\Drivers\SmsDriver;
use App\Services\WimsConnect\Drivers\WhatsAppSelfHostedDriver;
use App\Support\WimsConnect\DeliveryResult;
use App\Support\WimsConnect\OutboundMessage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Resolves a driver by key, reading its config from wims_connect_gateways.
 * The single point every future event listener and the settings controller
 * both go through — nothing else in WIMS should instantiate a driver class
 * directly.
 */
class GatewayManager
{
    private const LABELS = ['whatsapp' => 'WhatsApp', 'meta' => 'Meta', 'sms' => 'SMS'];

    public function driver(string $key): GatewayDriver
    {
        // Simulation WIMS never sends anything to a real phone or account: every gateway is the log-only mock
        if (\App\Support\Simulation\Environment::isSimulation()) {
            return new MockDriver();
        }
        $config = WimsConnectGateway::where('driver', $key)->first()?->config ?? [];

        return match ($key) {
            'whatsapp' => new WhatsAppSelfHostedDriver($config),
            'meta' => new MetaCloudApiDriver($config),
            'sms' => new SmsDriver($config),
            default => throw new InvalidArgumentException("Unknown WIMS Connect gateway [{$key}]."),
        };
    }

    public function mock(): GatewayDriver
    {
        return new MockDriver();
    }

    /**
     * The real-traffic counterpart to driver($key)->send() — every actual
     * send (bulk connect, event-triggered) should go through here instead
     * of calling the driver directly, so wims_connect_gateways' status/
     * last_success_at/last_failure_at/last_error reflect live traffic, not
     * just whenever an admin happens to click "Test Connection" (which is
     * the only place that updated them before this existed). Also the only
     * place a "gateway needs attention" notification gets sent — e.g. an
     * OpenWA WhatsApp session that died or was replaced with a new one
     * WIMS was never told about surfaces here as ordinary send failures.
     */
    public function send(string $channel, string $address, OutboundMessage $message): DeliveryResult
    {
        $result = $this->driver($channel)->send($address, $message);
        $this->recordHealth($channel, $result);

        return $result;
    }

    protected function recordHealth(string $channel, DeliveryResult $result): void
    {
        $gateway = WimsConnectGateway::firstOrCreate(['driver' => $channel]);

        if ($result->success) {
            $gateway->update(['status' => 'connected', 'last_success_at' => now(), 'last_error' => null]);

            return;
        }

        // Edge-triggered: only alert the moment this gateway flips from
        // healthy to failing, not on every subsequent failed send in the
        // same outage — a bulk send against a dead session would otherwise
        // fire this once per recipient.
        $wasHealthy = $gateway->status !== 'error';
        $safeError = $result->error ? Str::limit(strip_tags($result->error), 300) : null;

        $gateway->update(['status' => 'error', 'last_failure_at' => now(), 'last_error' => $safeError]);

        if ($wasHealthy) {
            $this->notifyGatewayNeedsAttention($channel, $safeError);
        }
    }

    protected function notifyGatewayNeedsAttention(string $channel, ?string $error): void
    {
        $label = self::LABELS[$channel] ?? ucfirst($channel);

        // Super Admin/Admin always included — they don't necessarily hold
        // manage-wims-connect as a real granted permission row (Super Admin
        // in particular bypasses every can() check without one), so a plain
        // permission-based query alone could miss the very people who'd
        // want this most.
        $recipients = User::role(['Super Admin', 'Admin'])->get()
            ->merge(User::permission('manage-wims-connect')->get())
            ->unique('id');

        foreach ($recipients as $user) {
            $user->notify(new AppNotification([
                'module' => 'wims_connect',
                'type' => 'wims_connect_gateway_down',
                'title' => __(':channel Gateway Needs Attention', ['channel' => $label]),
                'message' => __('The :channel gateway just failed to send a message (:error). If the session was restarted (e.g. a new WhatsApp Web login), verify it\'s live and reconnected.', ['channel' => $label, 'error' => $error ?? __('unknown error')]),
                'url' => route("wims-connect.gateways.{$channel}"),
                'created_at' => now()->toDateTimeString(),
            ]));
        }
    }
}
