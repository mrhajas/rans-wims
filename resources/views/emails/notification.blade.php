{{--
    Generic WIMS notification email — renders every event routed through
    EmailRecipientResolver::notify() (Low Stock Alert digest, Payment
    Voucher Created, Checkins, Checkouts, Transfers, Adjustments, Material
    Requests/Reallocations, Site Reports, Transport events, Executive
    Reports, and any future module wired the same way). See
    App\Notifications\AppNotification::toMail() for how $icon/$accent/
    $eyebrow are resolved from data['module'].
--}}
@include('emails.partials.shell-open', ['emailTitle' => $title])
@include('emails.partials.header', ['icon' => $icon, 'accent' => $accent, 'eyebrow' => $eyebrow, 'iconImage' => $iconImage ?? null])
<tr>
    <td style="padding:28px 28px 8px;">
        <h1 style="margin:0 0 16px; font-size:19px; color:#0b1a3a; text-align:center;">{{ $title }}</h1>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc; border:1px solid #eef1f6; border-left:4px solid {{ $accent }}; border-radius:8px; margin-bottom:22px;">
            <tr>
                <td style="padding:16px 18px; font-size:14px; color:#374151; line-height:1.6; white-space:pre-line;">
                    {{ $message_text }}
                </td>
            </tr>
        </table>

        @if(!empty($details))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #eef1f6; border-radius:8px; margin-bottom:20px; border-collapse:separate; overflow:hidden;">
            @foreach($details as $label => $value)
            <tr>
                <td style="padding:9px 14px; font-size:12px; color:#6b7280; width:38%; border-bottom:1px solid #f1f4f9; background:#f8fafc; vertical-align:top;">{{ $label }}</td>
                <td style="padding:9px 14px; font-size:13px; color:#111827; font-weight:600; border-bottom:1px solid #f1f4f9; vertical-align:top;">{{ $value }}</td>
            </tr>
            @endforeach
        </table>
        @endif

        @if(!empty($items))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #eef1f6; border-radius:8px; margin-bottom:24px; border-collapse:separate; overflow:hidden;">
            <tr style="background:#f8fafc;">
                <td style="padding:8px 12px; font-size:11px; color:#6b7280; font-weight:700; width:28px;">#</td>
                <td style="padding:8px 12px; font-size:11px; color:#6b7280; font-weight:700;">{{ __('Item') }}</td>
                <td style="padding:8px 12px; font-size:11px; color:#6b7280; font-weight:700; text-align:right;">{{ __('Qty') }}</td>
            </tr>
            @foreach($items as $i => $row)
            <tr>
                <td style="padding:7px 12px; font-size:12px; color:#6b7280; border-top:1px solid #f1f4f9;">{{ $i + 1 }}</td>
                <td style="padding:7px 12px; font-size:12px; color:#111827; border-top:1px solid #f1f4f9;">{{ ($row['code'] ?? '') !== '' ? $row['code'] . ' - ' : '' }}{{ $row['name'] ?? '' }}</td>
                <td style="padding:7px 12px; font-size:12px; color:#111827; border-top:1px solid #f1f4f9; text-align:right; white-space:nowrap;">{{ $row['quantity'] ?? '' }} {{ $row['unit'] ?? '' }}</td>
            </tr>
            @endforeach
        </table>
        @endif

        @if(!empty($url))
        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 24px;">
            <tr>
                <td align="center" style="border-radius:8px; background-color:{{ $accent }}; background-image:linear-gradient(135deg, {{ $accent }} 0%, #1e3a8a 100%);">
                    <a href="{{ $url }}" target="_blank" style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">
                        {{ __('View in WIMS') }}
                    </a>
                </td>
            </tr>
        </table>
        @endif

        <p style="margin:0 0 4px; font-size:12px; color:#9ca3af; text-align:center;">
            {{ __("You're receiving this because you're subscribed to this notification on the WIMS Notification page.") }}
        </p>
    </td>
</tr>
@include('emails.partials.footer')
@include('emails.partials.shell-close')
