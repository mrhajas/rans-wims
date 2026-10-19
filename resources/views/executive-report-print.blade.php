<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Executive Intelligence Report - {{ $metrics['week_start'] }} to {{ $metrics['week_end'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #1f2937; margin: 24px; }
        h1 { font-size: 20px; margin: 0; color: #27447a; }
        .top { margin-bottom: 14px; text-align: center; }
        .meta { margin: 16px 0; padding: 10px 12px; background: #f3f4f6; border-radius: 4px; }
        .meta-row { margin-bottom: 4px; }
        .label { font-weight: 700; color: #111827; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; word-break: break-word; }
        th { background: #27447a; color: #fff; font-weight: 600; }
        tr:nth-child(even) td { background: #f9fafb; }
        .section-title { font-size: 14px; font-weight: 700; margin-top: 14px; margin-bottom: 6px; page-break-after: avoid; page-break-inside: avoid; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-green { background: #d1fae5; color: #047857; }
        .badge-red { background: #fee2e2; color: #b91c1c; }
        .badge-amber { background: #fef3c7; color: #92400e; }
        .badge-gray { background: #f3f4f6; color: #6b7280; }
        .summary-box { margin: 14px 0; padding: 12px 14px; background: #eef2ff; border-left: 4px solid #27447a; border-radius: 4px; line-height: 1.5; }
        .events-list { margin: 6px 0 0; padding-left: 18px; }
        .events-list li { margin-bottom: 4px; }
        .kpi-grid { display: table; width: 100%; margin-top: 10px; }
        .kpi-cell { display: table-cell; width: 25%; padding: 10px; background: #f9fafb; border-radius: 4px; }
        .kpi-value { font-size: 16px; font-weight: 700; color: #27447a; }
        .kpi-label { font-size: 10px; color: #6b7280; text-transform: uppercase; }
        .change-up { color: #b91c1c; }
        .change-down { color: #047857; }
        .actions { margin: 12px 0 18px; }
        .actions button { padding: 6px 14px; background: #27447a; color: #fff; border: 0; border-radius: 4px; cursor: pointer; font-size: 12px; }
        @media print {
            .actions { display: none; }
            body { margin: 12mm; }
        }
    </style>
</head>
<body>
    @php
        $fmtMoney = fn ($v) => number_format((float) $v, 2);
        $fmtChange = function ($pct) {
            if (is_null($pct)) return '—';
            $cls = $pct > 0 ? 'change-up' : ($pct < 0 ? 'change-down' : '');
            $sign = $pct > 0 ? '+' : '';
            return "<span class=\"{$cls}\">{$sign}{$pct}%</span>";
        };
    @endphp

    <div class="top">
        @if($logoPath)
            <img src="{{ $logoPath }}" alt="Logo" style="max-height: 90px; width: auto;" />
        @endif
        <h1>Executive Intelligence Report</h1>
    </div>

    <div class="meta">
        <div class="meta-row"><span class="label">Period:</span> {{ $metrics['week_start'] }} to {{ $metrics['week_end'] }}</div>
        <div class="meta-row"><span class="label">Company:</span> {{ $companyName }}</div>
        <div class="meta-row"><span class="label">Generated:</span> {{ $generatedAt }}</div>
    </div>

    @if(!empty($summary['executive_summary']))
        <div class="section-title">Executive Summary</div>
        <div class="summary-box">{{ $summary['executive_summary'] }}</div>
    @endif

    @if(!empty($summary['notable_events']))
        <div class="section-title">Notable Events</div>
        <ul class="events-list">
            @foreach($summary['notable_events'] as $event)
                <li>{{ $event }}</li>
            @endforeach
        </ul>
    @endif

    @if(!empty($summary['action_items']))
        <div class="section-title">Recommended Actions</div>
        <ul class="events-list">
            @foreach($summary['action_items'] as $action)
                <li>{{ $action }}</li>
            @endforeach
        </ul>
    @endif

    @if(isset($metrics['total_inventory_value']) || isset($metrics['consumption_value']) || isset($metrics['active_projects']) || isset($metrics['month_to_date']))
    <div class="section-title">Key Metrics at a Glance</div>
    <div class="kpi-grid">
        @isset($metrics['total_inventory_value'])
        <div class="kpi-cell">
            <div class="kpi-value">TSh {{ $fmtMoney($metrics['total_inventory_value']) }}</div>
            <div class="kpi-label">Total Inventory Value</div>
        </div>
        @endisset
        @isset($metrics['consumption_value'])
        <div class="kpi-cell">
            <div class="kpi-value">TSh {{ $fmtMoney($metrics['consumption_value']['this_week']) }}</div>
            <div class="kpi-label">Consumed This Week ({!! $fmtChange($metrics['consumption_value']['change_percent']) !!} vs last week)</div>
        </div>
        @endisset
        @isset($metrics['active_projects'])
        <div class="kpi-cell">
            <div class="kpi-value">{{ $metrics['active_projects'] }}</div>
            <div class="kpi-label">Active Project(s) / Warehouse(s)</div>
        </div>
        @endisset
        @isset($metrics['month_to_date'])
        <div class="kpi-cell">
            <div class="kpi-value">TSh {{ $fmtMoney($metrics['month_to_date']['this_month_to_date']) }}</div>
            <div class="kpi-label">{{ $metrics['month_to_date']['month_label'] }} to Date ({!! $fmtChange($metrics['month_to_date']['change_percent']) !!} vs same point last month)</div>
        </div>
        @endisset
    </div>
    @endif

    @if(!empty($metrics['transaction_counts']))
    <table>
        <thead>
            <tr>
                <th>Transaction Type</th>
                <th style="text-align:right;">This Week</th>
                <th style="text-align:right;">Prior Week</th>
                <th style="text-align:right;">Change</th>
            </tr>
        </thead>
        <tbody>
            @foreach($metrics['transaction_counts'] as $type => $row)
                <tr>
                    <td style="text-transform: capitalize;">{{ $type }}</td>
                    <td style="text-align:right;">{{ $row['this_week'] }}</td>
                    <td style="text-align:right;">{{ $row['prior_week'] }}</td>
                    <td style="text-align:right;">{!! $fmtChange($row['change_percent']) !!}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if(!empty($metrics['top_consuming_projects']))
        <div class="section-title">Top Consuming Projects This Week</div>
        <table>
            <thead><tr><th>Project / Warehouse</th><th style="text-align:right;">Value Consumed</th></tr></thead>
            <tbody>
                @foreach($metrics['top_consuming_projects'] as $row)
                    <tr><td>{{ $row['name'] }}</td><td style="text-align:right;">TSh {{ $fmtMoney($row['value']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if(!empty($metrics['portfolio_overview']))
        <div class="section-title">Project Portfolio Overview (All Active Projects)</div>
        <table>
            <thead><tr><th>Project / Warehouse</th><th style="text-align:right;">Stock Value</th><th style="text-align:right;">Completion</th></tr></thead>
            <tbody>
                @foreach($metrics['portfolio_overview'] as $row)
                    <tr>
                        <td>{{ $row['name'] }}</td>
                        <td style="text-align:right;">TSh {{ $fmtMoney($row['stock_value']) }}</td>
                        <td style="text-align:right;">{{ $row['completion_percentage'] !== null ? $row['completion_percentage'] . '%' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if(!empty($metrics['top_used_materials']))
        <div class="section-title">Top Used Materials This Week</div>
        <table>
            <thead><tr><th>Item</th><th>Code</th><th style="text-align:right;">Quantity</th></tr></thead>
            <tbody>
                @foreach($metrics['top_used_materials'] as $row)
                    <tr><td>{{ $row['name'] }}</td><td>{{ $row['code'] }}</td><td style="text-align:right;">{{ number_format($row['quantity'], 2) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @isset($insights['material_requests_pipeline'])
    <div class="section-title">Material Requests Pipeline</div>
    @if(!empty($insights['material_requests_pipeline']['by_status']))
        <table>
            <thead><tr><th>Status</th><th style="text-align:right;">Count</th></tr></thead>
            <tbody>
                @foreach($insights['material_requests_pipeline']['by_status'] as $status => $count)
                    <tr><td>{{ $status }}</td><td style="text-align:right;">{{ $count }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p>No open material requests.</p>
    @endif
    @if(!empty($insights['material_requests_pipeline']['stale']))
        <div class="section-title" style="font-size:12px;">Stuck Over {{ $cfg->limit('stale_days') }} Days</div>
        <table>
            <thead><tr><th>ID</th><th>Status</th><th style="text-align:right;">Days Stuck</th></tr></thead>
            <tbody>
                @foreach($insights['material_requests_pipeline']['stale'] as $row)
                    <tr><td>#{{ $row['id'] }}</td><td>{{ $row['status'] }}</td><td style="text-align:right;"><span class="badge badge-amber">{{ $row['days_stuck'] }}d</span></td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @endisset

    @isset($insights['material_reallocations_pipeline'])
    <div class="section-title">Material Reallocations Pipeline</div>
    @if(!empty($insights['material_reallocations_pipeline']['by_status']))
        <table>
            <thead><tr><th>Status</th><th style="text-align:right;">Count</th></tr></thead>
            <tbody>
                @foreach($insights['material_reallocations_pipeline']['by_status'] as $status => $count)
                    <tr><td>{{ $status }}</td><td style="text-align:right;">{{ $count }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p>No open material reallocations.</p>
    @endif
    @if(!empty($insights['material_reallocations_pipeline']['stale']))
        <div class="section-title" style="font-size:12px;">Stuck Over {{ $cfg->limit('stale_days') }} Days</div>
        <table>
            <thead><tr><th>Reference</th><th>Status</th><th style="text-align:right;">Days Stuck</th></tr></thead>
            <tbody>
                @foreach($insights['material_reallocations_pipeline']['stale'] as $row)
                    <tr><td>{{ $row['reference'] }}</td><td>{{ $row['status'] }}</td><td style="text-align:right;"><span class="badge badge-amber">{{ $row['days_stuck'] }}d</span></td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @endisset

    @isset($insights['site_report_compliance'])
    <div class="section-title">Daily Site Report Compliance</div>
    @if($insights['site_report_compliance']['compliance_percent'] !== null)
        <p><span class="label">Compliance Rate:</span> {{ $insights['site_report_compliance']['submitted'] }} / {{ $insights['site_report_compliance']['expected'] }} ({{ $insights['site_report_compliance']['compliance_percent'] }}%)</p>
        @if(!empty($insights['site_report_compliance']['non_compliant_sites']))
            <table>
                <thead><tr><th>Site Engineer</th><th>Project / Warehouse</th></tr></thead>
                <tbody>
                    @foreach($insights['site_report_compliance']['non_compliant_sites'] as $row)
                        <tr><td>{{ $row['user_name'] }}</td><td>{{ $row['warehouse_name'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @else
        <p>No site engineer assignments configured.</p>
    @endif
    @endisset

    @if(!empty($insights['boq_variance_flags']))
        <div class="section-title">BOQ Budget Variance Flags</div>
        <table>
            <thead><tr><th>Project</th><th style="text-align:right;">Issued to Date</th><th style="text-align:right;">BOQ Baseline</th><th style="text-align:right;">Consumption</th></tr></thead>
            <tbody>
                @foreach($insights['boq_variance_flags'] as $row)
                    <tr>
                        <td>{{ $row['project_name'] }}</td>
                        <td style="text-align:right;">{{ number_format($row['issued_to_date'], 2) }}</td>
                        <td style="text-align:right;">{{ number_format($row['boq_baseline'], 2) }}</td>
                        <td style="text-align:right;"><span class="badge badge-red">{{ $row['consumption_percent'] }}%</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if(!empty($insights['adjustment_watch']))
        <div class="section-title">Stock &amp; Discrepancy Watch</div>
        <table>
            <thead><tr><th>Warehouse</th><th style="text-align:right;">Damage Adjustments This Week</th><th style="text-align:right;">4-Week Average</th></tr></thead>
            <tbody>
                @foreach($insights['adjustment_watch'] as $row)
                    <tr>
                        <td>{{ $row['warehouse_name'] }}</td>
                        <td style="text-align:right;"><span class="badge badge-amber">{{ $row['damage_adjustments_this_week'] }}</span></td>
                        <td style="text-align:right;">{{ $row['trailing_4week_average'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if(!empty($insights['stock_health']['out_of_stock_count']))
        <div class="section-title">Stock Health</div>
        <p>
            <span class="label">Actively-Used Items Now Out of Stock:</span> <span class="badge badge-red">{{ $insights['stock_health']['out_of_stock_count'] }}</span>
            <br><span style="font-size:10px;color:#6b7280;">Items checked out in the last 30 days at a warehouse where they currently sit at zero stock.</span>
        </p>
        <table>
            <thead><tr><th>Item</th><th>Warehouse</th><th style="text-align:right;">Quantity</th></tr></thead>
            <tbody>
                @foreach($insights['stock_health']['critical_items'] as $row)
                    <tr>
                        <td>{{ $row['item_name'] }} ({{ $row['item_code'] }})</td>
                        <td>{{ $row['warehouse_name'] }}</td>
                        <td style="text-align:right;"><span class="badge badge-red">{{ number_format($row['quantity'], 2) }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if(!empty($insights['dispatch_in_transit']))
        <div class="section-title">Dispatch &amp; Logistics</div>
        <table>
            <thead><tr><th>Reference</th><th>Status</th><th>Vehicle</th><th>Driver</th><th>Dispatched</th></tr></thead>
            <tbody>
                @foreach($insights['dispatch_in_transit'] as $row)
                    <tr>
                        <td>{{ $row['reference'] }}</td>
                        <td>{{ $row['status'] }}</td>
                        <td>{{ $row['vehicle'] ?? '-' }}</td>
                        <td>{{ $row['driver_name'] ?? '-' }}</td>
                        <td>{{ $row['dispatch_date_time'] ?? '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="actions">
        <button onclick="window.print()">Print / Save as PDF</button>
    </div>
</body>
</html>
