<?php

namespace App\Jobs\WimsConnect;

use App\Models\WimsConnectLog;
use App\Services\WimsConnect\GatewayManager;
use App\Support\WimsConnect\OutboundMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/**
 * One job per recipient per channel — dispatched by CommunicationService,
 * never constructed anywhere else. Deliberately never rolls anything back:
 * by the time this runs, the WIMS transaction that triggered it is already
 * committed (see the analysis doc, section N — a WhatsApp failure can never
 * undo a Transfer).
 */
class SendExternalCommunicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(protected int $logId)
    {
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    /** The job crashed (not just a refused message): the delivery log must not stay on "sending" for ever. */
    public function failed(\Throwable $e): void
    {
        WimsConnectLog::whereKey($this->logId)->whereNotIn('status', ['SENT', 'DELIVERED'])->update(['status' => 'FAILED', 'error_message' => Str::limit(strip_tags($e->getMessage()), 300)]);
    }

    public function handle(GatewayManager $gatewayManager): void
    {
        $log = WimsConnectLog::find($this->logId);

        if (! $log || in_array($log->status, ['SENT', 'DELIVERED'], true)) {
            return; // already handled — nothing to do
        }

        $log->update(['status' => 'SENDING']);

        // Routes through GatewayManager::send() (not driver()->send()
        // directly) so wims_connect_gateways' health columns get updated —
        // and a "gateway needs attention" notice fires the moment it flips
        // from healthy to failing — on every real send, not just when an
        // admin happens to click "Test Connection".
        $result = $gatewayManager->send($log->channel, $log->address, new OutboundMessage($log->message ?? ''));

        if ($result->success) {
            $log->update(['status' => 'SENT', 'sent_at' => now()]);

            return;
        }

        $safeError = Str::limit(strip_tags($result->error ?? 'Unknown error'), 300);
        // the sync queue (cPanel hosting) never tries a released job again, so a refusal there is final: FAILED, not "retrying" for ever
        $exhausted = $this->attempts() >= $this->tries || $this->job instanceof \Illuminate\Queue\Jobs\SyncJob;

        $log->update([
            'status' => $exhausted ? 'FAILED' : 'RETRYING',
            'retry_count' => $log->retry_count + 1,
            'error_message' => $safeError,
        ]);

        if (! $exhausted) {
            $this->release($this->backoff()[$this->attempts() - 1] ?? 300);
        }
    }
}
