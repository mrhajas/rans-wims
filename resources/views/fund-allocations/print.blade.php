{{--
    Funds Allocation sheet: the Excel-style landscape A4 sheet (available balances block, lettered categories with
    numbered rows and subtotals, TOTAL row, per-method totals, signatures). Used for the browser view and DomPDF.
--}}
@php
    $isPdf = !empty($isPdf);
    $a = $allocation;
    $t = $totals;
    $fmt = fn ($n) => number_format((float) $n, 2);
    $fmt0 = fn ($n) => ((float) $n) == 0 ? '' : number_format((float) $n, 2);
    $methods = collect($t['by_method']);
    $mcount = max($methods->count(), 0);
    $groups = $items->groupBy('fund_category_id');
    $catMeta = collect($t['by_category'])->keyBy('id');
    $letters = range('A', 'Z');
    $negative = $t['balance'] < 0;
    $statusClass = ['Completed' => 'status-active', 'Approved' => 'status-active', 'In Progress' => 'status-warn', 'Pending Approval' => 'status-warn', 'Rejected' => 'status-danger', 'Cancelled' => 'status-danger'][$a->status] ?? 'status-neutral';
    $fixedCols = 5 + $mcount;
    $pdfUrl = $pdfUrl ?? route('fund-allocations.pdf', $a->id);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Funds Allocation {{ $a->reference }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        body { margin: 0; font-size: 10px; }
        .sheet-title { width: 100%; border-collapse: collapse; margin: 2px 0 8px; }
        .sheet-title td { vertical-align: middle; }
        .sheet-title .t { font-size: 17px; font-weight: bold; color: #1c3f66; }
        .sheet-title .s { font-size: 9.5px; color: #64748b; }
        .sheet-title .r { text-align: right; font-size: 9.5px; color: #475569; }
        .sheet-title .r strong { font-size: 14px; color: #0f172a; }

        table.top { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.top td.col { vertical-align: top; }
        table.bal { width: 100%; border-collapse: collapse; }
        table.bal th { background: #1c3f66; color: #fff; font-size: 8.5px; padding: 4px 6px; text-align: center; text-transform: uppercase; }
        table.bal th.num { text-align: center; }
        table.bal td.num { text-align: right; }
        table.bal td { padding: 3.5px 6px; border-bottom: 1px solid #e2e8f0; font-size: 9.8px; }
        table.bal tr.tot td { font-weight: bold; background: #eef3fa; border-top: 1.5px solid #1c3f66; }
        .sum-box { border: 1.5px solid #1c3f66; border-radius: 8px; }
        .sum-box table { width: 100%; border-collapse: collapse; }
        .sum-box td { padding: 5px 10px; font-size: 10.5px; }
        .sum-box td.v { text-align: right; font-weight: bold; }
        .sum-box tr.final td { border-top: 1.5px solid #1c3f66; font-size: 12px; font-weight: bold; background: #eef3fa; }
        .neg { color: #be123c !important; }
        .pos { color: #047857; }

        table.sheet { width: 100%; border-collapse: collapse; }
        table.sheet thead { display: table-header-group; }
        table.sheet th { background: #1c3f66; color: #fff; padding: 5px 6px; font-size: 9px; text-transform: uppercase; border: 1px solid #1c3f66; text-align: center !important; vertical-align: middle; }
        table.sheet td { padding: 3.5px 6px; border: 1px solid #cbd5e1; font-size: 9.6px; vertical-align: top; }
        table.sheet tr { page-break-inside: avoid; }
        table.sheet .num { text-align: right; white-space: nowrap; }
        table.sheet .sn { width: 24px; text-align: center; color: #64748b; }
        table.sheet tr.cat td { background: #dbe5f3; font-weight: bold; color: #1c3f66; font-size: 10px; border-color: #b9c8de; }
        table.sheet tr.sub td { background: #f1f5f9; font-weight: bold; border-top: 1.5px solid #1c3f66; }
        table.sheet tr.grand td { background: #1c3f66; color: #fff; font-weight: bold; font-size: 10.5px; border-color: #1c3f66; }
        table.sheet tr.methods td { background: #eef3fa; font-weight: bold; }
        .small { font-size: 8.3px; color: #64748b; }
        .pv { color: #0e7490; font-size: 8.3px; }
        .diff { color: #b45309; font-size: 8.3px; }

        table.sign { width: 100%; border-collapse: collapse; margin-top: 26px; page-break-inside: avoid; }
        table.sign td { width: 31%; vertical-align: top; padding: 0 8px; }
        table.sign td.gap { width: 3.5%; padding: 0; }
        .sg-line { border-top: 1.5px solid #1c3f66; padding-top: 3px; margin-top: 32px; font-weight: bold; color: #111827; font-size: 10px; }
        .sg-role { color: #1c3f66; font-weight: bold; font-size: 9px; text-transform: uppercase; }
        .sg-date { color: #94a3b8; font-size: 8.3px; }
        .notes { border: 1px solid #e2e8f0; border-left: 4px solid #1c3f66; border-radius: 6px; padding: 6px 10px; margin-top: 8px; font-size: 9.5px; page-break-inside: avoid; }
        @if(!$isPdf)
        body { background: #e6edfb; }
        .doc-toolbar { position: sticky; top: 0; z-index: 10; background: #1c3f66; color: #fff; padding: 10px 22px; display: flex; align-items: center; justify-content: space-between; font-size: 13px; }
        .doc-toolbar a, .doc-toolbar button { display: inline-flex; background: #fff; color: #1c3f66; border: 0; border-radius: 999px; padding: 7px 16px; font-weight: bold; font-size: 12px; text-decoration: none; cursor: pointer; margin-left: 8px; }
        .paper { max-width: 1120px; margin: 22px auto 40px; background: #fff; padding: 22px 24px; border-radius: 12px; box-shadow: 0 30px 70px -28px rgba(30, 58, 138, 0.45); position: relative; overflow: hidden; }
        @media print {
            body { background: #fff; }
            .doc-toolbar { display: none; }
            .paper { margin: 0; max-width: none; border-radius: 0; box-shadow: none; padding: 0; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        @endif
    </style>
</head>
<body>
@if(!$isPdf)
    <div class="doc-toolbar">
        <div><strong>Funds Allocation</strong> &nbsp; {{ $a->reference }}</div>
        <div>
            <button type="button" onclick="window.print()">Print</button>
            <a href="{{ $pdfUrl }}">Download PDF</a>
        </div>
    </div>
    <div class="paper">
@endif

    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    <table class="sheet-title">
        <tr>
            <td>
                <div class="t">FUNDS ALLOCATION @if(in_array($a->status, ['Pending Approval', 'Approved', 'In Progress']))IN PROGRESS @endif</div>
                <div class="s">{{ $a->title ?: 'Allocation sheet' }} &middot; Date {{ optional($a->allocation_date)->format('l, d F Y') }}</div>
            </td>
            <td class="r">
                Reference<br><strong>{{ $a->reference }}</strong><br>
                <span class="status-pill {{ $statusClass }}">{{ $a->status }}</span>
            </td>
        </tr>
    </table>

    <table class="top">
        <tr>
            <td class="col" style="width:58%; padding-right:12px;">
                @unless($hideBalances ?? false)
                <table class="bal">
                    <thead>
                        <tr><th>Available balances</th><th>Currency</th><th class="num">Amount</th><th class="num">Rate</th><th class="num">TZS</th></tr>
                    </thead>
                    <tbody>
                        @forelse($a->balances as $b)
                            <tr>
                                <td>{{ $b->label }}</td>
                                <td>{{ $b->currency }}</td>
                                <td class="num">{{ $fmt($b->amount) }}</td>
                                <td class="num">{{ $b->currency === 'TZS' ? '' : rtrim(rtrim(number_format((float) $b->exchange_rate, 6), '0'), '.') }}</td>
                                <td class="num">{{ $fmt($b->amount_tzs) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="small">No balances recorded.</td></tr>
                        @endforelse
                        <tr class="tot"><td colspan="4">TOTAL AVAILABLE (TZS)</td><td class="num">{{ $fmt($t['available']) }}</td></tr>
                    </tbody>
                </table>
                @endunless
            </td>
            <td class="col" style="width:42%;">
                <div class="sum-box">
                    <table>
                        <tr><td>Total request</td><td class="v">{{ $fmt($t['requested']) }}</td></tr>
                        <tr><td>Total allocation</td><td class="v">{{ $fmt($t['allocated']) }}</td></tr>
                        @unless($hideBalances ?? false)<tr><td>Total available</td><td class="v">{{ $fmt($t['available']) }}</td></tr>@endunless
                        @if($a->status !== 'Draft' && $t['planned'] > 0)
                            <tr><td>Paid so far</td><td class="v">{{ $fmt($t['paid']) }} ({{ rtrim(rtrim(number_format($t['progress'], 1), '0'), '.') }}%)</td></tr>
                        @endif
                        @unless($hideBalances ?? false)<tr class="final"><td>BALANCE AFTER THIS EXPENSES</td><td class="v {{ $negative ? 'neg' : 'pos' }}">{{ $fmt($t['balance']) }}</td></tr>@endunless
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <table class="sheet">
        <thead>
            <tr>
                <th style="width:26px;">SN</th>
                <th>Description</th>
                <th style="width:17%;">Beneficiary</th>
                <th class="num" style="width:10%;">Request</th>
                <th class="num" style="width:10%;">Allocation</th>
                @foreach($methods as $m)
                    <th class="num" style="width:9%;">{{ $m['short'] ?: $m['name'] }}@if(($m['currency'] ?? 'TZS') === 'USD')<br><span class="small">(USD)</span>@endif</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @php($ci = 0)
            @foreach($groups as $catId => $rows)
                @php($meta = $catMeta->get($catId))
                <tr class="cat"><td colspan="{{ $fixedCols }}">{{ $letters[$ci % 26] }}. {{ $meta['name'] ?? optional($rows->first()->category)->name }}</td></tr>
                @foreach($rows as $n => $item)
                    @php($sumLines = (float) $item->lines->sum('amount'))
                    <tr>
                        <td class="sn">{{ $n + 1 }}</td>
                        <td>
                            {{ $item->description }}
                            @if($item->paymentVoucher)<span class="pv"> [{{ $item->paymentVoucher->pv_number }}]</span>@endif
                            @if(abs($sumLines - (float) $item->allocated_amount) > 0.009)<span class="diff"> (split differs: {{ $fmt($sumLines) }})</span>@endif
                        </td>
                        <td>{{ $item->beneficiary ?: optional($item->contact)->name ?: optional($item->staff)->name }}@if($item->warehouse)<div class="small">{{ $item->warehouse->name }}</div>@endif</td>
                        <td class="num">{{ $fmt0($item->request_amount) }}</td>
                        <td class="num">{{ $fmt0($item->allocated_amount) }}</td>
                        @foreach($methods as $m)
                            @if(($m['currency'] ?? 'TZS') === 'USD')
                                @php($v = (float) $item->lines->where('fund_payment_method_id', $m['id'])->sum('usd_amount'))
                                <td class="num">{{ $v > 0 ? $fmt($v) : '' }}</td>
                            @else
                                @php($v = (float) $item->lines->where('fund_payment_method_id', $m['id'])->sum('amount'))
                                <td class="num">{{ $fmt0($v) }}</td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
                <tr class="sub">
                    <td></td>
                    <td colspan="2">SUBTOTAL {{ $meta['name'] ?? '' }}</td>
                    <td class="num">{{ $fmt($meta['requested'] ?? 0) }}</td>
                    <td class="num">{{ $fmt($meta['allocated'] ?? 0) }}</td>
                    @foreach($methods as $m)
                        @if(($m['currency'] ?? 'TZS') === 'USD')
                            @php($v = (float) $rows->sum(fn ($i) => $i->lines->where('fund_payment_method_id', $m['id'])->sum('usd_amount')))
                            <td class="num">{{ $v > 0 ? $fmt($v) : '' }}</td>
                        @else
                            @php($v = (float) $rows->sum(fn ($i) => $i->lines->where('fund_payment_method_id', $m['id'])->sum('amount')))
                            <td class="num">{{ $fmt0($v) }}</td>
                        @endif
                    @endforeach
                </tr>
                @php($ci++)
            @endforeach
            @if($items->isEmpty())
                <tr><td colspan="{{ $fixedCols }}" class="small">No items on this sheet.</td></tr>
            @endif
            <tr class="grand">
                <td></td>
                <td colspan="2">TOTAL</td>
                <td class="num">{{ $fmt($t['requested']) }}</td>
                <td class="num">{{ $fmt($t['allocated']) }}</td>
                @foreach($methods as $m)
                    <td class="num">{{ ($m['currency'] ?? 'TZS') === 'USD' ? $fmt($m['usd_planned']).' USD' : $fmt($m['planned']) }}</td>
                @endforeach
            </tr>
            @if($methods->isNotEmpty() && $a->status !== 'Draft')
                <tr class="methods">
                    <td></td>
                    <td colspan="4">PAID SO FAR, BY PAYMENT METHOD</td>
                    @foreach($methods as $m)
                        <td class="num">{{ ($m['currency'] ?? 'TZS') === 'USD' ? $fmt($m['usd_paid']).' USD' : $fmt($m['paid']) }}</td>
                    @endforeach
                </tr>
            @endif
        </tbody>
    </table>

    @php($usdLines = $items->flatMap(fn ($i) => $i->lines)->filter(fn ($l) => $l->usd_amount !== null && $l->exchange_rate))
    @if($usdLines->isNotEmpty())
        <div class="notes"><strong>USD:</strong> amounts in the USD column are in US dollars (allocated TZS value &divide; exchange rate). Rate{{ $usdLines->pluck('exchange_rate')->map(fn ($r) => (float) $r)->unique()->count() > 1 ? 's' : '' }} used: {{ $usdLines->pluck('exchange_rate')->map(fn ($r) => rtrim(rtrim(number_format((float) $r, 4), '0'), '.'))->unique()->implode(', ') }} TZS per 1 USD.</div>
    @endif

    @if($a->notes)
        <div class="notes"><strong>Notes:</strong> {{ $a->notes }}</div>
    @endif

    @if(!empty($signatures['enabled']))
        @include('partials.print.signature-block', ['sig' => $signatures])
        {{-- the existing Paid by line stays unless the document setup maps the Paid action to a signing role --}}
        @if(!collect($signatures['slots'])->contains('action', 'paid'))
        <table class="sign" style="margin-top:10px;">
            <tr>
                <td>
                <div class="sg-line">{{ optional($a->assignedTo)->name ?: ' ' }}</div>
                <div class="sg-role">Paid by</div>
                <div class="sg-date">{{ optional($a->completed_at)->format('Y-m-d H:i') }}</div>
            </td>
                <td class="gap"></td><td></td><td class="gap"></td><td></td>
            </tr>
        </table>
        @endif
    @else
    <table class="sign">
        <tr>
            <td>
                <div class="sg-line">{{ optional($a->preparedBy)->name ?: ' ' }}</div>
                <div class="sg-role">Prepared by</div>
                <div class="sg-date">{{ optional($a->submitted_at)->format('Y-m-d H:i') ?: optional($a->created_at)->format('Y-m-d H:i') }}</div>
            </td>
            <td class="gap"></td>
            <td>
                <div class="sg-line">{{ optional($a->approvedBy)->name ?: ' ' }}</div>
                <div class="sg-role">Approved by</div>
                <div class="sg-date">{{ optional($a->approved_at)->format('Y-m-d H:i') }}</div>
            </td>
            <td class="gap"></td>
            <td>
                <div class="sg-line">{{ optional($a->assignedTo)->name ?: ' ' }}</div>
                <div class="sg-role">Paid by</div>
                <div class="sg-date">{{ optional($a->completed_at)->format('Y-m-d H:i') }}</div>
            </td>
        </tr>
    </table>
    @endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell">
                <img src="{{ $qr }}" style="width:52px;height:52px;" alt="QR" />
                <div class="qr-caption">Scan to verify</div>
            </td>
            <td class="foot-cell">{{ $companyName ?? 'RANS Company Limited' }} / Funds Allocation {{ $a->reference }}, Generated {{ now()->format('Y-m-d H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>

@if(!$isPdf)
    </div>
@endif
</body>
</html>
