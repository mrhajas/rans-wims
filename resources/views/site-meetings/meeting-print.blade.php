<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Site Meeting {{ $meeting->reference }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    @include('site-meetings._print-style')
    <style>@page { size: A4 portrait; }</style>
    <style>
        .chip { display:inline-block; margin:0 6px 4px 0; padding:3px 10px; border-radius:999px; font-size:9.5px; font-weight:bold; }
        .block-text { font-size:10.5px; color:#374151; white-space:pre-line; line-height:1.5; }
        .muted { color:#6b7280; }
    </style>
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    <?php $st = $styles[$meeting->status] ?? ['label' => $meeting->status, 'color' => 'slate']; ?>
    <?php $rs = $styles[$meeting->report_status] ?? ['label' => $meeting->report_status, 'color' => 'slate']; ?>

    <div class="title-card">
        <div class="doc-title">SITE MEETING</div>
        <div class="doc-ref">Reference<br><strong>{{ $meeting->reference }}</strong></div>
    </div>

    <div class="printed-line">Printed by <strong>{{ $printedBy }}</strong> on {{ $printedAt->format('d M Y') }} at {{ $printedAt->format('H:i') }}</div>

    <div style="margin: 0 0 14px;">
        <span class="chip" style="background:#eff6ff;color:#2563eb;">{{ optional($meeting->warehouse)->name ?? '-' }}</span>
        <span class="chip" style="background:#fffbeb;color:#b45309;">{{ $meeting->meeting_date->format('l, d M Y') }}@if($meeting->start_time) · {{ substr($meeting->start_time, 0, 5) }}@if($meeting->end_time)–{{ substr($meeting->end_time, 0, 5) }}@endif @endif</span>
        <span class="chip" style="{{ \App\Support\SiteMeetingColors::pill($st) }}">{{ $st['label'] }}</span>
        <span class="chip" style="{{ \App\Support\SiteMeetingColors::pill($rs) }}">{{ $rs['label'] }}</span>
    </div>

    <div class="card">
        <div class="card-title">{{ $meeting->display_title }}</div>
        <table class="grid">
            <tr>
                <td class="label">Project / Site</td><td class="value">{{ optional($meeting->warehouse)->name ?? '-' }}</td>
                <td class="label">Meeting no.</td><td class="value">{{ str_pad((string) $meeting->meeting_no, 2, '0', STR_PAD_LEFT) }}</td>
            </tr>
            <tr>
                <td class="label">Date</td><td class="value">{{ $meeting->meeting_date->format('d M Y') }}</td>
                <td class="label">Time</td><td class="value">{{ $meeting->start_time ? substr($meeting->start_time, 0, 5).($meeting->end_time ? ' – '.substr($meeting->end_time, 0, 5) : '') : '-' }}</td>
            </tr>
            <tr>
                <td class="label">Venue</td><td class="value">{{ $meeting->venue ?: '-' }}</td>
                <td class="label">Chaired by</td><td class="value">{{ optional($meeting->chairperson)->name ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Minutes by</td><td class="value">{{ optional($meeting->recorder)->name ?? '-' }}</td>
                <td class="label">Scheduled by</td><td class="value">{{ optional($meeting->creator)->name ?? '-' }}</td>
            </tr>
            @if($meeting->status_reason)
                <tr>
                    <td class="label">{{ $meeting->status === 'Cancelled' ? 'Cancelled because' : 'Postponed because' }}</td>
                    <td class="value" colspan="3">{{ $meeting->status_reason }}@if($meeting->postponed_to), new date {{ $meeting->postponed_to->format('d M Y') }}@endif</td>
                </tr>
            @endif
        </table>
    </div>

    @if($meeting->agenda)
        <div class="card"><div class="card-title">Agenda</div><div class="block-text">{{ $meeting->agenda }}</div></div>
    @endif
    @if($meeting->info)
        <div class="card"><div class="card-title">Information</div><div class="block-text">{{ $meeting->info }}</div></div>
    @endif
    @if($meeting->remarks)
        <div class="card"><div class="card-title">Remarks</div><div class="block-text">{{ $meeting->remarks }}</div></div>
    @endif

    <div class="card-title" style="margin-top:2px;">Invited</div>
    <table class="items">
        <thead><tr><th style="width:5%;">No</th><th>Name</th><th>Designation</th><th>Organisation</th><th>Attendance</th></tr></thead>
        <tbody>
        @forelse($meeting->attendees as $i => $a)
            <tr><td>{{ $i + 1 }}</td><td>{{ $a->name }}</td><td>{{ $a->designation ?: '-' }}</td><td>{{ $a->organisation ?: '-' }}</td><td>{{ $a->attendance }}</td></tr>
        @empty
            <tr><td colspan="5">Nobody invited yet.</td></tr>
        @endforelse
        </tbody>
    </table>

    @if($meeting->attachments->count())
        <div class="card" style="margin-top:10px;">
            <div class="card-title">Attachments</div>
            @foreach($meeting->attachments as $a)
                <div class="block-text">• {{ $a->title }}</div>
            @endforeach
        </div>
    @endif

    @if(!empty($signatures['enabled']) && !empty($signatures['slots']))
    @include('partials.print.generated-signatures', ['signatures' => $signatures, 'caption' => $sign['left'], 'cells' => [['line' => optional($meeting->chairperson)->name ?? '', 'role' => $sign['right']]]])
    @else
    <table class="signatures">
        <tr>
            <td style="width:50%;"><div class="line">{{ optional($meeting->recorder)->name ?? ' ' }}</div><div class="role">{{ $sign['left'] }}</div></td>
            <td style="width:50%;"><div class="line">{{ optional($meeting->chairperson)->name ?? ' ' }}</div><div class="role">{{ $sign['right'] }}</div></td>
        </tr>
    </table>
    @endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell"><img src="{{ $qr }}" style="width:64px;height:64px;" alt="QR" /><div class="qr-caption">Scan to open</div></td>
            <td class="foot-cell">{{ $companyName ?? 'RANS Company Limited' }} / Site Meeting {{ $meeting->reference }} · Printed by {{ $printedBy }}, {{ $printedAt->format('d M Y H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>
    @include('site-meetings._print-footer', ['footerRef' => 'Meeting · '.$meeting->reference])
</body>
</html>
