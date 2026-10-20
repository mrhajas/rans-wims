<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Super Adjustment {{ $superAdjustment->reference }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    <div class="title-card">
        <div class="doc-title">SUPER ADJUSTMENT</div>
        <div class="doc-ref">Reference<br><strong>{{ $superAdjustment->reference }}</strong></div>
    </div>

    {{-- Mirrors the Warehouse / Date / Submitted By chip row on the live
         Show page's header — same colors, same icon-then-label shape,
         reused here so the printed/downloaded report matches it exactly. --}}
    <div style="margin: 0 0 14px;">
        <span style="display:inline-block; margin-right:8px; margin-bottom:4px; padding:3px 10px; border-radius:999px; background:#eff6ff; color:#2563eb; font-size:9.5px; font-weight:bold;">
            <svg style="width:9px;height:9px;vertical-align:-1px;margin-right:3px;" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></svg>{{ optional($superAdjustment->warehouse)->name ?? '-' }}
        </span>
        <span style="display:inline-block; margin-right:8px; margin-bottom:4px; padding:3px 10px; border-radius:999px; background:#fffbeb; color:#b45309; font-size:9.5px; font-weight:bold;">
            <svg style="width:9px;height:9px;vertical-align:-1px;margin-right:3px;" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="2.5"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>{{ $superAdjustment->date ? \Illuminate\Support\Carbon::parse($superAdjustment->date)->format('d M Y') : '-' }}
        </span>
        <span style="display:inline-block; margin-bottom:4px; padding:3px 10px; border-radius:999px; background:#f5f3ff; color:#7c3aed; font-size:9.5px; font-weight:bold;">
            <svg style="width:9px;height:9px;vertical-align:-1px;margin-right:3px;" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2.5"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>Submitted by {{ optional($superAdjustment->user)->name ?? '-' }}
        </span>
    </div>

    <div class="card">
        <div class="card-title">Details</div>
        <table class="grid">
            <tr>
                <td class="label">Status</td>
                <td class="value">
                    @php($statusClass = ['Draft' => 'status-warn', 'Awaiting Phase 2' => 'status-warn', 'Approved' => 'status-active', 'Rejected' => 'status-danger'][$superAdjustment->status] ?? 'status-neutral')
                    <span class="status-pill {{ $statusClass }}">{{ $superAdjustment->status }}</span>
                </td>
                <td class="label">Reason</td>
                <td class="value">{{ $superAdjustment->reason ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">{{ $superAdjustment->status === 'Rejected' ? 'Rejected By' : 'Approved By' }}</td>
                <td class="value">
                    {{ $superAdjustment->status === 'Rejected' ? (optional($superAdjustment->rejecter)->name ?? '-') : ((optional($superAdjustment->approver)->name ?? 'Pending') . ($superAdjustment->approver && $superAdjustment->approved_by_role ? ' (' . $superAdjustment->approved_by_role . ')' : '')) }}
                </td>
                <td class="label"></td>
                <td class="value"></td>
            </tr>
            @if(! empty($session))
                <tr>
                    <td class="label">Adjustment Session</td>
                    <td class="value" colspan="3"><strong>{{ $session['reference'] }}</strong>@if($session['name']) ({{ $session['name'] }})@endif{{ $session['is_clearing'] ? ' - the count that cleared the items not counted' : '' }}<br>Started by {{ $session['started_by'] ?: '-' }} on {{ $session['started_at'] ? \Illuminate\Support\Carbon::parse($session['started_at'])->format('d/m/Y H:i') : '-' }}@if($session['ended_at']), ended {{ \Illuminate\Support\Carbon::parse($session['ended_at'])->format('d/m/Y H:i') }}@if($session['ended_by']) by {{ $session['ended_by'] }}@endif @else, still open @endif</td>
                </tr>
            @endif
            @if($superAdjustment->additionAdjustment || $superAdjustment->subtractionAdjustment)
                <tr>
                    <td class="label">Addition Adjustment</td>
                    <td class="value">{{ optional($superAdjustment->additionAdjustment)->reference ?? '-' }}</td>
                    <td class="label">Subtraction Adjustment</td>
                    <td class="value">{{ optional($superAdjustment->subtractionAdjustment)->reference ?? '-' }}</td>
                </tr>
            @endif
        </table>
    </div>

    {{-- $outcomes is the authoritative Match/Addition/Subtraction classification
         computed server-side (SuperAdjustmentController::itemOutcome) — for an
         Approved record it reflects which real Adjustment the item actually
         landed in, not a snapshot recomputation that can drift from history
         if stock moved between submission and approval. --}}
    @php($matchCount = 0)
    @php($additionCount = 0)
    @php($subtractionCount = 0)
    @php($clearedCount = 0)
    @foreach($superAdjustment->items as $line)
        @php($outcome = $outcomes[$line->id]['outcome'] ?? 'Match')
        @if($outcome === 'Match') @php($matchCount++) @elseif($outcome === 'Addition') @php($additionCount++) @else @php($subtractionCount++) @endif
        @if($line->auto_cleared) @php($clearedCount++) @endif
    @endforeach

    <div class="card">
        <div class="card-title">Summary</div>
        <table class="grid">
            <tr>
                <td class="label">Items Counted</td>
                <td class="value">{{ $superAdjustment->items->count() }}</td>
                <td class="label">Matched</td>
                <td class="value">{{ $matchCount }}</td>
            </tr>
            <tr>
                <td class="label">Additions</td>
                <td class="value">{{ $additionCount }}</td>
                <td class="label">Subtractions</td>
                <td class="value">{{ $subtractionCount }}</td>
            </tr>
            @if($clearedCount)
                <tr>
                    <td class="label">Auto-cleared to zero</td>
                    <td class="value" colspan="3">{{ $clearedCount }} item(s) not physically counted, stocked quantity cleared to zero</td>
                </tr>
            @endif
        </table>
    </div>

    <div class="card-title" style="margin-top:2px;">Physical Stock Count</div>
    <table class="items">
        <thead>
            <tr>
                <th>Item</th>
                <th>Unit</th>
                <th class="num">System (at submission)</th>
                <th class="num">System (now)</th>
                <th class="num">Physical Count</th>
                <th class="num">Variance</th>
            </tr>
        </thead>
        <tbody>
            @php($anyShifted = false)
            @forelse($superAdjustment->items as $line)
                @php($outcome = $outcomes[$line->id]['outcome'] ?? 'Match')
                @php($outcomeQuantity = $outcomes[$line->id]['outcome_quantity'] ?? 0)
                @php($liveQty = (float) ($liveStock[$line->item_id] ?? 0))
                @php($shifted = abs($liveQty - (float) $line->system_quantity_at_submission) > 0.0001)
                @if($shifted) @php($anyShifted = true) @endif
                <tr>
                    <td>
                        {{ optional($line->item)->name ?? '-' }}{{ optional($line->item)->code ? ' ('.$line->item->code.')' : '' }}
                        @if($line->auto_cleared)
                            <span class="status-pill status-danger" style="margin-left:4px; padding:1px 6px; font-size:7.5px;">CLEARED</span>
                        @endif
                    </td>
                    <td>{{ optional($line->unit)->name ?? optional($line->unit)->code ?? '-' }}</td>
                    <td class="num">{{ number_format($line->system_quantity_at_submission, 2) }}</td>
                    <td class="num" style="{{ $shifted ? 'font-weight:bold; color:#b45309;' : '' }}">
                        {{ number_format($liveQty, 2) }}{{ $shifted ? ' †' : '' }}
                    </td>
                    <td class="num">{{ number_format($line->physical_quantity, 2) }}</td>
                    <td class="num">{{ $outcome === 'Match' ? 'Match' : ($outcome === 'Addition' ? '+' : '-') . number_format($outcomeQuantity, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6">No items recorded.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($anyShifted)
        <div style="font-size:8.5px; color:#92400e; margin-top:6px;">
            † Stock has moved since this item was counted, the "System (now)" figure reflects live stock as of when this report was generated, which may include the correction this Super Adjustment's own approval applied, and/or any later, unrelated transaction against the same item.
        </div>
    @endif

    @if(!empty($superAdjustment->details) || !empty($superAdjustment->remarks) || !empty($superAdjustment->rejection_reason))
        <div class="card">
            <div class="card-title">Notes</div>
            @if(!empty($superAdjustment->details))
                <div style="font-size:10.5px; color:#374151;">{{ $superAdjustment->details }}</div>
            @endif
            @if(!empty($superAdjustment->remarks))
                <div style="font-size:10.5px; color:#374151; margin-top:6px;"><strong>Remarks:</strong> {{ $superAdjustment->remarks }}</div>
            @endif
            @if(!empty($superAdjustment->rejection_reason))
                <div style="font-size:10.5px; color:#be123c; margin-top:6px;"><strong>Rejection Reason:</strong> {{ $superAdjustment->rejection_reason }}</div>
            @endif
        </div>
    @endif

    @if(!empty($approval) && ($approval['required'] > 1 || count($approval['phases']) > 1))
        <div class="card" style="margin-top:14px;">
            <div class="card-title">Approval phases ({{ $approval['required'] }} Super Admin approvals required)</div>
            <table class="items">
                <thead><tr><th style="width:12%">Phase</th><th>Approved by</th><th style="width:22%">Role</th><th style="width:26%">Date and time</th></tr></thead>
                <tbody>
                @foreach($approval['phases'] as $ph)
                    <tr>
                        <td>Phase {{ $ph['phase'] }}</td>
                        <td>{{ $ph['done'] ? $ph['user'] : 'Waiting' }}</td>
                        <td>{{ $ph['done'] ? $ph['role'] : '-' }}</td>
                        <td>{{ $ph['done'] ? $ph['at'] : '-' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p style="font-size:9px;color:#64748b;margin:6px 0 0;">Each approval was confirmed with the approver's own password. The stock changed only after the last phase.</p>
        </div>
    @endif

    @if(!empty($signatures['enabled']))
        @include('partials.print.signature-block', ['sig' => $signatures])
    @else
    <table class="signatures">
        <tr>
            <td style="width:50%;">
                <div class="line">{{ optional($superAdjustment->user)->name ?? '-' }}</div>
                <div class="role">Submitted By</div>
            </td>
            <td style="width:50%;">
                <div class="line">{{ $superAdjustment->status === 'Rejected' ? (optional($superAdjustment->rejecter)->name ?? '-') : ((optional($superAdjustment->approver)->name ?? 'Pending') . ($superAdjustment->approver && $superAdjustment->approved_by_role ? ' (' . $superAdjustment->approved_by_role . ')' : '')) }}</div>
                <div class="role">{{ $superAdjustment->status === 'Rejected' ? 'Rejected By' : 'Approved By' }}</div>
            </td>
        </tr>
    </table>
    @endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell">
                <img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" />
                <div class="qr-caption">Scan to verify</div>
            </td>
            <td class="foot-cell">{{ $companyName ?? 'RANS Company Limited' }} / Super Adjustment {{ $superAdjustment->reference }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
