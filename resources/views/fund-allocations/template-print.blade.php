{{--
    Funds Allocation TEMPLATE: the monthly sheet as it is kept as a template (balances, lettered categories with numbered rows and
    subtotals, totals, signatures). A4 landscape, used for the browser Print view and the DomPDF download.
--}}
@php
    $isPdf = !empty($isPdf);
    $fmt = fn ($n) => ((float) $n) == 0 ? '' : number_format((float) $n, 2);
    $fmtAll = fn ($n) => number_format((float) $n, 2);
    $sum = fn ($items, $k) => collect($items)->sum(fn ($i) => (float) ($i[$k] ?? 0));
    $balTzs = collect($d['balances'])->sum(fn ($b) => strtoupper($b['currency'] ?? 'TZS') === 'TZS' ? (float) $b['amount'] : 0);
    $balUsd = collect($d['balances'])->sum(fn ($b) => strtoupper($b['currency'] ?? 'TZS') === 'USD' ? (float) $b['amount'] : 0);
    $allocAll = collect($d['sections'])->sum(fn ($s) => $sum($s['items'], 'allocation'));
    $letters = range('A', 'Z');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Funds Allocation Template</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        body { margin: 0; font-size: 9.6px; }
        .tt { width: 100%; border-collapse: collapse; margin: 2px 0 8px; } .tt td { vertical-align: middle; }
        .tt .t { font-size: 17px; font-weight: bold; color: #1c3f66; } .tt .s { font-size: 9.5px; color: #64748b; } .tt .r { text-align: right; font-size: 9.5px; color: #475569; } .tt .r strong { font-size: 13px; color: #0f172a; }
        .pill { display: inline-block; padding: 2px 9px; border-radius: 99px; font-size: 8.6px; font-weight: bold; background: #e0f2fe; color: #075985; }
        table.bal { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.bal th { background: #1c3f66; color: #fff; font-size: 8.4px; padding: 4px 6px; text-align: left; text-transform: uppercase; } table.bal th.n { text-align: right; }
        table.bal td { padding: 3.5px 6px; border-bottom: 1px solid #e2e8f0; font-size: 9.6px; } table.bal td.n { text-align: right; }
        table.bal tr.tzs td { background: #f0fdf4; } table.bal tr.tzs td.cur { color: #15803d; font-weight: bold; }
        table.bal tr.usd td { background: #eff6ff; } table.bal tr.usd td.cur { color: #1d4ed8; font-weight: bold; }
        table.bal tr.tot td { font-weight: bold; background: #eef3fa; border-top: 1.5px solid #1c3f66; }
        table.sec { width: 100%; border-collapse: collapse; margin: 0 0 8px; table-layout: fixed; }
        table.sec th { background: #1c3f66; color: #fff; font-size: 8.3px; padding: 4px 5px; text-transform: uppercase; text-align: left; } table.sec th.n { text-align: right; }
        table.sec td { padding: 3px 5px; border-bottom: 1px solid #e5e7eb; font-size: 9.2px; vertical-align: top; word-wrap: break-word; overflow-wrap: anywhere; } table.sec td.n { text-align: right; white-space: nowrap; }
        table.sec tr { page-break-inside: avoid; }
        table.sec td.sn { text-align: center; color: #64748b; }
        table.sec tr.cat td { background: #eaf1fb; font-weight: bold; color: #1c3f66; font-size: 10px; padding: 5px; }
        table.sec tr.sub td { background: #f1f5f9; font-weight: bold; border-top: 1.2px solid #94a3b8; }
        table.sec .sub-d { display: block; font-size: 8px; color: #64748b; }
        table.grand { width: 100%; border-collapse: collapse; margin-top: 6px; } table.grand td { padding: 5px 6px; font-weight: bold; font-size: 10px; background: #1c3f66; color: #fff; } table.grand td.n { text-align: right; }
        .box { border: 1.5px solid #1c3f66; border-radius: 8px; margin: 8px 0; } .box table { width: 100%; border-collapse: collapse; } .box td { padding: 5px 10px; font-size: 10.5px; } .box td.v { text-align: right; font-weight: bold; } .box tr.final td { border-top: 1.5px solid #1c3f66; background: #eef3fa; font-size: 12px; font-weight: bold; }
        .green { color: #15803d; } .red { color: #dc2626; }
        .notes { margin: 6px 0; padding: 5px 9px; border: 1px solid #fde68a; border-radius: 6px; background: #fffbeb; font-size: 9.2px; color: #92400e; }
        table.sign { width: 100%; border-collapse: collapse; margin-top: 18px; page-break-inside: avoid; } table.sign td { width: 31%; text-align: center; vertical-align: bottom; } table.sign td.gap { width: 3.5%; }
        .sg-line { border-top: 1px solid #1f2937; padding-top: 3px; font-size: 9.5px; font-weight: bold; min-height: 14px; } .sg-role { font-size: 8.8px; color: #6b7280; }
    </style>
</head>
<body>
@if(!$isPdf)
    <div class="doc-toolbar">
        <div><strong>Funds Allocation Template</strong></div>
        <div><button type="button" onclick="window.print()">Print</button> <a href="{{ route('fund-allocations.template.pdf') }}">Download PDF</a></div>
    </div>
    <div class="paper">
@endif

    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    <table class="tt">
        <tr>
            <td>
                <div class="t">{{ $d['title'] ?? 'FUNDS ALLOCATION' }}</div>
                <div class="s">Template @if(!empty($d['date'])) &middot; sheet date {{ \Carbon\Carbon::parse($d['date'])->format('d F Y') }} @endif &middot; USD rate {{ number_format((float) ($d['usd_rate'] ?? 0), 2) }} TZS</div>
            </td>
            <td class="r"><span class="pill">TEMPLATE</span><br>@if($savedAt)Last saved {{ $savedAt->format('Y-m-d H:i') }}@if($savedBy) by {{ $savedBy }}@endif @endif</td>
        </tr>
    </table>

    <table class="bal">
        <thead><tr><th>Available balances</th><th>Account</th><th>Currency</th><th class="n">Amount</th><th class="n">Rate</th><th class="n">TZS value</th></tr></thead>
        <tbody>
            @foreach($d['balances'] as $b)
                @php $cur = strtoupper($b['currency'] ?? 'TZS'); $rate = $cur === 'TZS' ? 1 : (float) ($b['exchange_rate'] ?? 1); @endphp
                <tr class="{{ $cur === 'USD' ? 'usd' : 'tzs' }}">
                    <td>{{ $b['label'] }}</td>
                    <td>{{ !empty($b['fund_account_id']) ? ($accounts[$b['fund_account_id']] ?? '') : '' }}</td>
                    <td class="cur">{{ $cur }}</td>
                    <td class="n">{{ $fmtAll($b['amount']) }}</td>
                    <td class="n">{{ $cur === 'TZS' ? '' : number_format($rate, 2) }}</td>
                    <td class="n">{{ $fmtAll((float) $b['amount'] * $rate) }}</td>
                </tr>
            @endforeach
            <tr class="tot"><td colspan="3">Cash and bank in TZS</td><td class="n">{{ $fmtAll($balTzs) }}</td><td></td><td class="n">{{ $fmtAll($balTzs) }}</td></tr>
            @if($balUsd > 0)<tr class="tot"><td colspan="3">Cash and bank in USD</td><td class="n">{{ $fmtAll($balUsd) }}</td><td></td><td class="n">{{ $fmtAll(collect($d['balances'])->sum(fn ($b) => strtoupper($b['currency'] ?? 'TZS') === 'USD' ? (float) $b['amount'] * (float) ($b['exchange_rate'] ?? 1) : 0)) }}</td></tr>@endif
        </tbody>
    </table>

    @foreach($d['sections'] as $si => $s)
        <table class="sec">
            <thead>
                <tr>
                    <th style="width:3.5%;">SN</th><th style="width:28%;">Description</th><th style="width:17%;">Beneficiary</th>
                    <th class="n" style="width:11.5%;">Request</th><th class="n" style="width:11.5%;">Allocation</th><th class="n" style="width:9.5%;">TT</th><th class="n" style="width:9.5%;">Cash</th><th class="n" style="width:9.5%;">USD</th>
                </tr>
            </thead>
            <tbody>
                <tr class="cat"><td>{{ $letters[$si % 26] }}</td><td colspan="7">{{ $s['name'] }}</td></tr>
                @foreach($s['items'] as $ii => $it)
                    <tr>
                        <td class="sn">{{ $ii + 1 }}</td>
                        <td>{{ $it['description'] }}@if(!empty($it['notes']))<span class="sub-d">{{ $it['notes'] }}</span>@endif</td>
                        <td>{{ $it['beneficiary'] }}</td>
                        <td class="n">{{ $fmt($it['request']) }}</td><td class="n">{{ $fmt($it['allocation']) }}</td>
                        <td class="n">{{ $fmt($it['tt']) }}</td><td class="n">{{ $fmt($it['cash']) }}</td><td class="n">{{ $fmt($it['usd']) }}</td>
                    </tr>
                @endforeach
                <tr class="sub"><td></td><td colspan="2">Subtotal {{ $s['name'] }}</td>
                    <td class="n">{{ $fmtAll($sum($s['items'], 'request')) }}</td><td class="n">{{ $fmtAll($sum($s['items'], 'allocation')) }}</td>
                    <td class="n">{{ $fmtAll($sum($s['items'], 'tt')) }}</td><td class="n">{{ $fmtAll($sum($s['items'], 'cash')) }}</td><td class="n">{{ $fmtAll($sum($s['items'], 'usd')) }}</td></tr>
            </tbody>
        </table>
    @endforeach

    <table class="grand">
        <tr><td style="width:48.5%;">TOTAL</td>
            <td class="n" style="width:11.5%;">{{ $fmtAll(collect($d['sections'])->sum(fn ($s) => $sum($s['items'], 'request'))) }}</td>
            <td class="n" style="width:11.5%;">{{ $fmtAll($allocAll) }}</td>
            <td class="n" style="width:9.5%;">{{ $fmtAll(collect($d['sections'])->sum(fn ($s) => $sum($s['items'], 'tt'))) }}</td>
            <td class="n" style="width:9.5%;">{{ $fmtAll(collect($d['sections'])->sum(fn ($s) => $sum($s['items'], 'cash'))) }}</td>
            <td class="n" style="width:9.5%;">{{ $fmtAll(collect($d['sections'])->sum(fn ($s) => $sum($s['items'], 'usd'))) }}</td></tr>
    </table>

    <div class="box">
        <table>
            <tr><td>Total balance (TZS)</td><td class="v green">{{ $fmtAll($balTzs) }}</td></tr>
            <tr><td>Total allocation</td><td class="v">{{ $fmtAll($allocAll) }}</td></tr>
            <tr class="final"><td>Balance after this expenses (TZS)</td><td class="v {{ $balTzs - $allocAll < 0 ? 'red' : '' }}">{{ $fmtAll($balTzs - $allocAll) }}</td></tr>
        </table>
    </div>

    @if(!empty($d['note']))<div class="notes"><strong>Note:</strong> {{ $d['note'] }}</div>@endif

    <table class="sign">
        <tr>
            <td><div class="sg-line">{{ $by }}</div><div class="sg-role">Printed by</div></td><td class="gap"></td>
            <td><div class="sg-line">&nbsp;</div><div class="sg-role">Approved by</div></td><td class="gap"></td>
            <td><div class="sg-line">&nbsp;</div><div class="sg-role">Paid by</div></td>
        </tr>
    </table>

    <table class="footer-row"><tr><td class="foot-cell">{{ $companyName }} / Funds Allocation Template, generated {{ $generatedAt->format('Y-m-d H:i') }}</td></tr></table>

@if(!$isPdf)
    </div>
@endif
</body>
</html>
