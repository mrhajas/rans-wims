<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Material Reallocation - {{ $materialReallocation->reference }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #1f2937; margin: 24px; }
        h1 { font-size: 20px; margin: 0; color: #27447a; }
        .top { margin-bottom: 14px; text-align: center; }
        .meta { margin: 16px 0; padding: 10px 12px; background: #f3f4f6; border-radius: 4px; }
        .meta-row { margin-bottom: 4px; }
        .label { font-weight: 700; color: #111827; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; table-layout: fixed; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; word-break: break-word; }
        th { background: #27447a; color: #fff; font-weight: 600; }
        tr:nth-child(even) td { background: #f9fafb; }
        .section-title { font-size: 14px; font-weight: 700; margin-top: 14px; margin-bottom: 6px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-green { background: #d1fae5; color: #047857; }
        .badge-red { background: #fee2e2; color: #b91c1c; }
        .badge-amber { background: #fef3c7; color: #92400e; }
        .badge-gray { background: #f3f4f6; color: #6b7280; }
        .actions { margin: 12px 0 18px; }
        .actions button { padding: 6px 14px; background: #27447a; color: #fff; border: 0; border-radius: 4px; cursor: pointer; font-size: 12px; }
        @media print {
            .actions { display: none; }
            body { margin: 12mm; }
        }
    </style>
</head>
<body>
    @php
        $pmActions = ['cleared', 'request_changes', 'reject'];
        $pmActionLabels = [
            'cleared' => 'Cleared for Approval',
            'request_changes' => 'Requested Changes',
            'reject' => 'Rejected',
        ];
        $pmActionBadges = [
            'cleared' => 'badge-green',
            'request_changes' => 'badge-amber',
            'reject' => 'badge-red',
        ];
        $pmRemarksTrail = $materialReallocation->logs->whereIn('action', $pmActions)->filter(fn ($log) => filled($log->remarks));

        $variance = function ($item) {
            if (is_null($item->received_quantity)) {
                return null;
            }
            $diff = (float) $item->received_quantity - (float) $item->allocated_quantity;
            if (abs($diff) < 0.0001) {
                return ['label' => 'Matches Allocation', 'class' => 'badge-green'];
            }
            return $diff < 0
                ? ['label' => 'Short (' . number_format(abs($diff), 2) . ')', 'class' => 'badge-red']
                : ['label' => 'Exceeds (' . number_format($diff, 2) . ')', 'class' => 'badge-amber'];
        };
        $hasReceivedItems = $materialReallocation->items->contains(fn ($item) => ! is_null($item->received_quantity));

        $attachmentsByGroup = $materialReallocation->attachments->groupBy(fn ($a) => in_array($a->group, ['dispatch', 'receiving'], true) ? $a->group : 'other');
    @endphp

    <div class="top">
        @if($logoPath)
            <img src="{{ $logoPath }}" alt="RANS Logo" style="max-height: 90px; width: auto;" />
        @endif
        <h1>Material Reallocation</h1>
    </div>

    <div class="meta">
        <div class="meta-row"><span class="label">Reference:</span> {{ $materialReallocation->reference }}</div>
        <div class="meta-row"><span class="label">Date Requested:</span> {{ optional($materialReallocation->date_requested)->format('Y-m-d') }}</div>
        <div class="meta-row"><span class="label">Source Warehouse / Site:</span> {{ optional($materialReallocation->sourceWarehouse)->name }}</div>
        <div class="meta-row"><span class="label">Requested By:</span> {{ optional($materialReallocation->user)->name }}</div>
        <div class="meta-row"><span class="label">Status:</span> {{ $materialReallocation->status }}</div>
        <div class="meta-row"><span class="label">Printed By:</span> {{ $printedBy }} ({{ $printedAt }})</div>
    </div>

    <div class="section-title">Chain of Custody</div>
    <table>
        <tbody>
            @if($materialReallocation->submitted_on)
                <tr><td style="width:28%;"><span class="label">Submitted</span></td><td>{{ optional($materialReallocation->user)->name }} on {{ optional($materialReallocation->submitted_on)->format('Y-m-d H:i') }}</td></tr>
            @endif
            @if($materialReallocation->cleared_on)
                <tr><td style="width:28%;"><span class="label">PM Clearance</span></td><td>{{ optional($materialReallocation->clearedBy)->name }} on {{ optional($materialReallocation->cleared_on)->format('Y-m-d H:i') }}</td></tr>
            @endif
            @if($materialReallocation->warehouse_allocated_on)
                <tr><td style="width:28%;"><span class="label">Warehouse Allocation</span></td><td>{{ optional($materialReallocation->warehouseAllocatedBy)->name }} on {{ optional($materialReallocation->warehouse_allocated_on)->format('Y-m-d H:i') }}</td></tr>
            @endif
            @if($materialReallocation->dispatch_date_time)
                <tr><td style="width:28%;"><span class="label">Dispatched</span></td><td>{{ optional($materialReallocation->dispatchedBy)->name }} on {{ optional($materialReallocation->dispatch_date_time)->format('Y-m-d H:i') }}<br>Driver: {{ optional($materialReallocation->driverContact)->name }} ({{ optional($materialReallocation->driverContact)->phone }}); Vehicle: {{ $materialReallocation->vehicle }}</td></tr>
            @endif
            @if($materialReallocation->received_date_time)
                <tr><td style="width:28%;"><span class="label">Received</span></td><td>{{ optional($materialReallocation->receivedBy)->name }} on {{ optional($materialReallocation->received_date_time)->format('Y-m-d H:i') }}</td></tr>
            @endif
            @if($materialReallocation->final_approval_date_time)
                <tr><td style="width:28%;"><span class="label">Final Approval</span></td><td>{{ optional($materialReallocation->finalApprovedBy)->name }} on {{ optional($materialReallocation->final_approval_date_time)->format('Y-m-d H:i') }}</td></tr>
            @endif
        </tbody>
    </table>

    @if(!empty($signatures['enabled']))
        @include('partials.print.signature-block', ['sig' => $signatures])
    @endif

    <div class="section-title">Items</div>
    <table>
        <thead>
            <tr>
                <th style="width: 22%;">Item</th>
                <th style="width: 8%;">Unit</th>
                <th style="width: 10%;">Requested</th>
                <th style="width: 20%;">Destination Warehouse</th>
                <th style="width: 10%;">Allocated</th>
                <th style="width: 10%;">Received</th>
                <th style="width: 20%;">Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse($materialReallocation->items as $item)
                <tr>
                    <td>{{ optional($item->item)->code }} - {{ optional($item->item)->name }}</td>
                    <td>{{ optional($item->unit)->code ?? optional($item->unit)->name }}</td>
                    <td style="text-align: right;">{{ number_format((float) $item->requested_quantity, 2) }}</td>
                    <td>{{ optional($item->destinationWarehouse)->name ?? '-' }}</td>
                    <td style="text-align: right;">{{ ! is_null($item->allocated_quantity) ? number_format((float) $item->allocated_quantity, 2) : '-' }}</td>
                    <td style="text-align: right;">{{ ! is_null($item->received_quantity) ? number_format((float) $item->received_quantity, 2) : '-' }}</td>
                    <td>{{ $item->remarks }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center; padding:16px;">No items found.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($hasReceivedItems)
        <div class="section-title">Warehouse Receiving / Variance</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 24%;">Item</th>
                    <th style="width: 9%;">Unit</th>
                    <th style="width: 22%;">Destination</th>
                    <th style="width: 12%;">Allocated</th>
                    <th style="width: 12%;">Received</th>
                    <th style="width: 21%;">Variance</th>
                </tr>
            </thead>
            <tbody>
                @foreach($materialReallocation->items as $item)
                    @php($v = $variance($item))
                    <tr>
                        <td>{{ optional($item->item)->code }} - {{ optional($item->item)->name }}</td>
                        <td>{{ optional($item->unit)->code ?? optional($item->unit)->name }}</td>
                        <td>{{ optional($item->destinationWarehouse)->name ?? '-' }}</td>
                        <td style="text-align: right;">{{ number_format((float) $item->allocated_quantity, 2) }}</td>
                        <td style="text-align: right;">{{ ! is_null($item->received_quantity) ? number_format((float) $item->received_quantity, 2) : '-' }}</td>
                        <td>@if($v)<span class="badge {{ $v['class'] }}">{{ $v['label'] }}</span>@else - @endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div style="margin-top: 6px; font-size: 11px; color: #6b7280;">
            <span class="badge badge-green">Matches Allocation</span>
            <span class="badge badge-red">Short: received less than allocated</span>
            <span class="badge badge-amber">Exceeds: received more than allocated</span>
        </div>
    @endif

    @if($materialReallocation->reason || $materialReallocation->approval_remarks)
        <div class="section-title">Reason &amp; Remarks</div>
        @if($materialReallocation->reason)
            <div><span class="label">Reason for Allocation:</span> {{ $materialReallocation->reason }}</div>
            @if($materialReallocation->reason_details)
                <div style="margin-top: 4px; color: #4b5563;">{{ $materialReallocation->reason_details }}</div>
            @endif
        @endif
        @if($materialReallocation->approval_remarks)
            <div style="margin-top: 8px;"><span class="label">Latest Remarks:</span> {{ $materialReallocation->approval_remarks }}</div>
        @endif
    @endif

    @if($pmRemarksTrail->isNotEmpty())
        <div class="section-title">Project Manager Clearance / Remarks Trail</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 20%;">Action</th>
                    <th style="width: 18%;">By</th>
                    <th style="width: 17%;">Date &amp; Time</th>
                    <th style="width: 45%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pmRemarksTrail as $log)
                    <tr>
                        <td><span class="badge {{ $pmActionBadges[$log->action] ?? 'badge-gray' }}">{{ $pmActionLabels[$log->action] ?? $log->action }}</span></td>
                        <td>{{ optional($log->user)->name }}</td>
                        <td>{{ optional($log->created_at)->format('Y-m-d H:i') }}</td>
                        <td>{{ $log->remarks }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @foreach(['other' => 'Request Attachments', 'dispatch' => 'Dispatch Attachments', 'receiving' => 'Receiving Attachments'] as $group => $groupLabel)
        @if($attachmentsByGroup->get($group, collect())->isNotEmpty())
            <div class="section-title">{{ $groupLabel }}</div>
            <ul style="margin: 0; padding-left: 18px;">
                @foreach($attachmentsByGroup->get($group) as $attachment)
                    <li>{{ $attachment->title }}</li>
                @endforeach
            </ul>
        @endif
    @endforeach

    <div class="section-title">Audit Trail</div>
    <table>
        <thead>
            <tr>
                <th style="width: 12%;">Action</th>
                <th style="width: 22%;">Status</th>
                <th style="width: 16%;">By</th>
                <th style="width: 15%;">Date &amp; Time</th>
                <th style="width: 35%;">Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse($materialReallocation->logs as $log)
                <tr>
                    <td>{{ $log->action }}</td>
                    <td>{{ $log->previous_status ? $log->previous_status . ' -> ' : '' }}{{ $log->new_status }}</td>
                    <td>{{ optional($log->user)->name }}{{ $log->role ? ' (' . $log->role . ')' : '' }}</td>
                    <td>{{ optional($log->created_at)->format('Y-m-d H:i') }}</td>
                    <td>{{ $log->remarks }}</td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center; padding:16px;">No logs available.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="actions">
        <button onclick="window.print()">Print / Save as PDF</button>
    </div>
</body>
</html>
