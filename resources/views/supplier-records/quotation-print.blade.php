<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quotation {{ $q['reference'] }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        .draft-mark { position: fixed; top: 330px; left: 70px; font-size: 130px; font-weight: bold; color: #e9eef6; transform: rotate(-30deg); z-index: -1; }
        .doc-toolbar { display: table; width: 100%; margin: -8px 0 12px; padding: 8px 12px; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 11px; }
        .doc-toolbar .tb-l { display: table-cell; vertical-align: middle; color: #475569; }
        .doc-toolbar .tb-r { display: table-cell; vertical-align: middle; text-align: right; }
        .doc-toolbar button, .doc-toolbar a { display: inline-block; margin-left: 6px; padding: 6px 12px; border: 0; border-radius: 6px; background: #1c3f66; color: #fff; font-size: 11px; font-weight: bold; text-decoration: none; cursor: pointer; }
        @media print { .doc-toolbar { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    @if(empty($isPdf))
        <div class="doc-toolbar">
            <div class="tb-l">Quotation {{ $q['reference'] }}</div>
            <div class="tb-r"><button type="button" onclick="window.print()">Print</button><a href="{{ $pdfUrl }}">Download PDF</a></div>
        </div>
    @endif
    @include('partials.print.watermark')
    @if($q['is_draft'])
        <div class="draft-mark">DRAFT</div>
    @endif
    @include('partials.print.letterhead')

    <div class="title-card">
        <div class="doc-title">QUOTATION</div>
        <div class="doc-ref">Quotation No.<br><strong>{{ $q['reference'] }}</strong></div>
    </div>

    <div class="card">
        <table class="grid">
            <tr>
                <td class="label">Supplier</td>
                <td class="value">{{ $q['supplier_name'] ?: 'N/A' }}</td>
                <td class="label">Quotation Date</td>
                <td class="value">{{ $q['date'] ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Project / Warehouse</td>
                <td class="value">{{ $q['warehouse_name'] ?: '-' }}</td>
                <td class="label">Valid Until</td>
                <td class="value">{{ $q['valid_until'] ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Purchase Request</td>
                <td class="value">{{ $q['purchase_request'] ?: '-' }}</td>
                <td class="label">Status</td>
                <td class="value">
                    <span class="status-pill status-{{ $q['status'] === 'Selected' ? 'active' : ($q['status'] === 'Rejected' ? 'danger' : ($q['status'] === 'Draft' ? 'neutral' : 'warn')) }}">{{ $q['status'] }}</span>
                </td>
            </tr>
            <tr>
                <td class="label">Recorded By</td>
                <td class="value">{{ $q['recorded_by'] ?: '-' }}</td>
                <td class="label">Recorded On</td>
                <td class="value">{{ $q['recorded_at'] ?: '-' }}</td>
            </tr>
            @if($q['selected'] || $q['rejected'])
            <tr>
                <td class="label">{{ $q['selected'] ? 'Selected' : 'Rejected' }} By</td>
                <td class="value">{{ ($q['selected'] ?: $q['rejected'])['by'] ?: '-' }}</td>
                <td class="label">{{ $q['selected'] ? 'Selected' : 'Rejected' }} On</td>
                <td class="value">{{ ($q['selected'] ?: $q['rejected'])['at'] }}</td>
            </tr>
            @endif
        </table>
    </div>

    <div class="card-title">Quoted Items</div>
    <table class="items">
        <thead>
            <tr>
                <th style="width:34px;">SN</th>
                <th>Code</th>
                <th>Item</th>
                <th>Unit</th>
                <th class="num">Quantity</th>
                <th class="num">Unit Price</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse($q['items'] as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $item['code'] ?: '-' }}</td>
                    <td>{{ $item['name'] }}</td>
                    <td>{{ $item['unit'] ?: '-' }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format($item['quantity'], 2), '0'), '.') }}</td>
                    <td class="num">{{ number_format($item['unit_price'], 2) }}</td>
                    <td class="num" style="font-weight:bold;">{{ number_format($item['line_total'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center; padding:10px;">No items.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="amount-card">
        <div class="label">Quotation Total</div>
        <div class="value">TZS {{ number_format($q['total'], 2) }}</div>
    </div>

    @if(!empty($q['remarks']))
    <div class="card">
        <div class="card-title">Remarks</div>
        <div>{{ $q['remarks'] }}</div>
    </div>
    @endif

    @if(!empty($signatures['enabled']))
        @include('partials.print.signature-block', ['sig' => $signatures])
    @else
    <table class="signatures">
        <tr>
            <td style="width:33.33%;">
                <div class="line">Recorded By</div>
                <div class="who">{{ $q['recorded_by'] ?: '-' }}</div>
                <div class="when">{{ $q['recorded_at'] }}</div>
            </td>
            <td style="width:33.33%;"></td>
            <td style="width:33.33%;">
                <div class="line">Approved By (Selection)</div>
                <div class="who">{{ $q['selected'] ? ($q['selected']['by'] ?: '-') : '' }}</div>
                <div class="when">{{ $q['selected'] ? $q['selected']['at'] : '' }}</div>
            </td>
        </tr>
    </table>
    @endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell">
                <img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" />
                <div class="qr-caption">Scan to verify</div>
            </td>
            <td class="foot-cell">{{ $companyName ?? 'RANS Company Limited' }} / Quotation {{ $q['reference'] }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
