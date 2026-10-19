<?php

namespace App\Services;

use App\Models\MaterialRequest;
use App\Models\MaterialRequestNotificationRule;
use App\Models\MaterialRequestWorkflowStep;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Services\WimsConnect\CommunicationService;
use App\Support\MaterialRequestMessageTags as Tags;
use Illuminate\Support\Facades\Log;

/**
 * Sends the SMS / email side of the Material Request workflow. Which events send what, to whom and with which text is
 * all data (Material Request Notification): per event a Super Admin switches SMS and/or email on, edits
 * the templates (with #tags, see MaterialRequestMessageTags) and picks the audience. The in-app bell notifications are
 * separate and always on.
 *
 * Audience = the event's roles / users / requester / assigned Site Engineers, plus whoever owns the NEXT workflow step
 * (so the approver hears about a new request, Procurement about an approval, ...). SMS goes to each person's own
 * enabled SMS channel (WIMS Connect profile); email to their user email. Email-Notifications-matrix subscribers get the
 * same email once, and nobody gets two copies.
 */
class MaterialRequestMessenger
{
    public function dispatch(MaterialRequest $mr, string $eventKey, ?User $actor = null, ?string $remarks = null, ?string $dedupeScope = null): void
    {
        $accountId = getAccountId(1);
        $rule = MaterialRequestNotificationRule::ruleFor($accountId, $eventKey);

        if (empty($rule['sms_enabled']) && empty($rule['email_enabled'])) {
            return;
        }

        $mr->loadMissing('user:id,name,email', 'warehouse:id,name');
        $actor ??= auth()->user();
        $actorName = $actor?->name;
        $scope = $dedupeScope ?? (string) $mr->logs()->max('id');

        // Audience. The requester is added by the rule flag only; the actor never gets a message about their own action
        // unless they are the requester.
        $exclude = $eventKey === MaterialRequestNotificationRule::EVENT_DELIVERY ? (int) optional($actor)->id : (int) $mr->user_id;
        $recipients = MaterialRequestNotificationRule::recipientsFor($accountId, $eventKey, $mr, $exclude);

        $nextStep = match ($eventKey) {
            MaterialRequestNotificationRule::EVENT_CREATED => MaterialRequestWorkflowStep::STEP_APPROVE,
            MaterialRequestNotificationRule::EVENT_APPROVED => MaterialRequestWorkflowStep::STEP_CLEAR,
            MaterialRequestNotificationRule::EVENT_ISSUED => MaterialRequestWorkflowStep::STEP_COMPLETE,
            default => null,
        };
        if ($nextStep) {
            $owners = MaterialRequestWorkflowStep::assignedUsers($accountId, $nextStep)->reject(fn ($u) => (int) $u->id === (int) $mr->user_id);
            $recipients = $recipients->merge($owners)->unique('id')->values();
        }

        $emailed = [];
        $smsValues = Tags::values($mr, $actorName, $remarks, null, true);
        $mailValues = Tags::values($mr, $actorName, $remarks, null, false);
        $details = $rule['email_show_details'] ? self::details($mr, $remarks) : [];
        $items = $rule['email_show_items'] ? self::items($mr) : [];

        foreach ($recipients as $recipient) {
            if ($rule['sms_enabled']) {
                $this->sendSms($eventKey, $mr, $recipient, Tags::render($rule['sms_template'], ['recipient' => $recipient->name] + $smsValues, true), $scope);
            }

            if ($rule['email_enabled'] && filled($recipient->email)) {
                $emailed[] = (int) $recipient->id;
                $this->sendEmail($recipient, $mr, $eventKey, $rule, ['recipient' => $recipient->name] + $mailValues, $details, $items, true);
            }
        }
    }

    private function sendSms(string $eventKey, MaterialRequest $mr, User $recipient, string $text, string $scope): void
    {
        if (trim($text) === '') {
            return;
        }

        try {
            app(CommunicationService::class)->notifyDirect('sms', 'material-request-' . str_replace('_', '-', $eventKey), $mr, collect([$recipient]), $text, $scope);
        } catch (\Throwable $e) {
            Log::error('MaterialRequestMessenger: SMS failed', ['event' => $eventKey, 'user' => $recipient->id, 'error' => $e->getMessage()]);
        }
    }

    private function sendEmail(User $recipient, MaterialRequest $mr, string $eventKey, array $rule, array $values, array $details, array $items, bool $emailOnly): void
    {
        try {
            $recipient->notify(new AppNotification([
                'module' => 'material_requests',
                'type' => 'material_request_mail_' . $eventKey,
                'title' => $this->subject($rule, $values),
                'message' => Tags::render($rule['email_body'], $values),
                'url' => route('material-requests.show', $mr->id),
                'status' => $mr->status,
                'reference' => $mr->reference,
                'details' => $details,
                'items' => $items,
                'send_email' => true,
                'email_only' => $emailOnly, // the bell notification already exists elsewhere
                'created_at' => now()->toDateTimeString(),
            ]));
        } catch (\Throwable $e) {
            Log::error('MaterialRequestMessenger: email failed', ['event' => $eventKey, 'user' => $recipient->id, 'error' => $e->getMessage()]);
        }
    }

    private function subject(array $rule, array $values): string
    {
        $subject = trim(preg_replace('/\s+/', ' ', Tags::render($rule['email_subject'], $values)));

        return $subject !== '' ? $subject : __('Material Request') . ' ' . ($values['reference'] ?? '');
    }

    /** The facts table under the message: project, who did each step and when, delivery, item count, remarks. */
    public static function details(MaterialRequest $mr, ?string $remarks = null): array
    {
        $mr->loadMissing(['warehouse:id,name', 'user:id,name', 'logs.user:id,name', 'items']);
        $when = fn ($log) => ($log->user->name ?? '-') . ' · ' . optional($log->created_at)->format('d M Y, H:i');

        $details = [
            'Reference' => $mr->reference,
            'Project / Warehouse / Site' => optional($mr->warehouse)->name ?? '-',
            'Requested by' => optional($mr->user)->name ?? '-',
            'Date requested' => optional($mr->date_requested)->format('d M Y'),
            'Status' => MaterialRequest::statusLabel($mr->status),
        ];

        $steps = [
            MaterialRequest::STATUS_PENDING_APPROVAL => 'Submitted by',
            MaterialRequest::STATUS_APPROVED => 'Approved by',
            MaterialRequest::STATUS_REJECTED => 'Rejected by',
            MaterialRequest::STATUS_PARTIALLY_ISSUED => 'Partially issued by',
            MaterialRequest::STATUS_ISSUED => 'Fully issued by',
            MaterialRequest::STATUS_CLEARED => 'Cleared / completed by',
        ];
        foreach ($steps as $status => $label) {
            $log = $mr->logs->where('status', $status)->last();
            if ($log) {
                $details[$label] = $when($log);
            }
        }

        $deliverable = $mr->items->whereNotNull('procedure');
        if ($deliverable->isNotEmpty() && $deliverable->whereNotNull('delivery_status')->isNotEmpty()) {
            $details['Delivery on site'] = sprintf(
                '%d Fully, %d Partially Delivered, %d Awaiting (of %d)',
                $deliverable->where('delivery_status', MaterialRequest::DELIVERY_FULL)->count(),
                $deliverable->where('delivery_status', MaterialRequest::DELIVERY_PARTIAL)->count(),
                $deliverable->whereNull('delivery_status')->count(),
                $deliverable->count()
            );
            // Who marked items as delivered on site, and when (the latest delivery update in the audit log).
            $delivery = $mr->logs->whereIn('status', MaterialRequest::DELIVERY_LOG_STATUSES)->last();
            if ($delivery) {
                $details['Delivered on site by'] = $when($delivery);
            }
        }

        $details['Items'] = (string) $mr->items->count();
        if ($remarks !== null && trim($remarks) !== '') {
            $details['Remarks'] = trim($remarks);
        }

        return $details;
    }

    public static function items(MaterialRequest $mr): array
    {
        $mr->loadMissing('items.item:id,name,code', 'items.unit:id,name');

        return $mr->items->map(fn ($row) => [
            'code' => optional($row->item)->code ?? '—',
            'name' => optional($row->item)->name ?? '—',
            'quantity' => $row->quantity,
            'unit' => optional($row->unit)->name ?? '—',
        ])->values()->all();
    }
}
