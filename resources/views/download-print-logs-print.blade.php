<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <meta name="color-scheme" content="light">
    <style>
        * { box-sizing: border-box; }
        html { color-scheme: light; background: #ffffff; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10.5px; color: #1f2937; margin: 20px; background: #ffffff; }

        .letterhead { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .letterhead .logo-cell { width: 30%; vertical-align: middle; }
        .letterhead .logo-cell img { max-height: 52px; }
        .letterhead .info-cell { width: 70%; vertical-align: middle; text-align: right; }
        .letterhead .company-name { font-size: 15px; font-weight: bold; color: #1c3f66; margin: 0 0 3px; }
        .letterhead .tagline { font-size: 9px; font-weight: bold; color: #64748b; text-transform: uppercase; margin-bottom: 5px; }
        .contact-row { display: inline-block; }
        .contact-item { display: inline-block; font-size: 8.7px; color: #475569; margin-left: 10px; white-space: nowrap; }
        .contact-item .ic { display: inline-block; width: 9px; height: 9px; vertical-align: -1px; margin-right: 3px; }
        .band { height: 5px; border-radius: 3px; background: #1c3f66; margin: 8px 0 12px; }

        .hero { display: table; width: 100%; background: #f4f6fb; border: 1px solid #e3e8f0; border-radius: 12px; padding: 12px 16px; margin-bottom: 14px; }
        .hero-icon { display: table-cell; width: 42px; height: 42px; border-radius: 11px; background: #1c3f66; text-align: center; vertical-align: middle; }
        .hero-icon svg { width: 20px; height: 20px; }
        .hero-text { display: table-cell; vertical-align: middle; padding-left: 12px; }
        .hero-text .t { font-size: 15px; font-weight: bold; color: #111827; }
        .hero-text .s { font-size: 9px; color: #6b7280; margin-top: 2px; }
        .hero-ref { display: table-cell; vertical-align: middle; text-align: right; }
        .hero-ref .n { font-size: 18px; font-weight: bold; color: #1c3f66; }
        .hero-ref .l { font-size: 7.5px; font-weight: bold; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.04em; }

        .stat-row { display: table; width: 100%; margin-bottom: 14px; }
        .stat-cell { display: table-cell; width: 33.3%; padding: 0 3.5px; }
        .stat-cell:first-child { padding-left: 0; }
        .stat-cell:last-child { padding-right: 0; }
        .stat-box { border-radius: 11px; border: 1px solid #eef1f6; padding: 9px 12px; background: #fafbfc; }
        .stat-icon { display: inline-block; width: 22px; height: 22px; border-radius: 6px; text-align: center; vertical-align: middle; margin-bottom: 5px; }
        .stat-icon svg { width: 12px; height: 12px; margin-top: 5px; }
        .stat-num { font-size: 16px; font-weight: bold; display: block; }
        .stat-label { font-size: 7.5px; font-weight: bold; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.03em; }
        .stat-total .stat-icon { background: #dbeafe; } .stat-total .stat-num { color: #1c3f66; }
        .stat-download .stat-icon { background: #d1fae5; } .stat-download .stat-num { color: #15803d; }
        .stat-print .stat-icon { background: #e0e7ff; } .stat-print .stat-num { color: #3730a3; }

        .meta { margin: 0 0 12px; padding: 8px 14px; background: #f8fafc; border: 1px solid #eef1f6; border-radius: 9px; font-size: 9.3px; }
        .meta strong { color: #111827; }

        table.items { width: 100%; border-collapse: collapse; }
        table.items th, table.items td { text-align: left; padding: 6px 8px; font-size: 9.1px; border-bottom: 1px solid #eef1f6; vertical-align: middle; }
        table.items th { background: #1c3f66; color: #fff; font-weight: bold; }
        table.items th:first-child { border-top-left-radius: 7px; }
        table.items th:last-child { border-top-right-radius: 7px; }
        table.items tr:nth-child(even) td { background: #f9fafb; }

        .pill { display: inline-block; padding: 2px 9px 2px 7px; border-radius: 999px; font-size: 8.5px; font-weight: bold; text-transform: capitalize; }
        .pill svg { width: 8px; height: 8px; vertical-align: -1px; margin-right: 3px; }
        .pill-download { background: #d1fae5; color: #047857; }
        .pill-print { background: #e0e7ff; color: #3730a3; }

        .actions { margin: 10px 0 16px; }
        .actions button { padding: 6px 14px; background: #1c3f66; color: #fff; border: 0; border-radius: 6px; cursor: pointer; font-size: 12px; }
        .footer-row { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .foot-cell { text-align: center; color: #9ca3af; font-size: 8.5px; }

        @media print {
            .actions { display: none; }
            body { margin: 10mm; }
        }
    </style>
</head>
<body>
    @php($displayCompanyName = preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) ($companyName ?? '')))
    @include('partials.print.letterhead', ['companyName' => $displayCompanyName, 'isPdf' => $isPdf ?? false])

    <div class="hero">
        <div class="hero-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 3v12M7.5 10.5L12 15l4.5-4.5"/><path d="M4 19.5h16"/>
            </svg>
        </div>
        <div class="hero-text">
            <div class="t">Download / Print Logs</div>
            <div class="s">Audit trail for every print and download action across the system.</div>
        </div>
        <div class="hero-ref">
            <div class="n">{{ count($logs) }}</div>
            <div class="l">Total Events</div>
        </div>
    </div>

    <div class="stat-row">
        <div class="stat-cell stat-total">
            <div class="stat-box">
                <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#1c3f66" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg></span>
                <span class="stat-num">{{ count($logs) }}</span>
                <span class="stat-label">Total</span>
            </div>
        </div>
        <div class="stat-cell stat-download">
            <div class="stat-box">
                <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#15803d" stroke-width="2"><path d="M12 3v12M7.5 10.5L12 15l4.5-4.5"/><path d="M4 19.5h16"/></svg></span>
                <span class="stat-num">{{ collect($logs)->where('action_type', 'download')->count() }}</span>
                <span class="stat-label">Downloads</span>
            </div>
        </div>
        <div class="stat-cell stat-print">
            <div class="stat-box">
                <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#3730a3" stroke-width="2"><rect x="4.5" y="8" width="15" height="8" rx="1.5"/><path d="M7 8V3.5h10V8"/><path d="M7 15.5v5h10v-5"/></svg></span>
                <span class="stat-num">{{ collect($logs)->where('action_type', 'print')->count() }}</span>
                <span class="stat-label">Prints</span>
            </div>
        </div>
    </div>

    <div class="meta">
        @if(!empty($filters['search']))
            <strong>Search:</strong> {{ $filters['search'] }} &nbsp;
        @endif
        @if(!empty($filters['action_type']))
            <strong>Action:</strong> {{ ucfirst($filters['action_type']) }} &nbsp;
        @endif
        @if(!empty($filters['module']))
            <strong>Module:</strong> {{ $filters['module'] }} &nbsp;
        @endif
        @if(!empty($filters['start_date']) || !empty($filters['end_date']))
            <strong>Date Range:</strong> {{ $filters['start_date'] ?? 'beginning' }} to {{ $filters['end_date'] ?? 'now' }} &nbsp;
        @endif
        <strong>Generated:</strong> {{ optional($generatedAt)->format('Y-m-d H:i') }} &nbsp;
        <strong>By:</strong> {{ $generatedBy ?? 'system' }}
    </div>

    @if($autoPrint)
        <div class="actions">
            <button onclick="window.print()">Print / Save as PDF</button>
        </div>
    @endif

    {{-- Drawn in blocks of 60 rows: DomPDF runs out of memory laying out one table of thousands of rows. --}}
    @forelse(collect($logs)->chunk(60) as $chunk)
    <table class="items">
        <thead>
            <tr>
                <th>Date Time</th>
                <th>User Name</th>
                <th>Role</th>
                <th>Action</th>
                <th>Module</th>
                <th>Route</th>
                <th>File</th>
                <th>IP</th>
                <th>Device</th>
                <th>OS</th>
                <th>Browser</th>
                <th>Location</th>
            </tr>
        </thead>
        <tbody>
            @foreach($chunk as $log)
                <tr>
                    <td>{{ optional($log->performed_at)->format('Y-m-d H:i:s') }}</td>
                    <td>{{ $log->user_name ?? optional($log->user)->name ?? '-' }}</td>
                    <td>{{ $log->role ?? '-' }}</td>
                    <td>
                        <span class="pill pill-{{ $log->action_type === 'print' ? 'print' : 'download' }}">
                            @if($log->action_type === 'print')
                                <svg viewBox="0 0 24 24" fill="none" stroke="#3730a3" stroke-width="2.5"><rect x="4.5" y="8" width="15" height="8" rx="1.5"/><path d="M7 8V3.5h10V8"/><path d="M7 15.5v5h10v-5"/></svg>
                            @else
                                <svg viewBox="0 0 24 24" fill="none" stroke="#047857" stroke-width="2.5"><path d="M12 3v12M7.5 10.5L12 15l4.5-4.5"/><path d="M4 19.5h16"/></svg>
                            @endif
                            {{ ucfirst($log->action_type) }}
                        </span>
                    </td>
                    <td>{{ $log->module ?? '-' }}</td>
                    <td>{{ $log->route_name ?? '-' }}</td>
                    <td>{{ $log->file_name ?? '-' }}</td>
                    <td>{{ $log->ip_address ?? '-' }}</td>
                    <td>{{ $log->device ?? '-' }}</td>
                    <td>{{ $log->os ?? '-' }}</td>
                    <td>{{ $log->browser ?? '-' }}</td>
                    <td>{{ $log->location ?? '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @empty
    <table class="items">
        <thead><tr><th>Download / Print Logs</th></tr></thead>
        <tbody><tr><td style="text-align:center; padding:16px;">There is no data to display.</td></tr></tbody>
    </table>
    @endforelse

    <table class="footer-row">
        <tr><td class="foot-cell">{{ $displayCompanyName ?: 'RANS Company Limited' }} / Download / Print Logs, Generated {{ now()->format('Y-m-d H:i') }}</td></tr>
    </table>

    @if($autoPrint)
        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
    @endif
</body>
</html>
