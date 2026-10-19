<?php

namespace App\Http\Controllers;

use App\Models\SystemFeature;
use App\Models\WimsConnectGateway;
use App\Models\WimsConnectLog;
use App\Support\CommunicationEventRegistry;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WimsConnectController extends Controller
{
    /**
     * WIMS Connect is deliberately gated to Super Admin only (see the
     * analysis doc's security section) rather than relying on
     * Authorizable's auto-derived per-action permission — the same explicit
     * abort_unless() style already used by EmailNotificationController and
     * SystemFeatureController for this class of admin-only module.
     */
    public function index(Request $request)
    {
        abort_unless($request->user()->hasRole('Super Admin'), 403);

        $featureKeys = ['wims_connect', 'wims_connect_whatsapp', 'wims_connect_meta', 'wims_connect_sms'];

        $features = SystemFeature::whereIn('key', $featureKeys)
            ->get(['id', 'key', 'name', 'is_enabled'])
            ->keyBy('key');

        $gateways = WimsConnectGateway::whereIn('driver', ['whatsapp', 'meta', 'sms'])
            ->get(['driver', 'is_enabled', 'status', 'last_success_at', 'last_failure_at', 'last_error'])
            ->keyBy('driver');

        $todayLogs = WimsConnectLog::whereDate('created_at', today())->get(['channel', 'status']);

        $recentLogs = WimsConnectLog::with('recipient:id,name')
            ->orderByDesc('id')
            ->limit(6)
            ->get(['id', 'event_key', 'recipient_user_id', 'channel', 'status', 'created_at']);

        return Inertia::render('WimsConnect/Overview', [
            'masterFeature' => $features->get('wims_connect'),
            'gatewayFeatures' => [
                'whatsapp' => $features->get('wims_connect_whatsapp'),
                'meta' => $features->get('wims_connect_meta'),
                'sms' => $features->get('wims_connect_sms'),
            ],
            'gateways' => $gateways,
            'todayCounts' => [
                'whatsapp' => $todayLogs->where('channel', 'whatsapp')->count(),
                'meta' => $todayLogs->where('channel', 'meta')->count(),
                'sms' => $todayLogs->where('channel', 'sms')->count(),
                'delivered' => $todayLogs->whereIn('status', ['SENT', 'DELIVERED'])->count(),
                'failed' => $todayLogs->where('status', 'FAILED')->count(),
                'queued' => $todayLogs->whereIn('status', ['QUEUED', 'SENDING', 'RETRYING'])->count(),
            ],
            'eventCount' => count(CommunicationEventRegistry::events()),
            'events' => CommunicationEventRegistry::events(),
            'recentLogs' => $recentLogs->map(fn ($log) => [
                'id' => $log->id,
                'event_key' => $log->event_key,
                'recipient' => $log->recipient?->name,
                'channel' => $log->channel,
                'status' => $log->status,
                'created_at' => $log->created_at?->diffForHumans(),
            ]),
        ]);
    }
}
