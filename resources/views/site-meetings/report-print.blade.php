<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Minutes - {{ $meeting->display_title }}</title>
    <meta name="color-scheme" content="light">
    @include('partials.print.styles')
    @include('site-meetings._print-style')
    <style>@page { size: A4 landscape; }</style>
    <style>
        .proj { border: 1px solid #c7d2fe; background: #eef2ff; border-radius: 8px; padding: 7px 12px; margin: 0 0 10px; }
        .proj .k { font-size: 7.5px; font-weight: bold; letter-spacing: .6px; text-transform: uppercase; color: #6366f1; }
        .proj .v { font-size: 13px; font-weight: bold; color: #1c3f66; letter-spacing: .2px; }
        .proj .code { font-size: 9px; font-weight: bold; color: #64748b; }
        table.facts { width: 100%; border-collapse: separate; border-spacing: 5px 0; margin: 0 -5px 10px; }
        table.facts td { width: 25%; vertical-align: top; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 9px; }
        table.facts .k { font-size: 7.3px; font-weight: bold; letter-spacing: .5px; text-transform: uppercase; color: #94a3b8; }
        table.facts .v { font-size: 10.2px; font-weight: bold; color: #1f2937; margin-top: 1px; }
        .sec { margin-top: 11px; page-break-inside: auto; }
        .sec.fit { page-break-inside: avoid; break-inside: avoid; }
        thead { display: table-header-group; }
        .sec-head { padding: 0; border-radius: 8px 8px 0 0; font-weight: bold; font-size: 10.5px; letter-spacing: .5px; text-transform: uppercase; page-break-after: avoid; }
        .sec-head table { width: 100%; border-collapse: collapse; }
        .sec-head td { padding: 5px 10px; vertical-align: middle; }
        .sec-head .no { width: 26px; text-align: center; color: #fff; font-size: 11px; border-radius: 8px 0 0 0; }
        .sec-head .cnt { width: 90px; text-align: right; font-size: 8px; font-weight: bold; letter-spacing: .3px; opacity: .85; }
        .sec-hint { font-size: 8.3px; color: #64748b; margin: 3px 2px 4px; font-style: italic; }
        table.min { width: 100%; border-collapse: collapse; font-size: 9.4px; }
        table.min th { background: #f1f5f9; color: #475569; text-align: left; padding: 4px 6px; border: 1px solid #cbd5e1; font-size: 8px; text-transform: uppercase; letter-spacing: .35px; }
        table.min td { padding: 4px 6px; border: 1px solid #e2e8f0; vertical-align: top; white-space: pre-line; word-wrap: break-word; }
        table.min tr.alt td { background: #fafbfc; }
        table.min td.sn { text-align: center; color: #94a3b8; font-weight: bold; font-size: 8.5px; }
        table.min tr.group td { background: #eef2ff; color: #3730a3; font-weight: bold; border-color: #c7d2fe; white-space: normal; font-size: 9.6px; }
        table.min td.empty { color: #9ca3af; font-style: italic; text-align: center; white-space: normal; }
        table.min tr { page-break-inside: avoid; }
        .txt { border: 1px solid #e2e8f0; border-top: 0; padding: 8px 11px; font-size: 10px; line-height: 1.55; white-space: pre-line; color: #1f2937; min-height: 28px; }
        .txt.empty { color: #9ca3af; font-style: italic; }
        .pill { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 8px; font-weight: bold; }
        table.att { width: 100%; border-collapse: collapse; font-size: 9.3px; }
        table.att th { background: #f1f5f9; color: #475569; text-align: left; padding: 4px 7px; border: 1px solid #cbd5e1; font-size: 8px; text-transform: uppercase; }
        table.att td { padding: 4px 7px; border: 1px solid #e2e8f0; }
        .sumline { font-size: 9px; color: #475569; margin: 5px 1px 0; }
        .stamp { margin-top: 14px; padding: 8px 12px; border: 1px solid #d1fae5; background: #ecfdf5; border-radius: 8px; font-size: 9.2px; color: #047857; page-break-inside: avoid; }
        .closing { page-break-inside: avoid; margin-top: 6px; }
        .stamp.draft { border-color: #fde68a; background: #fffbeb; color: #b45309; }
    </style>
</head>
<body>
    @include('partials.print.watermark')
    @include('partials.print.letterhead')

    <?php $rs = $styles[$meeting->report_status] ?? ['label' => $meeting->report_status, 'color' => 'slate']; ?>
    <?php $st = $styles[$meeting->status] ?? ['label' => $meeting->status, 'color' => 'slate']; ?>
    <?php $present = $meeting->attendees->where('attendance', 'Present'); ?>

    <div class="title-card">
        <div class="doc-title">{{ strtoupper($meeting->display_title) }} - MINUTES</div>
        <div class="doc-ref">Date<br><strong>{{ $meeting->meeting_date->format('d.m.Y') }}</strong></div>
    </div>
    <div class="printed-line">Printed by <strong>{{ $printedBy }}</strong> on {{ $printedAt->format('d M Y') }} at {{ $printedAt->format('H:i') }}</div>

    <div class="proj">
        <div class="k">Project</div>
        <div class="v">{{ strtoupper(optional($meeting->warehouse)->name ?? '-') }} @if(optional($meeting->warehouse)->code)<span class="code">&nbsp;({{ $meeting->warehouse->code }})</span>@endif</div>
    </div>

    <table class="facts">
        <tr>
            <td><div class="k">Meeting</div><div class="v">{{ $meeting->display_title }}</div></td>
            <td><div class="k">Date</div><div class="v">{{ $meeting->meeting_date->format('l, d M Y') }}</div></td>
            <td><div class="k">Time</div><div class="v">{{ $meeting->start_time ? substr($meeting->start_time, 0, 5).($meeting->end_time ? ' – '.substr($meeting->end_time, 0, 5) : '') : '—' }}</div></td>
            <td><div class="k">Venue</div><div class="v">{{ $meeting->venue ?: '—' }}</div></td>
        </tr>
    </table>
    <table class="facts" style="margin-top:-4px;">
        <tr>
            <td><div class="k">Chaired by</div><div class="v">{{ optional($meeting->chairperson)->name ?: '—' }}</div></td>
            <td><div class="k">Minutes by</div><div class="v">{{ optional($meeting->recorder)->name ?: '—' }}</div></td>
            <td><div class="k">Reference</div><div class="v">{{ $meeting->reference }}</div></td>
            <td><div class="k">Minutes status</div><div class="v"><span class="pill" style="{{ \App\Support\SiteMeetingColors::pill($rs) }}">{{ $rs['label'] }}</span></div></td>
        </tr>
    </table>

    @if($meeting->attendees->count())
        <div class="sec fit">
            <div class="sec-head" style="background:#ede9fe;color:#6d28d9;"><table><tr><td class="no" style="background:#6d28d9;">A</td><td>Attendance</td><td class="cnt">{{ $present->count() }} / {{ $meeting->attendees->count() }} present</td></tr></table></div>
            <table class="att">
                <thead><tr><th style="width:5%;">S/N</th><th>Name</th><th>Designation</th><th>Organisation</th><th style="width:12%;">Attendance</th></tr></thead>
                <tbody>
                @foreach($meeting->attendees as $i => $a)
                    <?php $col = ['Present' => 'green', 'Absent' => 'red', 'Apology' => 'amber'][$a->attendance] ?? 'slate'; ?>
                    <tr>
                        <td style="text-align:center;color:#94a3b8;font-weight:bold;">{{ $i + 1 }}</td>
                        <td>{{ $a->name }}</td><td>{{ $a->designation ?: '—' }}</td><td>{{ $a->organisation ?: '—' }}</td>
                        <td><span class="pill" style="{{ \App\Support\SiteMeetingColors::pill(['color' => $col]) }}">{{ $a->attendance }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @foreach($sections as $n => $sec)
        <?php
            $rowsOfSection = $rows[$sec['key']] ?? [];
            [$fg, $bg] = \App\Support\SiteMeetingColors::of($sec['color'] ?? 'sky');
            $filled = $sec['type'] === 'text'
                ? (trim($rowsOfSection[0]['text'] ?? '') !== '' ? 1 : 0)
                : collect($rowsOfSection)->filter(fn ($r) => empty($r['_group']) && collect($r)->filter(fn ($v) => trim((string) $v) !== '')->isNotEmpty())->count();
        ?>
        <div class="sec {{ ($sec['type'] === 'text' || count($rowsOfSection) <= 14) ? 'fit' : '' }}">
            <div class="sec-head" style="background:{{ $bg }};color:{{ $fg }};">
                <table><tr>
                    <td class="no" style="background:{{ $fg }};">{{ $n + 1 }}</td>
                    <td>{{ $sec['title'] }}</td>
                    <td class="cnt">{{ $filled ? $filled.($sec['type'] === 'text' ? ' written' : ($filled === 1 ? ' entry' : ' entries')) : 'nothing recorded' }}</td>
                </tr></table>
            </div>
            @if(!empty($sec['hint']))<div class="sec-hint">{{ $sec['hint'] }}</div>@endif
            @if($sec['type'] === 'text')
                @if(trim($rowsOfSection[0]['text'] ?? '') !== '')
                    <div class="txt">{{ $rowsOfSection[0]['text'] }}</div>
                @else
                    <div class="txt empty">Nothing recorded.</div>
                @endif
            @else
                <table class="min">
                    <thead>
                        <tr>
                            <th style="width:4.5%;text-align:center;">S/N</th>
                            @foreach($sec['columns'] as $col)
                                <th @if(!empty($col['width'])) style="width:{{ $col['width'] }}%;" @endif>{{ $col['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                    <?php $i = 0; $g = 0; ?>
                    @forelse($rowsOfSection as $row)
                        @if(!empty($row['_group']))
                            <?php $g++; $i = 0; ?>
                            <tr class="group"><td colspan="{{ count($sec['columns']) + 1 }}">{{ $n + 1 }}.{{ $g }} &nbsp; {{ $row['_group'] }}</td></tr>
                        @else
                            <?php $i++; ?>
                            <tr class="{{ $i % 2 === 0 ? 'alt' : '' }}">
                                <td class="sn">{{ $sec['grouped'] && $g ? ($n + 1).'.'.$g.'.'.$i : $i }}</td>
                                @foreach($sec['columns'] as $col)
                                    <?php $v = $row[$col['key']] ?? ''; ?>
                                    <td>{{ ($col['type'] === 'date' && $v) ? \Illuminate\Support\Carbon::parse($v)->format('d.m.Y') : $v }}</td>
                                @endforeach
                            </tr>
                        @endif
                    @empty
                        <tr><td class="empty" colspan="{{ count($sec['columns']) + 1 }}">Nothing recorded.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach

    <div class="closing">
    @if($meeting->report_status === 'Finalised' && $meeting->report_finalised_at)
        <div class="stamp">These minutes were finalised on {{ $meeting->report_finalised_at->format('d M Y') }} at {{ $meeting->report_finalised_at->format('H:i') }} and are locked.</div>
    @else
        <div class="stamp draft">These minutes are not finalised yet, they may still change. Status: {{ $rs['label'] }}.</div>
    @endif

    @if(!empty($signatures['enabled']))
        @include('partials.print.signature-block', ['sig' => $signatures])
        <div style="font-size:8.5px; color:#64748b; margin-top:4px;">Recorder: {{ optional($meeting->recorder)->name ?? '-' }} &nbsp;&middot;&nbsp; Chairperson: {{ optional($meeting->chairperson)->name ?? '-' }}</div>
    @else
    <table class="signatures" style="margin-top:16px;">
        <tr>
            <td style="width:50%;"><div class="line">{{ optional($meeting->recorder)->name ?? ' ' }}</div><div class="role">{{ $sign['left'] }}</div></td>
            <td style="width:50%;"><div class="line">{{ optional($meeting->chairperson)->name ?? ' ' }}</div><div class="role">{{ $sign['right'] }}</div></td>
        </tr>
    </table>
    @endif

    <table class="footer-row">
        <tr>
            <td class="qr-cell"><img src="{{ $qr }}" style="width:62px;height:62px;" alt="QR" /><div class="qr-caption">Scan to open</div></td>
            <td class="foot-cell">Minutes of {{ $meeting->display_title }} ({{ $meeting->reference }}) · Printed by {{ $printedBy }}, {{ $printedAt->format('d M Y H:i') }}</td>
            <td class="qr-cell"></td>
        </tr>
    </table>

    </div>

    @include('site-meetings._print-footer', ['footerRef' => 'Minutes · '.$meeting->reference])
</body>
</html>
