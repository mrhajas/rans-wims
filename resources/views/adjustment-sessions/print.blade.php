<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Adjustment Session {{ $session['reference'] }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        table.items td.num, table.items th.num { text-align: right; white-space: nowrap; }
        table.items tr { page-break-inside: avoid; break-inside: avoid; }
        table.items td.no, table.items th.no { width: 26px; text-align: center; }
        .sec-title { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: .06em; color: #1c3f66; margin: 14px 0 4px; }
        .note-box { border: 1px solid #d6dde6; border-radius: 4px; padding: 7px 10px; font-size: 10px; color: #334155; margin-top: 10px; }
        .note-box b { color: #1c3f66; }
        .stat-row { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .stat-row td { border: 1px solid #d6dde6; padding: 6px 8px; text-align: center; font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: .05em; }
        .stat-row td b { display: block; font-size: 15px; color: #1c3f66; letter-spacing: 0; }
    </style>
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    @php($open = $session['status'] === 'Open')
    @php($statusClass = $open ? 'warn' : 'active')
    @php($stamp = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y H:i') : '-')
    <div class="title-card">
        <div class="doc-title">ADJUSTMENT SESSION - {{ strtoupper($session['warehouse']['name'] ?? '') }}</div>
        <div class="doc-ref">Reference<br><strong>{{ $session['reference'] }}</strong></div>
    </div>

    <div class="card">
        <table class="grid">
            <tr>
                <td class="label">Project / Warehouse</td><td class="value"><strong>{{ $session['warehouse']['name'] }}</strong></td>
                <td class="label">Status</td><td class="value"><span class="status-pill status-{{ $statusClass }}">{{ $session['status'] }}</span></td>
            </tr>
            <tr>
                <td class="label">Started</td><td class="value"><strong>{{ $stamp($session['started_at']) }}</strong> by {{ $session['started_by'] }}</td>
                <td class="label">Ended</td><td class="value"><strong>{{ $stamp($session['ended_at']) }}</strong>@if($session['ended_by']) by {{ $session['ended_by'] }}@endif</td>
            </tr>
            @if(!empty($session['halt']['ever']))
                <tr><td class="label">Stock movement</td><td class="value" colspan="3"><strong>{{ !empty($session['halt']['active']) ? 'HALTED' : 'Was halted' }}</strong> by {{ $session['halt']['by'] ?: '-' }} since {{ $stamp($session['halt']['at']) }}@if(!empty($session['halt']['released_at'])), released {{ $stamp($session['halt']['released_at']) }}@if($session['halt']['released_by']) by {{ $session['halt']['released_by'] }}@endif @endif</td></tr>
            @endif
            @if($session['name'])
                <tr><td class="label">Name</td><td class="value" colspan="3"><strong>{{ $session['name'] }}</strong></td></tr>
            @endif
        </table>
    </div>

    <table class="stat-row">
        <tr>
            <td><b>{{ $stats['documents'] }}</b>Super Adjustments</td>
            <td><b>{{ $stats['counted'] }}</b>Items counted</td>
            <td><b>{{ $stats['not_counted'] }}</b>{{ $open ? 'Not counted yet' : 'Not counted' }}</td>
            <td><b>{{ $stats['percent'] }}%</b>Counted</td>
            @if(!empty($stats['not_counted_value']))<td><b>{{ number_format((float) $stats['not_counted_value'], 2) }}</b>Value untouched (Tsh)</td>@endif
            @if(! $open)<td><b>{{ $stats['cleared'] }}</b>Cleared</td>@endif
        </tr>
    </table>

    <div class="sec-title">Super Adjustments of this session</div>
    <table class="items">
        <thead><tr><th class="no">No</th><th>Reference</th><th>Date</th><th>Made by (when)</th><th>Status</th><th>Approved / rejected by (when)</th><th class="num">Items</th></tr></thead>
        <tbody>
            @forelse($documents as $i => $d)
                <tr>
                    <td class="no">{{ $i + 1 }}</td>
                    <td>{{ $d['reference'] }}@if($d['is_clearing']) (clearing count)@endif</td>
                    <td>{{ $d['date'] }}</td>
                    <td>{{ $d['by'] }}<br><span style="color:#64748b">{{ $stamp($d['created_at']) }}</span></td>
                    <td>{{ $d['status'] === 'Draft' ? 'Pending approval' : $d['status'] }}</td>
                    <td>@if($d['approved_by']){{ $d['approved_by'] }}<br><span style="color:#64748b">{{ $stamp($d['approved_at']) }}</span>@elseif($d['rejected_by']){{ $d['rejected_by'] }} (rejected)<br><span style="color:#64748b">{{ $stamp($d['rejected_at']) }}@if($d['rejection_reason']) - {{ $d['rejection_reason'] }}@endif</span>@else - @endif</td>
                    <td class="num">{{ $d['items'] }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center">No Super Adjustment was made in this session.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="sec-title">{{ $open ? 'Items with stock that are not counted yet' : 'Items with stock that were not counted' }}</div>
    <table class="items">
        <thead><tr><th class="no">No</th><th>Code</th><th>Item</th><th class="num">Stock in warehouse</th><th>Unit</th><th class="num">Value (Tsh)</th>@if(! $open)<th>What was done</th>@else<th>Moved since start</th>@endif</tr></thead>
        <tbody>
            @forelse($uncounted as $i => $r)
                <tr>
                    <td class="no">{{ $i + 1 }}</td><td>{{ $r['code'] }}</td><td>{{ strtoupper($r['name']) }}</td><td class="num">{{ rtrim(rtrim(number_format((float) $r['quantity'], 4, '.', ','), '0'), '.') }}</td><td>{{ $r['unit'] }}</td><td class="num">{{ !empty($r['value']) ? number_format((float) $r['value'], 2) : '-' }}</td>
                    @if(! $open)<td>{{ ! empty($r['cleared']) ? 'Cleared (counted at zero)' : (! empty($r['wanted']) ? 'Chosen to clear, not cleared' : 'Kept as it is') }}</td>@else<td>{{ ! empty($r['moved']) ? 'Yes' : '-' }}</td>@endif
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center">Every item with stock was counted.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if(!empty($history))
        <div class="sec-title">History (who did what, and when)</div>
        <table class="items">
            <thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead>
            <tbody>
                @foreach($history as $e)
                    <tr><td style="white-space:nowrap">{{ $stamp($e['at']) }}</td><td>{{ $e['by'] }}</td><td>{{ $e['text'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($session['note'])<div class="note-box"><b>Note:</b> {{ $session['note'] }}</div>@endif
    @if($session['end_note'])<div class="note-box"><b>End note:</b> {{ $session['end_note'] }}</div>@endif
    @if($session['clearing'])<div class="note-box"><b>Clearing count:</b> {{ $session['clearing']['reference'] }} ({{ $session['clearing']['status'] }})</div>@endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell"><img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" /><div class="qr-caption">Scan to verify</div></td>
            <td class="foot-cell">{{ $companyName }} / Adjustment Session {{ $session['reference'] }}, Printed by {{ auth()->user()->name }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
