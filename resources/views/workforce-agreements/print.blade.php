<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Workforce Agreement {{ $agreement['reference'] }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        @media print { .print-hide { display: none; } }
        .footer-row { margin-top: 8px; page-break-inside: avoid; }
        .doc-mark { position: fixed; top: 330px; left: 70px; font-size: 120px; font-weight: bold; color: #e9eef6; transform: rotate(-30deg); z-index: -1; }
        .terms { white-space: pre-line; }
    </style>
    @php
        $cancelled = $agreement['status'] === 'Cancelled';
        $payTone = $agreement['payment_status'] === 'Fully Paid' ? 'active' : ($agreement['payment_status'] === 'Unpaid' ? 'danger' : 'warn');
        $money = fn ($v) => number_format((float) $v, 2);
    @endphp
</head>
<body>
    @include('partials.print.watermark')
    @if($cancelled)<div class="doc-mark">CANCELLED</div>@endif
    @include('partials.print.letterhead')

    <div class="title-card">
        <div class="doc-title">WORKFORCE AGREEMENT</div>
        <div class="doc-ref">Agreement No.<br><strong>{{ $agreement['reference'] }}</strong></div>
    </div>

    <div class="card">
        <table class="grid">
            <tr>
                <td class="label">Worker / Contractor</td>
                <td class="value">{{ $agreement['payee_name'] }}</td>
                <td class="label">Agreement Date</td>
                <td class="value">{{ $agreement['agreement_date_label'] ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Worker Type</td>
                <td class="value">{{ $agreement['payee_type'] }}</td>
                <td class="label">Project / Site</td>
                <td class="value">{{ $agreement['warehouse_name'] ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Type of Work</td>
                <td class="value">{{ $agreement['type_of_work'] ?: '-' }}</td>
                <td class="label">Agreement Reference</td>
                <td class="value">{{ $agreement['agreement_reference'] ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Status</td>
                <td class="value"><span class="status-pill status-{{ $cancelled ? 'neutral' : 'active' }}">{{ $agreement['status'] }}</span>@if($cancelled && $agreement['cancelled_at']) <span style="font-weight:normal; color:#6b7280;">{{ $agreement['cancelled_at'] }}@if($agreement['cancelled_by_name']) by {{ $agreement['cancelled_by_name'] }}@endif</span>@endif</td>
                <td class="label">Payment Status</td>
                <td class="value"><span class="status-pill status-{{ $payTone }}">{{ $agreement['payment_status'] }}</span></td>
            </tr>
        </table>
    </div>

    @if(!empty($agreement['scope_of_work']))
        <div class="card">
            <div class="card-title">Scope of Work</div>
            <div class="terms">{{ $agreement['scope_of_work'] }}</div>
        </div>
    @endif

    <div class="card-title">Agreed Amount and Balance</div>
    <table class="items">
        <tbody>
            <tr><td>Agreed amount</td><td class="num">TZS {{ $money($agreement['agreed_amount']) }}</td></tr>
            @if($agreement['opening_balance'] > 0)
                <tr><td>Opening balance carried over</td><td class="num">TZS {{ $money($agreement['opening_balance']) }}</td></tr>
                <tr><td>Total entitlement</td><td class="num">TZS {{ $money($agreement['total_entitlement']) }}</td></tr>
            @endif
            <tr><td>Requested so far</td><td class="num">TZS {{ $money($agreement['total_requested']) }}</td></tr>
            <tr><td>Approved so far</td><td class="num">TZS {{ $money($agreement['total_approved']) }}</td></tr>
            <tr><td>Paid so far</td><td class="num" style="color:#15803d; font-weight:bold;">TZS {{ $money($agreement['total_paid']) }}</td></tr>
        </tbody>
    </table>

    <div class="amount-card">
        <div class="label">Outstanding Balance</div>
        <div class="value">TZS {{ $money($agreement['outstanding_balance']) }}</div>
    </div>

    @if(!empty($agreement['requests']) && count($agreement['requests']))
        <div class="card-title">Payment Requests Against This Agreement</div>
        <table class="items">
            <thead>
                <tr><th>Labour List</th><th>Status</th><th class="num">Requested (TZS)</th><th class="num">Approved (TZS)</th><th>Voucher</th></tr>
            </thead>
            <tbody>
                @foreach($agreement['requests'] as $r)
                    <tr>
                        <td>{{ $r['labour_list_reference'] ?: '-' }}</td>
                        <td>{{ $r['labour_list_status'] ?: '-' }}</td>
                        <td class="num">{{ $money($r['requested_amount']) }}</td>
                        <td class="num">{{ $r['approved_amount'] !== null ? $money($r['approved_amount']) : '-' }}</td>
                        <td>@if($r['voucher']){{ $r['voucher']['pv_number'] }}@if($r['voucher']['paid']) (paid)@endif @else - @endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if(!empty($agreement['notes']))
        <div class="card" style="margin-top:14px;">
            <div class="card-title">Notes</div>
            <div class="terms">{{ $agreement['notes'] }}</div>
        </div>
    @endif

    @if(!empty($signatures['enabled']))
        @include('partials.print.signature-block', ['sig' => $signatures])
    @else
        <table class="signatures">
            <tr>
                <td style="width:33.33%;">
                    <div class="line">Prepared By</div>
                    <div class="who">{{ $agreement['created_by_name'] ?? '-' }}</div>
                    <div class="when">{{ $agreement['created_at'] ?? '' }}</div>
                </td>
                <td style="width:66.66%;"></td>
            </tr>
        </table>
    @endif
    {{-- The worker or contractor signs the paper by hand: never part of the Signatories block. --}}
    <table class="signatures" style="margin-top:0;">
        <tr>
            <td style="width:45%; padding-top:10px;">
                <div class="line">Contractor</div>
                <div class="who">{{ $agreement['payee_name'] }}</div>
            </td>
            <td style="width:55%;"></td>
        </tr>
    </table>

    <table class="footer-row">
        <tr>
            <td class="qr-cell">
                <img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" />
                <div class="qr-caption">Scan to verify</div>
            </td>
            <td class="foot-cell">{{ $companyName ?? 'RANS Company Limited' }} / Workforce Agreement {{ $agreement['reference'] }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>

    @unless(!empty($isPdf))
        <div class="print-hide" style="text-align:center; margin-top:18px;">
            <button onclick="window.print()" style="padding:8px 18px; background:#1c3f66; color:#fff; border:0; border-radius:6px; cursor:pointer; font-size:12px;">Print / Save as PDF</button>
        </div>
    @endunless
</body>
</html>
