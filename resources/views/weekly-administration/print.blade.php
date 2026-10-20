<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Administration {{ $d->reference }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        table.items td.num, table.items th.num { text-align: right; white-space: nowrap; }
        table.items tr { page-break-inside: avoid; break-inside: avoid; }
        table.items td.no, table.items th.no { width: 26px; text-align: center; }
        .sec-title { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: .06em; color: #1c3f66; margin: 14px 0 4px; }
        .note-box { border: 1px solid #d6dde6; border-radius: 4px; padding: 7px 10px; font-size: 10px; color: #334155; margin-top: 10px; }
        .note-box b { color: #1c3f66; }
        .grand { margin-top: 10px; width: 100%; border-collapse: collapse; }
        .grand td { border: 1px solid #1c3f66; padding: 6px 8px; font-weight: bold; font-size: 11px; }
        .grand td.num { text-align: right; width: 150px; }
    </style>
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    @php($statusClass = $d->status === 'Authorized' ? 'active' : (in_array($d->status, ['Requested', 'Approved']) ? 'warn' : 'inactive'))
    @php($money = fn ($v) => number_format((float) $v, 2, '.', ','))
    <div class="title-card">
        <div class="doc-title">{{ strtoupper($d->department ?: 'ADMINISTRATION') }}</div>
        <div class="doc-ref">Reference<br><strong>{{ $d->reference }}</strong></div>
    </div>

    <div class="card">
        <table class="grid">
            <tr>
                <td class="label">Department</td><td class="value"><strong>{{ strtoupper($d->department ?: 'ADMINISTRATION') }}</strong></td>
                <td class="label">Date</td><td class="value"><strong>{{ $d->doc_date->format('d/m/Y') }}</strong></td>
            </tr>
            <tr>
                <td class="label">Requested By</td><td class="value"><strong>{{ strtoupper($d->beneficiary_name ?: optional($d->user)->name) }}</strong></td>
                <td class="label">Status</td><td class="value"><span class="status-pill status-{{ $statusClass }}">{{ $d->status }}</span></td>
            </tr>
        </table>
    </div>

    <table class="items">
        <thead><tr><th class="no">No</th><th>Description</th><th>Beneficiary</th><th class="num">Amount ({{ $d->currency }})</th></tr></thead>
        <tbody>
            @foreach($d->lines as $i => $l)
                <tr>
                    <td class="no">{{ $i + 1 }}</td>
                    <td>{{ strtoupper($l->description) }}</td>
                    <td>{{ $l->beneficiary ?: '-' }}</td>
                    <td class="num">{{ $money($l->amount) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="3" style="text-align:right;font-weight:bold;">TOTAL {{ $d->currency }}</td>
                <td class="num" style="font-weight:bold;">{{ $money($d->items_total) }}</td>
            </tr>
        </tbody>
    </table>

    @if($d->include_charity && $d->charityLines->count())
        <div class="sec-title">Charity</div>
        <table class="items">
            <thead><tr><th class="no">No</th><th>Charity</th><th class="num">Amount ({{ $d->currency }})</th></tr></thead>
            <tbody>
                @foreach($d->charityLines as $i => $c)
                    <tr>
                        <td class="no">{{ $i + 1 }}</td>
                        <td>{{ strtoupper($c->description) }}</td>
                        <td class="num">{{ $money($c->amount) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td colspan="2" style="text-align:right;font-weight:bold;">TOTAL {{ $d->currency }}</td>
                    <td class="num" style="font-weight:bold;">{{ $money($d->charity_total) }}</td>
                </tr>
            </tbody>
        </table>
        <table class="grand"><tr><td>TOTAL ADMINISTRATION AND CHARITY ({{ $d->currency }})</td><td class="num">{{ $money($d->total_amount) }}</td></tr></table>
    @endif

    @if($d->status === 'Rejected' && $d->rejection_reason)
        <div class="note-box"><b>Rejected:</b> {{ $d->rejection_reason }}</div>
    @elseif($d->status === 'Cancelled' && $d->cancel_reason)
        <div class="note-box"><b>Cancelled:</b> {{ $d->cancel_reason }}</div>
    @endif
    @if($d->notes)
        <div class="note-box"><b>Notes:</b> {{ $d->notes }}</div>
    @endif

    <table class="signatures">
        <tr>
            <td style="width:33.33%;"><div class="line">Requested By</div><div style="font-size:9px;color:#64748b">{{ strtoupper($d->beneficiary_name ?: optional($d->user)->name) }} / {{ $d->created_at->format('d M Y') }}</div></td>
            <td style="width:33.33%;"><div class="line">Approved By</div><div style="font-size:9px;color:#64748b">{{ strtoupper((string) optional($d->approvedBy)->name) }}@if($d->approved_at && in_array($d->status, ['Approved', 'Authorized'])) / {{ $d->approved_at->format('d M Y') }}@endif</div></td>
            <td style="width:33.33%;"><div class="line">Authorized By</div><div style="font-size:9px;color:#64748b">{{ strtoupper((string) optional($d->authorizedBy)->name) }}@if($d->authorized_at && $d->status === 'Authorized') / {{ $d->authorized_at->format('d M Y') }}@endif</div></td>
        </tr>
    </table>

    <table class="footer-row">
        <tr>
            <td class="qr-cell"><img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" /><div class="qr-caption">Scan to verify</div></td>
            <td class="foot-cell">{{ $companyName }} / Administration {{ $d->reference }}, Printed by {{ $printedBy }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
