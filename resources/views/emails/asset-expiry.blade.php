@include('emails.partials.shell-open', ['emailTitle' => $asset->name])
@include('emails.partials.header', ['icon' => '🔧', 'accent' => '#2563eb', 'eyebrow' => __('Asset Expiry')])
<tr>
    <td style="padding:28px 28px 8px;">
        <p style="margin:0 0 16px; font-size:15px; color:#111827;">{{ __('Hi :name,', ['name' => $notifiable->name]) }}</p>
        <div style="margin:0 0 12px;">
            <span style="display:inline-block; padding:4px 12px; border-radius:9999px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.03em; background:#fef3c7; color:#b45309;">
                {{ __(':days day(s) remaining', ['days' => $daysRemaining]) }}
            </span>
            <span style="display:inline-block; margin-left:6px; padding:4px 12px; border-radius:9999px; font-size:11px; font-weight:700; background:#eef2ff; color:#4338ca;">
                {{ $label }}
            </span>
        </div>
        <h1 style="margin:8px 0 4px; font-size:20px; color:#0b1a3a;">{{ $asset->name }}</h1>
        <p style="margin:0 0 20px; font-size:13px; color:#6b7280;">{{ $asset->asset_number }}</p>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc; border:1px solid #eef1f6; border-left:4px solid #2563eb; border-radius:8px; margin-bottom:20px;">
            <tr>
                <td style="padding:14px 16px; font-size:13px; color:#374151; line-height:1.6;">
                    {{ __(':label for :asset (:number) expires in :days day(s).', [
                        'label' => $label,
                        'asset' => $asset->name,
                        'number' => $asset->asset_number,
                        'days' => $daysRemaining,
                    ]) }}
                </td>
            </tr>
        </table>

        <p style="margin:0 0 14px; font-size:13px; color:#4b5563;">{{ __('Click below to view the full asset record.') }}</p>

        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
            <tr>
                <td style="border-radius:8px; background-color:#2563eb; background-image:linear-gradient(135deg, #2563eb 0%, #1e3a8a 100%);">
                    <a href="{{ $url }}" target="_blank" style="display:inline-block; padding:12px 22px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">
                        {{ __('View Asset') }}
                    </a>
                </td>
            </tr>
        </table>

        <p style="margin:0 0 4px; font-size:12px; color:#9ca3af;">{{ __('You are receiving this because asset expiry emails are enabled on your profile.') }}</p>
    </td>
</tr>
@include('emails.partials.footer')
@include('emails.partials.shell-close')
