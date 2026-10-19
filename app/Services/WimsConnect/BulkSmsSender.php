<?php

namespace App\Services\WimsConnect;

use App\Models\BulkSmsMessage;
use App\Models\BulkSmsRecipient;
use App\Services\FeatureManager;
use App\Support\WimsConnect\DeliveryResult;
use App\Support\WimsConnect\OutboundMessage;
use Illuminate\Support\Str;

/**
 * The actual per-recipient send + status bookkeeping, shared by
 * WimsConnectBulkConnectController (immediate sends, single retries) and
 * DispatchScheduledBulkConnect (the cron-driven scheduled sends) so both go
 * through the exact same logic rather than two copies drifting apart. Each
 * message carries its own channel (whatsapp/meta/sms), routed through
 * GatewayManager exactly like every other WimsConnect send path.
 */
class BulkSmsSender
{
    public function __construct(protected GatewayManager $gatewayManager)
    {
    }

    /**
     * Sends every Pending recipient on $message immediately, then flips the
     * message to Completed. Safe to call on a message that's already had
     * some recipients resolved — only Pending rows are touched.
     */
    public function dispatchNow(BulkSmsMessage $message): void
    {
        if (! FeatureManager::isEnabled('wims_connect') || ! FeatureManager::isEnabled("wims_connect_{$message->channel}")) {
            // nothing is sent, but the attempt still shows in the Delivery Logs so the failure is not only visible in the Bulk Connect history
            foreach ($message->recipients()->where('status', 'Pending')->get() as $r) {
                try {
                    OutboundLog::record([
                        'event_key' => 'bulk-connect', 'channel' => $message->channel, 'address' => $r->phone, 'message' => (string) $message->body,
                        'status' => 'FAILED', 'error_message' => "WIMS Connect or the {$message->channel} channel is switched off.",
                    ]);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
            $message->recipients()->where('status', 'Pending')->update([
                'status' => 'Failed',
                'error_message' => "WIMS Connect or the {$message->channel} channel is switched off.",
            ]);
            $message->update(['status' => 'Completed', 'failed_count' => $message->recipients()->where('status', 'Failed')->count()]);

            return;
        }

        foreach ($message->recipients()->where('status', 'Pending')->get() as $recipient) {
            $this->sendOne($recipient);
        }

        $message->refresh();
        $message->update(['status' => 'Completed']);
    }

    public function sendOne(BulkSmsRecipient $recipient): DeliveryResult
    {
        $recipient->update(['status' => 'Sending']);

        $channel = $recipient->message?->channel ?? 'sms';
        $result = $this->gatewayManager->send($channel, $recipient->phone, new OutboundMessage($recipient->message?->body ?? ''));

        if ($result->success) {
            $recipient->update(['status' => 'Sent', 'provider_message_id' => $result->providerMessageId, 'sent_at' => now(), 'error_message' => null]);
        } else {
            $recipient->update(['status' => 'Failed', 'error_message' => Str::limit(strip_tags($result->error ?? 'Unknown error'), 300)]);
        }

        try {
            \App\Services\WimsConnect\OutboundLog::record([
                'event_key' => 'bulk-connect', 'channel' => $channel, 'address' => $recipient->phone, 'message' => (string) ($recipient->message?->body ?? ''),
                'status' => $result->success ? 'SENT' : 'FAILED', 'sent_at' => $result->success ? now() : null,
                'error_message' => $result->success ? null : Str::limit(strip_tags($result->error ?? 'Unknown error'), 300),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        $message = $recipient->message;
        $message->update([
            'sent_count' => $message->recipients()->where('status', 'Sent')->count(),
            'failed_count' => $message->recipients()->where('status', 'Failed')->count(),
        ]);

        return $result;
    }
}
