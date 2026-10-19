<!DOCTYPE html>
{{--
    One WIMS report layout for register-style lists (Asset Register, Asset Dashboard report, Vehicles, Contacts…):
    letterhead → title bar → applied filters → summary cards (icon tiles) → optional breakdown → the table itself.

    The controller hands over plain data, never HTML:
      title, ref (optional right-hand label), companyName, isPdf, autoPrint, generatedBy, generatedAt
      scopeNote (string)         what this printout covers ("Filtered result", "Complete register" …)
      filters   [[label, value]] the filters in force (shown as chips)
      groups    [[title?, cards => [[label, value, sub?, tone, icon]]]]
      breakdown [title, columns => [..], rows => [[..]], money_cols => [idx]]  (optional)
      tableTitle, columns [[label, align?, width?]], rows [[cell…]], totals [span, label, cells => [cell…]]
    A cell is a string or [text, sub?, tone? (pill), strong?, color?, mono?].
--}}
@php
    $isPdf = ! empty($isPdf);
    $iconUrl = fn (string $n) => $isPdf ? public_path('images/report-icons/' . $n . '.png') : asset('images/report-icons/' . $n . '.png');
    $tones = [
        'navy' => '#1c3f66', 'indigo' => '#4f46e5', 'teal' => '#0d9488', 'emerald' => '#059669', 'amber' => '#d97706',
        'rose' => '#e11d48', 'slate' => '#64748b', 'maroon' => '#7f1d1d', 'sky' => '#0284c7', 'violet' => '#7c3aed',
    ];
    $pill = [
        'green' => ['#d1fae5', '#047857'], 'amber' => ['#fef3c7', '#b45309'], 'red' => ['#ffe4e6', '#be123c'],
        'slate' => ['#f1f5f9', '#475569'], 'blue' => ['#dbeafe', '#1d4ed8'], 'indigo' => ['#e0e7ff', '#4338ca'], 'teal' => ['#ccfbf1', '#0f766e'],
    ];
@endphp
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        @page { margin: 14mm 10mm 14mm 10mm; }
        body { margin: 0; }
        .chip-row { margin-bottom: 12px; }
        .fchip { display: inline-block; border: 1px solid #cbd5e1; border-radius: 999px; background: #f8fafc; padding: 3px 10px; margin: 0 6px 4px 0; font-size: 9.5px; color: #334155; }
        .fchip b { color: #1c3f66; }
        .scope { font-size: 9.5px; color: #64748b; margin: -6px 0 12px; }
        .group-title { font-size: 10px; font-weight: bold; color: #1c3f66; text-transform: uppercase; margin: 4px 0 6px; }
        table.cards { width: 100%; border-collapse: separate; border-spacing: 7px 0; margin: 0 -7px 8px; width: 101.5%; }
        table.cards td { vertical-align: top; padding: 0; }
        .stat-card { border: 1px solid #e2e8f0; border-radius: 10px; background: #fafbfc; padding: 8px 9px; page-break-inside: avoid; break-inside: avoid; }
        .stat-card table { width: 100%; border-collapse: collapse; }
        .stat-card td { padding: 0; vertical-align: middle; }
        .ico-box { width: 28px; height: 28px; border-radius: 8px; text-align: center; }
        .ico-box img { width: 15px; height: 15px; margin-top: 6.5px; }
        .stat-label { display: block; font-size: 7.8px; font-weight: bold; text-transform: uppercase; color: #6b7280; }
        .stat-value { display: block; font-size: 14px; font-weight: bold; margin-top: 1px; }
        .stat-sub { display: block; font-size: 8px; color: #94a3b8; margin-top: 1px; }
        .section-title { font-size: 11px; font-weight: bold; color: #1c3f66; margin: 14px 0 6px; }
        table.items td { vertical-align: middle; }
        table.items td .sub { display: block; font-size: 8.3px; color: #9ca3af; margin-top: 1px; }
        table.items tbody tr:nth-child(even) td { background: #f8fafc; }
        .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 8.6px; font-weight: bold; }
        .strong { font-weight: bold; }
        .mono { font-family: Courier, monospace; }
        table.items tfoot td { background: #eef2f7; font-weight: bold; border-top: 2px solid #1c3f66; border-bottom: 0; }
        .break-box { border: 1px solid #e2e8f0; border-radius: 10px; background: #fafbfc; padding: 8px 12px; margin-bottom: 12px; page-break-inside: avoid; break-inside: avoid; }
        table.mini { width: 100%; border-collapse: collapse; }
        table.mini th { text-align: left; font-size: 8.5px; text-transform: uppercase; color: #6b7280; padding: 3px 0; border-bottom: 1px solid #e2e8f0; }
        table.mini td { padding: 3px 0; font-size: 10px; border-bottom: 1px solid #eef1f6; }
        table.mini .num { text-align: right; }
        .actions { margin: 12px 0 18px; }
        .actions button { padding: 6px 14px; background: #1c3f66; color: #fff; border: 0; border-radius: 4px; cursor: pointer; font-size: 12px; }
        @media print { .actions { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    @include('partials.print.watermark', ['isPdf' => $isPdf])
    @include('partials.print.letterhead', ['companyName' => $companyName, 'isPdf' => $isPdf])

    <div class="title-card">
        <div class="doc-title">{{ strtoupper($title) }}</div>
        <div class="doc-ref">{{ $ref ?? 'Generated' }}<br><strong>{{ $generatedAt->format('d M Y, H:i') }}</strong></div>
    </div>

    @if(! empty($filters))
        <div class="chip-row">
            @foreach($filters as [$fl, $fv])
                <span class="fchip">{{ $fl }}: <b>{{ $fv }}</b></span>
            @endforeach
        </div>
    @endif
    @if(! empty($scopeNote))
        <div class="scope">{{ $scopeNote }}</div>
    @endif

    @foreach(($groups ?? []) as $group)
        @if(! empty($group['title']))
            <div class="group-title">{{ $group['title'] }}</div>
        @endif
        @php
            $cards = $group['cards'];
            $perRow = count($cards) <= 7 ? max(count($cards), 1) : (int) ceil(count($cards) / 2);
            $chunks = array_chunk($cards, $perRow);
            $w = round(100 / $perRow, 3);
        @endphp
        @foreach($chunks as $chunk)
            <table class="cards">
                <tr>
                    @foreach($chunk as $c)
                        @php
                            $col = $tones[$c['tone'] ?? 'navy'] ?? $tones['navy'];
                        @endphp
                        <td style="width: {{ $w }}%;">
                            <div class="stat-card">
                                <table><tr>
                                    <td style="width: 34px;"><div class="ico-box" style="background: {{ $col }};"><img src="{{ $iconUrl($c['icon'] ?? 'grid') }}" alt="" /></div></td>
                                    <td>
                                        <span class="stat-label">{{ $c['label'] }}</span>
                                        <span class="stat-value" style="color: {{ $col }}; font-size: {{ max(9.5, 14 - max(0, strlen((string) $c['value']) - 8) * 0.55) }}px; white-space: nowrap;">{{ $c['value'] }}</span>
                                        @if(! empty($c['sub']))<span class="stat-sub">{{ $c['sub'] }}</span>@endif
                                    </td>
                                </tr></table>
                            </div>
                        </td>
                    @endforeach
                    @for($i = count($chunk); $i < $perRow; $i++)<td style="width: {{ $w }}%;"></td>@endfor
                </tr>
            </table>
        @endforeach
    @endforeach

    @if(! empty($breakdown))
        <div class="break-box">
            <div class="card-title">{{ $breakdown['title'] }}</div>
            <table class="mini">
                <tr>
                    @foreach($breakdown['columns'] as $bi => $bc)
                        <th class="{{ $bi > 0 ? 'num' : '' }}">{{ $bc }}</th>
                    @endforeach
                </tr>
                @foreach($breakdown['rows'] as $br)
                    <tr>
                        @foreach($br as $bi => $bv)
                            <td class="{{ $bi > 0 ? 'num' : '' }}">{{ $bv }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @if(! empty($tableTitle))
        <div class="section-title" @if(! empty($tableOnNewPage))style="page-break-before: always;"@endif>{{ $tableTitle }}</div>
    @endif

    <table class="items">
        <thead>
            <tr>
                @foreach($columns as $col)
                    <th class="{{ ($col['align'] ?? '') === 'right' ? 'num' : '' }}" @if(! empty($col['width']))style="width: {{ $col['width'] }};"@endif>{{ $col['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($row as $ci => $cell)
                        @php
                            $cell = is_array($cell) ? $cell : ['text' => $cell];
                            $right = ($columns[$ci]['align'] ?? '') === 'right';
                            $cls = trim(($right ? 'num ' : '') . (! empty($cell['strong']) ? 'strong ' : '') . (! empty($cell['mono']) ? 'mono' : ''));
                        @endphp
                        <td class="{{ $cls }}" @if(! empty($cell['color']))style="color: {{ $cell['color'] }};"@endif>
                            @if(! empty($cell['tone']) && isset($pill[$cell['tone']]))
                                <span class="pill" style="background: {{ $pill[$cell['tone']][0] }}; color: {{ $pill[$cell['tone']][1] }};">{{ $cell['text'] }}</span>
                            @elseif(! empty($cell['url']))
                                <a href="{{ $cell['url'] }}" target="_blank" rel="noopener" style="color: #1d4ed8; text-decoration: underline;">{{ $cell['text'] }}</a>
                            @else
                                {{ $cell['text'] === null || $cell['text'] === '' ? '-' : $cell['text'] }}
                            @endif
                            @if(! empty($cell['sub']))<span class="sub">{{ $cell['sub'] }}</span>@endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) }}" style="text-align:center; padding:16px; color:#6b7280;">{{ $emptyText ?? 'Nothing to show for this selection.' }}</td></tr>
            @endforelse
        </tbody>
        @if(! empty($totals) && count($rows))
            <tfoot>
                <tr>
                    <td colspan="{{ $totals['span'] }}">{{ $totals['label'] }}</td>
                    @foreach($totals['cells'] as $ti => $tc)
                        @php
                            $tc = is_array($tc) ? $tc : ['text' => $tc];
                            $tRight = ($columns[$totals['span'] + $ti]['align'] ?? '') === 'right';
                        @endphp
                        <td class="{{ $tRight ? 'num' : '' }}" @if(! empty($tc['color']))style="color: {{ $tc['color'] }};"@endif>{{ $tc['text'] }}</td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>

    <table class="footer-row">
        <tr>
            <td class="foot-cell">{{ $companyName }} &mdash; {{ $title }} &mdash; Generated {{ $generatedAt->format('Y-m-d H:i') }}@if(! empty($generatedBy)) by {{ $generatedBy }}@endif</td>
        </tr>
    </table>

    @if(! $isPdf)
        <div class="actions"><button onclick="window.print()">Print / Save as PDF</button></div>
        @if(! empty($autoPrint))
            <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
        @endif
    @endif
</body>
</html>
