<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Multi Transfer {{ $mt->reference }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        .src-title { font-size: 11px; font-weight: bold; color: #1c3f66; margin: 14px 0 5px; text-transform: uppercase; }
        .src-title small { font-weight: normal; color: #64748b; text-transform: none; margin-left: 6px; }
        table.items td.num, table.items th.num { text-align: right; white-space: nowrap; }
        table.items tr { page-break-inside: avoid; break-inside: avoid; }
    </style>
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    <div class="title-card">
        <div class="doc-title">MULTI TRANSFER</div>
        <div class="doc-ref">Reference<br><strong>{{ $mt->reference }}</strong></div>
    </div>

    <div class="card">
        <table class="grid">
            <tr>
                <td class="label">Destination</td><td class="value">{{ optional($mt->toWarehouse)->name ?? '-' }}</td>
                <td class="label">Date</td><td class="value">{{ optional($mt->date)->format('d M Y') }}</td>
            </tr>
            <tr>
                <td class="label">Requested By</td><td class="value">{{ optional($mt->user)->name ?? '-' }}</td>
                <td class="label">Status</td><td class="value"><span class="status-pill status-{{ in_array($status, ['Approved','Received']) ? 'active' : 'warn' }}">{{ $status }}</span></td>
            </tr>
            <tr>
                <td class="label">Sources</td><td class="value" colspan="3">{{ $mt->transfers->map(fn($t) => optional($t->fromWarehouse)->name)->filter()->unique()->implode(', ') }}</td>
            </tr>
            @if($mt->details)
            <tr><td class="label">Details</td><td class="value" colspan="3">{{ $mt->details }}</td></tr>
            @endif
        </table>
    </div>

    @foreach($mt->transfers as $t)
        @php($st = $t->approval_status === 'cancelled' ? 'Cancelled' : ($t->approval_status === 'pending' ? 'Waiting for approval' : ($t->received_at ? 'Received' : 'Approved')))
        <div class="src-title">From {{ optional($t->fromWarehouse)->name }} <small>{{ $t->reference }} &middot; {{ $st }}</small></div>
        <table class="items">
            <thead><tr><th style="width:26px;">#</th><th>Item</th><th>Unit</th><th class="num">Quantity</th></tr></thead>
            <tbody>
                @foreach($t->items as $i => $line)
                    <tr><td>{{ $i + 1 }}</td><td>{{ optional($line->item)->name }} <span style="color:#94a3b8">({{ optional($line->item)->code }})</span></td><td>{{ optional($line->unit)->name ?: '-' }}</td><td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 4, '.', ','), '0'), '.') }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    <table class="signatures">
        <tr>
            <td style="width:33.33%;"><div class="line">Prepared By</div><div style="font-size:9px;color:#64748b">{{ optional($mt->user)->name }}</div></td>
            <td style="width:33.33%;"><div class="line">Approved By</div></td>
            <td style="width:33.33%;"><div class="line">Received By (Destination)</div></td>
        </tr>
    </table>

    <table class="footer-row">
        <tr>
            <td class="qr-cell"><img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" /><div class="qr-caption">Scan to verify</div></td>
            <td class="foot-cell">{{ $companyName }} / Multi Transfer {{ $mt->reference }}, Printed by {{ $printedBy }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
