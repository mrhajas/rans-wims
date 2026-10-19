<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Supplier Invoice {{ $invoice['reference'] }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        @media print { .print-hide { display: none; } }
        .footer-row { margin-top: 8px; page-break-inside: avoid; }
        .totals { width: 46%; margin-left: 54%; border-collapse: collapse; margin-top: 8px; page-break-inside: avoid; }
        .totals td { padding: 4px 9px; font-size: 10.5px; border-bottom: 1px solid #eef1f6; }
        .totals td.num { text-align: right; font-weight: bold; }
        .totals tr.grand td { background: #f0fdf4; color: #15803d; font-size: 12px; font-weight: bold; border-bottom: 0; }
    </style>
    @php
        $cur = $invoice['currency'] ?: 'TZS';
        $tone = $invoice['status'] === 'Paid' ? 'active' : ($invoice['status'] === 'Disputed' ? 'danger' : 'warn');
    @endphp
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    <div class="title-card">
        <div class="doc-title">SUPPLIER INVOICE</div>
        <div class="doc-ref">Invoice No.<br><strong>{{ $invoice['invoice_number'] ?: $invoice['reference'] }}</strong></div>
    </div>

    <div class="card">
        <table class="grid">
            <tr>
                <td class="label">Supplier</td>
                <td class="value">{{ $invoice['supplier_name'] ?? '-' }}</td>
                <td class="label">Invoice Date</td>
                <td class="value">{{ $invoice['invoice_date'] ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Supplier TIN</td>
                <td class="value">{{ $invoice['supplier_tin'] ?: '-' }}</td>
                <td class="label">Due Date</td>
                <td class="value">{{ $invoice['due_date'] ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Purchase Order</td>
                <td class="value">{{ $invoice['purchase_order_reference'] ?? '-' }}</td>
                <td class="label">Goods Receipt</td>
                <td class="value">{{ $invoice['goods_receipt_reference'] ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Project</td>
                <td class="value">{{ $invoice['warehouse_name'] ?: '-' }}</td>
                <td class="label">Match Result</td>
                <td class="value">{{ $invoice['match_status'] ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Status</td>
                <td class="value"><span class="status-pill status-{{ $tone }}">{{ $invoice['status'] }}</span></td>
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
            @foreach($invoice['items'] as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $line['item_name'] }}@if(!empty($line['item_code'])) <span style="color:#6b7280;">({{ $line['item_code'] }})</span>@endif</td>
                    <td>{{ $line['unit_label'] ?: '-' }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format($line['quantity'], 2), '0'), '.') }}</td>
                    <td class="num">{{ number_format($line['unit_price'], 2) }}</td>
                    <td class="num">{{ number_format($line['line_total'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="num">{{ number_format($invoice['subtotal'], 2) }}</td></tr>
        <tr><td>Tax</td><td class="num">{{ number_format($invoice['tax_amount'], 2) }}</td></tr>
        <tr class="grand"><td>Invoice Total ({{ $cur }})</td><td class="num">{{ number_format($invoice['total'], 2) }}</td></tr>
        <tr><td>Amount Paid</td><td class="num">{{ number_format($invoice['amount_paid'], 2) }}</td></tr>
        <tr><td>Balance Due</td><td class="num">{{ number_format($invoice['balance'], 2) }}</td></tr>
    </table>

    @if(!empty($invoice['payments']))
        <div class="card-title" style="margin-top:14px;">Payments Applied</div>
        <table class="items">
            <thead>
                <tr><th>Payment</th><th>Date Paid</th><th>Method</th><th class="num">Applied ({{ $cur }})</th></tr>
            </thead>
            <tbody>
                @foreach($invoice['payments'] as $p)
                    <tr>
                        <td>{{ $p['reference'] }}</td>
                        <td>{{ $p['paid_on'] ?: '-' }}</td>
                        <td>{{ $p['method'] ?: '-' }}</td>
                        <td class="num">{{ number_format($p['applied'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if(!empty($invoice['remarks']))
        <div class="card" style="margin-top:14px;">
            <div class="card-title">Remarks</div>
            <div>{{ $invoice['remarks'] }}</div>
        </div>
    @endif

    @if(!empty($signatures['enabled']))
        @include('partials.print.signature-block', ['sig' => $signatures])
    @else
        <table class="signatures">
            <tr>
                <td style="width:33.33%;">
                    <div class="line">Prepared By</div>
                    <div class="who">{{ $invoice['prepared_by_name'] ?? '-' }}</div>
                    <div class="when">{{ $invoice['created_at'] }}</div>
                </td>
                <td style="width:33.33%;">
                    <div class="line">Approved By</div>
                    <div class="who">{{ $invoice['approved_by_name'] ?? '-' }}</div>
                    <div class="when">{{ $invoice['approved_on'] }}</div>
                </td>
                <td style="width:33.33%;"></td>
            </tr>
        </table>
    @endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell">
                <img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" />
                <div class="qr-caption">Scan to verify</div>
            </td>
            <td class="foot-cell">{{ $companyName ?? 'RANS Company Limited' }} / Supplier Invoice {{ $invoice['reference'] }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
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
