<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Purchase Order {{ $order['reference'] }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        @media print { .print-hide { display: none; } }
        .footer-row { margin-top: 8px; page-break-inside: avoid; }
        .doc-mark { position: fixed; top: 330px; left: 70px; font-size: 120px; font-weight: bold; color: #e9eef6; transform: rotate(-30deg); z-index: -1; }
        .totals { width: 46%; margin-left: 54%; border-collapse: collapse; margin-top: 8px; page-break-inside: avoid; }
        .totals td { padding: 4px 9px; font-size: 10.5px; border-bottom: 1px solid #eef1f6; }
        .totals td.num { text-align: right; font-weight: bold; }
        .totals tr.grand td { background: #f0fdf4; color: #15803d; font-size: 12px; font-weight: bold; border-bottom: 0; }
        .stage-line { font-size: 9.6px; color: #475569; }
    </style>
    @php
        $statusTone = in_array($order['status'], ['Rejected', 'Cancelled'], true) ? 'danger' : (in_array($order['status'], ['Draft', 'Submitted'], true) ? 'warn' : 'active');
        $mark = $order['status'] === 'Draft' ? 'DRAFT' : (in_array($order['status'], ['Rejected', 'Cancelled'], true) ? strtoupper($order['status']) : null);
        $cur = $order['currency'] ?: 'TZS';
        $at = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d M Y H:i') : '';
    @endphp
</head>
<body>
    @include('partials.print.watermark')
    @if($mark)<div class="doc-mark">{{ $mark }}</div>@endif
    @include('partials.print.letterhead')

    <div class="title-card">
        <div class="doc-title">PURCHASE ORDER</div>
        <div class="doc-ref">PO No.<br><strong>{{ $order['reference'] }}</strong></div>
    </div>

    <div class="card">
        <table class="grid">
            <tr>
                <td class="label">Supplier</td>
                <td class="value">{{ $order['supplier_name'] ?? '-' }}</td>
                <td class="label">Date Issued</td>
                <td class="value">{{ $order['date_issued'] ? \Illuminate\Support\Carbon::parse($order['date_issued'])->format('d M Y') : '-' }}</td>
            </tr>
            <tr>
                <td class="label">Supplier Phone</td>
                <td class="value">{{ $order['supplier_phone'] ?: '-' }}</td>
                <td class="label">Expected Delivery</td>
                <td class="value">{{ $order['expected_delivery_date'] ? \Illuminate\Support\Carbon::parse($order['expected_delivery_date'])->format('d M Y') : '-' }}</td>
            </tr>
            <tr>
                <td class="label">Supplier TIN</td>
                <td class="value">{{ $order['supplier_tin'] ?: '-' }}</td>
                <td class="label">Deliver To / Project</td>
                <td class="value">{{ $order['warehouse_name'] ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Purchase Request</td>
                <td class="value">{{ $order['purchase_request_reference'] ?: '-' }}</td>
                <td class="label">Quotation</td>
                <td class="value">{{ $order['quotation_reference'] ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Status</td>
                <td class="value"><span class="status-pill status-{{ $statusTone }}">{{ $order['status'] }}</span></td>
                <td class="label">Currency</td>
                <td class="value">{{ $cur }}</td>
            </tr>
        </table>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width:30px;">SN</th>
                <th>Item</th>
                <th>Unit</th>
                <th class="num">Quantity</th>
                <th class="num">Unit Price ({{ $cur }})</th>
                <th class="num">Amount ({{ $cur }})</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order['items'] as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $line['item_name'] }}@if(!empty($line['item_code'])) <span style="color:#6b7280;">({{ $line['item_code'] }})</span>@endif</td>
                    <td>{{ $line['unit_label'] ?: '-' }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format($line['quantity'], 2), '0'), '.') }}</td>
                    <td class="num">{{ number_format($line['unit_price'], 2) }}</td>
                    <td class="num">{{ number_format($line['quantity'] * $line['unit_price'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="num">{{ number_format($order['subtotal'], 2) }}</td></tr>
        @if($order['tax_included'])
            <tr><td>Tax ({{ rtrim(rtrim(number_format($order['tax_percentage'], 2), '0'), '.') }}%)</td><td class="num">{{ number_format($order['tax_amount'], 2) }}</td></tr>
        @endif
        <tr class="grand"><td>Total ({{ $cur }})</td><td class="num">{{ number_format($order['total'], 2) }}</td></tr>
    </table>

    @if(!empty($order['terms']))
        <div class="card" style="margin-top:14px;">
            <div class="card-title">Terms</div>
            <div>{{ $order['terms'] }}</div>
        </div>
    @endif
    @if(!empty($order['remarks']))
        <div class="card">
            <div class="card-title">Remarks</div>
            <div>{{ $order['remarks'] }}</div>
        </div>
    @endif

    @if(!empty($order['stages']))
        <div class="card" style="margin-top:10px;">
            <div class="card-title">Order History</div>
            @foreach($order['stages'] as $st)
                <div class="stage-line">{{ $st['label'] }}@if($st['by']) by {{ $st['by'] }}@endif @if($st['at']) on {{ $st['at'] }}@endif</div>
            @endforeach
            @if(!empty($order['approval_remarks']))<div class="stage-line">Approval remarks: {{ $order['approval_remarks'] }}</div>@endif
        </div>
    @endif

    @if(!empty($signatures['enabled']))
        @include('partials.print.signature-block', ['sig' => $signatures])
    @else
        <table class="signatures">
            <tr>
                <td style="width:33.33%;">
                    <div class="line">Prepared By</div>
                    <div class="who">{{ $order['prepared_by_name'] ?? '-' }}</div>
                    <div class="when">{{ $at($order['created_at']) }}</div>
                </td>
                <td style="width:33.33%;">
                    <div class="line">Approved By</div>
                    <div class="who">{{ $order['approved_by_name'] ?? '-' }}</div>
                    <div class="when">{{ $at($order['approved_on']) }}</div>
                </td>
                <td style="width:33.33%;"></td>
            </tr>
        </table>
    @endif
    {{-- The supplier accepts the order by hand: never part of the Signatories block. --}}
    <table class="signatures" style="margin-top:0;">
        <tr>
            <td style="width:45%; padding-top:10px;"><div class="line">Accepted for the Supplier (name, signature and stamp)</div></td>
            <td style="width:55%;"></td>
        </tr>
    </table>

    <table class="footer-row">
        <tr>
            <td class="qr-cell">
                <img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" />
                <div class="qr-caption">Scan to verify</div>
            </td>
            <td class="foot-cell">{{ $companyName ?? 'RANS Company Limited' }} / Purchase Order {{ $order['reference'] }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
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
