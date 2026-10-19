{{-- Loading & Unloading table for the Transport reports (print / PDF). Needs $loading. --}}
<div style="margin: 14px 0 6px; font-size: 12px; font-weight: bold; color: #1c3f66;">Loading &amp; Unloading
    <span style="font-weight: normal; color: #6b7280; font-size: 9px;"> &nbsp;daily rate TZS {{ number_format($loading['rate'], 2) }}</span>
</div>
<table class="items">
    <thead>
        <tr>
            <th style="width:6%;">No</th>
            <th>Worker</th>
            <th class="num">Previous</th>
            <th class="num">Days</th>
            <th class="num">Earned</th>
            <th class="num">Paid</th>
            <th class="num">Balance</th>
        </tr>
    </thead>
    <tbody>
        @forelse($loading['rows'] as $i => $w)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $w['name'] }}@if(!empty($w['phone']))<span class="sub">{{ $w['phone'] }}</span>@endif</td>
                <td class="num">{{ !empty($w['opening']) ? number_format($w['opening'], 2) : '-' }}</td>
                <td class="num">{{ number_format($w['days']) }}</td>
                <td class="num">{{ number_format($w['earned'], 2) }}</td>
                <td class="num">{{ number_format($w['paid'], 2) }}</td>
                <td class="num">{{ number_format($w['balance'], 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" style="text-align:center; padding:16px;">No loading or unloading work recorded for this period.</td></tr>
        @endforelse
        @if(count($loading['rows']))
            <tr>
                <td></td><td><b>Total</b></td>
                <td class="num"><b>{{ number_format($loading['totals']['opening'], 2) }}</b></td>
                <td class="num"><b>{{ number_format($loading['totals']['days']) }} Day(s)</b></td>
                <td class="num"><b>{{ number_format($loading['totals']['earned'], 2) }}</b></td>
                <td class="num"><b>{{ number_format($loading['totals']['paid'], 2) }}</b></td>
                <td class="num"><b>{{ number_format($loading['totals']['balance'], 2) }}</b></td>
            </tr>
        @endif
    </tbody>
</table>
