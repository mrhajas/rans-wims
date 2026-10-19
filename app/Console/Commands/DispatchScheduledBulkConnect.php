<?php

namespace App\Console\Commands;

use App\Models\BulkSmsMessage;
use App\Services\WimsConnect\BulkSmsSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchScheduledBulkConnect extends Command
{
    protected $signature = 'wims-connect:dispatch-scheduled-bulk';

    protected $description = 'Send any Bulk Connect messages (WhatsApp/Meta/SMS) whose scheduled time has arrived.';

    public function handle(BulkSmsSender $sender): int
    {
        $due = BulkSmsMessage::where('status', 'Scheduled')
            ->where('scheduled_at', '<=', now())
            ->get();

        $sent = 0;

        foreach ($due as $message) {
            try {
                // Only ever touches Pending recipients — safe if a prior
                // overlapping run already resolved some of this message's
                // recipients before being interrupted.
                $sender->dispatchNow($message);
                $sent++;
            } catch (\Throwable $e) {
                Log::error('Failed to dispatch scheduled Bulk Connect message', [
                    'message_id' => $message->id,
                    'channel' => $message->channel,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Dispatched {$sent} scheduled Bulk Connect message(s).");

        return 0;
    }
}
