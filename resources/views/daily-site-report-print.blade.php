<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Site Report - {{ $dailySiteReport->report_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #1f2937; margin: 24px; }
        h1 { font-size: 20px; margin: 0; color: #27447a; }
        .top { margin-bottom: 14px; text-align: center; }
        .meta { margin: 16px 0; padding: 10px 12px; background: #f3f4f6; border-radius: 4px; }
        .meta-row { margin-bottom: 4px; }
        .label { font-weight: 700; color: #111827; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th { background: #27447a; color: #fff; font-weight: 600; }
        tr:nth-child(even) td { background: #f9fafb; }
        .section-title { font-size: 14px; font-weight: 700; margin-top: 14px; margin-bottom: 6px; }
        .actions { margin: 12px 0 18px; }
        .actions button { padding: 6px 14px; background: #27447a; color: #fff; border: 0; border-radius: 4px; cursor: pointer; font-size: 12px; }
        @media print {
            .actions { display: none; }
            body { margin: 12mm; }
        }
    </style>
</head>
<body>
    <div class="top">
        @if($logoPath)
            <img src="{{ $logoPath }}" alt="RANS Logo" style="max-height: 90px; width: auto;" />
        @endif
        <h1>Site Report</h1>
    </div>

    <div class="meta">
        <div class="meta-row"><span class="label">Report No:</span> {{ $dailySiteReport->report_number }}</div>
        <div class="meta-row"><span class="label">Report Date:</span> {{ optional($dailySiteReport->report_date)->format('Y-m-d') }}</div>
        <div class="meta-row"><span class="label">Submitted At:</span> {{ $dailySiteReport->submitted_at }}</div>
        <div class="meta-row"><span class="label">Project(s) / Warehouse(s):</span> {{ optional($dailySiteReport->warehouse)->name }}</div>
        <div class="meta-row"><span class="label">Site Engineer:</span> {{ optional($dailySiteReport->submittedBy)->name }}</div>
        <div class="meta-row"><span class="label">Report Type:</span> {{ $dailySiteReport->report_type }}</div>
        <div class="meta-row"><span class="label">Priority:</span> {{ $dailySiteReport->priority }}</div>
        <div class="meta-row"><span class="label">Status:</span> {{ $dailySiteReport->status }}</div>
        <div class="meta-row"><span class="label">Schedule Status:</span> {{ $dailySiteReport->schedule_status }}</div>
        <div class="meta-row"><span class="label">Printed By:</span> {{ $printedBy }} ({{ $printedAt }})</div>
    </div>

    <div class="section-title">Remarks</div>
    <div>{{ $dailySiteReport->remarks ?: '-' }}</div>

    <div class="section-title">Audit Trail</div>
    <table>
        <thead>
            <tr>
                <th style="width: 120px;">Action</th>
                <th style="width: 140px;">By</th>
                <th style="width: 180px;">Date & Time</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse($dailySiteReport->logs as $log)
                <tr>
                    <td>{{ ucfirst(str_replace('_', ' ', $log->action)) }}</td>
                    <td>{{ optional($log->user)->name ?? 'System' }}</td>
                    <td>{{ optional($log->created_at)->format('Y-m-d H:i') }}</td>
                    <td>{{ $log->remarks }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center; padding:16px;">No logs found.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Signatories module: shown only when enabled for this document; otherwise the report prints exactly as before. --}}
    @if(!empty($signatures['enabled']))
        <style>.sg-wrap td { border-bottom: 0 !important; background: transparent !important; } .sg-wrap table { margin-top: 14px; }</style>
        <div class="sg-wrap">
            @include('partials.print.signature-block', ['sig' => $signatures])
        </div>
    @endif

    <div class="actions">
        <button onclick="window.print()">Print / Save as PDF</button>
    </div>
</body>
</html>
