<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11px;
            color: #1f2937;
            margin: 18px;
        }
        .header {
            display: table;
            width: 100%;
            margin-bottom: 14px;
        }
        .header .left, .header .right {
            display: table-cell;
            vertical-align: top;
        }
        .header .right { text-align: right; }
        .header img { max-height: 70px; }
        h1 {
            font-size: 18px;
            margin: 8px 0 4px;
            color: #27447a;
            text-transform: uppercase;
            text-align: center;
            font-weight: 700;
        }
        .subtitle { text-align: center; font-size: 11px; color: #6b7280; margin-bottom: 4px; }
        .divider { border-bottom: 1px solid #e5e7eb; margin: 8px 0; }
        .meta {
            display: table;
            width: 100%;
            margin: 4px 0 12px;
            background: #f3f4f6;
            padding: 6px 8px;
            border-radius: 2px;
        }
        .meta .col {
            display: inline-block;
            margin-right: 20px;
            font-size: 9px;
        }
        .meta strong { color: #111827; font-weight: 600; }
        .summary-row { display: table; width: 100%; margin: 4px 0 14px; }
        .summary-card {
            display: table-cell;
            width: 20%;
            background: #f3f4f6;
            padding: 8px 10px;
            border-radius: 2px;
            text-align: center;
        }
        .summary-card + .summary-card { border-left: 1px solid #e5e7eb; }
        .summary-card .label { font-size: 8px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; }
        .summary-card .value { font-size: 13px; font-weight: 700; color: #27447a; margin-top: 2px; }
        .grand-total {
            margin: 4px 0 14px;
            padding: 10px 12px;
            background: #27447a;
            color: #fff;
            border-radius: 2px;
            text-align: center;
        }
        .grand-total .label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.85; }
        .grand-total .value { font-size: 20px; font-weight: 700; margin-top: 2px; }
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
            margin-bottom: 4px;
        }
        table.items th, table.items td {
            text-align: left;
            padding: 5px 7px;
            border: 1px solid #d1d5db;
            vertical-align: top;
            font-size: 9px;
        }
        table.items th {
            background: #f3f4f6;
            color: #374151;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 8px;
        }
        table.items td.right, table.items th.right { text-align: right; }
        table.items td.center, table.items th.center { text-align: center; }
        table.items tbody tr:nth-child(odd) { background: #f9fafb; }
        table.items tbody tr:nth-child(even) { background: #ffffff; }
        .type-badge { font-size: 8px; font-weight: 700; padding: 1px 5px; border-radius: 2px; }
        .type-checkin { background: #d1fae5; color: #065f46; }
        .type-checkout { background: #fee2e2; color: #991b1b; }
        .type-transfer_in { background: #dbeafe; color: #1e40af; }
        .type-transfer_out { background: #fef3c7; color: #92400e; }
        .empty { padding: 24px 8px; color: #6b7280; font-style: italic; text-align: center; }
        .footer { margin-top: 18px; color: #6b7280; font-size: 10px; text-align: center; }
        .company-info { font-size: 12px; font-weight: 600; color: #27447a; margin-bottom: 2px; }
        .generated-info { font-size: 9px; color: #6b7280; }
        @media print {
            body { margin: 0; }
            .header, h1, .meta, .summary-row, .grand-total { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    @php($logoPath = public_path('images/logo.png'))
    @php($logoSrc = !empty($autoPrint) ? asset('images/logo.png') : $logoPath)

    @php($displayCompanyName = preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) ($companyName ?? '')))

    <div class="header">
        <div class="left">
            @if(file_exists($logoPath))
                <img src="{{ $logoSrc }}" alt="Logo" />
            @endif
        </div>
        <div class="right">
            @if(!empty($displayCompanyName))
                <div class="company-info">{{ $displayCompanyName }}</div>
            @endif
            <div class="generated-info">Generated: {{ $generatedAt->format('Y-m-d H:i') }}</div>
        </div>
    </div>

    <div class="divider"></div>
    <h1>{{ $title }}</h1>
    <div class="subtitle">{{ $viewLabel ?? 'Ledger' }} &middot; WORKFLOW INTEGRATED MANAGEMENT SYSTEM</div>

    <div class="meta">
        <div class="col"><strong>Project:</strong> {{ $projectName ?? '-' }}</div>
        <div class="col"><strong>Site:</strong> {{ $siteName ?? '-' }}</div>
        <div class="col"><strong>Period:</strong> {{ $periodFrom ?: 'Entire Project' }}{{ $periodTo ? ' – ' . $periodTo : '' }}</div>
        <div class="col"><strong>Generated By:</strong> {{ $generatedBy }}</div>
    </div>

    @if($summary)
        <div class="summary-row">
            <div class="summary-card">
                <div class="label">Checked In</div>
                <div class="value">Tsh {{ number_format($summary['checked_in']['value'], 2) }}</div>
            </div>
            <div class="summary-card">
                <div class="label">Checked Out</div>
                <div class="value">Tsh {{ number_format($summary['checked_out']['value'], 2) }}</div>
            </div>
            <div class="summary-card">
                <div class="label">Transfer In</div>
                <div class="value">Tsh {{ number_format($summary['transfer_in']['value'], 2) }}</div>
            </div>
            <div class="summary-card">
                <div class="label">Transfer Out</div>
                <div class="value">Tsh {{ number_format($summary['transfer_out']['value'], 2) }}</div>
            </div>
            <div class="summary-card">
                <div class="label">Total Value</div>
                <div class="value">Tsh {{ number_format($summary['grand_total_value'], 2) }}</div>
            </div>
        </div>
    @endif

    @if($view === 'item_summary')
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 24%;">Item</th>
                    <th style="width: 8%; text-align: center;">Unit</th>
                    <th style="width: 13%;" class="right">Checked In</th>
                    <th style="width: 13%;" class="right">Checked Out</th>
                    <th style="width: 13%;" class="right">Transfer In</th>
                    <th style="width: 13%;" class="right">Transfer Out</th>
                    <th style="width: 16%;" class="right">Total Value</th>
                </tr>
            </thead>
            <tbody>
                @forelse($itemSummary as $row)
                    <tr>
                        <td>{{ $row['item_name'] }}{{ $row['item_code'] ? ' ('.$row['item_code'].')' : '' }}</td>
                        <td class="center">{{ $row['unit'] }}</td>
                        <td class="right">{{ number_format($row['checked_in'], 2) }}</td>
                        <td class="right">{{ number_format($row['checked_out'], 2) }}</td>
                        <td class="right">{{ number_format($row['transfer_in'], 2) }}</td>
                        <td class="right">{{ number_format($row['transfer_out'], 2) }}</td>
                        <td class="right">{{ number_format($row['total_value'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">No data for the selected filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    @elseif($view === 'movement_summary')
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 12%;">Reference</th>
                    <th style="width: 20%;">Item</th>
                    <th style="width: 18%;">From</th>
                    <th style="width: 18%;">To</th>
                    <th style="width: 10%;">Type</th>
                    <th style="width: 8%;" class="right">Qty</th>
                    <th style="width: 6%; text-align: center;">Unit</th>
                    <th style="width: 8%;" class="right">Value</th>
                </tr>
            </thead>
            <tbody>
                @forelse($movementSummary as $row)
                    <tr>
                        <td>{{ $row['reference'] }}</td>
                        <td>{{ $row['item_name'] }}{{ $row['item_code'] ? ' ('.$row['item_code'].')' : '' }}</td>
                        <td>{{ $row['from'] }}</td>
                        <td>{{ $row['to'] }}</td>
                        <td>{{ $row['transaction_type'] }}</td>
                        <td class="right">{{ number_format($row['quantity'], 2) }}</td>
                        <td class="center">{{ $row['unit'] }}</td>
                        <td class="right">{{ number_format($row['value'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty">No data for the selected filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    @elseif($view === 'supplier_summary')
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 30%;">Supplier</th>
                    <th style="width: 30%;">Item</th>
                    <th style="width: 12%;" class="right">Quantity</th>
                    <th style="width: 8%; text-align: center;">Unit</th>
                    <th style="width: 20%;" class="right">Value</th>
                </tr>
            </thead>
            <tbody>
                @forelse($supplierSummary as $group)
                    @forelse($group['items'] as $item)
                        <tr>
                            <td>{{ $group['supplier_name'] }}</td>
                            <td>{{ $item['item_name'] }}{{ $item['item_code'] ? ' ('.$item['item_code'].')' : '' }}</td>
                            <td class="right">{{ number_format($item['quantity'], 2) }}</td>
                            <td class="center">{{ $group['unit'] }}</td>
                            <td class="right">{{ number_format($item['value'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td>{{ $group['supplier_name'] }}</td>
                            <td colspan="4">-</td>
                        </tr>
                    @endforelse
                @empty
                    <tr><td colspan="5" class="empty">No supplier deliveries for the selected filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    @elseif($view === 'reconciliation')
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 24%;">Item</th>
                    <th style="width: 8%; text-align: center;">Unit</th>
                    <th style="width: 15%;" class="right">Opening Stock</th>
                    <th style="width: 15%;" class="right">Period Movement</th>
                    <th style="width: 15%;" class="right">Expected Closing</th>
                    <th style="width: 15%;" class="right">Actual Current Stock</th>
                    <th style="width: 8%;" class="right">Variance</th>
                </tr>
            </thead>
            <tbody>
                @forelse($reconciliation as $row)
                    <tr>
                        <td>{{ $row['item_name'] }}{{ $row['item_code'] ? ' ('.$row['item_code'].')' : '' }}</td>
                        <td class="center">{{ $row['unit'] }}</td>
                        <td class="right">{{ number_format($row['opening_stock'], 2) }}</td>
                        <td class="right">{{ number_format($row['period_movement'], 2) }}</td>
                        <td class="right">{{ number_format($row['expected_closing'], 2) }}</td>
                        <td class="right">{{ $row['actual_current_stock'] === null ? 'N/A' : number_format($row['actual_current_stock'], 2) }}</td>
                        <td class="right">{{ $row['variance'] === null ? 'N/A' : number_format($row['variance'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">No items to reconcile for the selected filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    @elseif($view === 'boq')
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 26%;">BOQ Item</th>
                    <th style="width: 8%; text-align: center;">Unit</th>
                    <th style="width: 16%;" class="right">BOQ Quantity</th>
                    <th style="width: 16%;" class="right">Used Quantity</th>
                    <th style="width: 16%;" class="right">Remaining</th>
                    <th style="width: 18%;" class="right">Material Value</th>
                </tr>
            </thead>
            <tbody>
                @forelse($boq as $row)
                    <tr>
                        <td>{{ $row['item_name'] }}{{ $row['item_code'] ? ' ('.$row['item_code'].')' : '' }}</td>
                        <td class="center">{{ $row['unit'] }}</td>
                        <td class="right">{{ number_format($row['boq_quantity'], 2) }}</td>
                        <td class="right">{{ number_format($row['used_quantity'], 2) }}</td>
                        <td class="right">{{ number_format($row['remaining_quantity'], 2) }}</td>
                        <td class="right">{{ number_format($row['material_value'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No BOQ lines for the selected filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    @else
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 9%;">Date</th>
                    <th style="width: 9%;">Type</th>
                    <th style="width: 10%;">Reference</th>
                    <th style="width: 16%;">Item</th>
                    <th style="width: 8%;" class="right">Qty</th>
                    <th style="width: 7%; text-align: center;">Unit</th>
                    <th style="width: 10%;" class="right">Unit Value</th>
                    <th style="width: 11%;" class="right">Total Value</th>
                    <th style="width: 10%;">From</th>
                    <th style="width: 10%;">To</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    <tr>
                        <td>{{ $row->tx_date }}</td>
                        <td><span class="type-badge type-{{ $row->transaction_key }}">{{ $row->transaction_type }}</span></td>
                        <td>{{ $row->reference }}</td>
                        <td>{{ $row->item_name }}</td>
                        <td class="right">{{ number_format($row->quantity, 2) }}</td>
                        <td class="center">{{ $row->unit }}</td>
                        <td class="right">{{ number_format($row->unit_value, 2) }}</td>
                        <td class="right">{{ number_format($row->total_value, 2) }}</td>
                        <td>{{ $row->from_label }}</td>
                        <td>{{ $row->to_label }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="empty">No matching material movement found for the selected filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif

    @if($summary)
        <div class="grand-total">
            <div class="label">Total Material Value</div>
            <div class="value">Tsh {{ number_format($summary['grand_total_value'], 2) }}</div>
        </div>
    @endif

    <div class="footer">
        RANS WIMS / Project Material Ledger
    </div>

    @if($autoPrint ?? false)
        <script>
            window.print();
        </script>
    @endif
</body>
</html>
