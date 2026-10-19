<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login Logs</title>
    <meta name="color-scheme" content="light">
    <style>
        * { box-sizing: border-box; }
        html { color-scheme: light; background: #ffffff; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #1f2937; margin: 22px; background: #ffffff; }

        .letterhead { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .letterhead .logo-cell { width: 34%; vertical-align: middle; }
        .letterhead .logo-cell img { max-height: 58px; }
        .letterhead .info-cell { width: 66%; vertical-align: middle; text-align: right; }
        .letterhead .company-name { font-size: 16px; font-weight: bold; color: #1c3f66; margin: 0 0 3px; }
        .letterhead .tagline { font-size: 9px; font-weight: bold; color: #64748b; text-transform: uppercase; margin-bottom: 5px; }
        .contact-row { display: inline-block; }
        .contact-item { display: inline-block; font-size: 8.7px; color: #475569; margin-left: 10px; white-space: nowrap; }
        .contact-item .ic { display: inline-block; width: 9px; height: 9px; vertical-align: -1px; margin-right: 3px; }
        .band { height: 6px; border-radius: 3px; background: #1c3f66; margin: 10px 0 16px; }

        /* Hero header — icon badge + title, mirroring the on-screen page */
        .hero { display: table; width: 100%; background: #f4f6fb; border: 1px solid #e3e8f0; border-radius: 14px; padding: 14px 18px; margin-bottom: 16px; }
        .hero-icon { display: table-cell; width: 46px; height: 46px; border-radius: 12px; background: #1c3f66; text-align: center; vertical-align: middle; }
        .hero-icon svg { width: 22px; height: 22px; }
        .hero-text { display: table-cell; vertical-align: middle; padding-left: 14px; }
        .hero-text .t { font-size: 16px; font-weight: bold; color: #111827; }
        .hero-text .s { font-size: 9.5px; color: #6b7280; margin-top: 2px; }
        .hero-ref { display: table-cell; vertical-align: middle; text-align: right; }
        .hero-ref .n { font-size: 20px; font-weight: bold; color: #1c3f66; }
        .hero-ref .l { font-size: 8px; font-weight: bold; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.04em; }

        /* Stat cards — the outer table-cell only spaces the columns; the
           inner .stat-box carries the actual card look. DomPDF's table
           layout (unlike a real browser) needs a plain display:table /
           table-cell pair with no border-collapse/border-spacing, or its
           cell-to-parent lookup breaks — so the gap between cards is done
           with padding on the cell, not border-spacing on the row. */
        .stat-row { display: table; width: 100%; margin-bottom: 16px; }
        .stat-cell { display: table-cell; width: 33.3%; padding: 0 4px; }
        .stat-cell:first-child { padding-left: 0; }
        .stat-cell:last-child { padding-right: 0; }
        .stat-box { border-radius: 12px; border: 1px solid #eef1f6; padding: 10px 14px; background: #fafbfc; }
        .stat-icon { display: inline-block; width: 24px; height: 24px; border-radius: 7px; text-align: center; vertical-align: middle; margin-bottom: 6px; }
        .stat-icon svg { width: 13px; height: 13px; margin-top: 5.5px; }
        .stat-num { font-size: 18px; font-weight: bold; display: block; }
        .stat-label { font-size: 8px; font-weight: bold; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.03em; }
        .stat-total .stat-icon { background: #dbeafe; } .stat-total .stat-num { color: #1c3f66; }
        .stat-success .stat-icon { background: #d1fae5; } .stat-success .stat-num { color: #15803d; }
        .stat-failed .stat-icon { background: #ffe4e6; } .stat-failed .stat-num { color: #b91c1c; }

        .meta { margin: 0 0 14px; padding: 9px 14px; background: #f8fafc; border: 1px solid #eef1f6; border-radius: 10px; font-size: 9.7px; }
        .meta strong { color: #111827; }

        table.items { width: 100%; border-collapse: collapse; }
        table.items th, table.items td { text-align: left; padding: 7px 9px; font-size: 9.8px; border-bottom: 1px solid #eef1f6; vertical-align: middle; }
        table.items th { background: #1c3f66; color: #fff; font-weight: bold; }
        table.items th:first-child { border-top-left-radius: 8px; }
        table.items th:last-child { border-top-right-radius: 8px; }
        table.items tr:nth-child(even) td { background: #f9fafb; }

        .pill { display: inline-block; padding: 3px 10px 3px 8px; border-radius: 999px; font-size: 9px; font-weight: bold; text-transform: capitalize; }
        .pill svg { width: 9px; height: 9px; vertical-align: -1px; margin-right: 3px; }
        .pill-success { background: #d1fae5; color: #047857; }
        .pill-failed { background: #ffe4e6; color: #be123c; }

        .actions { margin: 12px 0 18px; }
        .actions button { padding: 6px 14px; background: #1c3f66; color: #fff; border: 0; border-radius: 6px; cursor: pointer; font-size: 12px; }
        .footer-row { width: 100%; border-collapse: collapse; margin-top: 18px; }
        .foot-cell { text-align: center; color: #9ca3af; font-size: 8.5px; }

        @media print {
            .actions { display: none; }
            body { margin: 12mm; }
        }
    </style>
</head>
<body>
    @php($displayCompanyName = preg_replace('/tecdiary\s*wims/i', 'RANS WIMS', (string) ($companyName ?? '')))
    @include('partials.print.letterhead', ['companyName' => $displayCompanyName, 'isPdf' => $isPdf ?? false])

    <div class="hero">
        <div class="hero-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="7.5" cy="15.5" r="4"/><path d="M10 12.7L19.5 3.2"/><path d="M16.5 6.2l2.6 2.6M13.7 9l2 2"/>
            </svg>
        </div>
        <div class="hero-text">
            <div class="t">Login Logs</div>
            <div class="s">Track successful and failed login attempts.</div>
        </div>
        <div class="hero-ref">
            <div class="n">{{ count($logs) }}</div>
            <div class="l">Total Records</div>
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
        <div class="stat-cell stat-success">
            <div class="stat-box">
                <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#15803d" stroke-width="2.2"><path d="M5 13l4 4L19 7"/></svg></span>
                <span class="stat-num">{{ collect($logs)->where('status', 'success')->count() }}</span>
                <span class="stat-label">Successful</span>
            </div>
        </div>
        <div class="stat-cell stat-failed">
            <div class="stat-box">
                <span class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#b91c1c" stroke-width="2.2"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 16h.01"/></svg></span>
                <span class="stat-num">{{ collect($logs)->where('status', 'failed')->count() }}</span>
                <span class="stat-label">Failed</span>
            </div>
        </div>
    </div>

    <div class="meta">
        <strong>From:</strong> {{ $filters['start_date'] ?? 'beginning' }} &nbsp;
        <strong>To:</strong> {{ $filters['end_date'] ?? 'now' }} &nbsp;
        <strong>Status:</strong> {{ $filters['status'] ?? 'all' }} &nbsp;
        <strong>Role:</strong> {{ $filters['role'] ?? 'all' }} &nbsp;
        @if(!empty($filters['search']))
            <strong>Search:</strong> {{ $filters['search'] }} &nbsp;
        @endif
        <strong>Generated:</strong> {{ now()->format('Y-m-d H:i') }} &nbsp;
        <strong>By:</strong> {{ $generatedBy ?? 'system' }}
    </div>

    @if($autoPrint)
        <div class="actions">
            <button onclick="window.print()">Print / Save as PDF</button>
        </div>
    @endif

    {{-- Drawn in blocks of 60 rows: DomPDF runs out of memory laying out one table of a thousand-plus rows. --}}
    @forelse(collect($logs)->chunk(60) as $chunk)
    <table class="items">
        <thead>
            <tr>
                <th>Date Time</th>
                <th>Name</th>
                <th>Username</th>
                <th>Role</th>
                <th>Status</th>
                <th>IP Address</th>
                <th>Device</th>
                <th>OS</th>
                <th>Browser</th>
                <th>Location</th>
            </tr>
        </thead>
        <tbody>
            @foreach($chunk as $log)
                <tr>
                    <td>{{ optional($log->attempted_at)->format('Y-m-d H:i:s') }}</td>
                    <td>{{ optional($log->user)->name }}</td>
                    <td>{{ $log->username }}</td>
                    <td>{{ $log->role }}</td>
                    <td>
                        {{-- Plain text pill (no per-row icon): keeps the PDF light for thousands of rows. --}}
                        <span class="pill pill-{{ $log->status }}">{{ ucfirst($log->status) }}</span>
                    </td>
                    <td>{{ $log->ip_address }}</td>
                    <td>{{ $log->device }}</td>
                    <td>{{ $log->os }}</td>
                    <td>{{ $log->browser }}</td>
                    <td>{{ $log->location }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @empty
    <table class="items">
        <thead><tr><th>Login Logs</th></tr></thead>
        <tbody><tr><td style="text-align:center; padding:16px;">No login logs found.</td></tr></tbody>
    </table>
    @endforelse

    <table class="footer-row">
        <tr><td class="foot-cell">{{ $displayCompanyName ?: 'RANS Company Limited' }} / Login Logs Report, Generated {{ now()->format('Y-m-d H:i') }}</td></tr>
    </table>

    @if($autoPrint)
        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
    @endif
</body>
</html>
