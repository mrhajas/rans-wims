{{-- Running footer of every Site Meetings print / PDF: who printed it, when, and the page number (PDF). --}}
<div class="pfoot">
    <table>
        <tr>
            <td class="l"><strong>Printed by</strong> {{ $printedBy ?? '—' }} &nbsp;·&nbsp; {{ ($printedAt ?? now())->format('d M Y, H:i') }}</td>
            <td class="c">{{ $companyName ?? 'RANS Company Limited' }} · {{ $footerRef ?? '' }}</td>
            <td class="r">@if(! empty($isPdf))<span class="pgn"></span>@else WIMS @endif</td>
        </tr>
    </table>
</div>
