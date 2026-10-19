<?php

namespace App\Services;

use App\Jobs\WimsConnect\SendExternalCommunicationJob;
use App\Models\SalaryRecord;
use App\Models\User;
use App\Models\WimsConnectLog;
use App\Models\WimsConnectRecipientChannel;

/**
 * Sends a salary SMS to the employee on the WIMS Connect > Recipients number of the user their Staff record is linked to, through
 * WIMS Connect's existing gateway/job/delivery-log infrastructure (so it
 * still shows up in the normal Delivery Log and gets the same retry/status
 * tracking as every other WIMS Connect send) — but WITHOUT going through
 * CommunicationService::notify()/RecipientResolver, because "who" is already decided (the record's own employee).
 *
 * Deliberately does not use CommunicationEventRegistry/WimsConnectRule's
 * audience matching either — "who" is already fully decided (the record's
 * own Staff member), matching TaskSmsNotifier's precedent for the same
 * class of bypass.
 */
class SalarySmsNotifier
{
    public static function send(SalaryRecord $record, string $eventKey, string $message): void
    {
        if (! FeatureManager::isEnabled('wims_connect') || ! FeatureManager::isEnabled('wims_connect_sms')) {
            return;
        }

        $staff = $record->staff;
        if (! $staff) {
            return;
        }

        // The number comes from WIMS Connect > Recipients, like every other module. An employee is reached through the user their
        // Staff record is linked to, and that user's Recipients entry decides: no SMS when their SMS channel is off, has no number,
        // or the employee has no linked WIMS login (link the Staff profile to a user to give them an SMS number).
        $user = $staff->user_id ? User::withoutGlobalScopes()->find($staff->user_id) : null;
        if (! $user || $user->is_active === false) {
            return;
        }
        $channel = WimsConnectRecipientChannel::where('user_id', $user->id)->where('channel', 'sms')->where('is_enabled', true)->first();
        if (! $channel || ! $channel->address) {
            return;
        }
        $number = $channel->address;
        $recipientUserId = $user->id;

        $address = TaskSuperadminAlertNotifier::normalizeTanzanianPhone($number);
        $eventKey = 'salary-' . str_replace('_', '-', $eventKey); // how the Delivery Log and the Recipients pages name it

        // sourceId (the record) already uniquely identifies both the staff
        // member and the salary period, so a fixed recipient sentinel (0,
        // since there's no real users.id here) can't collide across
        // different employees' sends.
        $idempotencyKey = WimsConnectLog::buildIdempotencyKey($eventKey, SalaryRecord::class, $record->id, 0, 'sms');

        if (WimsConnectLog::where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }

        $log = WimsConnectLog::create([
            'idempotency_key' => $idempotencyKey,
            'event_key' => $eventKey,
            'source_type' => SalaryRecord::class,
            'source_id' => $record->id,
            'recipient_user_id' => $recipientUserId,
            'address' => $address,
            'channel' => 'sms',
            'message' => $message,
            'status' => 'QUEUED',
        ]);

        SendExternalCommunicationJob::dispatch($log->id);
    }
}
