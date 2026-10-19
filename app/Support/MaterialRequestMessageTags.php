<?php

namespace App\Support;

use App\Models\MaterialRequest;

/**
 * The "#tags" a Super Admin can drop into the SMS / email templates of the Material Request workflow
 * (Material Request Notification -> SMS & Email). Tags are plain words after a hash, e.g.
 * "#approvedby" or "#reference"; they are case-insensitive and anything that is not a known tag is left untouched,
 * so ordinary text such as "item #5" survives. SMS never carries a link (the gateway rejects them), so #url is
 * removed from SMS text automatically.
 */
class MaterialRequestMessageTags
{
    /** tag => [group, label, description, sample]. The order here is the order shown in the tag container. */
    public static function definitions(): array
    {
        return [
            // ---- the request
            'reference' => ['Request', 'Request number', 'The request number, e.g. RMR-0112.', 'RMR-0112'],
            'status' => ['Request', 'Current status', 'Where the request is now (Fully Issued is shown for Issued).', 'Approved'],
            'project' => ['Request', 'Project / Warehouse / Site', 'The project, warehouse or site the request is for.', 'MOMBASA MARKET'],
            'requestedby' => ['Request', 'Requested by', 'The Project Manager who raised the request.', 'Salha Shaaban'],
            'daterequested' => ['Request', 'Date requested', 'The date on the request.', '06 Oct 2026'],
            'reason' => ['Request', 'Reason', 'The reason given for the request.', 'Foundation works'],
            'comments' => ['Request', 'Comments', 'The comments / notes on the request.', 'Needed on site by Thursday'],
            'itemscount' => ['Request', 'Number of items', 'How many items the request has.', '3'],
            'itemslist' => ['Request', 'List of items', 'One line per item: number, code, name and quantity.', "1. RSI-0001 CEMENT 50KG - 120 Bags\n2. RSI-0006 STEEL Y8 PCS - 200 Pcs"],

            // ---- this action
            'actor' => ['This action', 'Done by', 'The person who has just done this step.', 'Abdulhakeem Nassir'],
            'actiontime' => ['This action', 'Done at', 'Date and time of this step.', '06 Oct 2026, 14:05'],
            'remarks' => ['This action', 'Remarks', 'The remarks typed with this step (empty if none).', 'Approved - go ahead'],
            'recipient' => ['This action', 'Recipient name', 'The name of the person this message is addressed to.', 'Flow PM'],

            // ---- who did what, and when (from the audit log)
            'submittedby' => ['History', 'Submitted by', 'Who submitted the request.', 'Salha Shaaban'],
            'submittedat' => ['History', 'Submitted at', 'When it was submitted.', '06 Oct 2026, 09:12'],
            'approvedby' => ['History', 'Approved by', 'Who approved the request.', 'DrHajas'],
            'approvedat' => ['History', 'Approved at', 'When it was approved.', '06 Oct 2026, 10:30'],
            'rejectedby' => ['History', 'Rejected by', 'Who rejected the request.', 'DrHajas'],
            'rejectedat' => ['History', 'Rejected at', 'When it was rejected.', '06 Oct 2026, 10:30'],
            'partiallyissuedby' => ['History', 'Partially issued by', 'Who marked it Partially Issued (latest).', 'Procurement Manager'],
            'partiallyissuedat' => ['History', 'Partially issued at', 'When it was marked Partially Issued (latest).', '06 Oct 2026, 11:00'],
            'issuedby' => ['History', 'Fully issued by', 'Who marked it Fully Issued.', 'Procurement Manager'],
            'issuedat' => ['History', 'Fully issued at', 'When it was marked Fully Issued.', '07 Oct 2026, 08:45'],
            'clearedby' => ['History', 'Cleared / completed by', 'Who cleared / completed the request.', 'DrHajas'],
            'clearedat' => ['History', 'Cleared / completed at', 'When it was cleared / completed.', '07 Oct 2026, 16:20'],

            // ---- site delivery
            'deliverysummary' => ['Delivery', 'Delivery summary', 'How many items are fully / partially delivered and how many are awaiting.', '2 Fully, 1 Partially Delivered, 0 Awaiting (of 3)'],
            'deliveredby' => ['Delivery', 'Delivered on site by', 'The Site Engineer who marked items as delivered (latest update).', 'Site Engineer'],
            'deliveredat' => ['Delivery', 'Delivered on site at', 'When the latest delivery update was saved.', '07 Oct 2026, 15:10'],

            // ---- general
            'company' => ['General', 'Company', 'The company name.', 'RANS Company Limited'],
            'today' => ['General', 'Today', 'Today\'s date.', '07 Oct 2026'],
            'url' => ['General', 'Link to the request', 'A link that opens the request in WIMS. Email only: it is removed from SMS.', 'https://wims.ransco.co.tz/material-requests/112'],
        ];
    }

    /** For the admin page: grouped list with samples. */
    public static function forAdmin(): array
    {
        $out = [];
        foreach (self::definitions() as $tag => [$group, $label, $description, $sample]) {
            $out[] = ['tag' => '#' . $tag, 'group' => $group, 'label' => $label, 'description' => $description, 'sample' => $sample];
        }

        return $out;
    }

    /** tag (without #) => sample value, used by the live preview. */
    public static function samples(): array
    {
        return array_map(fn ($d) => $d[3], self::definitions());
    }

    /** The real values for one request. */
    public static function values(MaterialRequest $mr, ?string $actor = null, ?string $remarks = null, ?string $recipient = null, bool $forSms = false): array
    {
        $mr->loadMissing(['warehouse:id,name', 'user:id,name', 'logs.user:id,name', 'items.item:id,name,code', 'items.unit:id,name']);

        $fmt = fn ($dt) => $dt ? $dt->format('d M Y, H:i') : '';
        $log = fn (string $status) => $mr->logs->where('status', $status)->last();
        $by = fn (string $status) => optional(optional($log($status))->user)->name ?? '';
        $at = fn (string $status) => $fmt(optional($log($status))->created_at);

        $lines = [];
        foreach ($mr->items as $i => $row) {
            $lines[] = ($i + 1) . '. ' . trim((optional($row->item)->code ?? '') . ' ' . (optional($row->item)->name ?? '')) . ' - ' . rtrim(rtrim(number_format((float) $row->quantity, 2, '.', ''), '0'), '.') . ' ' . (optional($row->unit)->name ?? '');
        }

        $deliverable = $mr->items->whereNotNull('procedure');
        $delivery = $deliverable->isEmpty() ? '' : sprintf(
            '%d Fully, %d Partially Delivered, %d Awaiting (of %d)',
            $deliverable->where('delivery_status', MaterialRequest::DELIVERY_FULL)->count(),
            $deliverable->where('delivery_status', MaterialRequest::DELIVERY_PARTIAL)->count(),
            $deliverable->whereNull('delivery_status')->count(),
            $deliverable->count()
        );
        $lastDelivery = $mr->logs->whereIn('status', MaterialRequest::DELIVERY_LOG_STATUSES)->last();

        return [
            'reference' => (string) $mr->reference,
            'status' => MaterialRequest::statusLabel($mr->status),
            'project' => (string) optional($mr->warehouse)->name,
            'requestedby' => (string) optional($mr->user)->name,
            'daterequested' => $mr->date_requested ? $mr->date_requested->format('d M Y') : '',
            'reason' => (string) $mr->reason,
            'comments' => (string) $mr->comments,
            'itemscount' => (string) $mr->items->count(),
            'itemslist' => implode("\n", $lines),
            'actor' => (string) ($actor ?? ''),
            'actiontime' => now()->format('d M Y, H:i'),
            'remarks' => trim((string) $remarks),
            'recipient' => (string) ($recipient ?? ''),
            'submittedby' => $by(MaterialRequest::STATUS_PENDING_APPROVAL),
            'submittedat' => $at(MaterialRequest::STATUS_PENDING_APPROVAL),
            'approvedby' => $by(MaterialRequest::STATUS_APPROVED),
            'approvedat' => $at(MaterialRequest::STATUS_APPROVED),
            'rejectedby' => $by(MaterialRequest::STATUS_REJECTED),
            'rejectedat' => $at(MaterialRequest::STATUS_REJECTED),
            'partiallyissuedby' => $by(MaterialRequest::STATUS_PARTIALLY_ISSUED),
            'partiallyissuedat' => $at(MaterialRequest::STATUS_PARTIALLY_ISSUED),
            'issuedby' => $by(MaterialRequest::STATUS_ISSUED),
            'issuedat' => $at(MaterialRequest::STATUS_ISSUED),
            'clearedby' => $by(MaterialRequest::STATUS_CLEARED),
            'clearedat' => $at(MaterialRequest::STATUS_CLEARED),
            'deliverysummary' => $delivery,
            'deliveredby' => (string) optional(optional($lastDelivery)->user)->name,
            'deliveredat' => $fmt(optional($lastDelivery)->created_at),
            'company' => (string) (config('app.company_name') ?: 'RANS Company Limited'),
            'today' => now()->format('d M Y'),
            'url' => $forSms ? '' : route('material-requests.show', $mr->id),
        ];
    }

    /**
     * Replaces every known #tag; unknown ones stay as typed. A line whose tags all came out empty (e.g. "Remarks: #remarks"
     * with no remarks) is dropped completely, so messages never show a dangling label. Runs of blank lines collapse;
     * $sms additionally removes blank lines altogether to keep the text short.
     */
    public static function render(string $template, array $values, bool $sms = false): string
    {
        $out = [];
        foreach (preg_split('/\R/', $template) as $line) {
            $known = 0;
            $filled = 0;
            $rendered = preg_replace_callback('/#([a-z]+)/i', function ($m) use ($values, &$known, &$filled) {
                $key = strtolower($m[1]);
                if (! array_key_exists($key, $values)) {
                    return $m[0];
                }
                $known++;
                $value = (string) $values[$key];
                if (trim($value) !== '') {
                    $filled++;
                }

                return $value;
            }, $line);

            if ($known > 0 && $filled === 0) {
                continue; // every tag on this line was empty
            }
            $out[] = rtrim($rendered);
        }

        $text = implode("\n", $out);
        $text = $sms ? preg_replace("/\n{2,}/", "\n", $text) : preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
