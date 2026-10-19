@php
    $activityStyles = [
        'Checkin' => ['icon' => '📥', 'accent' => '#059669'],
        'Checkout' => ['icon' => '📤', 'accent' => '#e11d48'],
        'Transfer' => ['icon' => '🔄', 'accent' => '#7c3aed'],
        'Adjustment' => ['icon' => '⚖️', 'accent' => '#d97706'],
        'Material Request' => ['icon' => '📋', 'accent' => '#4f46e5'],
        'Material Reallocation' => ['icon' => '🔀', 'accent' => '#0891b2'],
        'Site Report' => ['icon' => '📝', 'accent' => '#b45309'],
    ];
    $style = $activityStyles[$activityType] ?? ['icon' => '📄', 'accent' => '#0b1a3a'];
    $isSiteReport = $activityType === 'Site Report';
    $preposition = in_array($activityType, ['Material Request', 'Adjustment']) ? 'for' : 'at';
    $warehouseLabel = in_array($activityType, ['Material Request', 'Adjustment']) ? $warehouseName . ' Project(s) / Warehouse(s)' : $warehouseName;
    $hasVarianceData = !empty($items) && !$isSiteReport && array_key_exists('variance_label', $items[0] ?? []);
@endphp
@include('emails.partials.shell-open', ['emailTitle' => ($heading ?? ($activityType . ' Done')) . ' – ' . $warehouseName])
@include('emails.partials.header', ['icon' => $style['icon'], 'accent' => $style['accent'], 'eyebrow' => $activityType])
<tr>
    <td style="padding:28px 28px 8px;">
        <h1 style="margin:0 0 4px; font-size:19px; color:#0b1a3a; text-align:center;">{{ $heading ?? ($activityType . ' Done') }}</h1>
        <p style="margin:0 0 20px; font-size:13px; color:#6b7280; text-align:center;">{{ $warehouseName }}</p>

        <p style="margin:0 0 20px; font-size:13.5px; color:#374151; line-height:1.6;">
            @if($isSiteReport)
                A new <strong>{{ strtolower($activityType) }}</strong> has been completed for "<strong>{{ $warehouseName }}</strong>"@if(!empty($details['Site Engineer'] ?? null)) submitted by "<strong>{{ $details['Site Engineer'] }}</strong>"@endif. Details are below.
            @else
                A new <strong>{{ strtolower($activityType) }}</strong> has been completed {{ $preposition }} <strong>{{ $warehouseLabel }}</strong>. Details are below.
            @endif
        </p>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin-bottom:8px;">
            <tr style="background:#f1f5f9;">
                <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb; width:38%;">Field</th>
                <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb;">Value</th>
            </tr>
            <tr>
                <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; font-weight:700; color:#111827;">Reference</td>
                <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $reference }}</td>
            </tr>
            <tr>
                <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; font-weight:700; color:#111827;">Project(s) / Warehouse(s)</td>
                <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $warehouseName }}</td>
            </tr>
            @foreach($details as $label => $value)
                @if(!empty($value))
                <tr>
                    <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; font-weight:700; color:#111827;">{{ $label }}</td>
                    <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $value }}</td>
                </tr>
                @endif
            @endforeach
        </table>

        @if(!empty($chainOfCustody))
        <p style="margin:20px 0 8px; font-size:12px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.04em;">Chain of Custody</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin-bottom:8px;">
            <tr style="background:#f1f5f9;">
                <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb; width:32%;">Stage</th>
                <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb;">By</th>
                <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb; width:22%;">Date &amp; Time</th>
            </tr>
            @foreach($chainOfCustody as $stage)
            <tr>
                <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; font-weight:700; color:#111827;">{{ $stage['milestone'] ?? '—' }}</td>
                <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $stage['by'] ?? '—' }}</td>
                <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $stage['when'] ?? '—' }}</td>
            </tr>
            @endforeach
        </table>
        @endif

        @if(!empty($items))
        <p style="margin:20px 0 8px; font-size:12px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.04em;">{{ $isSiteReport ? 'Attachment Files' : 'Re-Allocated Items' }}</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin-bottom:4px;">
            <tr style="background:#f1f5f9;">
                <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb; width:5%;">No</th>
                @if($isSiteReport)
                    <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb;">File Name</th>
                    <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb; width:18%;">Size</th>
                @elseif($hasVarianceData)
                    <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb;">Item</th>
                    <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb; width:9%;">Unit</th>
                    <th style="padding:9px 12px; text-align:right; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb; width:11%;">Allocated</th>
                    <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb;">Destination</th>
                    <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb; width:12%;">Allocated By</th>
                    <th style="padding:9px 12px; text-align:right; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb; width:11%;">Received</th>
                    <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb; width:14%;">Variance</th>
                @else
                    <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb; width:18%;">Code</th>
                    <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb;">Item Name</th>
                    <th style="padding:9px 12px; text-align:right; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb; width:14%;">Qty</th>
                    <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb; width:14%;">Unit</th>
                @endif
            </tr>
            @foreach($items as $i => $item)
            <tr>
                <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $i + 1 }}</td>
                @if($isSiteReport)
                    <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $item['name'] ?? '—' }}</td>
                    <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $item['size'] ?? '—' }}</td>
                @elseif($hasVarianceData)
                    <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $item['code'] ?? '—' }} - {{ $item['name'] ?? '—' }}</td>
                    <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $item['unit'] ?? '—' }}</td>
                    <td style="padding:9px 12px; border:1px solid #e5e7eb; text-align:right; font-size:13px; color:#374151;">{{ $item['allocated_quantity'] ?? '—' }}</td>
                    <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $item['destination'] ?? '—' }}</td>
                    <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $item['allocated_by'] ?? '—' }}</td>
                    <td style="padding:9px 12px; border:1px solid #e5e7eb; text-align:right; font-size:13px; color:#374151;">{{ $item['received_quantity'] ?? '—' }}</td>
                    <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px;">
                        <span style="display:inline-block; padding:3px 10px; border-radius:9999px; font-size:11px; font-weight:700; background:{{ $item['variance_bg'] ?? '#f3f4f6' }}; color:{{ $item['variance_color'] ?? '#6b7280' }};">{{ $item['variance_label'] ?? '—' }}</span>
                    </td>
                @else
                    <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $item['code'] ?? '—' }}</td>
                    <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $item['name'] ?? '—' }}</td>
                    <td style="padding:9px 12px; border:1px solid #e5e7eb; text-align:right; font-size:13px; color:#374151;">{{ $item['quantity'] ?? '—' }}</td>
                    <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $item['unit'] ?? '—' }}</td>
                @endif
            </tr>
            @endforeach
        </table>
        @if($hasVarianceData)
        <p style="font-size:12px; color:#6b7280; margin:8px 0 20px;">
            <strong>Variance</strong> compares the Received quantity against the Allocated quantity for each item:
            <span style="display:inline-block; padding:2px 8px; border-radius:9999px; font-size:11px; font-weight:700; background:#d1fae5; color:#047857;">Matches Allocation</span>
            <span style="display:inline-block; padding:2px 8px; border-radius:9999px; font-size:11px; font-weight:700; background:#fee2e2; color:#b91c1c;">Short</span>
            <span style="display:inline-block; padding:2px 8px; border-radius:9999px; font-size:11px; font-weight:700; background:#fef3c7; color:#92400e;">Exceeds</span>
        </p>
        @endif
        @endif

        @if(!empty($auditTrail))
        <p style="margin:20px 0 8px; font-size:12px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.04em;">Audit Trail</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin-bottom:16px;">
            <tr style="background:#f1f5f9;">
                <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb;">Action</th>
                <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb;">Status</th>
                <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb;">By</th>
                <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb; width:20%;">Date &amp; Time</th>
                <th style="padding:9px 12px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:#64748b; border:1px solid #e5e7eb;">Remarks</th>
            </tr>
            @foreach($auditTrail as $entry)
            <tr>
                <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151; text-transform:capitalize;">{{ str_replace('_', ' ', $entry['action'] ?? '—') }}</td>
                <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $entry['status'] ?? '—' }}</td>
                <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $entry['by'] ?? '—' }}</td>
                <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $entry['datetime'] ?? '—' }}</td>
                <td style="padding:9px 12px; border:1px solid #e5e7eb; font-size:13px; color:#374151;">{{ $entry['remarks'] ?? '' }}</td>
            </tr>
            @endforeach
        </table>
        @endif
    </td>
</tr>
@include('emails.partials.footer')
@include('emails.partials.shell-close')
