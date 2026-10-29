{{--
    The Holiday Greetings email. Everything is drawn in code, nothing is a photo or a GIF:
      - Montserrat for every word (loaded from Google Fonts where the mail app allows web fonts, Segoe UI / Helvetica / Arial otherwise);
      - the RANS logo: the real R and NS lettering (two small PNG cut-outs of the logo) with the temple between them built in HTML and CSS, so it can build itself;
      - flat tiles with soft shadows and glow; the icons are inline SVG with a text-symbol twin for the mail apps that cannot draw SVG (Gmail, Outlook);
      - CSS animation (glow, float, columns rising, bricks being laid) in the mail apps that run it (Apple Mail, iPhone, Samsung, Thunderbird); everywhere else the same design
        shows as a finished, still picture.
    Table based with inline styles (Outlook and Gmail ignore anything else).

    Params: subject, headline, subline, paragraphs[{text, quote}], greeting, palette{primary, secondary, accent, soft}, icon, wishes[[icon, word]], holidayName, dateText,
            dayName, dayNum, monthName, monthOnly, bg{file, url}|null, signoff, company, year
--}}
@php
    use App\Services\HolidayGreetings\GreetingIcons;

    $p = $palette;
    $mailMessage = $message ?? null;   // only a real send has a mail message to embed the two logo cut-outs in
    $img = function ($file) use ($mailMessage) {
        $path = public_path('images/holiday/' . $file);
        if (! file_exists($path)) {
            return null;
        }

        return $mailMessage ? $mailMessage->embed($path) : 'data:image/png;base64,' . base64_encode(file_get_contents($path));
    };
    $logoR = $img('logo-r.png');
    $logoNS = $img('logo-ns.png');
    $f = "'Montserrat', 'Segoe UI', Helvetica, Arial, sans-serif";
    $sym = "'Segoe UI Symbol', 'Apple Symbols', 'Noto Sans Symbols', Arial, sans-serif";
    $gold = '#e9dd0e';    // the gold of the RANS logo
    $navy = '#142a52';    // its navy
    $ic = fn (string $name, int $size) => GreetingIcons::html($name, $size);
    $cols = 7;
    $softHex = $p['soft'];
    // the photo behind the hero (black and white at 15%, already laid over the hero colour): a real send links it by its public address (the reader's mail app loads it),
    // the preview inside WIMS inlines it. Without a photo the hero is the flat colour.
    $bgSrc = null;
    if (! empty($bg) && is_file($bg['file'])) {
        $bgSrc = $mailMessage ? $bg['url'] : 'data:image/jpeg;base64,' . base64_encode(file_get_contents($bg['file']));
    }
    // the colours of the calendar page follow the colours of the hero it sits on
    $shade = function (string $hex, float $f) {
        $h = ltrim($hex, '#');
        [$r, $g, $b] = [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
        $mix = fn ($c) => max(0, min(255, (int) round($f >= 0 ? $c + (255 - $c) * $f : $c * (1 + $f))));

        return sprintf('#%02x%02x%02x', $mix($r), $mix($g), $mix($b));
    };
    $accentDeep = $shade($p['accent'], -0.28);
    $pageTone = $shade($p['soft'], 0.35);
    $pageEdge = $shade($p['soft'], -0.18);
    $ring = $shade($p['soft'], -0.05);
    $ringEdge = $shade($p['primary'], 0.18);
@endphp
<!DOCTYPE html>
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<meta name="x-apple-disable-message-reformatting">
<title>{{ $subject }}</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,500;1,600&display=swap" rel="stylesheet">
<!--[if mso]><style>.fb { display:inline-block !important; mso-hide:none !important; } * { font-family: Arial, sans-serif !important; }</style><![endif]-->
<style>
@import url('https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,500;1,600&display=swap');
body, table, td, p, h1, span, div, a { font-family: {!! $f !!}; }
.fb { display: none; max-height: 0; overflow: hidden; mso-hide: all; }
u + .body .ic-svg { display: none !important; }
u + .body .fb { display: inline-block !important; max-height: none !important; }
.a-glow { animation: hgGlow 3.4s ease-in-out infinite; }
.a-float { animation: hgFloat 3.6s ease-in-out infinite; }
.a-col { transform-origin: bottom; animation: hgRise 9s ease-out infinite; }
.a-roof { animation: hgRoof 9s ease-out infinite; }
.a-brick { animation: hgBrick 10s ease-out infinite; }
.a-twinkle { animation: hgTwinkle 2.6s ease-in-out infinite; }
@keyframes hgGlow { 0%, 100% { box-shadow: 0 0 0 0 {{ $p['accent'] }}00, 0 0 22px 2px {{ $p['accent'] }}55; } 50% { box-shadow: 0 0 0 9px {{ $p['accent'] }}22, 0 0 42px 8px {{ $p['accent'] }}99; } }
@keyframes hgFloat { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-7px); } }
@keyframes hgRise { 0%, 58% { transform: scaleY(1); } 62% { transform: scaleY(0); } 74%, 100% { transform: scaleY(1); } }
@keyframes hgRoof { 0%, 58% { transform: translateY(0); opacity: 1; } 60% { transform: translateY(-34px); opacity: 0; } 76% { transform: translateY(-34px); opacity: 0; } 84%, 100% { transform: translateY(0); opacity: 1; } }
@keyframes hgBrick { 0%, 78% { transform: translateY(0); opacity: 1; } 80% { transform: translateY(-16px); opacity: 0; } 86%, 100% { transform: translateY(0); opacity: 1; } }
@keyframes hgTwinkle { 0%, 100% { opacity: .35; } 50% { opacity: 1; } }
@media (prefers-reduced-motion: reduce) { .a-glow, .a-float, .a-col, .a-roof, .a-brick, .a-twinkle { animation: none !important; } }
@media only screen and (max-width: 480px) { .pad { padding-left: 20px !important; padding-right: 20px !important; } .h1 { font-size: 27px !important; } .tile-cell { display: block !important; width: 100% !important; padding: 0 0 10px !important; } }
</style>
</head>
<body class="body" style="margin:0; padding:0; background-color:{{ $softHex }}; -webkit-text-size-adjust:100%; font-family:{!! $f !!};">
<div style="display:none; max-height:0; overflow:hidden; opacity:0; color:{{ $softHex }}; font-size:1px; line-height:1px;">{{ $headline }}{{ $subline ? ' - ' . $subline : '' }}. {{ __('A warm greeting from :company.', ['company' => $company]) }}</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:{{ $softHex }};">
<tr><td align="center" style="padding:28px 12px 36px;">

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px; background-color:#ffffff; border-radius:32px; overflow:hidden; box-shadow:0 2px 6px rgba(15,23,42,0.06), 0 28px 56px -16px {{ $p['primary'] }}77;">

        @include('emails.partials.holiday-logo', ['logoR' => $logoR, 'logoNS' => $logoNS, 'navy' => $navy, 'gold' => $gold, 'f' => $f, 'cols' => $cols])

        {{-- the hero: one flat colour, a glowing badge, a bold headline, the date as a calendar tile --}}
        <tr>
            <td align="center" bgcolor="{{ $p['primary'] }}" @if($bgSrc) background="{{ $bgSrc }}" @endif class="pad" style="background-color:{{ $p['primary'] }}; @if($bgSrc) background-image:url('{{ $bgSrc }}'); background-size:cover; background-position:center center; background-repeat:no-repeat; @endif padding:42px 30px 80px;">
                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 26px;">
                    <tr><td class="a-glow a-float" align="center" valign="middle" width="112" height="112" style="width:112px; height:112px; border-radius:56px; background-color:{{ $p['accent'] }}; color:{{ $p['primary'] }}; box-shadow:0 0 0 10px {{ $p['accent'] }}26, 0 0 44px 6px {{ $p['accent'] }}77; text-align:center;">{!! $ic($icon, 56) !!}</td></tr>
                </table>
                <h1 class="h1" style="margin:0; font-family:{!! $f !!}; font-size:36px; line-height:1.12; font-weight:800; text-transform:uppercase; letter-spacing:.03em; color:#ffffff;">{{ $headline }}</h1>
                @if($subline)
                    <p style="margin:14px 0 0; font-family:{!! $f !!}; font-size:17px; font-weight:500; font-style:italic; letter-spacing:.02em; color:{{ $p['accent'] }};">{{ $subline }}</p>
                @endif
                {{-- the date as a tear-off calendar page: rings, a header in the accent colour with the year between the two rings and the month below it, the day in a regular weight, the name of the holiday, a page edge below --}}
                <table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:30px auto 0; width:176px;">
                    <tr>
                        <td align="center" style="padding:0 0 0; line-height:0; font-size:0;">
                            <table role="presentation" cellpadding="0" cellspacing="0" width="116" style="width:116px; margin:0 auto -13px; position:relative;"><tr>
                                <td align="left" style="line-height:0; font-size:0;"><div style="width:11px; height:28px; border-radius:6px; background-color:{{ $ring }}; border:2px solid {{ $ringEdge }}; box-shadow:0 4px 8px -2px #00000066;"></div></td>
                                <td align="right" style="line-height:0; font-size:0;"><div style="width:11px; height:28px; border-radius:6px; background-color:{{ $ring }}; border:2px solid {{ $ringEdge }}; box-shadow:0 4px 8px -2px #00000066;"></div></td>
                            </tr></table>
                        </td>
                    </tr>
                    <tr><td align="center" style="padding:9px 10px 11px; background-color:{{ $p['accent'] }}; border-bottom:5px solid {{ $accentDeep }}; border-radius:16px 16px 6px 6px; box-shadow:0 18px 30px -14px #000000aa; font-family:{!! $f !!}; font-size:21px; font-weight:400; letter-spacing:.02em; color:{{ $p['primary'] }};"><div style="margin:0 0 7px; font-family:{!! $f !!}; font-size:12px; line-height:14px; font-weight:400; letter-spacing:.34em; text-indent:.34em; color:{{ $p['primary'] }};">{{ $year }}</div>{{ $monthOnly }}</td></tr>
                    <tr><td align="center" style="padding:6px 10px 0; background-color:{{ $pageTone }}; font-family:{!! $f !!}; font-size:70px; line-height:1.08; font-weight:400; color:{{ $p['primary'] }};">{{ $dayNum }}</td></tr>
                    <tr><td align="center" style="padding:2px 14px 14px; background-color:{{ $pageTone }}; font-family:{!! $f !!}; font-size:11px; line-height:1.5; font-weight:400; letter-spacing:.16em; text-transform:uppercase; color:{{ $p['secondary'] }};">{{ $holidayName }}</td></tr>
                    <tr><td style="height:7px; font-size:0; line-height:0; background-color:{{ $pageEdge }}; border-radius:0 0 12px 12px; box-shadow:0 12px 20px -10px #000000aa;">&nbsp;</td></tr>
                </table>
            </td>
        </tr>

        {{-- the letter on a sheet that rises over the hero --}}
        <tr>
            <td bgcolor="{{ $p['primary'] }}" style="background-color:{{ $p['primary'] }}; padding:0; line-height:0; font-size:0;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:{{ $softHex }}; border-radius:38px 38px 0 0; margin-top:-44px;">
                    <tr>
                        <td class="pad" style="padding:46px 40px 10px; line-height:normal; font-size:medium;">
                            <p style="margin:0 0 20px; font-family:{!! $f !!}; font-size:22px; line-height:1.3; font-weight:700; color:{{ $p['primary'] }};">{{ $greeting }}</p>
                            @foreach($paragraphs as $para)
                                @if($para['quote'])
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:10px 0 28px; background-color:#ffffff; border-radius:22px; overflow:hidden; box-shadow:0 14px 28px -14px {{ $p['primary'] }}66;">
                                        <tr>
                                            <td width="7" style="width:7px; background-color:{{ $p['accent'] }}; font-size:0; line-height:0;">&nbsp;</td>
                                            <td style="padding:22px 26px 24px;">
                                                <span style="display:inline-block; padding:5px 14px; border-radius:999px; background-color:{{ $p['primary'] }}; font-family:{!! $f !!}; font-size:9.5px; font-weight:800; letter-spacing:.2em; text-transform:uppercase; color:{{ $p['accent'] }};">{{ __('A wish for you') }}</span>
                                                <p style="margin:14px 0 0; font-family:{!! $f !!}; font-size:17px; line-height:1.65; font-weight:600; font-style:italic; color:{{ $p['primary'] }};">&ldquo;{{ $para['text'] }}&rdquo;</p>
                                            </td>
                                        </tr>
                                    </table>
                                @else
                                    <p style="margin:0 0 18px; font-family:{!! $f !!}; font-size:15px; line-height:1.9; font-weight:400; color:#3b3f4a;">{{ $para['text'] }}</p>
                                @endif
                            @endforeach
                        </td>
                    </tr>

                    @if(count($wishes))
                    <tr>
                        <td class="pad" align="center" style="padding:8px 40px 6px; line-height:normal; font-size:medium;">
                            <p style="margin:0 0 6px; font-family:{!! $f !!}; font-size:11px; font-weight:800; letter-spacing:.22em; text-transform:uppercase; color:{{ $p['secondary'] }};">{{ __('May this day bring you') }}</p>
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 18px;"><tr><td width="44" height="4" style="width:44px; height:4px; background-color:{{ $p['accent'] }}; border-radius:4px; font-size:0; line-height:0;">&nbsp;</td></tr></table>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    @foreach($wishes as $i => $w)
                                    <td class="tile-cell" width="33%" valign="top" style="padding:0 {{ $i === 0 ? '8px 0 0' : ($i === count($wishes) - 1 ? '0 0 8px' : '0 8px') }};">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:22px; box-shadow:0 14px 26px -14px {{ $p['primary'] }}88;">
                                            <tr><td align="center" style="padding:20px 8px 18px;">
                                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;"><tr><td class="a-float" align="center" valign="middle" width="54" height="54" style="width:54px; height:54px; border-radius:18px; background-color:{{ $p['accent'] }}; color:{{ $p['primary'] }}; box-shadow:0 8px 18px -6px {{ $p['accent'] }}; text-align:center; animation-delay:{{ $i * 0.5 }}s;">{!! $ic($w[0], 28) !!}</td></tr></table>
                                                <div style="margin-top:12px; font-family:{!! $f !!}; font-size:11px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; color:{{ $p['primary'] }};">{{ $w[1] }}</div>
                                            </td></tr>
                                        </table>
                                    </td>
                                    @endforeach
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @endif

                    {{-- a wall of bricks, laid brick by brick --}}
                    <tr>
                        <td bgcolor="#ffffff" align="center" style="background-color:#ffffff; padding:30px 20px 4px; line-height:normal; font-size:medium;">
                            @foreach([8, 7] as $row => $n)
                                <table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto;"><tr>
                                    @if($row === 1)<td style="width:20px; font-size:0; line-height:0;">&nbsp;</td>@endif
                                    @for($b = 0; $b < $n; $b++)
                                        <td style="padding:0 2px 4px; line-height:0; font-size:0;"><div class="a-brick" style="width:36px; height:14px; border-radius:4px; background-color:{{ ($b + $row) % 2 === 0 ? $p['accent'] : $p['secondary'] }}; box-shadow:0 3px 0 0 {{ ($b + $row) % 2 === 0 ? $p['accent'] . 'aa' : $p['primary'] . '88' }}; animation-delay:-{{ number_format(10 - ($row * 8 + $b) * 0.14, 2) }}s;"></div></td>
                                    @endfor
                                </tr></table>
                            @endforeach
                        </td>
                    </tr>

                    {{-- the sign-off --}}
                    <tr>
                        <td class="pad" align="center" bgcolor="#ffffff" style="background-color:#ffffff; padding:18px 40px 44px; line-height:normal; font-size:medium;">
                            <p style="margin:0 0 6px; font-family:{!! $f !!}; font-size:14px; font-weight:500; font-style:italic; color:#6b7280;">{{ __('With warm regards,') }}</p>
                            <p style="margin:0; font-family:{!! $f !!}; font-size:21px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:{{ $p['primary'] }};">{{ $signoff }}</p>
                            <p style="margin:8px 0 0; font-family:{!! $f !!}; font-size:10.5px; font-weight:700; letter-spacing:.2em; text-transform:uppercase; color:{{ $p['secondary'] }};">{{ $company }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        {{-- the footer: flat colour, a row of construction icons --}}
        <tr>
            <td align="center" bgcolor="{{ $p['primary'] }}" class="pad" style="background-color:{{ $p['primary'] }}; padding:28px 28px 32px;">
                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 18px;"><tr>
                    @foreach(['hardhat', 'hammer', 'brick', 'ruler', 'house'] as $k => $fi)
                    <td style="padding:0 5px;"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td align="center" valign="middle" width="42" height="42" class="{{ $k % 2 ? 'a-twinkle' : '' }}" style="width:42px; height:42px; border-radius:14px; background-color:{{ $p['secondary'] }}; color:{{ $p['accent'] }}; box-shadow:0 8px 16px -8px #00000088; text-align:center; animation-delay:{{ $k * 0.4 }}s;">{!! $ic($fi, 21) !!}</td></tr></table></td>
                    @endforeach
                </tr></table>
                <p style="margin:0 0 4px; font-family:{!! $f !!}; font-size:13px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#ffffff;">{{ $company }}</p>
                <p style="margin:0 0 14px; font-family:{!! $f !!}; font-size:11px; font-weight:500; color:{{ $p['accent'] }};">Building &amp; Civil Contractors &middot; Fumba Road, Zanzibar &middot; +255 777 700615</p>
                <p style="margin:0; font-family:{!! $f !!}; font-size:10.5px; line-height:1.7; font-weight:400; color:#ffffffb3;">{{ __('You are receiving this greeting because you are a valued friend, colleague or partner of :company.', ['company' => str_replace(' ', "\u{00A0}", $company)]) }}</p>
            </td>
        </tr>
    </table>

    <p style="margin:18px 0 0; font-family:{!! $f !!}; font-size:10.5px; font-weight:500; color:#94a3b8;">{{ __('Sent with care by RANS WIMS') }} &middot; {{ $year }}</p>
</td></tr>
</table>
</body>
</html>
