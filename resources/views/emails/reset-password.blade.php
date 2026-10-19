@include('emails.partials.shell-open', ['emailTitle' => __('Reset Your Password')])
@include('emails.partials.header', ['icon' => '🔒', 'accent' => '#0b1a3a'])
<tr>
    <td align="center" style="padding:20px 28px 0;">
        {{-- Animated GIF (not CSS @keyframes, which most desktop/webmail
             clients silently ignore) of the padlock artwork with a soft
             yellow glow pulsing around it. See resources/build-lock-glow.sh
             for how this is generated from resources/lock-icon-source.png. --}}
        <img src="{{ $message->embed(public_path('images/lock-glow.gif')) }}" alt="{{ __('Password reset') }}" width="120" height="120" style="width:120px; height:120px; display:block;" />
    </td>
</tr>
<tr>
    <td align="center" style="padding:12px 28px 0;">
        <h1 style="margin:0; font-size:21px; color:#0b1a3a;">{{ __('Reset Your Password') }}</h1>
    </td>
</tr>
<tr>
    <td style="padding:20px 28px 8px;">
        <p style="margin:0 0 14px; font-size:14px; color:#111827;">{{ __('Hi :name,', ['name' => $notifiable->name]) }}</p>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc; border:1px solid #eef1f6; border-radius:8px; margin-bottom:22px;">
            <tr>
                <td style="padding:14px 16px; font-size:13px; color:#374151; line-height:1.6;">
                    {{ __('You are receiving this email because we received a password reset request for your account.') }}
                </td>
            </tr>
        </table>

        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 22px;">
            <tr>
                <td align="center" style="border-radius:8px; background-color:#2563eb; background-image:linear-gradient(135deg, #2563eb 0%, #1e3a8a 100%);">
                    <a href="{{ $url }}" target="_blank" style="display:inline-block; padding:13px 32px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">
                        {{ __('Reset Password') }}
                    </a>
                </td>
            </tr>
        </table>

        <p style="margin:0 0 20px; font-size:12.5px; color:#6b7280; text-align:center;">
            {{ __('This password reset link will expire in :count minutes.', ['count' => $expireMinutes]) }}
        </p>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #f1f5f9; margin-bottom:16px;">
            <tr><td style="padding-top:16px;"></td></tr>
        </table>

        <p style="margin:0 0 6px; font-size:12px; color:#6b7280;">
            {{ __("If you're having trouble clicking the \":text\" button, copy and paste the URL below into your web browser:", ['text' => __('Reset Password')]) }}
        </p>
        <p style="margin:0 0 20px; font-size:12px; word-break:break-all;">
            <a href="{{ $url }}" target="_blank" style="color:#2563eb; text-decoration:underline;">{{ $url }}</a>
        </p>

        <p style="margin:0 0 4px; font-size:12px; color:#9ca3af;">{{ __('If you did not request a password reset, no further action is required.') }}</p>
    </td>
</tr>
@include('emails.partials.footer')
@include('emails.partials.shell-close')
