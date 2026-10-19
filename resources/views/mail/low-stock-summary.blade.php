@php
    $hour = (int) now('Africa/Dar_es_Salaam')->format('G');
    $hello = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $t = $digest['totals'];
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
@endphp
@include('emails.partials.shell-open', ['emailTitle' => 'Low Stock Alert'])
@include('emails.partials.header', ['icon' => '📦', 'accent' => '#b45309', 'eyebrow' => 'Low Stock Alert'])
<tr>
    <td style="padding:26px 28px 6px;">
        @if($sample)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:14px;"><tr><td style="padding:8px 12px; border-radius:8px; background:#fef3c7; border:1px dashed #f59e0b; font-size:12px; color:#92400e; text-align:center;"><strong>Sample email.</strong> It shows how the daily Low Stock Alert looks. The figures below are for illustration.</td></tr></table>
        @endif
        <h1 style="margin:0 0 4px; font-size:21px; line-height:1.25; color:#0b1a3a; text-align:center;">Stock of the watched materials</h1>
        <p style="margin:0 0 18px; font-size:12.5px; color:#6b7280; text-align:center;">{{ $generatedAt }}</p>

        <p style="margin:0 0 4px; font-size:14px; color:#111827;">{{ $hello }}{{ $recipientName ? ', '.$recipientName : '' }},</p>
        @if($t['projects'] > 0)
            <p style="margin:0 0 14px; font-size:13.5px; line-height:1.55; color:#374151;">
                @if($t['items'] > 0)
                    <strong style="color:#b45309;">{{ $t['items'] }} item{{ $t['items'] === 1 ? '' : 's' }}</strong> {{ $t['items'] === 1 ? 'is' : 'are' }} at or below the alert level in <strong>{{ $t['with_problem'] }} project{{ $t['with_problem'] === 1 ? '' : 's' }}</strong>.
                    @if($t['out']) <strong style="color:#be123c;">{{ $t['out'] }} {{ $t['out'] === 1 ? 'has' : 'have' }} run out.</strong> @endif
                    Below is the stock of every watched material in every project.
                @else
                    Everything you watch is well stocked. Below is the stock of every watched material in every project.
                @endif
            </p>

            {{-- one slim row of numbers --}}
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:14px;">
                <tr>
                    <td width="24%" align="center" style="padding:8px 4px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:10px;">
                        <span style="font-size:19px; font-weight:800; color:#334155;">{{ $t['projects'] }}</span><br><span style="font-size:9.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#64748b;">Projects</span>
                    </td>
                    <td width="1.3%"></td>
                    <td width="24%" align="center" style="padding:8px 4px; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:10px;">
                        <span style="font-size:19px; font-weight:800; color:#047857;">{{ $t['ok'] }}</span><br><span style="font-size:9.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#047857;">Well stocked</span>
                    </td>
                    <td width="1.3%"></td>
                    <td width="24%" align="center" style="padding:8px 4px; background:#fffbeb; border:1px solid #fde68a; border-radius:10px;">
                        <span style="font-size:19px; font-weight:800; color:#b45309;">{{ $t['low'] }}</span><br><span style="font-size:9.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#92400e;">Running low</span>
                    </td>
                    <td width="1.3%"></td>
                    <td width="24%" align="center" style="padding:8px 4px; background:#fff1f2; border:1px solid #fecdd3; border-radius:10px;">
                        <span style="font-size:19px; font-weight:800; color:#be123c;">{{ $t['out'] }}</span><br><span style="font-size:9.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#9f1239;">Out of stock</span>
                    </td>
                </tr>
            </table>

            @php
                // a group with one item gets its own number column; groups with several items are listed as small chips under the project
                $groupCols = collect($digest['columns'])->groupBy('gi')->map(fn ($c) => ['name' => $c->first()['group'], 'color' => $c->first()['color'], 'cols' => $c->values()->all()])->values();
                $singles = $groupCols->filter(fn ($g) => count($g['cols']) === 1)->values();
                $multis = $groupCols->filter(fn ($g) => count($g['cols']) > 1)->values();
                $span = 1 + $singles->count();
                $sw = 14;
                $tone = fn ($st) => $st === 'out' ? ['#be123c', '#fff1f2'] : ($st === 'low' ? ['#b45309', '#fffbeb'] : ['#111827', null]);
            @endphp
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb; border-radius:10px; border-collapse:separate; overflow:hidden;">
                <tr>
                    <td width="{{ 100 - $sw * $singles->count() }}%" style="padding:7px 8px; background:#0b1a3a; font-size:10.5px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:#ffffff;">Project</td>
                    @foreach($singles as $g)
                        <td width="{{ $sw }}%" align="center" style="padding:7px 4px; background:#0b1a3a; border-bottom:3px solid {{ $g['color'] ?: '#94a3b8' }}; font-size:10px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:#ffffff;">
                            {{ $g['cols'][0]['item'] }}@if($g['cols'][0]['unit'])<br><span style="font-weight:400; color:#93c5fd; text-transform:none; letter-spacing:0;">{{ $g['cols'][0]['unit'] }}</span>@endif
                        </td>
                    @endforeach
                </tr>
                @foreach($digest['projects'] as $i => $p)
                    @php
                        $bg = $i % 2 ? '#f8fafc' : '#ffffff';
                        $chips = [];
                        foreach ($multis as $g) {
                            foreach ($g['cols'] as $c) {
                                if (isset($p['cells'][$c['item_id']])) { $chips[$g['name']][] = [$c, $p['cells'][$c['item_id']]]; }
                            }
                        }
                    @endphp
                    <tr>
                        <td style="padding:7px 8px {{ $chips ? '2px' : '7px' }}; border-top:1px solid #eef1f6; background:{{ $bg }}; font-size:12px; font-weight:700; color:#111827;">{{ $p['name'] }}</td>
                        @foreach($singles as $g)
                            @php $cell = $p['cells'][$g['cols'][0]['item_id']] ?? null; $tn = $cell ? $tone($cell['state']) : null; @endphp
                            <td align="center" style="padding:7px 4px; border-top:1px solid #eef1f6; background:{{ $tn && $tn[1] ? $tn[1] : $bg }}; font-size:13px; font-weight:{{ $cell && $cell['state'] !== 'ok' ? '800' : '600' }}; color:{{ $cell ? $tn[0] : '#cbd5e1' }};" @if($chips) rowspan="2" valign="middle" @endif>{{ $cell ? $fmt($cell['qty']) : '-' }}</td>
                        @endforeach
                    </tr>
                    @if($chips)
                        <tr>
                            <td style="padding:0 8px 8px; background:{{ $bg }}; font-size:10.5px; line-height:2; color:#6b7280;">
                                @foreach($chips as $gname => $list)
                                    @foreach($list as [$c, $cell])
                                        @php $tn = $tone($cell['state']); @endphp<span style="white-space:nowrap; padding:1px 5px; margin-right:2px; border-radius:6px; {{ $tn[1] ? 'background:'.$tn[1].';' : 'background:#f1f5f9;' }} color:{{ $tn[0] }}; font-weight:{{ $cell['state'] !== 'ok' ? '800' : '600' }};"><span style="font-weight:500; color:#6b7280;">{{ $c['short'] }}</span> {{ $fmt($cell['qty']) }}</span>
                                    @endforeach
                                @endforeach
                            </td>
                        </tr>
                    @endif
                @endforeach
            </table>
            <p style="margin:8px 0 0; font-size:10.5px; color:#6b7280; line-height:1.6;">
                <span style="padding:1px 6px; border-radius:5px; background:#fff1f2; color:#be123c; font-weight:800;">0</span> out of stock &nbsp;
                <span style="padding:1px 6px; border-radius:5px; background:#fffbeb; color:#b45309; font-weight:800;">12</span> at or below the alert level &nbsp;
                <span style="color:#cbd5e1; font-weight:800;">-</span> the project does not stock it.
                @if($config['exclude_main_warehouses'] ?? true) Main Warehouses are left out. @endif
                @if(! empty($config['attach_pdf'])) The same overview is attached as a PDF. @endif
            </p>

            <table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:18px auto 6px;">
                <tr><td align="center" style="border-radius:10px; background:#b45309; background-image:linear-gradient(135deg,#d97706,#b45309);">
                    <a href="{{ url('/') }}" style="display:inline-block; padding:11px 28px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:10px;">Open WIMS</a>
                </td></tr>
            </table>
        @else
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 18px;"><tr><td align="center" style="padding:22px 16px; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:12px;">
                <div style="font-size:15px; font-weight:700; color:#065f46;">Nothing to show yet.</div>
                <div style="margin-top:4px; font-size:12.5px; color:#047857;">No project holds any of the watched materials.</div>
            </td></tr></table>
        @endif

        @php($watched = collect($config['groups'] ?? [])->where('enabled', true)->pluck('name')->implode(' · '))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:14px 0 6px; background:#f8fafc; border:1px solid #eef1f6; border-radius:10px;">
            <tr><td style="padding:11px 14px; font-size:11.5px; line-height:1.6; color:#6b7280;">
                <strong style="color:#475569;">Watching:</strong> {{ $watched ?: 'nothing yet' }}.
                You receive this alert because you were chosen under <em>WIMS Notification &rsaquo; Low Stock Alert</em>. The same page changes who is told, how (email or text message), at what time, and which materials and projects are watched.
            </td></tr>
        </table>
    </td>
</tr>
@include('emails.partials.footer')
@include('emails.partials.shell-close')
