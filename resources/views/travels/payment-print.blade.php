<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Travel agent payment {{ $p->reference }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        table.items td.num, table.items th.num { text-align: right; white-space: nowrap; }
        .note-box { border: 1px solid #d6dde6; border-radius: 4px; padding: 7px 10px; font-size: 10px; color: #334155; margin-top: 10px; }
        .note-box b { color: #1c3f66; }
        .amount { font-size: 22px; font-weight: bold; color: #1c3f66; margin: 10px 0 2px; }
        .sec-title { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: .06em; color: #1c3f66; margin: 12px 0 4px; }
    </style>
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    @php($money = fn ($v) => number_format((float) $v, 2, '.', ','))
    @php($statusClass = $p->status === 'Paid' ? 'active' : ($p->status === 'Pending Voucher' ? 'warn' : 'inactive'))
    <div class="title-card">
        <div class="doc-title">PAYMENT TO TICKET AGENT</div>
        <div class="doc-ref">Reference<br><strong>{{ $p->reference }}</strong></div>
    </div>

    <div class="card">
        <table class="grid">
            <tr>
                <td class="label">Paid to</td><td class="value"><strong>{{ optional($p->contact)->name ?? '-' }}</strong>@if(optional($p->contact)->phone) ({{ $p->contact->phone }})@endif</td>
                <td class="label">Status</td><td class="value"><span class="status-pill status-{{ $statusClass }}">{{ $p->status }}</span></td>
            </tr>
            <tr>
                <td class="label">Asked on</td><td class="value">{{ $p->requested_on->format('d M Y') }}</td>
                <td class="label">Paid on</td><td class="value">{{ $p->paid_on ? $p->paid_on->format('d M Y') : '-' }}</td>
            </tr>
            <tr>
                <td class="label">Asked by</td><td class="value">{{ optional($p->user)->name ?? '-' }}</td>
                <td class="label">Paid by</td><td class="value">{{ optional($p->paidBy)->name ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Payment Voucher</td><td class="value">{{ $v ? $v->pv_number : '-' }}</td>
                <td class="label">Method / reference</td><td class="value">{{ trim(($p->method ?: '-') . ($p->payment_reference ? ' / ' . $p->payment_reference : '')) }}</td>
            </tr>
            @if($p->travel)
            <tr><td class="label">For travel</td><td class="value" colspan="3">{{ $p->travel->reference }} ({{ $p->travel->to_place }})</td></tr>
            @endif
            @if($p->notes)
            <tr><td class="label">Notes</td><td class="value" colspan="3">{{ $p->notes }}</td></tr>
            @endif
        </table>
    </div>

    <div class="amount">{{ $p->currency }} {{ $money($p->amount) }}</div>
    @if($p->currency !== 'TZS')
        <div class="note-box"><b>Voucher amount:</b> TZS {{ $money($p->voucher_amount) }} at {{ rtrim(rtrim(number_format((float) $p->exchange_rate, 6, '.', ','), '0'), '.') }} TZS per {{ $p->currency }}.</div>
    @endif
    @if($balanceAfter !== null && $p->status === 'Paid')
        <div class="note-box"><b>Balance owed to {{ optional($p->contact)->name }} after this payment ({{ $p->currency }}):</b> {{ $money($balanceAfter) }}</div>
    @endif
    @if(isset($owed[$p->currency]))
        <div class="note-box"><b>Owed to {{ optional($p->contact)->name }} today ({{ $p->currency }}):</b> {{ $money($owed[$p->currency]) }} at {{ now()->format('d M Y') }}</div>
    @endif

    @if($v)
    <div class="sec-title">Payment Voucher stages</div>
    <table class="items">
        <thead><tr><th>Stage</th><th>By</th><th>Date</th></tr></thead>
        <tbody>
            <tr><td>Prepared</td><td>{{ $names[$v->prepared_by_user_id] ?? '-' }}</td><td>{{ optional($v->created_at)->format('d M Y H:i') }}</td></tr>
            <tr><td>Approved</td><td>{{ $names[$v->approved_by_user_id] ?? '-' }}</td><td>{{ optional($v->approved_at)->format('d M Y H:i') ?: '-' }}</td></tr>
            <tr><td>Authorised</td><td>{{ $names[$v->authorised_by_user_id] ?? '-' }}</td><td>{{ optional($v->authorised_at)->format('d M Y H:i') ?: '-' }}</td></tr>
            <tr><td>Marked paid</td><td>{{ $names[$v->paid_by_user_id] ?? '-' }}</td><td>{{ optional($v->paid_at)->format('d M Y H:i') ?: '-' }}</td></tr>
        </tbody>
    </table>
    @endif
    @if($p->status === 'Released')
        <div class="note-box"><b>Released:</b> {{ $p->release_reason }}. Nothing was paid and the amount is available again.</div>
    @endif
    @if(count($files))
        <div class="note-box"><b>Attached documents:</b> {{ implode(', ', $files) }}</div>
    @endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell"><img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" /><div class="qr-caption">Scan to verify</div></td>
            <td class="foot-cell">{{ $companyName }} / Travel agent payment {{ $p->reference }}, Printed by {{ $printedBy }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
