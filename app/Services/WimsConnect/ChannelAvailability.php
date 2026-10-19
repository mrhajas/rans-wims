<?php

namespace App\Services\WimsConnect;

use App\Models\SystemFeature;
use App\Models\WimsConnectGateway;

/**
 * "Live" mirrors the actual send-time gate in CommunicationService: the
 * WIMS Connect master switch, the per-channel switch, and the gateway's own
 * last-known connectivity all have to be good — a channel can be fully
 * configured and still not actually be reachable if the gateway is down.
 * Shared by WimsConnectRecipientController and WimsConnectBulkConnectController
 * so both pages agree on what counts as "live".
 */
class ChannelAvailability
{
    public const CHANNELS = ['whatsapp', 'meta', 'sms'];

    public static function liveMap(): array
    {
        $features = SystemFeature::whereIn('key', ['wims_connect', 'wims_connect_whatsapp', 'wims_connect_meta', 'wims_connect_sms'])
            ->get(['key', 'is_enabled'])
            ->keyBy('key');
        $masterLive = (bool) ($features->get('wims_connect')->is_enabled ?? false);

        $gateways = WimsConnectGateway::whereIn('driver', self::CHANNELS)->get(['driver', 'status'])->keyBy('driver');

        $channelLive = [];
        foreach (self::CHANNELS as $channel) {
            $channelLive[$channel] = $masterLive
                && (bool) ($features->get("wims_connect_{$channel}")->is_enabled ?? false)
                && ($gateways->get($channel)->status ?? null) === 'connected';
        }

        $channelLive['email'] = true; // email goes through the mail settings, not a gateway

        return $channelLive;
    }
}
