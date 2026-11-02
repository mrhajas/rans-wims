{{--
    The System Health report e-mail. Same shell, header and footer as every WIMS e-mail; one container per part of the system with its rating, figures,
    what can be done and its errors. Table-based and inline-styled (e-mail clients ignore flex, grid and style sheets).
--}}
@php
    $tone = ['excellent' => '#059669', 'very_good' => '#16a34a', 'good' => '#2563eb', 'fair' => '#d97706', 'bad' => '#dc2626', 'unknown' => '#64748b'];
    $soft = ['excellent' => '#d1fae5', 'very_good' => '#dcfce7', 'good' => '#dbeafe', 'fair' => '#fef3c7', 'bad' => '#fee2e2', 'unknown' => '#e2e8f0'];
    $word = ['excellent' => __('Excellent'), 'very_good' => __('Very Good'), 'good' => __('Good'), 'fair' => __('Fair'), 'bad' => __('Bad'), 'unknown' => __('Not rated')];
    $accent = $tone[$report['rating']] ?? '#0b1a3a';
    $verdict = ['excellent' => __('Excellent shape'), 'very_good' => __('In very good shape'), 'good' => __('In good shape'), 'fair' => __('Fair: a few things to fix'), 'bad' => __('Needs attention')][$report['rating']] ?? '';
@endphp
@include('emails.partials.shell-open', ['emailTitle' => __('System Health Report')])
@include('emails.partials.header', ['icon' => '🩺', 'accent' => '#0b1a3a', 'eyebrow' => __('System Health')])
<tr>
    <td style="padding:26px 24px 6px;">
        <h1 style="margin:0 0 4px; font-size:21px; color:#0b1a3a; text-align:center;">{{ __('System Health Report') }}</h1>
        <p style="margin:0 0 20px; font-size:12px; color:#94a3b8; text-align:center;">{{ $report['host'] }} &middot; {{ $report['generated_at'] }} &middot; {{ __('by') }} {{ $report['by'] }}</p>

        {{-- the whole picture --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5eaf2; border-left:5px solid {{ $accent }}; border-radius:12px; margin-bottom:18px; border-collapse:separate; box-shadow:0 6px 16px rgba(15,23,42,0.07); background:#f8fafc;">
            <tr>
                <td width="104" align="center" style="padding:18px 6px 18px 18px;">
                    <div style="width:70px; height:70px; border:7px solid {{ $accent }}; border-radius:50%; background:#ffffff; text-align:center;">
                        <div style="padding-top:15px; font-size:28px; font-weight:800; color:#0b1a3a; line-height:1;">{{ $report['score'] }}</div>
                        <div style="font-size:10px; color:#94a3b8; font-weight:700; padding-top:3px;">/ 100</div>
                    </div>
                </td>
                <td style="padding:18px 18px 18px 10px;">
                    <div style="font-size:11px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:{{ $accent }};">{{ __('Overall health') }}</div>
                    <div style="font-size:18px; font-weight:800; color:#0b1a3a; margin:2px 0 8px;">{{ $verdict }}</div>
                    @foreach($report['counts'] as $k => $n)
                        @if($n > 0)
                            <span style="display:inline-block; margin:0 4px 4px 0; padding:3px 10px; border-radius:99px; background:#ffffff; border:1px solid #e5eaf2; font-size:11px; font-weight:700; color:{{ $tone[$k] }};">&#9679; {{ $n }} {{ $word[$k] }}</span>
                        @endif
                    @endforeach
                </td>
            </tr>
        </table>

        @if(!empty($report['top']))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #fde7c2; border-radius:12px; margin-bottom:18px; border-collapse:separate; background:#fffaf0; box-shadow:0 4px 12px rgba(217,119,6,0.08);">
            <tr><td style="padding:12px 16px 4px; font-size:11px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; color:#b45309;">&#128295; {{ __('Do this first') }}</td></tr>
            @foreach($report['top'] as $i => $a)
            <tr><td style="padding:5px 16px {{ $loop->last ? '14px' : '5px' }};">
                <table role="presentation" cellpadding="0" cellspacing="0"><tr>
                    <td valign="top" style="padding-right:10px;"><span style="display:inline-block; width:20px; height:20px; border-radius:10px; background:{{ $tone[$a['rating']] ?? '#d97706' }}; color:#fff; font-size:11px; font-weight:800; text-align:center; line-height:20px;">{{ $i + 1 }}</span></td>
                    <td style="font-size:13px; color:#374151; line-height:1.45;"><span style="font-size:10px; font-weight:800; color:#94a3b8; text-transform:uppercase; letter-spacing:.04em;">{{ $a['part'] }}</span><br>{{ $a['text'] }}</td>
                </tr></table>
            </td></tr>
            @endforeach
        </table>
        @endif

        {{-- each part of the system --}}
        @foreach($report['parts'] as $p)
        @php($t = $tone[$p['rating']] ?? '#64748b')
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5eaf2; border-left:5px solid {{ $t }}; border-radius:12px; margin-bottom:16px; border-collapse:separate; box-shadow:0 5px 14px rgba(15,23,42,0.06); background:#ffffff;">
            <tr>
                <td style="padding:13px 16px 8px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                        <td style="font-size:15px; font-weight:800; color:#0b1a3a;"><span style="display:inline-block; width:30px; height:30px; border-radius:9px; background:{{ $soft[$p['rating']] ?? '#e2e8f0' }}; text-align:center; line-height:30px; font-size:16px; margin-right:8px; vertical-align:middle;">{{ $p['icon'] }}</span><span style="vertical-align:middle;">{{ $p['title'] }}</span></td>
                        <td align="right" style="white-space:nowrap;"><span style="display:inline-block; padding:4px 11px; border-radius:99px; background:{{ $soft[$p['rating']] ?? '#e2e8f0' }}; color:{{ $t }}; font-size:11px; font-weight:800;">{{ $word[$p['rating']] ?? '' }}@if($p['score'] !== null) &middot; {{ $p['score'] }}@endif</span></td>
                    </tr></table>
                </td>
            </tr>
            @if(!empty($p['figures']))
            <tr><td style="padding:0 16px 8px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #eef1f6; border-radius:8px; border-collapse:separate; overflow:hidden;">
                    @foreach($p['figures'] as $f)
                    <tr>
                        <td style="padding:7px 12px; font-size:12px; color:#6b7280; width:42%; background:{{ $loop->odd ? '#f8fafc' : '#ffffff' }}; border-top:{{ $loop->first ? '0' : '1px solid #f1f4f9' }}; vertical-align:top;">{{ $f[0] }}</td>
                        <td style="padding:7px 12px; font-size:12px; color:#111827; font-weight:700; background:{{ $loop->odd ? '#f8fafc' : '#ffffff' }}; border-top:{{ $loop->first ? '0' : '1px solid #f1f4f9' }}; vertical-align:top;">{{ $f[1] }}</td>
                    </tr>
                    @endforeach
                </table>
            </td></tr>
            @endif
            @if(!empty($p['errors']))
            <tr><td style="padding:2px 16px 8px;">
                <div style="font-size:10px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; color:#dc2626; margin-bottom:4px;">&#9888;&#65039; {{ __('Errors and details') }}</div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #fbd5d5; border-radius:8px; border-collapse:separate; overflow:hidden; background:#fff7f7;">
                    @foreach($p['errors'] as $e)
                    <tr><td style="padding:7px 11px; border-top:{{ $loop->first ? '0' : '1px solid #fbe3e3' }}; font-size:11px; color:#7f1d1d; line-height:1.45;">
                        <b>{{ $e['label'] }}</b>@if($e['count']) &middot; {{ $e['count'] }}&times;@endif @if(!empty($e['at'])) &middot; {{ $e['at'] }}@endif<br>
                        <span style="font-family:Menlo,Consolas,monospace; color:#991b1b; word-break:break-word;">{{ $e['text'] }}</span>
                    </td></tr>
                    @endforeach
                </table>
            </td></tr>
            @endif
            <tr><td style="padding:2px 16px 14px;">
                @if(!empty($p['actions']))
                    <div style="font-size:10px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; color:{{ $t }}; margin-bottom:3px;">&#10148; {{ __('What can be done') }}</div>
                    @foreach($p['actions'] as $a)
                        <div style="font-size:12px; color:#374151; line-height:1.5; padding:1px 0 1px 14px; position:relative;">&bull; {{ $a }}</div>
                    @endforeach
                @else
                    <div style="font-size:11px; font-weight:700; color:#059669;">&#10003; {{ $p['rating'] === 'unknown' ? ($p['note'] ?: __('Not rated.')) : __('Nothing to do. All is well here.') }}</div>
                @endif
                @if(!empty($p['note']) && !empty($p['actions']))
                    <div style="font-size:11px; color:#94a3b8; margin-top:4px;">{{ $p['note'] }}</div>
                @endif
            </td></tr>
        </table>
        @endforeach

        <p style="margin:6px 0 4px; font-size:11px; color:#94a3b8; text-align:center; line-height:1.5;">{{ __('Error text in this report has had e-mail addresses, passwords, keys, query values and server paths removed.') }}<br>{{ __('Sent to') }} {{ $sentTo }}.</p>
    </td>
</tr>
@include('emails.partials.footer', ['companyName' => $companyName])
@include('emails.partials.shell-close')
