@php
    $tone = ($color ?? '') === '#15803d' ? 'ok' : (in_array($color ?? '', ['#b45309'], true) ? 'warn' : (in_array($color ?? '', ['#b91c1c'], true) ? 'bad' : 'idle'));
    $accent = $state === 'card' ? ($color ?? '#15803d') : ($state === 'notfound' ? '#b91c1c' : '#1e6fd9');
    $icon = match ($word ?? '') { 'VALID' => 'check', 'EXPIRED', 'NOT YET VALID' => 'clock', 'SUSPENDED' => 'pause', 'REVOKED', 'REPLACED', 'REJECTED' => 'cross', default => 'info' };
    $phoneLinks = collect($contact['phones'] ?? [])->map(fn ($p) => ['text' => $p, 'href' => 'tel:' . preg_replace('/[^+0-9]/', '', $p)]);
    $site = trim((string) ($contact['website'] ?? ''));
    $siteHref = $site !== '' ? (preg_match('#^https?://#i', $site) ? $site : 'https://' . $site) : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0b1d36">
    <title>RANS ID Verification</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap">
    <style>
        :root { --navy: #0b1d36; --navy2: #12335a; --gold: #f2a93b; --ink: #0f172a; --mute: #64748b; --accent: {{ $accent }}; --band: {{ $band ?? '#1565c0' }}; --bandink: {{ $bandInk ?? '#fff' }}; --ease: cubic-bezier(.2, .8, .2, 1); --font: Montserrat, ui-rounded, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        html, body, button, input, a, time, dl, dt, dd { font-family: var(--font); }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html { background: var(--navy); }
        body { margin: 0; min-height: 100vh; min-height: 100dvh; font-family: var(--font); color: var(--ink); overflow-x: hidden; position: relative; padding: max(18px, env(safe-area-inset-top)) 14px max(26px, env(safe-area-inset-bottom)); background: radial-gradient(120% 70% at 50% -10%, #1b4778 0%, var(--navy) 62%); }
        /* the backdrop: a drawing board (grid of a site plan) and two soft lights in the colour of the answer */
        .bg { position: fixed; inset: 0; z-index: 0; pointer-events: none; overflow: hidden; }
        .bg::before { content: ''; position: absolute; inset: -40px; background-image: linear-gradient(rgba(255,255,255,.045) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.045) 1px, transparent 1px), linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px); background-size: 96px 96px, 96px 96px, 24px 24px, 24px 24px; animation: drift 38s linear infinite; mask-image: radial-gradient(75% 60% at 50% 35%, #000 30%, transparent 100%); -webkit-mask-image: radial-gradient(75% 60% at 50% 35%, #000 30%, transparent 100%); }
        .bg i { position: absolute; border-radius: 50%; filter: blur(70px); opacity: .5; animation: breathe 9s ease-in-out infinite; }
        .bg i:nth-child(1) { width: 420px; height: 420px; left: -120px; top: -60px; background: var(--accent); }
        .bg i:nth-child(2) { width: 360px; height: 360px; right: -120px; bottom: 80px; background: var(--gold); opacity: .22; animation-delay: -4s; }
        @keyframes drift { to { transform: translate(96px, 96px); } }
        @keyframes breathe { 50% { transform: scale(1.18) translate(14px, -10px); opacity: .32; } }

        /* architecture drawn on the board: a skyline that draws itself, a tower crane at work, a compass of a site plan (all faint, all slow) */
        .arch { position: absolute; fill: none; stroke: #cfe3ff; stroke-width: 1.4; stroke-linecap: round; stroke-linejoin: round; overflow: visible; }
        .arch * { vector-effect: non-scaling-stroke; }
        .arch .d { stroke-dasharray: 1; stroke-dashoffset: 1; animation: bpdraw 11s ease-in-out infinite alternate; }
        .arch .d:nth-child(2n) { animation-delay: -2.2s; animation-duration: 13s; } .arch .d:nth-child(3n) { animation-delay: -4.5s; animation-duration: 9s; } .arch .d:nth-child(5n) { animation-delay: -7s; }
        @keyframes bpdraw { 0% { stroke-dashoffset: 1; } 38%, 100% { stroke-dashoffset: 0; } }
        .sky { left: 50%; bottom: 0; width: max(112vw, 820px); margin-left: calc(max(112vw, 820px) / -2); height: auto; aspect-ratio: 1200 / 320; opacity: .17; animation: skydrift 60s ease-in-out infinite alternate; }
        @keyframes skydrift { to { transform: translateX(-3%); } }
        .crane { right: max(2vw, 8px); top: 4vh; width: min(34vw, 250px); opacity: .15; }
        .crane .jibset { transform-origin: 120px 40px; animation: sway 14s ease-in-out infinite alternate; }
        @keyframes sway { from { transform: rotate(-.9deg); } to { transform: rotate(.9deg); } }
        .crane .trolley { animation: roll 16s ease-in-out infinite alternate; }
        @keyframes roll { to { transform: translateX(-118px); } }
        .crane .rope { transform-box: fill-box; transform-origin: 50% 0; animation: lower 8s ease-in-out infinite alternate; }
        @keyframes lower { from { transform: scaleY(.45); } to { transform: scaleY(1); } }
        .crane .load { animation: lift 8s ease-in-out infinite alternate; }
        @keyframes lift { from { transform: translateY(-62px); } to { transform: translateY(0); } }
        .compass { left: max(-60px, -8vw); top: 7vh; width: min(52vw, 360px); opacity: .13; }
        .compass .spin { transform-origin: 150px 150px; animation: spin 90s linear infinite; }
        .compass .spin.rev { animation-duration: 140s; animation-direction: reverse; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (max-width: 520px) { .crane { opacity: .12; width: 44vw; top: 2vh; } .compass { opacity: .1; } }
        @media (prefers-reduced-motion: reduce) { .arch, .arch * { animation: none !important; } .arch .d { stroke-dashoffset: 0; } }
        .wrap { position: relative; z-index: 1; width: 100%; max-width: 460px; margin: 0 auto; }

        .brand { display: flex; justify-content: center; margin: 4px 0 20px; animation: drop .8s var(--ease) both; }
        /* the logo in gold, no plate: the letters are one layer, the temple is cut into a roof, seven columns and a base that hop in turn every few seconds */
        .mark { position: relative; width: min(236px, 64vw); aspect-ratio: 765 / 263; container-type: inline-size; filter: drop-shadow(0 8px 20px rgba(242, 169, 59, .38)); animation: glow 7s ease-in-out infinite; }
        @keyframes glow { 0%, 100% { filter: drop-shadow(0 8px 18px rgba(242, 169, 59, .3)); } 14% { filter: drop-shadow(0 10px 28px rgba(255, 200, 90, .7)); } }
        .mark .lt, .mark .in { position: absolute; left: 0; top: 0; width: 100cqw; height: calc(100cqw * 263 / 765); background: linear-gradient(105deg, transparent 42%, rgba(255, 249, 220, .95) 50%, transparent 58%) no-repeat, linear-gradient(180deg, #ffdc7e 0%, #f6b53a 52%, #d8890f 100%); background-size: 320% 100%, 100% 100%; background-position: 85% 0, 0 0; animation: glint 7s ease-in-out infinite; }
        .mark .lt { -webkit-mask: url('{{ asset('images/rans-mark-letters.png') }}') center / 100% 100% no-repeat; mask: url('{{ asset('images/rans-mark-letters.png') }}') center / 100% 100% no-repeat; animation-delay: .35s; }
        .mark .in { -webkit-mask: url('{{ asset('images/rans-mark-temple.png') }}') center / 100% 100% no-repeat; mask: url('{{ asset('images/rans-mark-temple.png') }}') center / 100% 100% no-repeat; }
        .mark .sl { position: absolute; overflow: hidden; } /* each slice shows only its own piece of the temple */
        @keyframes glint { 0%, 8% { background-position: 85% 0, 0 0; } 30%, 100% { background-position: 8% 0, 0 0; } }
        .mark .col { animation: hop 7s cubic-bezier(.3, 1.4, .5, 1) infinite; animation-delay: calc(var(--i) * 90ms); }
        .mark .roof { animation: lift 7s cubic-bezier(.3, 1.3, .5, 1) infinite; animation-delay: .75s; }
        @keyframes hop { 0%, 14%, 100% { transform: translateY(0); } 4% { transform: translateY(-3.4cqw); } 9% { transform: translateY(.5cqw); } }
        @keyframes lift { 0%, 16%, 100% { transform: translateY(0) rotate(0); } 4% { transform: translateY(-5.5cqw) rotate(-1.2deg); } 9% { transform: translateY(.7cqw) rotate(.5deg); } 12% { transform: translateY(-.8cqw); } }
        @media (prefers-reduced-motion: reduce) { .mark, .mark * { animation: none !important; } }
        @keyframes drop { from { opacity: 0; transform: translateY(-26px) scale(.9); } }
        @keyframes rise { from { opacity: 0; transform: translateY(22px); } }

        /* the flipping card */
        .stage { perspective: 1500px; animation: rise .9s .1s var(--ease) both; }
        .flip { display: grid; transform-style: preserve-3d; transition: transform .9s var(--ease); cursor: pointer; }
        .flip.is-back { transform: rotateY(180deg); }
        .face { grid-area: 1 / 1; backface-visibility: hidden; -webkit-backface-visibility: hidden; background: #fff; border-radius: 26px; overflow: hidden; box-shadow: 0 40px 70px -30px rgba(0,0,0,.75), 0 0 0 1px rgba(255,255,255,.18); position: relative; }
        .face.rear { transform: rotateY(180deg); background: linear-gradient(165deg, #fff 0%, #f4f8fd 100%); }
        .flip:not(.is-back) .rear, .flip.is-back .front { pointer-events: none; }
        .flip:not(.is-back) .rear { visibility: hidden; transition: visibility 0s .45s; } .flip.is-back .front { visibility: hidden; transition: visibility 0s .45s; } .flip.is-back .rear, .flip:not(.is-back) .front { visibility: visible; }

        .status { position: relative; padding: 26px 20px 22px; text-align: center; color: #fff; overflow: hidden; background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 80%, #fff 0%), color-mix(in srgb, var(--accent) 62%, #000)); }
        .status::before { content: ''; position: absolute; inset: 0; background: repeating-linear-gradient(115deg, rgba(255,255,255,.07) 0 14px, transparent 14px 34px); animation: stripes 6s linear infinite; }
        .status::after { content: ''; position: absolute; top: -40%; bottom: -40%; width: 40%; left: -60%; background: linear-gradient(100deg, transparent, rgba(255,255,255,.28), transparent); transform: skewX(-18deg); animation: sheen 5.5s 1.2s ease-in-out infinite; }
        @keyframes stripes { to { background-position: 68px 0; } }
        @keyframes sheen { 0%, 55% { left: -60%; } 100% { left: 130%; } }
        .seal { position: relative; z-index: 1; width: 74px; height: 74px; margin: 0 auto 10px; }
        .seal::before, .seal::after { content: ''; position: absolute; inset: 0; border-radius: 50%; border: 2px solid rgba(255,255,255,.55); animation: ring 2.6s ease-out infinite; }
        .seal::after { animation-delay: 1.3s; }
        @keyframes ring { from { transform: scale(.85); opacity: .9; } to { transform: scale(1.6); opacity: 0; } }
        .seal svg { position: relative; width: 100%; height: 100%; display: block; filter: drop-shadow(0 8px 14px rgba(0,0,0,.28)); }
        .seal .disc { fill: rgba(255,255,255,.2); stroke: #fff; stroke-width: 2.4; stroke-dasharray: 220; stroke-dashoffset: 220; animation: draw .9s .35s var(--ease) forwards; }
        .seal .mark { fill: none; stroke: #fff; stroke-width: 5; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 60; stroke-dashoffset: 60; animation: draw .6s .95s var(--ease) forwards; }
        @keyframes draw { to { stroke-dashoffset: 0; } }
        .status b { position: relative; z-index: 1; display: block; font-size: 2.15rem; letter-spacing: .12em; font-weight: 900; text-shadow: 0 3px 12px rgba(0,0,0,.25); animation: pop .7s .7s var(--ease) both; }
        .status span.say { position: relative; z-index: 1; display: block; margin-top: 6px; font-size: .94rem; opacity: .96; animation: rise .7s .9s var(--ease) both; }
        @keyframes pop { from { opacity: 0; transform: scale(.7); letter-spacing: .4em; } }

        .live { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 8px 12px; font-size: .74rem; font-weight: 700; letter-spacing: .04em; color: var(--mute); background: #f6f9fc; border-bottom: 1px solid #e8eef5; }
        .live i { width: 8px; height: 8px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 0 rgba(34,197,94,.6); animation: ping 1.8s infinite; }
        @keyframes ping { 70% { box-shadow: 0 0 0 9px rgba(34,197,94,0); } 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); } }
        .live time { font-variant-numeric: tabular-nums; color: var(--ink); }

        .front { display: flex; flex-direction: column; } .front .body { flex: 1; display: flex; flex-direction: column; padding: 20px 20px 22px; }
        .rear { display: flex; flex-direction: column; } .rear .in { flex: 1; display: flex; flex-direction: column; }
        /* the sign-off at the foot of each side */
        .tagline { margin-top: auto; padding-top: 22px; display: flex; align-items: center; justify-content: center; gap: 12px; animation: rise .7s 2.1s var(--ease) both; }
        .tagline::before, .tagline::after { content: ''; flex: 1; height: 1px; max-width: 70px; background: linear-gradient(90deg, transparent, #e0b25c); } .tagline::after { transform: scaleX(-1); }
        .tagline b { flex: none; font-size: .98rem; font-weight: 800; letter-spacing: .26em; text-transform: uppercase; white-space: nowrap; background: linear-gradient(105deg, #b9770e 20%, #f6b53a 40%, #ffe29a 50%, #f6b53a 60%, #b9770e 80%) 120% 0 / 250% 100% repeat-x; -webkit-background-clip: text; background-clip: text; color: transparent; -webkit-text-fill-color: transparent; animation: tagshine 6s 3s ease-in-out infinite; }
        @keyframes tagshine { 0%, 15% { background-position: 120% 0; } 45%, 100% { background-position: -20% 0; } }
        .tagline i { width: 7px; height: 7px; transform: rotate(45deg); background: #e0b25c; flex: none; }
        .who { display: flex; gap: 16px; align-items: center; margin-bottom: 18px; animation: rise .7s 1.05s var(--ease) both; }
        .avatar { position: relative; flex: none; width: 92px; height: 112px; border-radius: 18px; padding: 3px; background: linear-gradient(150deg, var(--band), color-mix(in srgb, var(--band) 40%, #fff)); box-shadow: 0 16px 24px -14px color-mix(in srgb, var(--band) 80%, #000); }
        .avatar img, .avatar .mono { width: 100%; height: 100%; border-radius: 15px; object-fit: cover; display: grid; place-items: center; background: #dde5ee; }
        .avatar .ghost { background: linear-gradient(160deg, #f4f7fb, #e3eaf4); } .avatar .ghost img { width: 72%; height: auto; border-radius: 0; background: none; object-fit: contain; filter: drop-shadow(0 6px 8px rgba(70, 60, 140, .25)); }
        .avatar .mono { background: linear-gradient(160deg, var(--band), color-mix(in srgb, var(--band) 60%, #000)); color: var(--bandink); font-weight: 900; font-size: 2rem; letter-spacing: .04em; }
        .who h1 { margin: 0; font-size: 1.32rem; line-height: 1.2; letter-spacing: -.01em; }
        .who p { margin: 5px 0 0; color: #475569; font-size: .92rem; line-height: 1.4; }
        .chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 9px; }
        .chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px; border-radius: 999px; font-size: .74rem; font-weight: 800; letter-spacing: .02em; }
        .chip.cat { background: var(--band); color: var(--bandink); box-shadow: 0 8px 14px -8px var(--band); }
        .chip.kind { background: #eef3f9; color: #1e4467; }

        .meter { margin: 4px 0 18px; padding: 14px 14px 12px; border-radius: 18px; background: linear-gradient(160deg, #f7fafd, #eef3f9); box-shadow: inset 0 0 0 1px #e3eaf2; animation: rise .7s 1.2s var(--ease) both; }
        .meter .top { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; margin-bottom: 9px; font-size: .74rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--mute); }
        .meter .top b { font-size: 1.05rem; letter-spacing: 0; text-transform: none; color: var(--accent); font-variant-numeric: tabular-nums; }
        .track { position: relative; height: 12px; border-radius: 999px; background: #dfe7f0; overflow: hidden; }
        .track i { position: absolute; left: 0; top: 0; bottom: 0; width: 0; border-radius: 999px; background: linear-gradient(90deg, color-mix(in srgb, var(--accent) 70%, #fff), var(--accent)); transition: width 1.6s 1.4s var(--ease); }
        .track i::after { content: ''; position: absolute; inset: 0; background: repeating-linear-gradient(115deg, rgba(255,255,255,.28) 0 8px, transparent 8px 16px); animation: stripes 1.4s linear infinite; }
        .meter .ends { display: flex; justify-content: space-between; gap: 10px; margin-top: 9px; font-size: .78rem; color: #475569; } .meter .ends b { display: block; color: var(--ink); font-size: .84rem; } .meter .ends div:last-child { text-align: right; }

        dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 0 14px; font-size: .94rem; }
        dt, dd { padding: 10px 0; border-bottom: 1px dashed #dbe4ee; animation: rise .6s var(--ease) both; }
        dt { color: var(--mute); } dd { margin: 0; font-weight: 700; text-align: right; overflow-wrap: anywhere; }
        dt:nth-of-type(1), dd:nth-of-type(1) { animation-delay: 1.3s; } dt:nth-of-type(2), dd:nth-of-type(2) { animation-delay: 1.4s; } dt:nth-of-type(3), dd:nth-of-type(3) { animation-delay: 1.5s; } dt:nth-of-type(4), dd:nth-of-type(4) { animation-delay: 1.6s; } dt:nth-of-type(5), dd:nth-of-type(5) { animation-delay: 1.7s; }
        dl > :nth-last-child(-n+2) { border-bottom: 0; }
        .idno { font-variant-numeric: tabular-nums; letter-spacing: .08em; font-size: 1.05rem; background: none; border: 0; color: inherit; font-weight: 800; cursor: copy; padding: 2px 10px; border-radius: 10px; transition: background .2s; } .idno:hover { background: #eef3f9; }
        .note { margin-top: 16px; padding: 11px 13px; border-radius: 14px; background: #f1f5f9; color: #475569; font-size: .8rem; line-height: 1.5; animation: rise .6s 1.8s var(--ease) both; } .note a { color: #1e4467; font-weight: 800; }
        .note.warn { background: #fff7ed; color: #9a3412; }

        .flipcue { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 14px; padding: 9px; border-radius: 14px; background: #f6f9fc; color: #1e4467; font-size: .78rem; font-weight: 800; animation: rise .6s 1.9s var(--ease) both; }
        .flipcue svg { width: 18px; height: 18px; animation: nudge 2.4s ease-in-out infinite; } @keyframes nudge { 40%, 60% { transform: translateX(5px) rotate(14deg); } }

        /* the back */
        .rear .head { position: relative; padding: 24px 22px 18px; color: #fff; background: linear-gradient(140deg, #12335a, var(--navy)); overflow: hidden; }
        .rear .head::after { content: ''; position: absolute; right: -30px; top: -30px; width: 150px; height: 150px; border-radius: 50%; background: radial-gradient(circle, rgba(242,169,59,.55), transparent 70%); }
        .rear .head small { display: block; font-size: .7rem; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; color: var(--gold); }
        .rear .head h2 { margin: 4px 0 6px; font-size: 1.35rem; } .rear .head p { margin: 0; font-size: .86rem; line-height: 1.5; opacity: .9; max-width: 34ch; }
        .rear .in { padding: 18px 20px 22px; }
        .terms { list-style: none; margin: 0 0 16px; padding: 0; display: grid; gap: 8px; counter-reset: t; }
        .terms li { position: relative; padding: 9px 12px 9px 44px; border-radius: 13px; background: #fff; box-shadow: 0 0 0 1px #e6edf5; font-size: .86rem; line-height: 1.4; counter-increment: t; opacity: 0; transform: translateX(-14px); transition: opacity .45s var(--ease), transform .45s var(--ease); }
        .terms li::before { content: counter(t); position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 24px; height: 24px; border-radius: 8px; display: grid; place-items: center; font-size: .74rem; font-weight: 900; background: color-mix(in srgb, var(--gold) 22%, #fff); color: #9a5b00; }
        .is-back .terms li { opacity: 1; transform: none; }
        .is-back .terms li:nth-child(1) { transition-delay: .5s; } .is-back .terms li:nth-child(2) { transition-delay: .58s; } .is-back .terms li:nth-child(3) { transition-delay: .66s; } .is-back .terms li:nth-child(4) { transition-delay: .74s; } .is-back .terms li:nth-child(5) { transition-delay: .82s; } .is-back .terms li:nth-child(6) { transition-delay: .9s; } .is-back .terms li:nth-child(7) { transition-delay: .98s; } .is-back .terms li:nth-child(8) { transition-delay: 1.06s; } .is-back .terms li:nth-child(n+9) { transition-delay: 1.14s; }
        .found { display: flex; gap: 12px; align-items: flex-start; padding: 13px 14px; border-radius: 16px; background: linear-gradient(150deg, #fff7e8, #fff); box-shadow: inset 0 0 0 1px #f6dcaa; font-size: .84rem; line-height: 1.5; color: #7a4a05; }
        .found svg { flex: none; width: 24px; height: 24px; color: #d9831a; margin-top: 1px; } .found b { display: block; color: #5c3704; }
        .checks { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 14px; }
        .checks div { text-align: center; padding: 10px 6px; border-radius: 14px; background: #f1f6fb; font-size: .72rem; font-weight: 800; color: #1e4467; line-height: 1.3; } .checks svg { width: 22px; height: 22px; display: block; margin: 0 auto 5px; color: var(--accent); }

        .toggle { position: relative; display: grid; grid-template-columns: 1fr 1fr; width: 220px; margin: 16px auto 0; padding: 4px; border-radius: 999px; background: rgba(255,255,255,.1); box-shadow: inset 0 0 0 1px rgba(255,255,255,.18); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); animation: rise .8s .5s var(--ease) both; }
        .toggle button { position: relative; z-index: 1; border: 0; background: none; padding: 9px 0; font: inherit; font-size: .84rem; font-weight: 800; color: rgba(255,255,255,.75); cursor: pointer; transition: color .3s; }
        .toggle button[aria-pressed="true"] { color: var(--navy); }
        .toggle .pill { position: absolute; z-index: 0; top: 4px; bottom: 4px; left: 4px; width: calc(50% - 4px); border-radius: 999px; background: #fff; box-shadow: 0 8px 16px -8px rgba(0,0,0,.6); transition: transform .5s var(--ease); }
        .toggle.is-back .pill { transform: translateX(100%); }

        .msg { padding: 40px 24px 36px; text-align: center; } .msg h1 { margin: 14px 0 8px; font-size: 1.35rem; } .msg p { margin: 0; color: #475569; line-height: 1.5; }
        .msg .seal { margin: 0 auto; width: 84px; height: 84px; } .msg .seal::before, .msg .seal::after { border-color: color-mix(in srgb, var(--accent) 50%, transparent); } .msg .seal .disc { fill: color-mix(in srgb, var(--accent) 14%, #fff); stroke: var(--accent); } .msg .seal .mark { stroke: var(--accent); }
        a.btn { display: inline-block; margin-top: 18px; padding: 12px 24px; border-radius: 999px; background: linear-gradient(135deg, #1e6fd9, #12335a); color: #fff; text-decoration: none; font-weight: 800; box-shadow: 0 14px 22px -12px #1e6fd9; transition: transform .2s; } a.btn:hover { transform: translateY(-2px) scale(1.03); }

        /* RANS contacts */
        .contacts { margin-top: 22px; animation: rise .8s .9s var(--ease) both; }
        .contacts h3 { margin: 0 0 10px; text-align: center; font-size: .7rem; font-weight: 800; letter-spacing: .2em; text-transform: uppercase; color: rgba(255,255,255,.6); }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; }
        .c { display: flex; align-items: center; gap: 10px; min-width: 0; padding: 11px 12px; border-radius: 16px; text-decoration: none; color: #fff; background: rgba(255,255,255,.08); box-shadow: inset 0 0 0 1px rgba(255,255,255,.14); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); transition: transform .25s var(--ease), background .25s, box-shadow .25s; animation: rise .6s var(--ease) both; }
        .grid .c:nth-child(1) { animation-delay: 1.1s; } .grid .c:nth-child(2) { animation-delay: 1.18s; } .grid .c:nth-child(3) { animation-delay: 1.26s; } .grid .c:nth-child(4) { animation-delay: 1.34s; } .grid .c:nth-child(5) { animation-delay: 1.42s; } .grid .c:nth-child(6) { animation-delay: 1.5s; }
        a.c:hover, a.c:focus-visible { transform: translateY(-3px); background: rgba(255,255,255,.16); box-shadow: inset 0 0 0 1px rgba(255,255,255,.35), 0 16px 24px -14px rgba(0,0,0,.7); outline: none; }
        .c .ico { flex: none; display: grid; place-items: center; width: 34px; height: 34px; border-radius: 11px; background: color-mix(in srgb, var(--gold) 26%, transparent); color: var(--gold); } .c .ico svg { width: 18px; height: 18px; }
        .c span.t { display: grid; min-width: 0; font-size: .8rem; line-height: 1.3; } .c small { font-size: .62rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: rgba(255,255,255,.55); } .c b { font-weight: 700; overflow-wrap: anywhere; }
        .c.wide { grid-column: 1 / -1; }
        .foot { text-align: center; color: rgba(255,255,255,.5); font-size: .72rem; margin-top: 18px; animation: rise .8s 1.6s var(--ease) both; }
        .toast { position: fixed; left: 50%; bottom: 22px; transform: translate(-50%, 30px); padding: 10px 18px; border-radius: 14px; background: #fff; color: var(--ink); font-weight: 800; font-size: .84rem; box-shadow: 0 20px 36px -16px rgba(0,0,0,.7); opacity: 0; transition: .3s var(--ease); z-index: 5; pointer-events: none; } .toast.on { opacity: 1; transform: translate(-50%, 0); }

        @media (max-width: 400px) { .tagline { gap: 8px; } .tagline b { font-size: .84rem; letter-spacing: .18em; } }
        @media (max-width: 380px) { .grid { grid-template-columns: 1fr; } .who { gap: 12px; } .avatar { width: 80px; height: 98px; } .status b { font-size: 1.85rem; } }
        @media (min-width: 700px) { body { padding-top: 34px; } .wrap { max-width: 480px; } }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .001s !important; animation-delay: 0s !important; transition-duration: .001s !important; transition-delay: 0s !important; } .seal .disc, .seal .mark { stroke-dashoffset: 0; } }
        :focus-visible { outline: 3px solid #f2a93b; outline-offset: 2px; }
    </style>
</head>
<body>
<div class="bg" aria-hidden="true"><i></i><i></i><svg class="arch compass" viewBox="0 0 300 300" aria-hidden="true"><g class="spin"><circle cx="150" cy="150" r="128" stroke-dasharray="4 7"/><circle cx="150" cy="150" r="104"/><path d="M150 22V36" transform="rotate(0 150 150)"/><path d="M150 22V36" transform="rotate(15 150 150)"/><path d="M150 22V36" transform="rotate(30 150 150)"/><path d="M150 22V36" transform="rotate(45 150 150)"/><path d="M150 22V36" transform="rotate(60 150 150)"/><path d="M150 22V36" transform="rotate(75 150 150)"/><path d="M150 22V36" transform="rotate(90 150 150)"/><path d="M150 22V36" transform="rotate(105 150 150)"/><path d="M150 22V36" transform="rotate(120 150 150)"/><path d="M150 22V36" transform="rotate(135 150 150)"/><path d="M150 22V36" transform="rotate(150 150 150)"/><path d="M150 22V36" transform="rotate(165 150 150)"/><path d="M150 22V36" transform="rotate(180 150 150)"/><path d="M150 22V36" transform="rotate(195 150 150)"/><path d="M150 22V36" transform="rotate(210 150 150)"/><path d="M150 22V36" transform="rotate(225 150 150)"/><path d="M150 22V36" transform="rotate(240 150 150)"/><path d="M150 22V36" transform="rotate(255 150 150)"/><path d="M150 22V36" transform="rotate(270 150 150)"/><path d="M150 22V36" transform="rotate(285 150 150)"/><path d="M150 22V36" transform="rotate(300 150 150)"/><path d="M150 22V36" transform="rotate(315 150 150)"/><path d="M150 22V36" transform="rotate(330 150 150)"/><path d="M150 22V36" transform="rotate(345 150 150)"/></g><g class="spin rev"><circle cx="150" cy="150" r="70" stroke-dasharray="2 5"/><path d="M150 70L170 150L150 230L130 150Z"/><path d="M70 150L150 130L230 150L150 170Z"/></g><path d="M150 0V300M0 150H300" stroke-dasharray="2 8"/><circle cx="150" cy="150" r="5"/></svg><svg class="arch crane" viewBox="0 0 300 360" aria-hidden="true"><g class="jibset"><path class="d" pathLength="1" d="M110 360V40 M130 360V40 M110 360L130 330 L110 300 L130 270 L110 240 L130 210 L110 180 L130 150 L110 120 L130 90 L110 60 L130 40"/><path class="d" pathLength="1" d="M20 40H292 M20 54H292 M20 54L60 40 L100 54 L140 40 L180 54 L220 40 L260 54 L292 40"/><path class="d" pathLength="1" d="M120 40V8 M120 8L20 40 M120 8L292 40"/><path class="d" pathLength="1" d="M14 40H56V76H14Z"/><g class="trolley"><path d="M250 54h10v8h-10z"/><g class="rope"><path d="M255 62V140"/></g><g class="load"><path d="M255 140l-12 12M255 140l12 12M241 152h28v22h-28z"/></g></g></g></svg><svg class="arch sky" viewBox="0 0 1200 320" preserveAspectRatio="xMidYMax meet" aria-hidden="true"><path class="d" pathLength="1" d="M0 320H1200"/><path class="d" pathLength="1" d="M40 320V120H140V320 M40 150H140 M40 180H140 M40 210H140 M40 240H140 M40 270H140 M40 300H140 M73 120V320 M107 120V320"/><path class="d" pathLength="1" d="M170 320V80L200 30L230 80V320 M170 110H230 M170 150H230 M170 190H230 M170 230H230 M170 270H230 M200 30V6"/><path class="d" pathLength="1" d="M280 320V210H500V320 M270 210L390 152L510 210Z M300 210V320 M335 210V320 M370 210V320 M410 210V320 M445 210V320 M480 210V320 M375 320V270H405V320"/><path class="d" pathLength="1" d="M540 320V160H600V120H660V160H720V320 M540 200H720 M540 240H720 M540 280H720 M570 160V320 M630 120V320 M690 160V320"/><path class="d" pathLength="1" d="M760 320V60H850V320 M760 100H850 M760 140H850 M760 180H850 M760 220H850 M760 260H850 M760 60L850 100 M760 100L850 140 M760 140L850 180 M760 180L850 220"/><path class="d" pathLength="1" d="M880 320V240A60 60 0 0 1 1000 240V320 M900 320V250A40 40 0 0 1 980 250V320 M940 180V150 M930 150H950"/><path class="d" pathLength="1" d="M1030 320V150H1160V320 M1030 190H1160 M1030 230H1160 M1030 270H1160 M1060 150V320 M1095 150V320 M1130 150V320 M1030 150L1095 110L1160 150"/></svg></div>
<div class="wrap">
    <div class="brand"><div class="mark" role="img" aria-label="RANS Building &amp; Civil Contractor"><i class="lt"></i><b class="sl roof" style="left:22.745cqw;top:0.0cqw;width:36.601cqw;height:10.065cqw"><i class="in" style="left:-22.745cqw;top:-0.0cqw"></i></b><b class="sl col" style="left:22.745cqw;top:10.065cqw;width:7.32cqw;height:11.765cqw;--i:0"><i class="in" style="left:-22.745cqw;top:-10.065cqw"></i></b><b class="sl col" style="left:30.065cqw;top:10.065cqw;width:4.444cqw;height:11.765cqw;--i:1"><i class="in" style="left:-30.065cqw;top:-10.065cqw"></i></b><b class="sl col" style="left:34.51cqw;top:10.065cqw;width:4.183cqw;height:11.765cqw;--i:2"><i class="in" style="left:-34.51cqw;top:-10.065cqw"></i></b><b class="sl col" style="left:38.693cqw;top:10.065cqw;width:4.314cqw;height:11.765cqw;--i:3"><i class="in" style="left:-38.693cqw;top:-10.065cqw"></i></b><b class="sl col" style="left:43.007cqw;top:10.065cqw;width:4.314cqw;height:11.765cqw;--i:4"><i class="in" style="left:-43.007cqw;top:-10.065cqw"></i></b><b class="sl col" style="left:47.32cqw;top:10.065cqw;width:4.444cqw;height:11.765cqw;--i:5"><i class="in" style="left:-47.32cqw;top:-10.065cqw"></i></b><b class="sl col" style="left:51.765cqw;top:10.065cqw;width:7.582cqw;height:11.765cqw;--i:6"><i class="in" style="left:-51.765cqw;top:-10.065cqw"></i></b><b class="sl base" style="left:22.745cqw;top:21.83cqw;width:36.601cqw;height:4.314cqw"><i class="in" style="left:-22.745cqw;top:-21.83cqw"></i></b></div></div>

@if($state === 'card')
    <div class="stage">
        <div class="flip" id="flip" role="group" aria-label="{{ __('ID verification card') }}" tabindex="0">
            <section class="face front" aria-live="polite">
                <div class="status">
                    <div class="seal">
                        <svg viewBox="0 0 80 80" aria-hidden="true"><circle class="disc" cx="40" cy="40" r="35"/>
                            @if($icon === 'check')<path class="mark" d="M25 41 l10 10 l21 -23"/>
                            @elseif($icon === 'cross')<path class="mark" d="M28 28 L52 52 M52 28 L28 52"/>
                            @elseif($icon === 'pause')<path class="mark" d="M32 27 V53 M48 27 V53"/>
                            @elseif($icon === 'clock')<path class="mark" d="M40 24 V41 L51 48"/>
                            @else<path class="mark" d="M40 36 V53 M40 27 V28"/>@endif
                        </svg>
                    </div>
                    <b>{{ $word }}</b>
                    <span class="say">{{ $note }}</span>
                </div>
                <div class="live"><i></i>{{ __('Live check') }} &middot; <time id="clock">--:--:--</time></div>
                <div class="body">
                    <div class="who">
                        <div class="avatar">@if($photo)<img src="{{ $photo }}" alt="">@else<div class="mono ghost" aria-hidden="true"><img src="{{ asset('images/avatars/person-gradient.png') }}" alt=""></div>@endif</div>
                        <div>
                            <h1>{{ $holder }}</h1>
                            @if($title)<p>{{ $title }}</p>@endif
                            <div class="chips"><span class="chip cat">{{ $category ?: $kind }}</span>@if($category && $category !== $kind)<span class="chip kind">{{ $kind }}</span>@endif</div>
                        </div>
                    </div>
                    <div class="meter">
                        <div class="top"><span>{{ __('Validity') }}</span><b id="days" data-to="{{ $daysLeft ?? '' }}">@if($hasEnd){{ $daysLeft >= 0 ? $daysLeft . ' ' . __('days left') : __('Ended') }}@else{{ __('No expiry') }}@endif</b></div>
                        <div class="track"><i id="bar" data-pct="{{ $tone === 'ok' || $tone === 'warn' ? $pct : 100 }}"></i></div>
                        <div class="ends"><div><small>{{ __('Issued') }}</small><b>{{ $issued }}</b></div><div><small>{{ __('Valid until') }}</small><b>{{ $expires }}</b></div></div>
                    </div>
                    <dl>
                        <dt>{{ __('ID number') }}</dt><dd><button type="button" class="idno" id="copy" data-n="{{ $number }}" title="{{ __('Tap to copy') }}">{{ $number }}</button></dd>
                        @if($vehicle)<dt>{{ __('Vehicle') }}</dt><dd>{{ $vehicle }}</dd>@endif
                        @if($host)<dt>{{ __('Person visited') }}</dt><dd>{{ $host }}</dd>@endif
                        @if($replacedBy)<dt>{{ __('Replaced by') }}</dt><dd>{{ $replacedBy }}</dd>@endif
                    </dl>
                    @if($restrictions)<div class="note warn"><b>{{ __('Restrictions') }}:</b> {{ $restrictions }}</div>@endif
                    @if(! $full)<div class="note">{{ __('Details such as the photograph and the full name are shown to RANS staff who are signed in to WIMS.') }}@if(! $signedIn)<br><a href="{{ url('/login') }}">{{ __('Sign in') }}</a>@endif</div>@endif
                    <div class="flipcue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>{{ __('Tap the card to see the back') }}</div>
                    <div class="tagline"><i></i><b>{{ __('Your Contractor') }}</b><i></i></div>
                </div>
            </section>

            <section class="face rear" aria-label="{{ __('Back of the card') }}">
                <div class="head"><small>RANS &middot; {{ $kind }}</small><h2>{{ __('About this ID') }}</h2><p>{{ $intro }}</p></div>
                <div class="in">
                    <ol class="terms">@foreach($terms as $t)<li>{{ $t }}</li>@endforeach</ol>
                    <div class="found"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg><div><b>{{ __('Found this ID?') }}</b>{{ __('Please return it to RANS') }}@if(! empty($contact['address'])) &middot; {{ $contact['address'] }}@endif @if(! empty($contact['pobox'])) &middot; {{ $contact['pobox'] }}@endif</div></div>
                    <div class="checks">
                        <div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/></svg>{{ __('Photo matches the person') }}</div>
                        <div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg>{{ __('This page says VALID') }}</div>
                        <div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10"/></svg>{{ __('Number matches the card') }}</div>
                    </div>
                    <div class="tagline"><i></i><b>{{ __('Your Contractor') }}</b><i></i></div>
                </div>
            </section>
        </div>
    </div>
    <div class="toggle" id="toggle" role="group" aria-label="{{ __('Side of the card') }}"><span class="pill"></span><button type="button" data-side="front" aria-pressed="true">{{ __('Front') }}</button><button type="button" data-side="back" aria-pressed="false">{{ __('Back') }}</button></div>
@else
    @php
        $mTitle = ['login' => __('Sign in to verify'), 'off' => __('Verification is switched off'), 'notfound' => __('ID not found')][$state] ?? __('ID not found');
        $mText = ['login' => __('This ID can only be checked by RANS staff who are signed in to WIMS.'), 'off' => __('Please contact RANS reception to check this ID.'), 'notfound' => __('This code does not belong to a RANS ID or pass. Do not accept it.')][$state] ?? '';
    @endphp
    <div class="stage"><div class="flip" style="cursor:default"><section class="face front">
        <div class="msg">
            <div class="seal"><svg viewBox="0 0 80 80" aria-hidden="true"><circle class="disc" cx="40" cy="40" r="35"/>@if($state === 'notfound')<path class="mark" d="M28 28 L52 52 M52 28 L28 52"/>@elseif($state === 'login')<path class="mark" d="M30 38 V33 a10 10 0 0 1 20 0 V38 M28 38 H52 V54 H28 Z"/>@else<path class="mark" d="M40 36 V53 M40 27 V28"/>@endif</svg></div>
            <h1>{{ $mTitle }}</h1><p>{{ $mText }}</p>
            @if($state === 'login')<a class="btn" href="{{ url('/login') }}">{{ __('Sign in') }}</a>@endif
        </div>
    </section></div></div>
@endif

    <div class="contacts">
        <h3>{{ __('RANS contacts') }}</h3>
        <div class="grid">
            @foreach($phoneLinks as $p)
                <a class="c" href="{{ $p['href'] }}"><span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></svg></span><span class="t"><small>{{ __('Call') }}</small><b>{{ $p['text'] }}</b></span></a>
            @endforeach
            @if(! empty($contact['email']))<a class="c" href="mailto:{{ $contact['email'] }}"><span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg></span><span class="t"><small>{{ __('Email') }}</small><b>{{ $contact['email'] }}</b></span></a>@endif
            @if($siteHref)<a class="c" href="{{ $siteHref }}" target="_blank" rel="noopener"><span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.2 3 14.8 0 18M12 3c-3 3.2-3 14.8 0 18"/></svg></span><span class="t"><small>{{ __('Website') }}</small><b>{{ $site }}</b></span></a>@endif
            @if(! empty($contact['address']) || ! empty($contact['pobox']))<a class="c wide" href="https://www.google.com/maps/search/?api=1&query={{ urlencode('RANS Building & Civil Contractor ' . ($contact['address'] ?? '')) }}" target="_blank" rel="noopener"><span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg></span><span class="t"><small>{{ __('Visit') }}</small><b>{{ $contact['address'] ?? '' }}@if(! empty($contact['pobox'])) <br>{{ $contact['pobox'] }}@endif</b></span></a>@endif
        </div>
    </div>
    <div class="foot">RANS Building &amp; Civil Contractor &middot; {{ __('ID verification') }}</div>
</div>
<div class="toast" id="toast" role="status"></div>
<script>
(function () {
    var flip = document.getElementById('flip'), tog = document.getElementById('toggle');
    var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    // the clock proves the page is live (a photograph of an old screen cannot tick)
    var clock = document.getElementById('clock');
    function tick() { if (clock) { var d = new Date(); clock.textContent = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }); } }
    tick(); setInterval(tick, 1000);
    // the meter fills and the days count up once the page has settled
    var bar = document.getElementById('bar');
    if (bar) setTimeout(function () { bar.style.width = (bar.getAttribute('data-pct') || 0) + '%'; }, 60);
    var days = document.getElementById('days');
    if (days && !calm && days.getAttribute('data-to') !== '' && +days.getAttribute('data-to') > 0) {
        var to = +days.getAttribute('data-to'), t0 = null, label = days.textContent.replace(/^\s*\d+/, '');
        days.textContent = '0' + label;
        var final = to + label; setTimeout(function () { days.textContent = final; }, 3300); // never left half counted
        setTimeout(function () { requestAnimationFrame(function step(ts) { if (t0 === null) t0 = ts; var p = Math.min(1, (ts - t0) / 1500); days.textContent = Math.round(to * (1 - Math.pow(1 - p, 3))) + label; if (p < 1) requestAnimationFrame(step); }); }, 1400);
    }
    if (flip && tog) {
        var buttons = tog.querySelectorAll('button');
        function show(side) {
            var back = side === 'back';
            flip.classList.toggle('is-back', back); tog.classList.toggle('is-back', back);
            buttons.forEach(function (b) { b.setAttribute('aria-pressed', String((b.getAttribute('data-side') === 'back') === back)); });
            if (navigator.vibrate) navigator.vibrate(12);
            window.scrollTo({ top: 0, behavior: calm ? 'auto' : 'smooth' });
        }
        buttons.forEach(function (b) { b.addEventListener('click', function (e) { e.stopPropagation(); show(b.getAttribute('data-side')); }); });
        flip.addEventListener('click', function (e) { if (e.target.closest('a, button')) return; show(flip.classList.contains('is-back') ? 'front' : 'back'); });
        flip.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowRight' || e.key === 'ArrowLeft') { e.preventDefault(); show(flip.classList.contains('is-back') ? 'front' : 'back'); } });
    }
    var copy = document.getElementById('copy'), toast = document.getElementById('toast');
    if (copy) copy.addEventListener('click', function (e) {
        e.stopPropagation();
        var n = copy.getAttribute('data-n'), done = function () { toast.textContent = n + ' copied'; toast.classList.add('on'); setTimeout(function () { toast.classList.remove('on'); }, 1600); };
        if (navigator.clipboard) navigator.clipboard.writeText(n).then(done, done); else done();
    });
})();
</script>
</body>
</html>
