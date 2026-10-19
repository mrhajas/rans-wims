@include('emails.partials.shell-open', ['emailTitle' => __('Your WIMS verification code')])
@include('emails.partials.header', ['icon' => '🔐', 'accent' => '#1d4ed8', 'eyebrow' => __('Security Code')])
<tr>
    <td style="padding:28px 28px 8px;">
        <p style="margin:0 0 4px; font-size:15px; color:#111827;">{{ __('Hello') }}{{ $name ? ' ' . $name : '' }},</p>
        <p style="margin:0 0 20px; font-size:13px; color:#6b7280;">
            {{ $purpose === 'login' ? __('Use this code to finish signing in to WIMS.') : __('Use this code to confirm this email address for two-factor verification.') }}
        </p>
        <style>
            @keyframes otpGlow { 0%,100% { box-shadow: 0 0 0 1px rgba(79,109,245,.35), 0 0 6px rgba(79,109,245,.35); } 50% { box-shadow: 0 0 0 1px rgba(79,109,245,.8), 0 0 16px 3px rgba(79,109,245,.55); } }
            .otp-digit { animation: otpGlow 2.6s ease-in-out infinite; }
        </style>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5ff; border:1px solid #dbe5ff; border-radius:12px;">
            <tr>
                <td align="center" style="padding:24px 12px;">
                    {{-- One inline-block square per digit, written without whitespace between them: selecting or
                         double-clicking copies the whole code as plain "123456" (no tabs or line breaks). --}}
                    <div style="font-size:0; line-height:0; -webkit-user-select:all; user-select:all; white-space:nowrap;">@foreach (str_split((string) $code) as $digit)<span class="otp-digit" style="display:inline-block; width:44px; height:54px; line-height:54px; margin:0 3px; text-align:center; font-size:28px; font-weight:800; color:#0b1a3a; font-family:'Courier New',monospace; background:#ffffff; border:2px solid #4f6df5; border-radius:10px; box-shadow:0 0 0 1px rgba(79,109,245,.55), 0 0 14px 2px rgba(79,109,245,.45);">{{ $digit }}</span>@endforeach</div>
                    <div style="margin-top:14px; font-size:12px; color:#64748b;">{{ __('Tap or select the squares to copy the whole code.') }}</div>
                    <div style="margin-top:6px; font-size:12px; color:#64748b;">{{ __('This code expires in :minutes minutes.', ['minutes' => $minutes]) }}</div>
                </td>
            </tr>
        </table>
        <p style="margin:18px 0 0; font-size:12.5px; color:#64748b;">
            {{ __('Never share this code with anyone, including WIMS staff. If you did not try to sign in, you can ignore this email and consider changing your password.') }}
        </p>
    </td>
</tr>
@include('emails.partials.footer')
@include('emails.partials.shell-close')
