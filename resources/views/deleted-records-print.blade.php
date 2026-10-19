<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
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

        .hero { display: table; width: 100%; background: #fdf2f3; border: 1px solid #f7d9dc; border-radius: 14px; padding: 14px 18px; margin-bottom: 16px; }
        .hero-icon { display: table-cell; width: 46px; height: 46px; border-radius: 12px; background: #b91c1c; text-align: center; vertical-align: middle; }
        .hero-icon svg { width: 22px; height: 22px; }
        .hero-text { display: table-cell; vertical-align: middle; padding-left: 14px; }
        .hero-text .t { font-size: 16px; font-weight: bold; color: #111827; }
        .hero-text .s { font-size: 9.5px; color: #6b7280; margin-top: 2px; }
        .hero-ref { display: table-cell; vertical-align: middle; text-align: right; }
        .hero-ref .n { font-size: 20px; font-weight: bold; color: #b91c1c; }
        .hero-ref .l { font-size: 8px; font-weight: bold; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.04em; }

        .type-badge { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 10px; font-weight: bold; background: #fee2e2; color: #b91c1c; margin-bottom: 14px; }

        .meta { margin: 0 0 14px; padding: 9px 14px; background: #f8fafc; border: 1px solid #eef1f6; border-radius: 10px; font-size: 9.7px; }
        .meta strong { color: #111827; }

        table.items { width: 100%; border-collapse: collapse; }
        table.items th, table.items td { text-align: left; padding: 7px 9px; font-size: 10px; border-bottom: 1px solid #eef1f6; vertical-align: top; }
        table.items th { background: #1c3f66; color: #fff; font-weight: bold; }
        table.items th:first-child { border-top-left-radius: 8px; }
        table.items th:last-child { border-top-right-radius: 8px; }
        table.items tr:nth-child(even) td { background: #f9fafb; }

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
                <path d="M4 7h16M9 7V4.5h6V7"/><path d="M6 7l1.1 12.5A2 2 0 009.1 21.5h5.8a2 2 0 002-1.8L18 7"/>
            </svg>
        </div>
        <div class="hero-text">
            <div class="t">Deleted Records</div>
            <div class="s">Transactions that have been deleted, reviewable and restorable.</div>
        </div>
        <div class="hero-ref">
            <div class="n">{{ count($rows) }}</div>
            <div class="l">Total</div>
        </div>
    </div>

    <span class="type-badge">{{ strtoupper(str_replace('-', ' ', $type)) }}</span>

    <div class="meta">
        <strong>Type:</strong> {{ ucfirst(str_replace('-', ' ', $type)) }} &nbsp;
        @if(!empty($filters['search']))
            <strong>Search:</strong> {{ $filters['search'] }} &nbsp;
        @endif
        @if(!empty($filters['deleted_by']))
            <strong>Deleted By:</strong> {{ $filters['deleted_by'] }} &nbsp;
        @endif
        <strong>Generated:</strong> {{ optional($generatedAt)->format('Y-m-d H:i') }} &nbsp;
        <strong>By:</strong> {{ $generatedBy ?? 'system' }}
    </div>

    @if($autoPrint)
        <div class="actions">
            <button onclick="window.print()">Print / Save as PDF</button>
        </div>
    @endif

    <table class="items">
        <thead>
            <tr>
                @foreach($columns as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) }}" style="text-align:center; padding:16px;">There is no data to display.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="footer-row">
        <tr><td class="foot-cell">{{ $displayCompanyName ?: 'RANS Company Limited' }} / Deleted Records, Generated {{ now()->format('Y-m-d H:i') }}</td></tr>
    </table>

    @if($autoPrint)
        <script>
            window.addEventListener('load', function () {
                setTimeout(function () { window.print(); }, 250);
            });
        </script>
    @endif
</body>
</html>
