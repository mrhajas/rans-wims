<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Contracts\Queue\ShouldQueue;

class MainWarehouseActivityMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public string $activityType;   // 'Checkin' | 'Checkout' | 'Transfer'
    public string $reference;
    public string $warehouseName;
    public array  $details;        // key-value pairs to display in the email
    public array  $items;          // [{code, name, unit, quantity}] or the richer reallocation shape (see extractReallocationItemsDetailed)
    public ?string $heading;       // overrides the default "{Type} Done" header/subject when set
    public array  $auditTrail;     // optional [{action, status, by, datetime, remarks}], rendered as a history table when present
    public array  $chainOfCustody; // optional [{milestone, by, when}], rendered as a named-stage table above Items
    // Optional [{data, filename, mime}] raw file attachments (named to avoid colliding with Mailable's
    // own $attachments). This mailable implements ShouldQueue, and even the 'sync' queue driver routes
    // jobs through Illuminate\Queue\Queue::createPayload(), which json_encode()s the serialized job —
    // raw binary (e.g. PDF bytes) is not valid UTF-8 and fatally breaks that encode. Stored base64-encoded
    // here (and decoded again in build()) so the property stays JSON-safe through queue serialization.
    public array $fileAttachments;
    public ?string $eventKey = null; // the workflow event that sent it, for the Delivery Logs

    public function __construct(string $activityType, string $reference, string $warehouseName, array $details = [], array $items = [], ?string $heading = null, array $auditTrail = [], array $chainOfCustody = [], array $fileAttachments = [])
    {
        $this->activityType    = $activityType;
        $this->reference       = $reference;
        $this->warehouseName   = $warehouseName;
        $this->details         = $details;
        $this->items           = $items;
        $this->heading         = $heading;
        $this->auditTrail      = $auditTrail;
        $this->chainOfCustody  = $chainOfCustody;
        $this->fileAttachments = array_map(function ($attachment) {
            $attachment['data'] = base64_encode($attachment['data']);

            return $attachment;
        }, $fileAttachments);
    }

    public function build()
    {
        $preposition = $this->activityType === 'Material Request' ? 'for' : 'at';

        $mail = $this
            ->withSymfonyMessage(fn ($m) => $m->getHeaders()->addTextHeader(\App\Services\WimsConnect\OutboundLog::EVENT_HEADER, $this->eventKey ?: 'warehouse-activity'))
            ->subject($this->heading ?? "{$this->activityType} Done {$preposition} {$this->warehouseName}")
            ->view('mail.main-warehouse-activity');

        foreach ($this->fileAttachments as $attachment) {
            $mail->attachData(base64_decode($attachment['data']), $attachment['filename'], [
                'mime' => $attachment['mime'] ?? 'application/octet-stream',
            ]);
        }

        return $mail;
    }
}
