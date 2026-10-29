{{--
    The reminder the Super Admins get before a holiday: it is coming, how many people will receive the greeting, and a button to review and send. Same design language as the
    greeting itself (Montserrat, the RANS logo without a container, flat tiles with shadow and glow, icons drawn in code, CSS animation where the mail app runs it).
    Params: title, whenText, recipient, holiday, hours, icon, palette{primary, secondary, accent, soft}, counts{email, sms, duplicates}, url
--}}
@php
    use App\Services\HolidayGreetings\GreetingIcons;

    $p = $palette;
    $mailMessage = $message ?? null;
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
    $gold = '#e9dd0e';
    $navy = '#142a52';
    $cols = 7;
    $ic = fn (string $name, int $size) => GreetingIcons::html($name, $size);
    $tiles = [['email', $counts['email'] ?? 0, __('Emails')], ['sms', $counts['sms'] ?? 0, __('SMS')], ['unity', $counts['duplicates'] ?? 0, __('Repeats removed')]];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<meta name="x-apple-disable-message-reformatting">
<title>{{ $title }}</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,500&display=swap" rel="stylesheet">
<!--[if mso]><style>.fb { display:inline-block !important; mso-hide:none !important; } * { font-family: Arial, sans-serif !important; }</style><![endif]-->
<style>
@import url('https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,500&display=swap');
body, table, td, p, h1, span, div, a { font-family: {!! $f !!}; }
.fb { display: none; max-height: 0; overflow: hidden; mso-hide: all; }
u + .body .ic-svg { display: none !important; }
u + .body .fb { display: inline-block !important; max-height: none !important; }
.a-glow { animation: hgGlow 3.4s ease-in-out infinite; }
.a-float { animation: hgFloat 3.6s ease-in-out infinite; }
.a-col { transform-origin: bottom; animation: hgRise 9s ease-out infinite; }
.a-roof { animation: hgRoof 9s ease-out infinite; }
@keyframes hgGlow { 0%, 100% { box-shadow: 0 0 0 0 {{ $p['accent'] }}00, 0 0 22px 2px {{ $p['accent'] }}55; } 50% { box-shadow: 0 0 0 9px {{ $p['accent'] }}22, 0 0 42px 8px {{ $p['accent'] }}99; } }
@keyframes hgFloat { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-7px); } }
@keyframes hgRise { 0%, 58% { transform: scaleY(1); } 62% { transform: scaleY(0); } 74%, 100% { transform: scaleY(1); } }
@keyframes hgRoof { 0%, 58% { transform: translateY(0); opacity: 1; } 60% { transform: translateY(-34px); opacity: 0; } 76% { transform: translateY(-34px); opacity: 0; } 84%, 100% { transform: translateY(0); opacity: 1; } }
@media (prefers-reduced-motion: reduce) { .a-glow, .a-float, .a-col, .a-roof { animation: none !important; } }
@media only screen and (max-width: 480px) { .pad { padding-left: 20px !important; padding-right: 20px !important; } .tile-cell { display: block !important; width: 100% !important; padding: 0 0 10px !important; } }
</style>
</head>
<body class="body" style="margin:0; padding:0; background-color:{{ $p['soft'] }}; -webkit-text-size-adjust:100%; font-family:{!! $f !!};">
<div style="display:none; max-height:0; overflow:hidden; opacity:0; color:{{ $p['soft'] }}; font-size:1px; line-height:1px;">{{ $title }}. {{ $whenText }}.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:{{ $p['soft'] }};">
<tr><td align="center" style="padding:28px 12px 36px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background-color:#ffffff; border-radius:32px; overflow:hidden; box-shadow:0 2px 6px rgba(15,23,42,0.06), 0 28px 56px -16px {{ $p['primary'] }}77;">

        @include('emails.partials.holiday-logo', ['logoR' => $logoR, 'logoNS' => $logoNS, 'navy' => $navy, 'gold' => $gold, 'f' => $f, 'cols' => $cols])

        <tr>
            <td align="center" bgcolor="{{ $p['primary'] }}" class="pad" style="background-color:{{ $p['primary'] }}; padding:38px 30px 40px;">
                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 20px;">
                    <tr><td class="a-glow a-float" align="center" valign="middle" width="84" height="84" style="width:84px; height:84px; border-radius:42px; background-color:{{ $p['accent'] }}; color:{{ $p['primary'] }}; box-shadow:0 0 0 8px {{ $p['accent'] }}26, 0 0 34px 4px {{ $p['accent'] }}77; text-align:center;">{!! $ic($icon, 42) !!}</td></tr>
                </table>
                <p style="margin:0 0 8px; font-family:{!! $f !!}; font-size:10px; font-weight:800; letter-spacing:.24em; text-transform:uppercase; color:{{ $p['accent'] }};">{{ __('Holiday Greetings') }}</p>
                <h1 style="margin:0; font-family:{!! $f !!}; font-size:26px; line-height:1.2; font-weight:800; text-transform:uppercase; letter-spacing:.03em; color:#ffffff;">{{ $title }}</h1>
                <p style="margin:12px 0 0; font-family:{!! $f !!}; font-size:14px; font-weight:500; color:#ffffffd9;">{{ $whenText }}</p>
            </td>
        </tr>

        <tr>
            <td class="pad" style="padding:34px 36px 8px; background-color:#ffffff;">
                <p style="margin:0 0 14px; font-family:{!! $f !!}; font-size:20px; font-weight:700; color:{{ $p['primary'] }};">{{ __('Hello :name,', ['name' => $recipient]) }}</p>
                <p style="margin:0 0 24px; font-family:{!! $f !!}; font-size:14.5px; line-height:1.85; color:#3b3f4a;">{{ __(':holiday begins in about :hours hours. The greeting for it is ready for you to review: the message, who receives the email and who receives the SMS. You can change the message, leave people out and then send.', ['holiday' => $holiday, 'hours' => $hours]) }}</p>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                    @foreach($tiles as $i => $t)
                    <td class="tile-cell" width="33%" valign="top" style="padding:0 {{ $i === 0 ? '7px 0 0' : ($i === 2 ? '0 0 7px' : '0 7px') }};">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:{{ $p['soft'] }}; border-radius:20px; box-shadow:0 12px 22px -14px {{ $p['primary'] }}88;">
                            <tr><td align="center" style="padding:16px 6px 14px;">
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 8px;"><tr><td align="center" valign="middle" width="40" height="40" style="width:40px; height:40px; border-radius:14px; background-color:{{ $p['accent'] }}; color:{{ $p['primary'] }}; text-align:center;">{!! $ic($t[0], 22) !!}</td></tr></table>
                                <div style="font-family:{!! $f !!}; font-size:26px; line-height:1; font-weight:900; color:{{ $p['primary'] }};">{{ $t[1] }}</div>
                                <div style="margin-top:6px; font-family:{!! $f !!}; font-size:9.5px; font-weight:800; letter-spacing:.14em; text-transform:uppercase; color:{{ $p['secondary'] }};">{{ $t[2] }}</div>
                            </td></tr>
                        </table>
                    </td>
                    @endforeach
                </tr></table>

                @if(!empty($url))
                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:30px auto 8px;">
                    <tr><td align="center" style="border-radius:999px; background-color:{{ $p['primary'] }}; box-shadow:0 14px 24px -10px {{ $p['primary'] }}cc;">
                        <a href="{{ $url }}" target="_blank" style="display:inline-block; padding:15px 34px; font-family:{!! $f !!}; font-size:12px; font-weight:800; letter-spacing:.14em; text-transform:uppercase; color:#ffffff; text-decoration:none;">{{ __('Review and send the greetings') }}</a>
                    </td></tr>
                </table>
                @endif
                <p style="margin:12px 0 26px; font-family:{!! $f !!}; font-size:11px; font-weight:500; text-align:center; color:#94a3b8;">{{ __('Nothing is sent until you press Send in WIMS.') }}</p>
            </td>
        </tr>

        <tr>
            <td align="center" bgcolor="{{ $p['primary'] }}" class="pad" style="background-color:{{ $p['primary'] }}; padding:22px 28px 26px;">
                <p style="margin:0 0 4px; font-family:{!! $f !!}; font-size:12px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#ffffff;">RANS Company Limited</p>
                <p style="margin:0 0 10px; font-family:{!! $f !!}; font-size:10.5px; font-weight:500; color:{{ $p['accent'] }};">Building &amp; Civil Contractors &middot; Fumba Road, Zanzibar &middot; +255 777 700615</p>
                <p style="margin:0; font-family:{!! $f !!}; font-size:10px; line-height:1.7; color:#ffffffb3;">{{ __('This is an automated message from RANS WIMS, please do not reply.') }}</p>
            </td>
        </tr>
    </table>
</td></tr>
</table>
</body>
</html>
