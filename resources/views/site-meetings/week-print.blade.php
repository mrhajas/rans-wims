<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Weekly Site Meeting Schedule</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    @include('site-meetings._print-style')
    <style>@page { size: A4 landscape; }</style>
    <style>
        .daybar td { background:#1c3f66; color:#fff; font-weight:bold; font-size:10px; padding:5px 8px; }
        .daybar.today td { background:#b45309; }
        .none td { color:#9ca3af; font-style:italic; }
        .pill { display:inline-block; padding:1px 8px; border-radius:999px; font-size:8.5px; font-weight:bold; }
    </style>
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    <div class="title-card">
        <div class="doc-title">WEEKLY SITE MEETING SCHEDULE</div>
        <div class="doc-ref">Week<br><strong>{{ $start->format('d M') }} – {{ $end->format('d M Y') }}</strong></div>
    </div>
    <div class="printed-line">Printed by <strong>{{ $printedBy }}</strong> on {{ $printedAt->format('d M Y') }} at {{ $printedAt->format('H:i') }}</div>
    <div style="font-size:10px;color:#374151;margin-bottom:8px;">
        {{ $site ? 'Site: '.$site.' · ' : 'All sites · ' }}{{ $total }} {{ \Illuminate\Support\Str::plural('meeting', $total) }}
    </div>

    <table class="items">
        <thead>
            <tr><th style="width:9%;">Time</th><th style="width:22%;">Site</th><th style="width:20%;">Meeting</th><th style="width:15%;">Venue</th><th style="width:14%;">Chaired by</th><th style="width:12%;">Minutes by</th><th style="width:8%;">Status</th></tr>
        </thead>
        <tbody>
        @for($d = $start->copy(); $d->lte($end); $d->addDay())
            <?php $key = $d->toDateString(); ?>
            <?php $day = $meetings[$key] ?? collect(); ?>
            @if($day->isEmpty() && ! in_array($d->dayOfWeek, $shown, true))
                @continue
            @endif
            <tr class="daybar {{ $d->isToday() ? 'today' : '' }}"><td colspan="7">{{ $d->format('l, d M Y') }}</td></tr>
            @forelse($day as $m)
                <?php $st = $styles[$m->status] ?? ['label' => $m->status, 'color' => 'slate']; ?>
                <tr>
                    <td>{{ $m->start_time ? substr($m->start_time, 0, 5) : '—' }}@if($m->end_time)<br><span style="color:#6b7280;">{{ substr($m->end_time, 0, 5) }}</span>@endif</td>
                    <td><strong>{{ optional($m->warehouse)->name }}</strong>@if(optional($m->warehouse)->code)<br><span style="color:#6b7280;">{{ $m->warehouse->code }}</span>@endif</td>
                    <td>{{ $m->display_title }}<br><span style="color:#6b7280;">{{ $m->reference }} · {{ $m->attendees_count }} invited</span></td>
                    <td>{{ $m->venue ?: '—' }}</td>
                    <td>{{ optional($m->chairperson)->name ?: '—' }}</td>
                    <td>{{ optional($m->recorder)->name ?: '—' }}</td>
                    <td><span class="pill" style="{{ \App\Support\SiteMeetingColors::pill($st) }}">{{ $st['label'] }}</span></td>
                </tr>
            @empty
                <tr class="none"><td colspan="7">No meeting scheduled.</td></tr>
            @endforelse
        @endfor
        </tbody>
    </table>

    <table class="footer-row">
        <tr>
            <td class="qr-cell"></td>
            <td class="foot-cell">{{ $companyName ?? 'RANS Company Limited' }} / Weekly Site Meeting Schedule {{ $start->format('d M') }} – {{ $end->format('d M Y') }}, Printed by {{ $printedBy }}, {{ $printedAt->format('d M Y H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
    @include('site-meetings._print-footer', ['footerRef' => 'Weekly schedule '.$start->format('d M').' – '.$end->format('d M Y')])
</body>
</html>
