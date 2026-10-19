{{--
    Shared "Liquid Glass" email header — logo on a navy-to-indigo gradient
    band, with a colored circular icon badge overlapping the header/body
    seam (a soft "floating glass" touch every WIMS email now shares).
    Included by every email view under resources/views/emails/ and
    resources/views/mail/ so the whole system reads as one product instead
    of a dozen one-off templates.

    Requires $message — Laravel shares this automatically with every mail
    view — to embed the logo as a CID attachment rather than a remote URL,
    since most clients block remote images until "show images" is clicked.

    Params:
      $icon      - emoji shown in the circular badge (default: bell)
      $iconImage - optional image filename under public/images, embedded
                   via CID (not a remote URL/data-URI — those are the ones
                   Gmail and other clients tend to block/strip in email
                   bodies). When set, renders instead of $icon inside a
                   soft accent-colored glow rather than the plain white
                   circle.
      $accent    - hex color for the gradient/badge accent (default: navy)
      $eyebrow   - optional small uppercase label shown under the badge
      $logo      - logo filename under public/images (default: logo-gold.png)
                   — the gold-ink variant reads clearly against every
                   header's dark gradient; the plain navy logo.png is
                   nearly invisible on navy and should only be used on a
                   light background
      $logoHeight - logo max-height in px (default: 64)
--}}
@php
    $icon ??= '🔔';
    $iconImage ??= null;
    $accent ??= '#0b1a3a';
    $logo ??= 'logo-gold.png';
    $logoHeight ??= 64;
    $iconImagePath = $iconImage ? public_path('images/' . $iconImage) : null;
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td align="center" style="background-color:{{ $accent }}; background-image:linear-gradient(135deg, {{ $accent }} 0%, #1e3a8a 100%); padding:30px 24px 40px; border-radius:16px 16px 0 0;">
            @php($logoPath = public_path('images/' . $logo))
            @if(file_exists($logoPath))
                <img src="{{ $message->embed($logoPath) }}" alt="RANS WIMS" style="max-height:{{ $logoHeight }}px; width:auto; display:block; margin:0 auto;" />
            @else
                <span style="color:#ffffff; font-size:17px; font-weight:700; letter-spacing:.02em;">RANS WIMS</span>
            @endif
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:0; line-height:0;">
            @if($iconImagePath && file_exists($iconImagePath))
                <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:-28px;">
                    <tr>
                        <td align="center" valign="middle" style="width:76px; height:76px; background:#ffffff; border-radius:20px; box-shadow:0 0 0 6px {{ $accent }}26, 0 0 26px 6px {{ $accent }}73, 0 6px 16px rgba(15,23,42,0.25);">
                            <img src="{{ $message->embed($iconImagePath) }}" alt="" width="52" style="width:52px; height:auto; display:block; margin:0 auto;" />
                        </td>
                    </tr>
                </table>
            @else
                <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:-28px;">
                    <tr>
                        <td align="center" valign="middle" style="width:60px; height:60px; background:#ffffff; border-radius:9999px; box-shadow:0 4px 14px rgba(15,23,42,0.2); font-size:26px; line-height:60px;">
                            {{ $icon }}
                        </td>
                    </tr>
                </table>
            @endif
        </td>
    </tr>
    @isset($eyebrow)
    <tr>
        <td align="center" style="padding:12px 24px 0; line-height:normal;">
            <span style="display:inline-block; padding:4px 14px; border-radius:9999px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; background:#f1f5f9; color:{{ $accent }};">
                {{ $eyebrow }}
            </span>
        </td>
    </tr>
    @endisset
</table>
