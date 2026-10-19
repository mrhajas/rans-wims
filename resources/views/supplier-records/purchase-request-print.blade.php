<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Purchase Request {{ $pr['reference'] }}</title>
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
            <div class="tb-l">Purchase Request {{ $pr['reference'] }}</div>
            <div class="tb-r"><button type="button" onclick="window.print()">Print</button><a href="{{ $pdfUrl }}">Download PDF</a></div>
        </div>
    @endif
    @include('partials.print.watermark')
    @if($pr['is_draft'])
        <div class="draft-mark">DRAFT</div>
    @endif
    @include('partials.print.letterhead')

    <div class="title-card">
        <div class="doc-title">PURCHASE REQUEST</div>
        <div class="doc-ref">Request No.<br><strong>{{ $pr['reference'] }}</strong></div>
    </div>

    <div class="card">
        <table class="grid">
            <tr>
                <td class="label">Project / Warehouse</td>
                <td class="value">{{ $pr['warehouse_name'] ?: 'N/A' }}</td>
                <td class="label">Date Requested</td>
                <td class="value">{{ $pr['date_requested'] ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Requested By</td>
                <td class="value">{{ $pr['requested_by'] ?: '-' }}</td>
                <td class="label">Status</td>
                <td class="value">
                    <span class="status-pill status-{{ in_array($pr['status'], ['Approved', 'Converted to PO']) ? 'active' : ($pr['status'] === 'Rejected' ? 'danger' : ($pr['status'] === 'Draft' ? 'neutral' : 'warn')) }}">{{ $pr['status'] }}</span>
                </td>
            </tr>
            @if($pr['decision'])
            <tr>
                <td class="label">{{ $pr['decision']['label'] }} By</td>
                <td class="value">{{ $pr['decision']['by'] ?: '-' }}</td>
                <td class="label">{{ $pr['decision']['label'] }} On</td>
                <td class="value">{{ $pr['decision']['at'] }}</td>
            </tr>
            @endif
            @if(count($pr['quotations']) || count($pr['purchase_orders']))
            <tr>
                <td class="label">Quotations</td>
                <td class="value">{{ count($pr['quotations']) ? implode(', ', $pr['quotations']) : '-' }}</td>
                <td class="label">Purchase Orders</td>
                <td class="value">{{ count($pr['purchase_orders']) ? implode(', ', $pr['purchase_orders']) : '-' }}</td>
            </tr>
            @endif
        </table>
    </div>

    @if(!empty($pr['reason']))
    <div class="card">
        <div class="card-title">Reason for the Request</div>
        <div>{{ $pr['reason'] }}</div>
    </div>
    @endif

    <div class="card-title">Items Requested</div>
    <table class="items">
        <thead>
            <tr>
                <th style="width:34px;">SN</th>
                <th>Code</th>
                <th>Item</th>
                <th>Unit</th>
                <th class="num">Quantity</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse($pr['items'] as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $item['code'] ?: '-' }}</td>
                    <td>{{ $item['name'] }}</td>
                    <td>{{ $item['unit'] ?: '-' }}</td>
                    <td class="num" style="font-weight:bold;">{{ rtrim(rtrim(number_format($item['quantity'], 2), '0'), '.') }}</td>
                    <td>{{ $item['remarks'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center; padding:10px;">No items.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div style="text-align:right; margin-top:6px; font-size:10.5px;"><strong>{{ count($pr['items']) }}</strong> item(s), total quantity <strong>{{ rtrim(rtrim(number_format($pr['total_quantity'], 2), '0'), '.') }}</strong></div>

    @if(!empty($pr['remarks']))
    <div class="card" style="margin-top:14px;">
        <div class="card-title">Approval Remarks</div>
        <div>{{ $pr['remarks'] }}</div>
    </div>
    @endif

    @if(!empty($signatures['enabled']))
        @include('partials.print.signature-block', ['sig' => $signatures])
    @else
    <table class="signatures">
        <tr>
            <td style="width:33.33%;">
                <div class="line">Requested By</div>
                <div class="who">{{ $pr['requested_by'] ?: '-' }}</div>
                <div class="when">{{ $pr['requested_at'] }}</div>
            </td>
            <td style="width:33.33%;"></td>
            <td style="width:33.33%;">
                <div class="line">Approved By</div>
                <div class="who">{{ ($pr['decision']['label'] ?? '') === 'Approved' ? ($pr['decision']['by'] ?: '-') : '' }}</div>
                <div class="when">{{ ($pr['decision']['label'] ?? '') === 'Approved' ? $pr['decision']['at'] : '' }}</div>
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
            <td class="foot-cell">{{ $companyName ?? 'RANS Company Limited' }} / Purchase Request {{ $pr['reference'] }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
