<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Voucher {{ $voucher['pv_number'] }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    <div class="title-card">
        <div class="doc-title">PAYMENT VOUCHER</div>
        <div class="doc-ref">PV No.<br><strong>{{ $voucher['pv_number'] }}</strong></div>
    </div>

    <table class="grid">
        <tr>
            <td class="label">Payee</td>
            <td class="value">{{ $voucher['payee_name'] }}</td>
            <td class="label">Date</td>
            <td class="value">{{ \Illuminate\Support\Carbon::parse($voucher['voucher_date'])->format('d M Y') }}</td>
        </tr>
        <tr>
            <td class="label">Project</td>
            <td class="value">{{ $voucher['warehouse_name'] }}</td>
            <td class="label">Type</td>
            <td class="value">{{ $voucher['type'] }}</td>
        </tr>
    </table>

    <br>

    <table class="items">
        <thead>
            <tr>
                <th style="width:34px;">SN</th>
                <th>Particulars</th>
                <th class="num">Amount (TZS)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($voucher['items'] as $item)
                <tr>
                    <td>{{ $item['line_number'] }}</td>
                    <td>{{ $item['particulars'] }}</td>
                    <td class="num">{{ number_format($item['amount'], 2) }}</td>
                </tr>
            @endforeach
            @for($i = 0; $i < max(0, 3 - count($voucher['items'])); $i++)
                <tr><td>&nbsp;</td><td></td><td></td></tr>
            @endfor
        </tbody>
    </table>

    <div class="amount-card">
        <div class="label">Total Amount</div>
        <div class="value">TZS {{ number_format($voucher['total_amount'], 2) }}</div>
    </div>

    <div class="card">
        <div class="card-title">Amount in Words</div>
        <div style="font-weight:bold; color:#1c3f66;">{{ $voucher['amount_in_words'] }}</div>
    </div>

    @if(!empty($voucher['remarks']))
        <div class="card">
            <div class="card-title">Remarks</div>
            <div>{{ $voucher['remarks'] }}</div>
        </div>
    @endif

    @if(!empty($signatures['enabled']))
        @include('partials.print.signature-block', ['sig' => $signatures])
        <table class="signatures" style="margin-top:0; width:25%;">
            <tr>
                <td style="width:100%;">
                <div class="line">Recipient</div>
                <div class="role">&nbsp;</div>
                <div class="who">{{ $voucher['recipient_name'] ?? '-' }}</div>
                <div class="when">{{ !empty($voucher['recipient_date']) ? \Illuminate\Support\Carbon::parse($voucher['recipient_date'])->format('d M Y') : '' }}</div>
            </td>
            </tr>
        </table>
    @else
    <table class="signatures">
        <tr>
            <td style="width:25%;">
                <div class="line">Prepared By</div>
                <div class="role">&nbsp;</div>
                <div class="who">{{ $voucher['prepared_by_name'] ?? '-' }}</div>
                <div class="when">{{ !empty($voucher['created_at']) ? \Illuminate\Support\Carbon::parse($voucher['created_at'])->format('d M Y H:i') : '' }}</div>
            </td>
            <td style="width:25%;">
                <div class="line">Approved By</div>
                <div class="role">(Accountant)</div>
                <div class="who">{{ $voucher['approved_by_name'] ?? '-' }}</div>
                <div class="when">{{ !empty($voucher['approved_at']) ? \Illuminate\Support\Carbon::parse($voucher['approved_at'])->format('d M Y H:i') : '' }}</div>
            </td>
            <td style="width:25%;">
                <div class="line">Authorised By</div>
                <div class="role">(Managing Director)</div>
                <div class="who">{{ $voucher['authorised_by_name'] ?? '-' }}</div>
                <div class="when">{{ !empty($voucher['authorised_at']) ? \Illuminate\Support\Carbon::parse($voucher['authorised_at'])->format('d M Y H:i') : '' }}</div>
            </td>
            <td style="width:25%;">
                <div class="line">Recipient</div>
                <div class="role">&nbsp;</div>
                <div class="who">{{ $voucher['recipient_name'] ?? '-' }}</div>
                <div class="when">{{ !empty($voucher['recipient_date']) ? \Illuminate\Support\Carbon::parse($voucher['recipient_date'])->format('d M Y') : '' }}</div>
            </td>
        </tr>
    </table>
    @endif

    <div style="text-align:center; margin-top:6px;">
        <span class="status-pill status-{{ strtolower($voucher['status']) === 'authorised' ? 'active' : (in_array($voucher['status'], ['Rejected','Cancelled']) ? 'danger' : 'warn') }}">
            {{ $voucher['status'] }}@if($voucher['printed_count'] > 1) &middot; Reprint #{{ $voucher['printed_count'] }}@endif
        </span>
    </div>

    <table class="footer-row">
        <tr>
            <td class="qr-cell">
                <img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" />
                <div class="qr-caption">Scan to verify</div>
            </td>
            <td class="foot-cell">{{ $companyName ?? 'RANS Company Limited' }} / Payment Voucher {{ $voucher['pv_number'] }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
