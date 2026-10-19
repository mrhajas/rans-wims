<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Request {{ $req['reference'] }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
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
            <div class="tb-l">Payment Request {{ $req['reference'] }}</div>
            <div class="tb-r"><button type="button" onclick="window.print()">Print</button><a href="{{ $pdfUrl }}">Download PDF</a></div>
        </div>
    @endif
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    <div class="title-card">
        <div class="doc-title">PAYMENT REQUEST</div>
        <div class="doc-ref">Request No.<br><strong>{{ $req['reference'] }}</strong></div>
    </div>

    <div class="card">
        <table class="grid">
            <tr>
                <td class="label">Supplier</td>
                <td class="value">{{ $req['supplier_name'] ?: 'N/A' }}</td>
                <td class="label">Date Requested</td>
                <td class="value">{{ $req['date_requested'] ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Request Type</td>
                <td class="value">{{ $req['type_label'] }}</td>
                <td class="label">Project / Warehouse</td>
                <td class="value">{{ $req['warehouse_name'] ?: '-' }}</td>
            </tr>
            <tr>
                <td class="label">Requested By</td>
                <td class="value">{{ $req['requested_by'] ?: '-' }}</td>
                <td class="label">Status</td>
                <td class="value">
                    <span class="status-pill status-{{ in_array($req['status'], ['Approved', 'Paid']) ? 'active' : ($req['status'] === 'Rejected' ? 'danger' : 'warn') }}">{{ $req['status'] }}</span>
                </td>
            </tr>
            @if($req['decision'])
            <tr>
                <td class="label">{{ $req['decision']['label'] }} By</td>
                <td class="value">{{ $req['decision']['by'] ?: '-' }}</td>
                <td class="label">{{ $req['decision']['label'] }} On</td>
                <td class="value">{{ $req['decision']['at'] }}</td>
            </tr>
            @endif
        </table>
    </div>

    <div class="amount-card">
        <div class="label">Amount Requested</div>
        <div class="value">TZS {{ number_format($req['amount'], 2) }}</div>
    </div>

    <div class="card">
        <div class="card-title">Amount in Words</div>
        <div style="font-weight:bold; color:#1c3f66;">{{ $req['amount_in_words'] }}</div>
    </div>

    <div class="card-title">{{ $req['type_label'] === 'Invoice Payment' ? 'Invoice Covered By This Request' : 'Basis of This Request' }}</div>
    @if($req['type_label'] === 'Invoice Payment')
    <table class="items">
        <thead>
            <tr>
                <th>Invoice No.</th>
                <th>Invoice Date</th>
                <th class="num">Invoice Total</th>
                <th class="num">Balance Now</th>
            </tr>
        </thead>
        <tbody>
            @forelse($req['invoices'] as $inv)
                <tr>
                    <td>{{ $inv['number'] }}</td>
                    <td>{{ $inv['invoice_date'] ?: '-' }}</td>
                    <td class="num">{{ number_format($inv['total'], 2) }}</td>
                    <td class="num" style="font-weight:bold;">{{ number_format($inv['balance'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center; padding:10px;">No invoice linked to this request.</td></tr>
            @endforelse
        </tbody>
    </table>
    @else
    <div class="card">Settlement of the supplier's outstanding account balance, not tied to a single invoice. Once paid, the amount is applied to the oldest outstanding invoices first.</div>
    @endif

    @if(count($req['payments']))
    <div class="card-title" style="margin-top:14px;">Payments Recorded Against This Request</div>
    <table class="items">
        <thead>
            <tr>
                <th>Payment No.</th>
                <th>Date Paid</th>
                <th>Method</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($req['payments'] as $p)
                <tr>
                    <td>{{ $p['reference'] }}</td>
                    <td>{{ $p['paid_on'] ?: '-' }}</td>
                    <td>{{ $p['method'] }}</td>
                    <td class="num">{{ number_format($p['amount'], 2) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="3" style="text-align:right; font-weight:bold;">Total paid</td>
                <td class="num" style="font-weight:bold;">{{ number_format($req['paid_total'], 2) }}</td>
            </tr>
        </tbody>
    </table>
    @endif

    @if(!empty($req['remarks']))
    <div class="card" style="margin-top:14px;">
        <div class="card-title">Remarks</div>
        <div>{{ $req['remarks'] }}</div>
    </div>
    @endif

    @if(!empty($signatures['enabled']))
        @include('partials.print.signature-block', ['sig' => $signatures])
    @else
    <table class="signatures">
        <tr>
            <td style="width:33.33%;">
                <div class="line">Requested By</div>
                <div class="who">{{ $req['requested_by'] ?: '-' }}</div>
                <div class="when">{{ $req['requested_at'] }}</div>
            </td>
            <td style="width:33.33%;"></td>
            <td style="width:33.33%;">
                <div class="line">Approved By</div>
                <div class="who">{{ ($req['decision']['label'] ?? '') === 'Approved' ? ($req['decision']['by'] ?: '-') : '' }}</div>
                <div class="when">{{ ($req['decision']['label'] ?? '') === 'Approved' ? $req['decision']['at'] : '' }}</div>
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
            <td class="foot-cell">{{ $companyName ?? 'RANS Company Limited' }} / Payment Request {{ $req['reference'] }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
</body>
</html>
