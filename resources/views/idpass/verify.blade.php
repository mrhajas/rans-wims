<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>RANS ID Verification</title>
    <style>
        :root { --navy: #1e4467; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: #0f2540; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #0f172a; display: flex; justify-content: center; padding: 18px 14px 28px; }
        .wrap { width: 100%; max-width: 440px; }
        .brand { display: flex; justify-content: center; margin: 4px 0 14px; }
        .brand img { height: 52px; background: #fff; border-radius: 12px; padding: 8px 14px; }
        .card { background: #fff; border-radius: 22px; overflow: hidden; box-shadow: 0 24px 50px -22px rgba(0, 0, 0, .6); }
        .status { padding: 26px 20px 20px; text-align: center; color: #fff; }
        .status b { display: block; font-size: 2.1rem; letter-spacing: .08em; }
        .status span { display: block; margin-top: 6px; font-size: .92rem; opacity: .95; }
        .body { padding: 18px 20px 22px; }
        .who { display: flex; gap: 14px; align-items: center; margin-bottom: 14px; }
        .who img { width: 84px; height: 105px; object-fit: cover; border-radius: 12px; background: #afb3b6; flex: none; }
        .who h1 { margin: 0; font-size: 1.25rem; line-height: 1.25; }
        .who p { margin: 3px 0 0; color: #475569; font-size: .92rem; }
        dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 9px 14px; font-size: .94rem; }
        dt { color: #64748b; } dd { margin: 0; font-weight: 600; text-align: right; overflow-wrap: anywhere; }
        .note { margin-top: 16px; padding: 10px 12px; border-radius: 12px; background: #f1f5f9; color: #475569; font-size: .8rem; line-height: 1.45; }
        .msg { padding: 34px 22px; text-align: center; } .msg h1 { margin: 0 0 8px; font-size: 1.3rem; } .msg p { margin: 0; color: #475569; }
        a.btn { display: inline-block; margin-top: 16px; padding: 11px 20px; border-radius: 999px; background: var(--navy); color: #fff; text-decoration: none; font-weight: 700; }
        .foot { text-align: center; color: #94a3b8; font-size: .75rem; margin-top: 16px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand"><img src="{{ asset('images/logo.png') }}" alt="RANS"></div>
    <div class="card">
    @if($state === 'card')
        <div class="status" style="background: {{ $color }}"><b>{{ $word }}</b><span>{{ $note }}</span></div>
        <div class="body">
            <div class="who">
                @if($photo)<img src="{{ $photo }}" alt="">@endif
                <div><h1>{{ $holder }}</h1><p>{{ $kind }}@if($category && $category !== $kind) &middot; {{ $category }}@endif @if($title) <br>{{ $title }}@endif</p></div>
            </div>
            <dl>
                <dt>ID number</dt><dd>{{ $number }}</dd>
                <dt>Issued</dt><dd>{{ $issued }}</dd>
                <dt>Valid until</dt><dd>{{ $expires }}</dd>
                @if($vehicle)<dt>Vehicle</dt><dd>{{ $vehicle }}</dd>@endif
                @if($host)<dt>Person visited</dt><dd>{{ $host }}</dd>@endif
                @if($replacedBy)<dt>Replaced by</dt><dd>{{ $replacedBy }}</dd>@endif
            </dl>
            @if($restrictions)<div class="note"><b>Restrictions:</b> {{ $restrictions }}</div>@endif
            @if(! $full)<div class="note">Details such as the photograph and the full name are shown to RANS staff who are signed in to WIMS.@if(! $signedIn)<br><a href="{{ url('/login') }}" style="color:#1e4467;font-weight:700">Sign in</a>@endif</div>@endif
        </div>
    @elseif($state === 'login')
        <div class="msg"><h1>Sign in to verify</h1><p>This ID can only be checked by RANS staff who are signed in to WIMS.</p><a class="btn" href="{{ url('/login') }}">Sign in</a></div>
    @elseif($state === 'off')
        <div class="msg"><h1>Verification is switched off</h1><p>Please contact RANS reception to check this ID.</p></div>
    @else
        <div class="msg"><h1>ID not found</h1><p>This code does not belong to a RANS ID or pass. Do not accept it.</p></div>
    @endif
    </div>
    <div class="foot">RANS Building &amp; Civil Contractor &middot; ID verification</div>
</div>
</body>
</html>
