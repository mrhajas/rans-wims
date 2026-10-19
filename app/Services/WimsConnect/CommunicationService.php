<?php

namespace App\Services\WimsConnect;

use App\Jobs\WimsConnect\SendExternalCommunicationJob;
use App\Models\WimsConnectLog;
use App\Models\WimsConnectRecipientChannel;
use App\Services\FeatureManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The single entry point every event listener calls — never a driver, never
 * a job, directly. Deliberately does not compute *who* is in scope for an
 * event (that's real WIMS business logic — "Site Engineers on the
 * destination warehouse" — that belongs to each listener, which already
 * knows its own domain); this only decides, for a given candidate audience,
 * which of them actually receive it and through which channel, then queues
 * the sends. See CommunicationEventRegistry for the full list of event
 * keys this expects.
 */
class CommunicationService
{
    /**
     * Sends $message to each candidate on one channel — for modules that decide
     * the audience and the on/off switch themselves (the Material Request workflow's Messages tab). Still honours the
     * master + channel feature switches, each person's own enabled channel address, duplicate protection and logging.
     */
    public function notifyDirect(string $channel, string $eventKey, Model $source, Collection $candidates, string $message, ?string $dedupeScope = null): void
    {
        if (! FeatureManager::isEnabled('wims_connect') || ! FeatureManager::isEnabled("wims_connect_{$channel}")) {
            return;
        }

        $sourceType = get_class($source);

        foreach ($candidates as $user) {
            $recipientChannel = WimsConnectRecipientChannel::where('user_id', $user->id)
                ->where('channel', $channel)
                ->where('is_enabled', true)
                ->first();

            if (! $recipientChannel || ! $recipientChannel->address) {
                continue;
            }

            $idempotencyKey = WimsConnectLog::buildIdempotencyKey($eventKey, $sourceType, $source->id, $user->id, $channel, $dedupeScope);
            if (WimsConnectLog::where('idempotency_key', $idempotencyKey)->exists()) {
                continue;
            }

            $log = WimsConnectLog::create([
                'idempotency_key' => $idempotencyKey,
                'event_key' => $eventKey,
                'source_type' => $sourceType,
                'source_id' => $source->id,
                'recipient_user_id' => $user->id,
                'address' => $recipientChannel->address,
                'channel' => $channel,
                'message' => $message,
                'status' => 'QUEUED',
            ]);

            SendExternalCommunicationJob::dispatch($log->id);
        }
    }
}
