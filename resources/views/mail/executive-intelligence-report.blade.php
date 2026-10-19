@php
    $hour = (int) now()->format('G');
    $greetingWord = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
@endphp
@include('emails.partials.shell-open', ['emailTitle' => 'Executive Intelligence Report – ' . $weekStart . ' to ' . $weekEnd])
@include('emails.partials.header', ['icon' => '📊', 'accent' => '#334155', 'eyebrow' => 'Executive Report'])
<tr>
    <td style="padding:28px 28px 8px;">
        <h1 style="margin:0 0 4px; font-size:19px; color:#0b1a3a; text-align:center;">Executive Intelligence Report</h1>
        <p style="margin:0 0 20px; font-size:13px; color:#6b7280; text-align:center;">{{ $companyName }}: {{ $weekStart }} to {{ $weekEnd }}</p>

        <p style="margin:0 0 4px; font-size:14px; color:#111827;">{{ $greetingWord }}{{ $recipientName ? ', ' . $recipientName : '' }},</p>
        <p style="margin:0 0 18px; font-size:14px; color:#374151;">Here's your weekly operations briefing. Wishing you a great week ahead!</p>

        @if(!empty($summary['executive_summary']))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2ff; border-left:4px solid #334155; border-radius:8px; margin-bottom:20px;">
            <tr><td style="padding:14px 16px; font-size:13.5px; line-height:1.6; color:#374151;">{{ $summary['executive_summary'] }}</td></tr>
        </table>
        @endif

        @php
            $kpis = [];
            if (isset($metrics['total_inventory_value'])) $kpis[] = ['TSh ' . number_format($metrics['total_inventory_value'], 0), 'Total Inventory Value'];
            if (isset($metrics['consumption_value'])) $kpis[] = ['TSh ' . number_format($metrics['consumption_value']['this_week'], 0), 'Consumed This Week'];
            if (isset($metrics['active_projects'])) $kpis[] = [$metrics['active_projects'], 'Active Projects'];
            if (isset($metrics['month_to_date'])) $kpis[] = ['TSh ' . number_format($metrics['month_to_date']['this_month_to_date'], 0), $metrics['month_to_date']['month_label'] . ' to Date'];
        @endphp
        @if(count($kpis))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
            <tr>
                @foreach($kpis as $i => [$value, $label])
                    @if($i > 0)<td width="2%"></td>@endif
                    <td style="padding:12px; background:#f8fafc; border:1px solid #eef1f6; border-radius:8px;">
                        <div style="font-size:17px; font-weight:700; color:#334155;">{{ $value }}</div>
                        <div style="font-size:10.5px; color:#94a3b8; text-transform:uppercase; letter-spacing:.03em; margin-top:2px;">{{ $label }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
        @endif

        @if(!empty($summary['notable_events']))
            <p style="margin:0 0 8px; font-size:12px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.04em;">Notable Events</p>
            <ul style="margin:0 0 18px; padding-left:18px; font-size:13px; color:#374151;">
                @foreach($summary['notable_events'] as $event)
                    <li style="margin-bottom:6px;">{{ $event }}</li>
                @endforeach
            </ul>
        @endif

        @if(!empty($summary['action_items']))
            <p style="margin:0 0 8px; font-size:12px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.04em;">Recommended Actions</p>
            <ul style="margin:0 0 18px; padding-left:18px; font-size:13px; color:#374151;">
                @foreach($summary['action_items'] as $action)
                    <li style="margin-bottom:6px;">{{ $action }}</li>
                @endforeach
            </ul>
        @endif

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc; border:1px solid #eef1f6; border-radius:8px; margin-bottom:16px;">
            <tr><td style="padding:14px 16px; font-size:13px; color:#4b5563; line-height:1.6;">
                The full detailed report, including the complete project portfolio, workflow pipeline status, BOQ variance flags, site report compliance, and stock health, is attached as a PDF.
            </td></tr>
        </table>

        <p style="margin:0 0 16px; font-size:13px; color:#4b5563;">Have a great week ahead.</p>
    </td>
</tr>
@include('emails.partials.footer')
@include('emails.partials.shell-close')
