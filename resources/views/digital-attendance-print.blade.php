<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Rans Wims - Digital Attendance Report</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #1f2937; margin: 24px; }
        h1 { font-size: 20px; margin: 0 0 4px; color: #27447a; }
        .sub { color: #6b7280; font-size: 12px; margin-bottom: 12px; }
        .meta { margin-bottom: 12px; padding: 8px 12px; background: #f3f4f6; border-radius: 4px; }
        .meta strong { color: #111827; }
        .summary { display: flex; gap: 16px; margin-bottom: 16px; }
        .summary div { flex: 1; padding: 10px 12px; background: #eef2ff; border-radius: 6px; text-align: center; }
        .summary .label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: #4338ca; font-weight: 700; }
        .summary .value { font-size: 16px; font-weight: 800; color: #1e1b4b; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th { background: #27447a; color: #fff; font-weight: 600; }
        tr:nth-child(even) td { background: #f9fafb; }
        .status-late { color: #b45309; font-weight: 700; }
        .status-missing { color: #dc2626; font-weight: 700; }
        .status-ok { color: #15803d; font-weight: 700; }
        .actions { margin: 12px 0 18px; }
        .actions button { padding: 6px 14px; background: #27447a; color: #fff; border: 0; border-radius: 4px; cursor: pointer; font-size: 12px; }
        .footer { margin-top: 16px; color: #6b7280; font-size: 11px; text-align: center; }
        @media print {
            .actions { display: none; }
            body { margin: 12mm; }
        }
    </style>
</head>
<body>
    <div style="text-align:center; margin-bottom: 12px;">
        <img src="{{ asset('images/logo.png') }}" alt="RANS Logo" style="max-height: 90px; width: auto;" />
    </div>
    <h1 style="text-align:center;">Digital Attendance Report</h1>
    @if($companyName)
        <div class="sub" style="text-align:center;">{{ $companyName }}</div>
    @endif

    <div class="meta">
        <strong>From:</strong> {{ $filters['date_from'] ?? $filters['date'] ?? 'beginning' }} &nbsp;
        <strong>To:</strong> {{ $filters['date_to'] ?? $filters['date'] ?? 'now' }} &nbsp;
        <strong>Status:</strong> {{ $filters['status'] ?? 'all' }} &nbsp;
        @if(!empty($filters['search']))
            <strong>Search:</strong> {{ $filters['search'] }} &nbsp;
        @endif
        <strong>Generated:</strong> {{ now()->format('Y-m-d H:i') }} &nbsp;
        <strong>By:</strong> {{ $generatedBy ?? 'system' }}
    </div>

    <div class="summary">
        <div><div class="label">Attendance Days</div><div class="value">{{ $attendanceDays }}</div></div>
        <div><div class="label">Total Hours</div><div class="value">{{ $totalLabel }}</div></div>
        <div><div class="label">Average / Day</div><div class="value">{{ $averageLabel }}</div></div>
    </div>

    @if($autoPrint)
        <div class="actions">
            <button onclick="window.print()">Print / Save as PDF</button>
        </div>
    @endif

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Employee</th>
                <th>Clock In</th>
                <th>Clock Out</th>
                <th>Worked</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($records as $r)
                <tr>
                    <td>{{ $r->attendance_date->toDateString() }}</td>
                    <td>{{ optional($r->user)->name }}</td>
                    <td>{{ optional($r->clock_in_at)->format('H:i:s') ?? '—' }}</td>
                    <td>{{ optional($r->clock_out_at)->format('H:i:s') ?? '—' }}</td>
                    <td>{{ $r->worked_label }}</td>
                    <td class="{{ $r->status === 'MISSING_CLOCK_OUT' ? 'status-missing' : ($r->is_late ? 'status-late' : 'status-ok') }}">
                        {{ $r->is_late && $r->status !== 'MISSING_CLOCK_OUT' ? 'Late' : ucwords(strtolower(str_replace('_', ' ', $r->status))) }}
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center; padding:16px;">No attendance records found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">RANS WIMS &middot; Digital Attendance Report</div>

    @if($autoPrint)
        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
    @endif
</body>
</html>
