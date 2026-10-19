@php($accent = $isEscalation ? '#be123c' : '#b45309')
@include('emails.partials.shell-open', ['emailTitle' => $record->title])
@include('emails.partials.header', ['icon' => '📁', 'accent' => $accent, 'eyebrow' => $isEscalation ? __('Escalation') : __('Compliance Reminder')])
<tr>
    <td style="padding:28px 28px 8px;">
        <p style="margin:0 0 16px; font-size:15px; color:#111827;">{{ __('Hi :name,', ['name' => $notifiable->name]) }}</p>
        <div style="margin:0 0 12px;">
            @if($isEscalation)
                <span style="display:inline-block; padding:4px 12px; border-radius:9999px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.03em; background:#fee2e2; color:#b91c1c;">
                    {{ __('Escalation') }}
                </span>
            @else
                <span style="display:inline-block; padding:4px 12px; border-radius:9999px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.03em; background:#fef3c7; color:#b45309;">
                    {{ __('Reminder') }}
                </span>
            @endif
            <span style="display:inline-block; margin-left:6px; padding:4px 12px; border-radius:9999px; font-size:11px; font-weight:700; background:#eef2ff; color:#4338ca;">
                {{ $record->compliance_type }}
            </span>
        </div>
        <h1 style="margin:8px 0 4px; font-size:20px; color:#0b1a3a;">{{ $record->title }}</h1>
        <p style="margin:0 0 20px; font-size:13px; color:#6b7280;">{{ $record->compliance_number }}</p>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc; border:1px solid #eef1f6; border-left:4px solid {{ $accent }}; border-radius:8px; margin-bottom:20px;">
            <tr>
                <td style="padding:14px 16px; font-size:13px; color:#374151; line-height:1.6;">
                    {{ $message_text }}
                </td>
            </tr>
        </table>

        <p style="margin:0 0 14px; font-size:13px; color:#4b5563;">{{ __('Click below to view the full compliance record and take action.') }}</p>

        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
            <tr>
                <td style="border-radius:8px; background-color:{{ $accent }}; background-image:linear-gradient(135deg, {{ $accent }} 0%, #1e3a8a 100%);">
                    <a href="{{ $url }}" target="_blank" style="display:inline-block; padding:12px 22px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">
                        {{ __('View Compliance Record') }}
                    </a>
                </td>
            </tr>
        </table>

        <p style="margin:0 0 4px; font-size:12px; color:#9ca3af;">{{ __('You are receiving this because compliance reminder emails are enabled on your profile.') }}</p>
    </td>
</tr>
@include('emails.partials.footer')
@include('emails.partials.shell-close')
