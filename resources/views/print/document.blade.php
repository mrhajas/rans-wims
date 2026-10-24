{{--
    Shared, data-driven print/PDF document for Check-in, Check-out, Transfer, Adjustment and
    Delivery Note. Fed by App\Support\PrintDocuments (the $doc array). Layout uses tables and
    plain CSS only so DomPDF (the "Download" path) renders it the same as the browser (the
    "Print" path). Browser-only extras (toolbar, paper shadow) are gated on !$isPdf.
--}}
@php
    $isPdf = !empty($isPdf);
    $A = $doc['accent'];
    $AD = $doc['accent_dark'];
    $AS = $doc['accent_soft'];
    $AL = $doc['accent_line'];
    $pdfUrl = $pdfUrl ?? str_replace('/print', '/pdf', url()->current());
    $companyName = $companyName ?? 'RANS Company Limited';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ ucwords(strtolower($doc['title'])) }} {{ $doc['reference'] }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    @if(!empty($doc['landscape']))<style>@page { size: A4 landscape; margin: 10mm; }</style>@endif
    <style>
        .band { background: {{ $A }}; }
        .status-info { background: #dbeefe; color: #0b6fb8; }
        .status-violet { background: #e6e3ff; color: #4338ca; }
        /* ORIGINAL / COPY label in the banner, and the break between the two printed copies */
        .copy-pill { display: inline-block; margin-top: 5px; padding: 3px 12px; border-radius: 999px; background: #ffffff; color: {{ $AD }}; font-weight: bold; font-size: 9.5px; letter-spacing: 0.08em; }
        .copy-break { page-break-after: always; height: 0; line-height: 0; font-size: 0; }
        .tick-box { display: inline-block; width: 13px; height: 13px; border: 1.6px solid {{ $AD }}; border-radius: 3px; background: #ffffff; vertical-align: middle; }
        table.items th.tick, table.items td.tick { width: 62px; text-align: center; }
        .gate-box { border: 1.5px solid {{ $A }}; border-radius: 12px; overflow: hidden; background: #ffffff; margin-top: 14px; page-break-inside: avoid; }
        .gate-box .gb-head { background: {{ $A }}; color: #ffffff; font-weight: bold; font-size: 10.5px; padding: 7px 14px; text-transform: uppercase; }
        .gate-box .gb-sub { background: {{ $AS }}; color: {{ $AD }}; font-size: 9px; padding: 5px 14px; border-bottom: 1px solid {{ $AL }}; }
        .gate-box .gb-body { padding: 2px 16px 10px; }
        .gate-box table { width: 100%; border-collapse: collapse; }
        .gate-box td { padding: 9px 12px 0; font-size: 10px; color: #475569; vertical-align: bottom; }
        .gate-box td.lbl { width: 62px; }
        .gate-box td.fill { border-bottom: 1px solid #94a3b8; height: 24px; font-weight: bold; color: #111827; }
        .gate-box td.chk { padding: 12px 14px 4px; font-size: 10.5px; color: #1f2937; }
        .gate-box td.chk .tick-box { margin-right: 4px; }
        .gate-box .gb-note { padding: 0 14px 12px; font-size: 8.5px; color: #94a3b8; }
        .top-strip { height: 9px; background: {{ $A }}; border-radius: 0 0 6px 6px; margin: -24px -24px 14px; }

        .banner { background: {{ $A }}; border-radius: 14px; padding: 13px 18px; margin-bottom: 12px; color: #ffffff; page-break-inside: avoid; }
        .banner table { width: 100%; border-collapse: collapse; }
        .banner td { vertical-align: middle; }
        .banner .b-icon { width: 54px; }
        .icon-disc { width: 42px; height: 42px; border-radius: 21px; background: rgba(255,255,255,0.22); border: 2px solid rgba(255,255,255,0.55); text-align: center; padding-top: 9px; }
        .banner .b-t { font-size: 19px; font-weight: bold; color: #ffffff; }
        .banner .b-s { font-size: 9.5px; color: {{ $AS }}; margin-top: 2px; }
        .banner .b-ref { text-align: right; font-size: 9px; color: {{ $AS }}; text-transform: uppercase; }
        .banner .b-r { font-size: 16px; font-weight: bold; color: #ffffff; }

        .parent-chip { border: 1px dashed {{ $A }}; background: {{ $AS }}; border-radius: 9px; padding: 6px 12px; margin-bottom: 12px; font-size: 10px; color: {{ $AD }}; }
        .parent-chip strong { color: {{ $AD }}; font-size: 11px; }

        table.stats { width: 100%; border-collapse: collapse; margin-bottom: 12px; page-break-inside: avoid; }
        table.stats td.stat { padding: 0; vertical-align: top; }
        table.stats td.gap { width: 8px; padding: 0; }
        .stat-box { background: {{ $AS }}; border: 1px solid {{ $AL }}; border-radius: 10px; padding: 8px 11px; }
        .stat-label { font-size: 8px; font-weight: bold; color: #64748b; text-transform: uppercase; }
        .stat-value { font-size: 13px; font-weight: bold; color: {{ $AD }}; margin-top: 3px; }

        table.parties { width: 100%; border-collapse: collapse; margin-bottom: 12px; page-break-inside: avoid; }
        table.parties td.party { width: 49%; vertical-align: top; padding: 0; }
        .party-box { border: 1px solid {{ $AL }}; border-radius: 10px; background: #ffffff; }
        table.parties td.gap { width: 2%; padding: 0; }
        .party-head { background: {{ $AS }}; border-bottom: 1px solid {{ $AL }}; border-radius: 10px 10px 0 0; padding: 7px 12px; font-size: 9.5px; font-weight: bold; color: {{ $AD }}; text-transform: uppercase; }
        table.party-rows { width: 100%; border-collapse: collapse; }
        table.party-rows td { padding: 4px 12px; font-size: 10.3px; vertical-align: top; }
        table.party-rows td.k { color: #6b7280; width: 38%; }
        table.party-rows td.v { font-weight: bold; color: #111827; }

        .section-title { font-size: 10px; font-weight: bold; color: {{ $AD }}; text-transform: uppercase; margin: 4px 0 6px; }
        table.items th { background: {{ $A }}; }
        table.items tr.alt td { background: #f8fafc; }
        table.items td.idx, table.items th.idx { width: 26px; text-align: center; color: #94a3b8; }
        table.items td .code { color: #94a3b8; font-size: 8.8px; }
        table.items tr.total-row td { background: {{ $AS }}; border-top: 2px solid {{ $A }}; font-weight: bold; color: {{ $AD }}; }
        table.items td.remarks { width: 22%; border-left: 1px dashed #cbd5e1; }

        .amount-card { display: block; background: {{ $AS }}; border: 1.5px solid {{ $A }}; border-radius: 12px; padding: 0; }
        .amount-card table { width: 100%; border-collapse: collapse; }
        .amount-card td { padding: 11px 18px; vertical-align: middle; }
        .amount-card .label { display: table-cell; color: {{ $AD }}; font-size: 10px; font-weight: bold; text-transform: uppercase; }
        .amount-card .value { display: table-cell; text-align: right; color: {{ $AD }}; font-size: 20px; font-weight: bold; }

        .notes-card { border: 1px solid {{ $AL }}; border-left: 4px solid {{ $A }}; border-radius: 8px; background: #ffffff; padding: 9px 14px; margin-bottom: 12px; page-break-inside: avoid; }

        table.sign { width: 100%; border-collapse: collapse; margin-top: 26px; page-break-inside: avoid; }
        table.sign td.sg { width: 48%; vertical-align: top; }
        table.sign td.gap { width: 4%; }
        .sg-line { border-top: 1.5px solid {{ $A }}; padding-top: 4px; margin-top: 34px; font-weight: bold; color: #1f2937; font-size: 10.5px; }
        .sg-role { color: {{ $AD }}; font-weight: bold; font-size: 9.5px; text-transform: uppercase; }
        .sg-date { color: #9ca3af; font-size: 8.5px; margin-top: 1px; }

        .sign-box { border: 1.5px solid {{ $A }}; border-radius: 12px; overflow: hidden; background: #ffffff; }
        .sign-box .sb-head { background: {{ $A }}; color: #ffffff; font-weight: bold; font-size: 10.5px; padding: 7px 14px; text-transform: uppercase; }
        .sign-box table { width: 100%; border-collapse: collapse; }
        .sign-box td { padding: 8px 14px 0; font-size: 10px; color: #475569; vertical-align: bottom; }
        .sign-box td.fill { border-bottom: 1px solid #94a3b8; font-weight: bold; color: #111827; height: 22px; }
        .stamp-area { border: 1.5px dashed {{ $A }}; border-radius: 10px; height: 74px; margin: 12px 14px 14px; text-align: center; color: #94a3b8; font-size: 9px; padding-top: 28px; text-transform: uppercase; }

        .draft-mark { position: fixed; top: 330px; left: 70px; font-size: 130px; font-weight: bold; color: #e9eef6; transform: rotate(-30deg); z-index: -1; }

        @if(!empty($doc['compact']))
        /* Compact spacing (Gate Pass): keeps a normal pass - signatures AND the gate-security block - on one A4 page. */
        .letterhead { margin-bottom: 6px; }
        .letterhead .logo-cell img { max-height: 44px; }
        .letterhead .company-name { font-size: 14px; margin-bottom: 1px; }
        .letterhead .tagline { margin-bottom: 3px; }
        .top-strip { height: 7px; margin-bottom: 8px; }
        .footer-row { margin-top: 8px; }
        .banner { padding: 9px 16px; margin-bottom: 8px; }
        .icon-disc { width: 34px; height: 34px; border-radius: 17px; padding-top: 6px; }
        .banner .b-icon { width: 46px; }
        .banner .b-t { font-size: 17px; }
        .parent-chip { padding: 4px 10px; margin-bottom: 8px; }
        table.stats { margin-bottom: 8px; }
        .stat-box { padding: 5px 10px; }
        .stat-value { font-size: 12px; margin-top: 1px; }
        table.parties { margin-bottom: 8px; }
        .party-head { padding: 5px 12px; }
        table.party-rows td { padding: 2.5px 12px; font-size: 10px; }
        .section-title { margin: 2px 0 4px; }
        table.sign { margin-top: 10px; }
        .sign-box .sb-head { padding: 5px 14px; }
        .sign-box td { padding: 5px 14px 0; }
        .sign-box td.fill { height: 16px; }
        .stamp-area { height: 30px; margin: 7px 14px 9px; padding-top: 9px; }
        .gate-box { margin-top: 10px; }
        .gate-box .gb-head { padding: 5px 14px; }
        .gate-box .gb-sub { padding: 3px 14px; }
        .gate-box td { padding: 6px 0 0; }
        .gate-box td.fill { height: 17px; }
        .gate-box td.chk { padding: 8px 0 2px; }
        .gate-box .gb-note { display: none; }
        @endif
        @if(!$isPdf)
        body { background: #e6edfb; margin: 0; padding: 0; }
        .doc-toolbar { position: sticky; top: 0; z-index: 10; background: {{ $A }}; color: #ffffff; padding: 10px 22px; display: flex; align-items: center; justify-content: space-between; font-size: 13px; box-shadow: 0 8px 24px -10px rgba(15,23,42,0.45); }
        .doc-toolbar strong { font-size: 14px; }
        .doc-toolbar a, .doc-toolbar button { display: inline-flex; align-items: center; gap: 6px; background: #ffffff; color: {{ $AD }}; border: 0; border-radius: 999px; padding: 7px 16px; font-weight: bold; font-size: 12px; text-decoration: none; cursor: pointer; margin-left: 8px; }
        .paper { position: relative; max-width: 830px; margin: 26px auto 40px; background: #ffffff; padding: 24px; border-radius: 16px; box-shadow: 0 30px 70px -28px rgba(30,58,138,0.45); overflow: hidden; }
        .paper .top-strip { margin: -24px -24px 16px; border-radius: 0; }
        .paper .watermark { position: absolute; left: 160px; top: 380px; }
        .paper .draft-mark { position: absolute; z-index: 0; }
        @media print {
            body { background: #ffffff; }
            .doc-toolbar { display: none; }
            .paper { margin: 0; max-width: none; border-radius: 0; box-shadow: none; padding: 0; page-break-after: always; }
            .copy-break { display: none; }
            .paper:last-of-type { page-break-after: auto; }
            .paper .top-strip { margin: 0 0 14px; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        @endif
        @if(!empty($doc['ink_saver']))
        /* Ink saver (Gate Pass, Delivery Note): light tints and thin lines instead of solid colour blocks. Same layout, far less ink. */
        .top-strip { background: {{ $AL }}; height: 4px; }
        .band { background: {{ $AL }}; height: 2px; }
        .banner { background: {{ $AS }}; border: 1px solid {{ $AL }}; color: {{ $AD }}; }
        .banner .b-t, .banner .b-r { color: {{ $AD }}; }
        .banner .b-s, .banner .b-ref { color: #64748b; }
        .icon-disc { background: #ffffff; border: 1.5px solid {{ $AL }}; }
        .gate-box, .sign-box, .amount-card { border: 1px solid {{ $AL }}; }
        .gate-box .gb-head, .sign-box .sb-head { background: {{ $AS }}; color: {{ $AD }}; border-bottom: 1px solid {{ $AL }}; }
        table.items th { background: {{ $AS }}; color: {{ $AD }}; border-bottom: 1.5px solid {{ $AL }}; }
        table.items tr.total-row td { border-top: 1.5px solid {{ $AL }}; }
        .notes-card { border-left: 3px solid {{ $AL }}; }
        .parent-chip, .stamp-area { border-color: {{ $AL }}; }
        .sg-line { border-top: 1px solid #94a3b8; }
        @endif
    </style>
@if(!empty($doc['site_receipt']) || !empty($doc['copies']))
<style>
    /* Keeps each printed copy (ORIGINAL / COPY, with the site-receipt block on Delivery Notes) on one A4 page. */
    .stamp-area { height: 28px; margin: 6px 14px 8px; padding-top: 8px; }
    table.sign { margin-top: 14px; }
    .gate-box { margin-top: 10px; }
    .gate-box .gb-head { padding: 5px 14px; }
    .gate-box .gb-sub { padding: 3px 14px; }
    .gate-box td { padding: 6px 12px 0; }
    .gate-box td.fill { height: 17px; }
    .gate-box td.chk { padding: 8px 14px 2px; }
    .letterhead { margin-bottom: 6px; }
    .letterhead .logo-cell img { max-height: 46px; }
    .band { margin: 6px 0 8px; }
    .banner { padding: 9px 16px; margin-bottom: 8px; }
    table.stats, table.parties, .notes-card { margin-bottom: 8px; }
    .sign-box td { padding: 5px 14px 0; }
    .sign-box td.fill { height: 17px; }
    .footer-row { margin-top: 6px; }
    .footer-row img { width: 36px !important; height: 36px !important; }
    .footer-row .qr-caption { display: none; }
    table.party-rows td { padding: 3px 12px; }
    .party-head { padding: 5px 12px; }
    .stat-box { padding: 6px 11px; }
    .stat-value { margin-top: 1px; }
    .section-title { margin: 2px 0 4px; }
    table.items th, table.items td { padding-top: 5px; padding-bottom: 5px; }
</style>
@endif
</head>
<body>
@if(!$isPdf)
    <div class="doc-toolbar">
        <div><strong>{{ $doc['title'] }}</strong> &nbsp; {{ $doc['reference'] }}@if(!empty($doc['copies'])) &nbsp;&middot;&nbsp; {{ count($doc['copies']) }} copies: {{ implode(' + ', $doc['copies']) }}@endif</div>
        <div>
            <button type="button" onclick="window.print()">&#128424; Print</button>
            <a href="{{ $pdfUrl }}">&#11015; Download PDF</a>
        </div>
    </div>
@endif

@foreach(($doc['copies'] ?: [null]) as $copyIndex => $copyLabel)
@if(!$isPdf)
    <div class="paper">
@endif

    @include('partials.print.watermark')
    @if($doc['draft'])
        <div class="draft-mark">DRAFT</div>
    @endif

    <div class="top-strip"></div>
    @include('partials.print.letterhead')

    <div class="banner">
        <table>
            <tr>
                <td class="b-icon"><div class="icon-disc">@include('print.icon', ['name' => $doc['icon'], 'color' => !empty($doc['ink_saver']) ? $AD : '#ffffff', 'size' => 22])</div></td>
                <td>
                    <div class="b-t">{{ $doc['title'] }}</div>
                    <div class="b-s">{{ $doc['subtitle'] }}</div>
                </td>
                <td class="b-ref">
                    Reference<br><span class="b-r">{{ $doc['reference'] }}</span><br>
                    <span class="status-pill {{ $doc['status']['class'] }}" style="margin-top:4px;">{{ $doc['status']['label'] }}</span>
                    @if($copyLabel)<br><span class="copy-pill">{{ $copyLabel }}</span>@endif
                </td>
            </tr>
        </table>
    </div>

    @if(!empty($doc['parent']))
        <div class="parent-chip">
            @include('print.icon', ['name' => 'link', 'color' => $AD, 'size' => 12])
            &nbsp;Generated from {{ $doc['parent']['label'] }} <strong>{{ $doc['parent']['reference'] }}</strong>
            &nbsp;&middot;&nbsp; this document reflects the parent record and has no effect on stock.
        </div>
    @endif

    <table class="stats">
        <tr>
            @foreach($doc['stats'] as $i => $stat)
                @if($i > 0)<td class="gap"></td>@endif
                <td class="stat"><div class="stat-box">
                    <div class="stat-label">@include('print.icon', ['name' => $stat['icon'], 'color' => $A, 'size' => 11]) &nbsp;{{ $stat['label'] }}</div>
                    <div class="stat-value">{{ $stat['value'] }}</div>
                </div></td>
            @endforeach
        </tr>
    </table>

    @foreach([$doc['parties'], $doc['extra_parties']] as $partyGroup)
    @if(!empty($partyGroup))
    <table class="parties">
        <tr>
            @foreach($partyGroup as $i => $party)
                @if($i > 0)<td class="gap"></td>@endif
                <td class="party"><div class="party-box">
                    <div class="party-head">@include('print.icon', ['name' => $party['icon'], 'color' => $AD, 'size' => 12]) &nbsp;{{ $party['title'] }}</div>
                    <table class="party-rows">
                        @foreach($party['rows'] as $row)
                            @php($ci = \App\Support\PrintIcons::forLabel($row[0]))
                            <tr><td class="k">@if($ci)@include('print.icon', ['name' => $ci, 'color' => $AD, 'size' => 10, 'style' => 'margin-right:4px;'])@endif{{ $row[0] }}</td><td class="v">{{ $row[1] }}</td></tr>
                        @endforeach
                    </table>
                </div></td>
            @endforeach
        </tr>
    </table>
    @endif
    @endforeach

    @if(!empty($doc['table']))
    @php($T = $doc['table'])
    <div class="section-title">@include('print.icon', ['name' => 'package', 'color' => $AD, 'size' => 12]) &nbsp;{{ $T['title'] }}</div>
    @if(!empty($T['note']))<p style="margin:0 0 6px;font-size:9px;color:#64748b;">{{ $T['note'] }}</p>@endif
    <table class="items">
        <thead><tr>
            <th class="idx">#</th>
            @foreach($T['cols'] as $c)<th class="{{ ($c['a'] ?? 'r') === 'l' ? '' : 'num' }}">{{ $c['label'] }}</th>@endforeach
        </tr></thead>
        <tbody>
            @forelse($T['rows'] as $n => $row)
                <tr class="{{ $n % 2 ? 'alt' : '' }}">
                    <td class="idx">{{ ($T['start'] ?? 0) + $n + 1 }}</td>
                    @foreach($row as $cell)
                        <td class="{{ ($cell['a'] ?? 'r') === 'l' ? '' : 'num' }}" style="@if(!empty($cell['b']))font-weight:700;@endif @if(!empty($cell['nw']))white-space:nowrap;@endif @if(($cell['tone'] ?? 0) > 0)color:#166534;@elseif(($cell['tone'] ?? 0) < 0)color:#991b1b;@endif">{{ $cell['v'] }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($T['cols']) + 1 }}">Nothing to show.</td></tr>
            @endforelse
            @if(!empty($T['totals']))
            <tr class="total-row">
                <td></td>
                @foreach($T['totals'] as $cell)
                    <td @if(!empty($cell['span']))colspan="{{ $cell['span'] }}"@endif class="{{ ($cell['a'] ?? 'r') === 'l' ? '' : 'num' }}" style="@if(($cell['tone'] ?? 0) > 0)color:#166534;@elseif(($cell['tone'] ?? 0) < 0)color:#991b1b;@endif">{{ $cell['v'] }}</td>
                @endforeach
            </tr>
            @endif
        </tbody>
    </table>
    @else
    <div class="section-title">@include('print.icon', ['name' => 'package', 'color' => $AD, 'size' => 12]) &nbsp;Items</div>
    <table class="items">
        <thead>
            <tr>
                <th class="idx">#</th>
                <th>Item</th>
                <th>Unit</th>
                <th class="num">Quantity</th>
                @if($doc['change_cols'])
                    <th class="num">Was</th>
                    <th class="num">Change</th>
                    <th class="num">Now</th>
                @endif
                @if($doc['show_values'])
                    <th class="num">Unit Value</th>
                    <th class="num">Total Value</th>
                @endif
                @if($doc['extra_cols'])
                    <th>Remarks / Condition</th>
                @endif
                @if($doc['tick_col'])
                    <th class="tick">Gate Check</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse($doc['lines'] as $n => $line)
                <tr class="{{ $n % 2 ? 'alt' : '' }}">
                    <td class="idx">{{ $n + 1 }}</td>
                    <td>{{ $line['name'] }}@if(!empty($line['code'])) <span class="code">({{ $line['code'] }})</span>@endif</td>
                    <td>{{ $line['unit'] }}</td>
                    <td class="num">{{ $line['qty'] }}</td>
                    @if($doc['change_cols'])
                        <td class="num">{{ $line['was'] ?? '-' }}</td>
                        <td class="num" style="font-weight:700;color:{{ ($line['change_dir'] ?? 0) > 0 ? '#166534' : (($line['change_dir'] ?? 0) < 0 ? '#991b1b' : '#475569') }}">{{ $line['change'] ?? '-' }}</td>
                        <td class="num" style="font-weight:700">{{ $line['now'] ?? '-' }}</td>
                    @endif
                    @if($doc['show_values'])
                        <td class="num">{{ $line['unit_value'] }}</td>
                        <td class="num">{{ $line['total'] }}</td>
                    @endif
                    @if($doc['extra_cols'])
                        <td class="remarks">&nbsp;</td>
                    @endif
                    @if($doc['tick_col'])
                        <td class="tick"><span class="tick-box"></span></td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="8">No items recorded.</td></tr>
            @endforelse
            <tr class="total-row">
                <td></td>
                <td colspan="2">Total</td>
                <td class="num">{{ $doc['total_qty'] }}</td>
                @if($doc['change_cols'])
                    <td></td><td></td><td></td>
                @endif
                @if($doc['show_values'])
                    <td></td>
                    <td class="num">{{ $doc['total'] }}</td>
                @endif
                @if($doc['extra_cols'])
                    <td></td>
                @endif
                @if($doc['tick_col'])
                    <td></td>
                @endif
            </tr>
        </tbody>
    </table>

    @endif

    @if($doc['change_cols'] && !empty($doc['change_note']))
        <p style="margin:6px 0 0;font-size:10px;color:#64748b">{{ $doc['change_note'] }}</p>
    @endif

    @if($doc['show_values'])
        <div class="amount-card">
            <table><tr><td class="label">Total Value</td><td class="value">{{ $doc['total'] }}</td></tr></table>
        </div>
    @endif

    @if(!empty($doc['notes']))
        <div class="notes-card">
            <div class="card-title" style="color:{{ $AD }};">@include('print.icon', ['name' => 'note', 'color' => $AD, 'size' => 11]) &nbsp;Notes</div>
            <div style="font-size:10.5px; color:#374151;">{{ $doc['notes'] }}</div>
        </div>
    @endif

    {{-- Signatories module: only when a controller passed an enabled $signatures (transfer, adjustment, check-in, check-out, delivery note, gate pass). Every other document falls through to the original blocks below, unchanged. --}}
    @if(!empty($signatures['enabled']))
        @include('partials.print.signature-block', ['sig' => $signatures])
        {{-- Parties that are not WIMS users (a supplier or receiving contact) keep their typed line next to the block. --}}
        @if(!empty($doc['typed_signers']))
            <table class="sign">
                <tr>
                    @foreach($doc['typed_signers'] as $i => $sig)
                        @if($i > 0)<td class="gap"></td>@endif
                        <td class="sg">
                            <div class="sg-line">{{ $sig['name'] ?: ' ' }}</div>
                            <div class="sg-role">{{ $sig['role'] }}</div>
                        </td>
                    @endforeach
                </tr>
            </table>
        @endif
        {{-- The official stamp area of the stamp-box layouts (delivery note, gate pass) stays available. --}}
        @if($doc['stamp_boxes'])
            <table class="sign" style="margin-top:10px;">
                <tr><td class="sg"><div class="stamp-area" style="margin:0;">Official stamp</div></td><td class="gap"></td><td class="sg"></td></tr>
            </table>
        @endif
    @elseif($doc['stamp_boxes'])
        <table class="sign">
            <tr>
                @foreach($doc['signatures'] as $i => $sig)
                    @if($i > 0)<td class="gap"></td>@endif
                    <td class="sg">
                        <div class="sign-box">
                            <div class="sb-head">{{ $sig['role'] }}</div>
                            <table>
                                <tr><td style="width:60px;">Name</td><td class="fill">{{ $sig['name'] ?? '' }}</td></tr>
                                <tr><td>Signature</td><td class="fill">&nbsp;</td></tr>
                                <tr><td>Date</td><td class="fill">{{ $sig['date'] ?? '' }}</td></tr>
                            </table>
                            <div class="stamp-area">Official stamp</div>
                        </div>
                    </td>
                @endforeach
            </tr>
        </table>
    @else
        <table class="sign">
            <tr>
                @foreach($doc['signatures'] as $i => $sig)
                    @if($i > 0)<td class="gap"></td>@endif
                    <td class="sg">
                        <div class="sg-line">{{ $sig['name'] ?: ' ' }}</div>
                        <div class="sg-role">{{ $sig['role'] }}</div>
                        @if(!empty($sig['date']))<div class="sg-date">{{ $sig['date'] }}</div>@endif
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    @if(!empty($doc['site_receipt']))
        <div class="gate-box">
            <div class="gb-head">@include('print.icon', ['name' => 'user', 'color' => !empty($doc['ink_saver']) ? $AD : '#ffffff', 'size' => 12]) &nbsp;Received at Site / Site Engineer or Site Personnel</div>
            <div class="gb-sub">To be completed on site when the items are received.</div>
            <div class="gb-body">
            <table>
                <tr>
                    <td class="lbl">Name</td><td class="fill">&nbsp;</td>
                    <td class="lbl" style="padding-left:18px;">Position</td><td class="fill">&nbsp;</td>
                </tr>
                <tr>
                    <td class="lbl">Signature</td><td class="fill">&nbsp;</td>
                    <td class="lbl" style="padding-left:18px;">Date</td><td class="fill">&nbsp;</td>
                </tr>
                <tr>
                    <td class="chk" colspan="4">
                        <span class="tick-box"></span> All items received in good order &nbsp;&nbsp;&nbsp;
                        <span class="tick-box"></span> Shortage / damage noted below
                    </td>
                </tr>
                <tr>
                    <td class="lbl">Remarks</td><td class="fill" colspan="3">&nbsp;</td>
                </tr>
            </table>
            </div>
        </div>
    @endif

    @if(!empty($doc['gate_security']))
        <div class="gate-box">
            <div class="gb-head">@include('print.icon', ['name' => 'shield', 'color' => !empty($doc['ink_saver']) ? $AD : '#ffffff', 'size' => 12]) &nbsp;Gate Security / Security Guard</div>
            <div class="gb-sub">To be completed at the gate when the goods and the vehicle are checked.</div>
            <div class="gb-body">
            <table>
                <tr>
                    <td class="lbl">Name</td><td class="fill">&nbsp;</td>
                    <td class="lbl" style="padding-left:18px;">Signature</td><td class="fill">&nbsp;</td>
                </tr>
                <tr>
                    <td class="lbl">Date</td><td class="fill">&nbsp;</td>
                    <td class="lbl" style="padding-left:18px;">Time</td><td class="fill">&nbsp;</td>
                </tr>
                <tr>
                    <td class="chk" colspan="4">
                        <span class="tick-box"></span> All items ticked and match the pass &nbsp;&nbsp;&nbsp;
                        <span class="tick-box"></span> Discrepancy noted below
                    </td>
                </tr>
                <tr>
                    <td class="lbl">Remarks</td><td class="fill" colspan="3">&nbsp;</td>
                </tr>
            </table>
            </div>
            <div class="gb-note">&nbsp;</div>
        </div>
    @endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell">
                <img src="{{ $qr }}" style="width:{{ !empty($doc['compact']) ? 44 : 64 }}px;height:{{ !empty($doc['compact']) ? 44 : 64 }}px;" alt="QR" />
                <div class="qr-caption">Scan to verify</div>
            </td>
            <td class="foot-cell">{{ $companyName }} / {{ $doc['footer'] }}, Generated {{ now()->format('Y-m-d H:i') }}@if($copyLabel) / {{ ucfirst(strtolower($copyLabel)) }} copy @endif</td>
            <td class="qr-cell"></td>
        </tr>
    </table>

@if(!$isPdf)
    </div>
@endif
@if(!$loop->last)
    <div class="copy-break"></div>
@endif
@endforeach

@if(!$isPdf && !empty($autoPrint))
    <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
@endif
</body>
</html>
