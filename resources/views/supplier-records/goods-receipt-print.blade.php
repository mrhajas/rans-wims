<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Goods Receipt {{ $receipt['reference'] }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>@media print { .print-hide { display: none; } }
        .footer-row { margin-top: 8px; page-break-inside: avoid; }</style>
    @php
        $qty = fn ($v) => $v === null ? '-' : rtrim(rtrim(number_format($v, 2), '0'), '.');
    @endphp
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    <div class="title-card">
        <div class="doc-title">GOODS RECEIPT NOTE</div>
        <div class="doc-ref">GRN No.<br><strong>{{ $receipt['reference'] }}</strong></div>
    </div>

    <div class="card">
        <table class="grid">
            <tr>
                <td class="label">Supplier</td>
                <td class="value">{{ $receipt['supplier_name'] ?? '-' }}</td>
                <td class="label">Date Received</td>
                <td class="value">{{ $receipt['date_received'] ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Purchase Order</td>
                <td class="value">{{ $receipt['purchase_order_reference'] ?? '-' }}</td>
                <td class="label">Received At / Project</td>
                <td class="value">{{ $receipt['warehouse_name'] ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Received By</td>
                <td class="value">{{ $receipt['received_by_name'] ?? '-' }}</td>
                <td class="label">Recorded In WIMS</td>
                <td class="value">{{ $receipt['recorded_at'] ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Status</td>
                <td class="value"><span class="status-pill status-active">{{ $receipt['status'] }}</span></td>
                <td class="label">Order Status</td>
                <td class="value">{{ $receipt['purchase_order_status'] ?? '-' }}</td>
            </tr>
        </table>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width:30px;">SN</th>
                <th>Item</th>
                <th>Unit</th>
                <th class="num">Ordered</th>
                <th class="num">Received Now</th>
                <th class="num">Received To Date</th>
                <th class="num">Outstanding</th>
            </tr>
        </thead>
        <tbody>
            @foreach($receipt['items'] as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $line['item_name'] }}@if(!empty($line['item_code'])) <span style="color:#6b7280;">({{ $line['item_code'] }})</span>@endif</td>
                    <td>{{ $line['unit_label'] ?: '-' }}</td>
                    <td class="num">{{ $qty($line['ordered']) }}</td>
                    <td class="num" style="font-weight:bold;">{{ $qty($line['received']) }}</td>
                    <td class="num">{{ $qty($line['received_to_date']) }}</td>
                    <td class="num">{{ $qty($line['outstanding']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if(!empty($receipt['remarks']))
        <div class="card" style="margin-top:14px;">
            <div class="card-title">Remarks</div>
            <div>{{ $receipt['remarks'] }}</div>
        </div>
    @endif

    @if(!empty($signatures['enabled']))
        @include('partials.print.signature-block', ['sig' => $signatures])
    @else
        <table class="signatures">
            <tr>
                <td style="width:33.33%;">
                    <div class="line">Received By</div>
                    <div class="who">{{ $receipt['received_by_name'] ?? '-' }}</div>
                    <div class="when">{{ $receipt['recorded_at'] }}</div>
                </td>
                <td style="width:66.66%;"></td>
            </tr>
        </table>
    @endif
    {{-- The person who delivered the goods signs by hand: never part of the Signatories block. --}}
    <table class="signatures" style="margin-top:0;">
        <tr>
            <td style="width:45%; padding-top:10px;"><div class="line">Delivered By (Supplier representative)</div></td>
            <td style="width:55%;"></td>
        </tr>
    </table>

    <table class="footer-row">
        <tr>
            <td class="qr-cell">
                <img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" />
                <div class="qr-caption">Scan to verify</div>
            </td>
            <td class="foot-cell">{{ $companyName ?? 'RANS Company Limited' }} / Goods Receipt {{ $receipt['reference'] }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
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
