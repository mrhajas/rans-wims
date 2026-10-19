<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        * { box-sizing: border-box; }
        html { color-scheme: light; background: #ffffff; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11px;
            color: #1f2937;
            margin: 0;
            background: #ffffff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .header { display: table; width: 100%; margin-bottom: 14px; }
        .header .left, .header .right { display: table-cell; vertical-align: middle; }
        .header .right { text-align: right; }
        .header img { max-height: 64px; }
        .company-info { font-size: 12px; font-weight: 700; color: #27447a; margin-bottom: 2px; }
        .generated-info { font-size: 9px; color: #6b7280; }
        h1 { font-size: 19px; margin: 10px 0 3px; color: #27447a; text-align: center; font-weight: 700; text-transform: uppercase; }
        h1 .ic { width: 15px; height: 15px; vertical-align: -2px; margin-right: 6px; }
        .subtitle { text-align: center; font-size: 11px; color: #6b7280; margin-bottom: 10px; }
        .divider { border-bottom: 1px solid #e5e7eb; margin: 0 0 12px; }

        .summary-row { display: table; width: 100%; margin: 4px 0 14px; }
        .summary-card { display: table-cell; width: 33.33%; background: #f3f4f6; padding: 10px 14px; border-radius: 4px; text-align: center; }
        .summary-card + .summary-card { border-left: 1px solid #e5e7eb; }
        .summary-card .label { font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; }
        .summary-card .value { font-size: 15px; font-weight: 700; color: #27447a; margin-top: 3px; }

        .grand-total { margin: 6px 0 16px; padding: 12px 18px; background: #27447a; color: #fff; border-radius: 6px; text-align: center; }
        .grand-total .label { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.85; }
        .grand-total .value { font-size: 22px; font-weight: 700; margin-top: 3px; }

        table.items { width: 100%; border-collapse: collapse; margin-top: 4px; margin-bottom: 4px; }
        table.items th, table.items td { text-align: left; padding: 6px 9px; border: 1px solid #d1d5db; vertical-align: middle; font-size: 9.3px; }
        table.items th { background: #27447a; color: #fff; font-weight: 700; text-transform: uppercase; font-size: 8.3px; }
        table.items td.right, table.items th.right { text-align: right; }
        table.items td.center, table.items th.center { text-align: center; }
        table.items tbody tr:nth-child(odd) { background: #f9fafb; }
        table.items tbody tr:nth-child(even) { background: #ffffff; }
        table.items tfoot td { border: 1px solid #d1d5db; background: #eef2ff; font-weight: 700; color: #1f2a5a; font-size: 10px; }
        .total-cell { color: #800000 !important; font-weight: 700; }

        .cat-badge { display: inline-block; padding: 2px 7px; border-radius: 999px; font-size: 7.8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; background: #eef2ff; color: #3730a3; }
        .wh-dot { display: inline-block; width: 7px; height: 7px; border-radius: 999px; margin-right: 5px; vertical-align: 1px; }

        .share-track { position: relative; width: 100%; height: 7px; border-radius: 999px; background: #e5e7eb; }
        .share-fill { position: absolute; top: 0; left: 0; height: 7px; border-radius: 999px; background: #0e7490; }
        .share-label { font-size: 8px; color: #6b7280; margin-top: 2px; }

        .empty { padding: 24px 8px; color: #6b7280; font-style: italic; text-align: center; }
        .footer { margin-top: 18px; color: #6b7280; font-size: 10px; text-align: center; }

        @media print {
            body { margin: 0; }
            .header, h1, .summary-row, .grand-total { page-break-inside: avoid; }
            table.items tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    @php($logoPath = public_path('images/logo.png'))
    @php($logoSrc = !empty($autoPrint) ? asset('images/logo.png') : $logoPath)
    @php($displayCompanyName = preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) ($companyName ?? '')))
    @php($sortedWarehouses = collect($warehouses)->sortByDesc('amount')->values())
    @php($grandTotal = (float) ($totalValue ?? $sortedWarehouses->sum('amount')))
    @php($topWarehouse = $sortedWarehouses->first())
    @php($palette = ['#0891b2', '#4f46e5', '#d97706', '#059669', '#db2777', '#7c3aed', '#0284c7', '#dc2626'])

    <div class="header">
        <div class="left">
            @if(file_exists($logoPath))
                <img src="{{ $logoSrc }}" alt="Logo" />
            @endif
        </div>
        <div class="right">
            <div class="company-info">{{ $displayCompanyName ?: 'RANS Company Limited' }}</div>
            <div class="generated-info">Generated: {{ optional($generatedAt)->format('Y-m-d H:i') }}</div>
            <div class="generated-info">Generated By: {{ $generatedBy ?? 'system' }}</div>
        </div>
    </div>

    <h1>
        <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="#27447a" stroke-width="2.5"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></svg>{{ $title }}
    </h1>
    <div class="subtitle">Stock value across every project / warehouse, {{ $sortedWarehouses->count() }} listed</div>
    <div class="divider"></div>

    <div class="summary-row">
        <div class="summary-card">
            <div class="label">Warehouses Listed</div>
            <div class="value">{{ $sortedWarehouses->count() }}</div>
        </div>
        <div class="summary-card">
            <div class="label">Top Warehouse</div>
            <div class="value" style="font-size: 12px;">{{ $topWarehouse['name'] ?? '-' }}</div>
        </div>
        <div class="summary-card">
            <div class="label">Company Total Value</div>
            <div class="value">{{ number_format($grandTotal, 2) }} Tsh</div>
        </div>
    </div>

    <div class="grand-total">
        <div class="label">Company Total Stock Value</div>
        <div class="value">Tsh {{ number_format($grandTotal, 2) }}</div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 40px;">SN</th>
                <th>Project(s) / Warehouse(s) Name</th>
                <th style="width: 110px;">Category</th>
                <th style="width: 130px;">Share</th>
                <th class="right" style="width: 130px;">Stock Value</th>
            </tr>
        </thead>
        <tbody>
            @forelse($sortedWarehouses as $index => $warehouse)
                @php($share = $grandTotal > 0 ? round(((float) $warehouse['amount'] / $grandTotal) * 100, 1) : 0)
                @php($dot = $palette[$index % count($palette)])
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td><span class="wh-dot" style="background: {{ $dot }};"></span>{{ $warehouse['name'] }}</td>
                    <td><span class="cat-badge">{{ $warehouse['category'] ?? 'Uncategorized' }}</span></td>
                    <td>
                        <div class="share-track"><div class="share-fill" style="width: {{ $share }}%; background: {{ $dot }};"></div></div>
                        <div class="share-label">{{ $share }}%</div>
                    </td>
                    <td class="right total-cell">{{ number_format((float) $warehouse['amount'], 2) }} Tsh</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">There is no data to display.</td></tr>
            @endforelse
        </tbody>
        @if($sortedWarehouses->count())
            <tfoot>
                <tr>
                    <td colspan="4">Total</td>
                    <td class="right total-cell">{{ number_format($grandTotal, 2) }} Tsh</td>
                </tr>
            </tfoot>
        @endif
    </table>

    <div class="footer">RANS WIMS &mdash; {{ $title }}</div>

    @if(!empty($autoPrint))
        <script>
            window.addEventListener('load', function () {
                setTimeout(function () { window.print(); }, 250);
            });
        </script>
    @endif
</body>
</html>
