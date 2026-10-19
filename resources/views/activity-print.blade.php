<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Logs</title>
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

        .hero { display: table; width: 100%; background: #f4f6fb; border: 1px solid #e3e8f0; border-radius: 14px; padding: 14px 18px; margin-bottom: 16px; }
        .hero-icon { display: table-cell; width: 46px; height: 46px; border-radius: 12px; background: #1c3f66; text-align: center; vertical-align: middle; }
        .hero-icon svg { width: 22px; height: 22px; }
        .hero-text { display: table-cell; vertical-align: middle; padding-left: 14px; }
        .hero-text .t { font-size: 16px; font-weight: bold; color: #111827; }
        .hero-text .s { font-size: 9.5px; color: #6b7280; margin-top: 2px; }
        .hero-ref { display: table-cell; vertical-align: middle; text-align: right; }
        .hero-ref .n { font-size: 20px; font-weight: bold; color: #1c3f66; }
        .hero-ref .l { font-size: 8px; font-weight: bold; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.04em; }

        .meta { margin: 0 0 14px; padding: 9px 14px; background: #f8fafc; border: 1px solid #eef1f6; border-radius: 10px; font-size: 9.7px; }
        .meta strong { color: #111827; }

        table.items { width: 100%; border-collapse: collapse; }
        table.items th, table.items td { text-align: left; padding: 7px 9px; font-size: 10px; border-bottom: 1px solid #eef1f6; vertical-align: top; }
        table.items th { background: #1c3f66; color: #fff; font-weight: bold; }
        table.items th:first-child { border-top-left-radius: 8px; }
        table.items th:last-child { border-top-right-radius: 8px; }
        table.items tr:nth-child(even) td { background: #f9fafb; }

        .name-chip { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 9px; font-weight: bold; background: #eff4ff; color: #1c3f66; }

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
                <path d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </div>
        <div class="hero-text">
            <div class="t">System Logs</div>
            <div class="s">The full audit trail of every significant action in WIMS.</div>
        </div>
        <div class="hero-ref">
            <div class="n">{{ count($activities) }}</div>
            <div class="l">Total Events</div>
        </div>
    </div>

    <div class="meta">
        <strong>From:</strong> {{ $filters['from'] ?? 'beginning' }} &nbsp;
        <strong>To:</strong> {{ $filters['to'] ?? 'now' }} &nbsp;
        <strong>User:</strong> {{ $userName ?? 'all users' }}
        @if(!empty($filters['module']))
            &nbsp; <strong>Module:</strong> {{ collect(\App\Support\ActivityModules::options())->firstWhere('value', $filters['module'])['label'] ?? $filters['module'] }}
        @endif
        @if(!empty($filters['search']))
            &nbsp; <strong>Search:</strong> {{ $filters['search'] }}
        @endif
        &nbsp; <strong>Generated:</strong> {{ optional($generatedAt ?? now())->format('Y-m-d H:i') }}
        &nbsp; <strong>By:</strong> {{ $generatedBy ?? 'system' }}
    </div>

    <div class="actions">
        <button onclick="window.print()">Print / Save as PDF</button>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Date</th>
                <th>Name</th>
                <th>Description</th>
                <th>By</th>
                <th>Device / Location</th>
            </tr>
        </thead>
        <tbody>
            @forelse($activities as $activity)
                @php($c = data_get($activity->properties, 'context', []))
                <tr>
                    <td>{{ optional($activity->created_at)->format('Y-m-d H:i') }}</td>
                    <td><span class="name-chip">{{ $activity->log_name }}</span></td>
                    <td>{{ $activity->description }}</td>
                    <td>{{ $activity->causer ? $activity->causer->name : '' }}</td>
                    <td>
                        {{ $c['device'] ?? '' }}{{ !empty($c['os']) ? ' ('.$c['os'].')' : '' }}
                        <br>
                        <small>{{ $c['location'] ?? ($c['ip'] ?? '') }}{{ !empty($c['location']) && !empty($c['ip']) ? ' · '.$c['ip'] : '' }}</small>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center; padding:16px;">No activities found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="footer-row">
        <tr><td class="foot-cell">{{ $displayCompanyName ?: 'RANS Company Limited' }} / System Logs Report, Generated {{ now()->format('Y-m-d H:i') }}</td></tr>
    </table>

    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
