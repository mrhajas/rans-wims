<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $kindLabel }} {{ $m->reference }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        table.items td.num, table.items th.num { text-align: right; white-space: nowrap; }
        table.items tr { page-break-inside: avoid; break-inside: avoid; }
        .note-box { border: 1px solid #d6dde6; border-radius: 4px; padding: 7px 10px; font-size: 10px; color: #334155; margin-top: 10px; }
        .note-box b { color: #1c3f66; }
    </style>
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    @php($statusClass = $m->status === 'Approved' ? 'active' : ($m->status === 'Pending Approval' ? 'warn' : 'inactive'))
    <div class="title-card">
        <div class="doc-title">{{ strtoupper($kindLabel) }}</div>
        <div class="doc-ref">Reference<br><strong>{{ $m->reference }}</strong></div>
    </div>

    <div class="card">
        <table class="grid">
            <tr>
                <td class="label">External Person</td><td class="value">{{ optional($m->contact)->name ?? '-' }}@if(optional($m->contact)->phone) ({{ $m->contact->phone }})@endif</td>
                <td class="label">Date</td><td class="value">{{ $m->date->format('d M Y') }}</td>
            </tr>
            <tr>
                <td class="label">Location</td><td class="value">{{ $m->location ?: optional($m->contact)->location ?: '-' }}</td>
                <td class="label">Status</td><td class="value"><span class="status-pill status-{{ $statusClass }}">{{ $m->status }}</span></td>
            </tr>
            <tr>
                <td class="label">Project(s) / Warehouse(s)</td><td class="value">{{ $m->warehouseNames() ?: '-' }}</td>
                <td class="label">Recorded By</td><td class="value">{{ optional($m->user)->name ?? '-' }}</td>
            </tr>
            @if($m->kind === 'withdrawal')
            <tr>
                <td class="label">Collected By</td><td class="value">{{ $m->collector_name ?: '-' }}@if($m->collector_phone) ({{ $m->collector_phone }})@endif</td>
                <td class="label">ID Number</td><td class="value">{{ $m->collector_id_no ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Driver</td><td class="value">{{ $driver ?: '-' }}</td>
                <td class="label">Vehicle</td><td class="value">{{ $vehicle ?: '-' }}</td>
            </tr>
            @endif
            @if($m->purpose)
            <tr><td class="label">Purpose</td><td class="value" colspan="3">{{ $m->purpose }}</td></tr>
            @endif
        </table>
    </div>

    <table class="items">
        <thead><tr><th style="width:26px;">#</th><th>Item</th><th>Warehouse</th><th>Unit</th><th class="num">Quantity</th><th>Note</th></tr></thead>
        <tbody>
            @foreach($m->items as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ optional($line->item)->name }} <span style="color:#94a3b8">({{ optional($line->item)->code }})</span></td>
                    <td>{{ optional($line->warehouse)->name ?: optional($m->warehouse)->name ?: '-' }}</td>
                    <td>{{ optional($line->unit)->name ?: '-' }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 4, '.', ','), '0'), '.') }}</td>
                    <td>{{ $line->note }}</td>
                </tr>
            @endforeach
            <tr><td colspan="4" style="text-align:right;font-weight:bold;">Total quantity</td><td class="num" style="font-weight:bold;">{{ rtrim(rtrim(number_format((float) $m->items->sum('quantity'), 4, '.', ','), '0'), '.') }}</td><td></td></tr>
        </tbody>
    </table>

    @if($m->status === 'Rejected' && $m->decision_remarks)
        <div class="note-box"><b>Rejected:</b> {{ $m->decision_remarks }}</div>
    @elseif($m->status === 'Cancelled' && $m->cancel_reason)
        <div class="note-box"><b>Cancelled:</b> {{ $m->cancel_reason }}</div>
    @elseif($m->status === 'Approved' && $m->decision_remarks)
        <div class="note-box"><b>Approval remarks:</b> {{ $m->decision_remarks }}</div>
    @endif
    @if(count($files))
        <div class="note-box"><b>Attached documents:</b> {{ implode(', ', $files) }}</div>
    @endif

    @if(!empty($signatures['enabled']))
        @include('partials.print.signature-block', ['sig' => $signatures])
        {{-- The external person signs the paper by hand: never part of the Signatories block. --}}
        <table class="signatures" style="margin-top:0;"><tr><td style="width:33.33%;"><div class="line">{{ $m->kind === 'withdrawal' ? 'Received By (External Person)' : 'Delivered By (External Person)' }}</div></td><td style="width:66.66%;"></td></tr></table>
    @else
    <table class="signatures">
        <tr>
            <td style="width:33.33%;"><div class="line">Prepared By</div><div style="font-size:9px;color:#64748b">{{ optional($m->user)->name }}</div></td>
            <td style="width:33.33%;"><div class="line">Approved By</div><div style="font-size:9px;color:#64748b">{{ optional($m->decidedBy)->name }}@if($m->decided_at) / {{ $m->decided_at->format('d M Y') }}@endif</div></td>
            <td style="width:33.33%;"><div class="line">{{ $m->kind === 'withdrawal' ? 'Received By (External Person)' : 'Delivered By (External Person)' }}</div></td>
        </tr>
    </table>

    @endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell"><img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" /><div class="qr-caption">Scan to verify</div></td>
            <td class="foot-cell">{{ $companyName }} / {{ $kindLabel }} {{ $m->reference }}, Printed by {{ $printedBy }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
