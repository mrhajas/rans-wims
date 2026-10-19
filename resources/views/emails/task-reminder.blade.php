@include('emails.partials.shell-open', ['emailTitle' => $reminder->name])
@include('emails.partials.header', ['icon' => '⏰', 'accent' => '#4f46e5', 'eyebrow' => __('Task Reminder')])
<tr>
    <td style="padding:28px 28px 8px;">
        <p style="margin:0 0 16px; font-size:15px; color:#111827;">{{ __('Hi :name,', ['name' => $notifiable->name]) }}</p>
        <div style="margin:0 0 12px;">
            <span style="display:inline-block; padding:4px 12px; border-radius:9999px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.03em; background:{{ $priorityColors[$reminder->priority]['bg'] ?? '#dbeafe' }}; color:{{ $priorityColors[$reminder->priority]['text'] ?? '#1d4ed8' }};">
                {{ ucfirst($reminder->priority) }} {{ __('priority') }}
            </span>
            @if($reminder->category)
                <span style="display:inline-block; margin-left:6px; padding:4px 12px; border-radius:9999px; font-size:11px; font-weight:700; background:#eef2ff; color:#4338ca;">
                    {{ $reminder->category }}
                </span>
            @endif
        </div>
        <h1 style="margin:8px 0 4px; font-size:20px; color:#0b1a3a;">{{ $reminder->name }}</h1>
        <p style="margin:0 0 4px; font-size:13px; color:#6b7280;">{{ __('Due') }}: {{ $reminder->due_at->format('D, d M Y \a\t H:i') }}</p>
        @if($reminder->attachments && $reminder->attachments->count())
            <p style="margin:0 0 20px; font-size:13px; color:#6b7280;">{{ __(':count attachment(s) included.', ['count' => $reminder->attachments->count()]) }}</p>
        @else
            <p style="margin:0 0 20px; font-size:13px; color:#6b7280;">&nbsp;</p>
        @endif

        @if($reminder->notes)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc; border:1px solid #eef1f6; border-left:4px solid #4f46e5; border-radius:8px; margin-bottom:20px;">
                <tr>
                    <td style="padding:14px 16px; font-size:13px; color:#374151; line-height:1.6;">
                        {{ $reminder->notes }}
                    </td>
                </tr>
            </table>
        @endif

        <p style="margin:0 0 14px; font-size:13px; color:#4b5563;">{{ __('Click below to view the full reminder or mark it as complete.') }}</p>

        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
            <tr>
                <td style="border-radius:8px; background-color:#4f46e5; background-image:linear-gradient(135deg, #4f46e5 0%, #1e3a8a 100%);">
                    <a href="{{ $url }}" target="_blank" style="display:inline-block; padding:12px 22px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">
                        {{ __('View Reminder') }}
                    </a>
                </td>
            </tr>
        </table>

        <p style="margin:0 0 4px; font-size:12px; color:#9ca3af;">{{ __('You are receiving this because email reminders are enabled on your profile.') }}</p>
    </td>
</tr>
@include('emails.partials.footer')
@include('emails.partials.shell-close')
