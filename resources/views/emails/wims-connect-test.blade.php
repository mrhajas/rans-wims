@include('emails.partials.shell-open', ['emailTitle' => __('WIMS test email')])
@include('emails.partials.header', ['icon' => '✉', 'accent' => '#14294e', 'eyebrow' => __('Test message')])
<tr>
    <td style="padding:28px;">
        <p style="margin:0 0 4px; font-size:13px; color:#6b7280;">{{ __('Sent by') }} {{ $sentBy }} @if(!empty($companyName)) &middot; {{ $companyName }} @endif</p>
        <h1 style="margin:0 0 18px; font-size:20px; color:#111827;">{{ __('Hello :name', ['name' => $recipientName]) }}</h1>

        <p style="margin:0 0 18px; font-size:14px; line-height:1.6; color:#374151;">{{ $body }}</p>

        <p style="margin:0; font-size:12.5px; line-height:1.6; color:#9ca3af;">
            {{ __('This is a test from RANS WIMS (WIMS Connect) sent on :when. Nothing is needed from you; if you received it, email delivery to you works.', ['when' => $sentAt->format('d M Y, H:i')]) }}
        </p>
    </td>
</tr>
@include('emails.partials.footer')
@include('emails.partials.shell-close')
