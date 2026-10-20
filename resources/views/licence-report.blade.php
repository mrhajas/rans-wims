@php
    /** @var \App\Models\Licence $licence */
    $state = $licence->state;
    $palette = [
        'active' => ['#059669', '#34d399', '#ecfdf5', 'LICENCE ACTIVE'],
        'expiring' => ['#d97706', '#fbbf24', '#fffbeb', 'EXPIRING SOON'],
        'expired' => ['#e11d48', '#fb7185', '#fff1f2', 'LICENCE EXPIRED'],
        'deactivated' => ['#e11d48', '#fb7185', '#fff1f2', 'DEACTIVATED'],
        'scheduled' => ['#2563eb', '#60a5fa', '#eff6ff', 'STARTS SOON'],
        'issued' => ['#2563eb', '#60a5fa', '#eff6ff', 'ISSUED'],
        'superseded' => ['#64748b', '#94a3b8', '#f1f5f9', 'REPLACED'],
    ][$state] ?? ['#059669', '#34d399', '#ecfdf5', 'LICENCE'];
    [$c1, $c2, $soft, $stateLabel] = $palette;
    $live = in_array($state, ['active', 'expiring'], true);
    $elapsed = $licence->elapsed_percent;
    $key = (string) $licence->getRawOriginal('key');
    $blocks = $licence->keyBlocks();
    $footerLabel = $live ? 'Licence Active' : ($state === 'expired' ? 'Licence Expired' : 'Licence '.ucfirst($state));
    $icons = [
        'building' => '<path d="M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"/><path d="M16 9h2a2 2 0 0 1 2 2v10"/><path d="M8 7h4M8 11h4M8 15h4M3 21h18"/>',
        'star' => '<path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1Z"/>',
        'tag' => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8Z"/><path d="M7 7h.01"/>',
        'cal' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
        'headset' => '<path d="M3 14v-2a9 9 0 0 1 18 0v2"/><rect x="2" y="14" width="5" height="7" rx="2"/><rect x="17" y="14" width="5" height="7" rx="2"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'key' => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.3-9.3M16 7l3 3M14 9l2 2"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
    ];
    $svg = fn (string $name, string $color = 'currentColor', int $size = 15) => '<svg viewBox="0 0 24 24" width="'.$size.'" height="'.$size.'" fill="none" stroke="'.$color.'" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'.$icons[$name].'</svg>';
    $cells = [
        ['building', 'Licensed to', $licence->licensee],
        ['star', 'Edition', $licence->edition],
        ['tag', 'Licence type', $licence->licence_type],
        ['cal', 'Valid from', $licence->starts_at->format('d M Y')],
        ['cal', 'Valid until', $licence->expires_at->format('d M Y')],
        ['clock', 'Duration', $licence->duration_label],
        ['users', 'User limit', $licence->max_users ? number_format($licence->max_users) : 'Unlimited'],
        ['headset', 'Support level', $licence->meta['support_level'] ?? 'Standard'],
        ['grid', 'Modules covered', $licence->meta['modules'] ?? 'All modules'],
        ['user', 'Issued by', ($licence->issuedBy->name ?? 'Licence Manager').' · '.optional($licence->issued_at)->format('d M Y')],
        ['check', 'Activated', $licence->applied_at ? $licence->applied_at->format('d M Y').' · '.($licence->appliedBy->name ?? '-') : 'Not yet activated'],
        ['shield', 'Key signature', 'Verified'],
    ];
    $eventNames = ['issued' => 'Issued', 'activated' => 'Activated', 'reactivated' => 'Reactivated', 'deactivated' => 'Deactivated', 'superseded' => 'Replaced', 'file-downloaded' => 'File downloaded', 'imported' => 'File imported', 'printed' => 'Report printed', 'edited' => 'Edited'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WIMS Licence {{ $licence->licence_number }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,400;1,600&display=swap">
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; margin: 0; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        html, body { background: #e8edf5; font-family: 'WIMS Avenir', 'Avenir Next', Avenir, Montserrat, 'Segoe UI', Arial, sans-serif; color: #0f172a; }
        .bar { position: sticky; top: 0; z-index: 5; display: flex; gap: 10px; justify-content: center; padding: 10px; background: #0f172a; }
        .bar button { padding: 8px 18px; border: 0; border-radius: 10px; font: 800 13px 'WIMS Avenir', 'Avenir Next', Avenir, Montserrat, 'Segoe UI', Arial, sans-serif; color: #fff; background: #4f46e5; cursor: pointer; }
        .bar button.alt { background: #334155; }
        .sheet { position: relative; width: 210mm; height: 297mm; margin: 12px auto; padding: 9mm; overflow: hidden; background: #fffdf9; box-shadow: 0 10px 40px rgba(15, 23, 42, .25); }
        .frame { position: relative; height: 100%; padding: 5.5mm 8mm 4mm; border-radius: 6mm; border: .5mm solid {{ $c1 }}; outline: .25mm solid {{ $c2 }}; outline-offset: 1.2mm; overflow: hidden; background: linear-gradient(160deg, #fff 0%, {{ $soft }} 140%); }
        /* watermark layers */
        .wm { position: absolute; inset: 0; pointer-events: none; z-index: 0; }
        .wm-text { position: absolute; left: 50%; top: 52%; transform: translate(-50%, -50%) rotate(-32deg); font-size: 70mm; font-weight: 900; letter-spacing: 4mm; white-space: nowrap; color: transparent; -webkit-text-stroke: .5mm {{ $c1 }}; opacity: .09; }
        .wm-tile { position: absolute; inset: -20mm; transform: rotate(-32deg); opacity: .06; font-size: 4.4mm; font-weight: 800; letter-spacing: 1mm; line-height: 9mm; color: {{ $c1 }}; word-break: break-all; overflow: hidden; }
        .wm-guil { position: absolute; width: 110mm; height: 110mm; opacity: .5; }
        .wm-guil.tl { left: -42mm; top: -42mm; } .wm-guil.br { right: -42mm; bottom: -42mm; }
        .content { position: relative; z-index: 1; display: flex; flex-direction: column; height: 100%; }
        /* header */
        .head { display: flex; align-items: center; justify-content: space-between; gap: 6mm; padding-bottom: 2.4mm; border-bottom: .35mm solid transparent; border-image: linear-gradient(90deg, #16a34a, #f59e0b) 1; }
        .head img { height: 12mm; width: auto; }
        .head .ttl { text-align: right; }
        .head .ttl small { display: block; font-size: 2.6mm; font-weight: 800; letter-spacing: .5mm; text-transform: uppercase; color: #64748b; }
        .head .ttl b { display: block; font-size: 7.4mm; line-height: 1; font-weight: 900; letter-spacing: .4mm; background: linear-gradient(90deg, #16a34a, #f59e0b); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .head .ttl span { font-size: 3mm; font-weight: 700; color: #475569; }
        /* hero */
        .hero { display: grid; grid-template-columns: 44mm 1fr; gap: 5mm; align-items: center; margin-top: 3mm; }
        .seal { position: relative; width: 44mm; height: 44mm; }
        .seal svg { width: 100%; height: 100%; }
        .hero h1 { margin-top: .6mm; font-size: 7.6mm; line-height: 1.05; font-weight: 900; letter-spacing: -.2mm; }
        .hero .no { display: inline-flex; align-items: center; gap: 2mm; padding: 1.2mm 3.2mm; border-radius: 99px; font: 800 3.4mm ui-monospace, Menlo, monospace; color: #fff; background: linear-gradient(90deg, {{ $c1 }}, {{ $c2 }}); }
        .hero p { margin-top: 1.4mm; font-size: 3mm; font-weight: 600; color: #475569; line-height: 1.45; }
        .tags { display: flex; flex-wrap: wrap; gap: 1.4mm; margin-top: 1.8mm; }
        .tags span { padding: .9mm 3mm; border-radius: 99px; font-size: 2.7mm; font-weight: 800; letter-spacing: .3mm; text-transform: uppercase; color: {{ $c1 }}; background: {{ $soft }}; border: .25mm solid {{ $c2 }}; }
        /* timeline */
        .tl { margin-top: 3mm; padding: 2.2mm 4mm 2.2mm; border-radius: 4mm; background: rgba(255, 255, 255, .85); border: .25mm solid #e2e8f0; }
        .tl .track { position: relative; height: 3mm; margin: 5.4mm 0 1.8mm; border-radius: 99px; background: #e2e8f0; }
        .tl .fill { position: absolute; inset: 0 auto 0 0; width: {{ $elapsed }}%; border-radius: inherit; background: linear-gradient(90deg, #16a34a, #f59e0b); }
        .tl .now { position: absolute; top: 50%; left: {{ $elapsed }}%; width: 6mm; height: 6mm; margin: -3mm 0 0 -3mm; border-radius: 50%; background: #fff; border: 1.1mm solid {{ $c1 }}; }
        .tl .now i { position: absolute; bottom: 7mm; {{ $elapsed < 14 ? 'left: -1mm; transform: none;' : ($elapsed > 86 ? 'right: -1mm; left: auto; transform: none;' : 'left: 50%; transform: translateX(-50%);') }} padding: .4mm 2mm; border-radius: 99px; font: 800 2.5mm 'WIMS Avenir', 'Avenir Next', Avenir, Montserrat, 'Segoe UI', Arial, sans-serif; font-style: normal; color: #fff; background: {{ $c1 }}; white-space: nowrap; }
        .tl .ends { display: flex; justify-content: space-between; font-size: 2.9mm; font-weight: 700; color: #64748b; }
        .tl .ends b { display: block; font-size: 3.5mm; color: #0f172a; } .tl .ends div:last-child { text-align: right; }
        .tl .mid { text-align: center; }
        /* details */
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.8mm; margin-top: 3mm; }
        .cell { display: flex; gap: 2.2mm; align-items: center; padding: 1.5mm 2.2mm; border-radius: 3mm; background: rgba(255, 255, 255, .88); border: .25mm solid #e2e8f0; }
        .cell .ic { display: grid; place-items: center; flex: none; width: 6.6mm; height: 6.6mm; border-radius: 2.4mm; color: #fff; }
        .cell small { display: block; font-size: 2.2mm; font-weight: 800; letter-spacing: .35mm; text-transform: uppercase; color: #94a3b8; }
        .cell b { display: block; font-size: 3.05mm; font-weight: 800; line-height: 1.2; color: #0f172a; overflow-wrap: anywhere; }
        .cell:nth-child(6n+1) .ic { background: #4f46e5; } .cell:nth-child(6n+2) .ic { background: #0ea5e9; } .cell:nth-child(6n+3) .ic { background: #7c3aed; }
        .cell:nth-child(6n+4) .ic { background: #059669; } .cell:nth-child(6n+5) .ic { background: #e11d48; } .cell:nth-child(6n) .ic { background: #d97706; }
        /* key */
        .keybox { position: relative; margin-top: 3mm; padding: 2.6mm 3.6mm 2.4mm; border-radius: 4mm; color: #e2e8f0; background: radial-gradient(90mm 40mm at 0% 0%, rgba(99, 102, 241, .35), transparent 70%), linear-gradient(160deg, #0b1226, #111b38); }
        .keybox .kh { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.6mm; }
        .keybox .kh b { display: flex; align-items: center; gap: 1.8mm; font-size: 3.3mm; font-weight: 900; letter-spacing: .5mm; color: #fff; }
        .keybox .kh span { padding: .5mm 2.4mm; border-radius: 99px; font-size: 2.5mm; font-weight: 800; letter-spacing: .3mm; color: #bbf7d0; border: .25mm solid rgba(34, 197, 94, .5); }
        .keyrows { display: grid; gap: .6mm; font-family: ui-monospace, 'JetBrains Mono', Menlo, monospace; }
        .keyrow { display: grid; grid-template-columns: 5mm repeat(4, 1fr); gap: 1.2mm; align-items: center; }
        .keyrow i { font: 700 2.2mm ui-monospace, monospace; font-style: normal; color: #64748b; }
        .keyrow span { padding: .55mm 0; text-align: center; font-size: 3.25mm; font-weight: 800; letter-spacing: .5mm; border-radius: 1.6mm; background: rgba(148, 163, 184, .13); border: .2mm solid rgba(148, 163, 184, .22); }
        .keyrow:nth-child(even) span { color: #fde68a; }
        .keynote { margin-top: 1.4mm; font-size: 2.4mm; color: #94a3b8; }
        /* history */
        .hist { margin-top: 2.6mm; }
        .hist h3 { display: flex; align-items: center; gap: 2mm; margin-bottom: 1.6mm; font-size: 3mm; font-weight: 900; letter-spacing: .4mm; text-transform: uppercase; color: #475569; }
        .hist table { width: 100%; border-collapse: collapse; font-size: 2.6mm; }
        .hist td { padding: .6mm 1.6mm; border-top: .2mm solid #e2e8f0; vertical-align: top; } .hist tr:first-child td { border-top: 0; }
        .hist td:first-child { width: 28mm; font-weight: 800; color: #4f46e5; } .hist td:nth-child(2) { color: #475569; } .hist td:last-child { width: 56mm; white-space: nowrap; text-align: right; color: #64748b; font-weight: 700; }
        .grow { flex: 1; }
        /* footer */
        .foot { display: flex; align-items: flex-end; justify-content: space-between; gap: 4mm; padding-top: 1.8mm; border-top: .35mm solid transparent; border-image: linear-gradient(90deg, #16a34a, #f59e0b) 1; font-size: 2.6mm; color: #64748b; }
        .foot .brand { text-align: center; flex: 1; font-weight: 700; color: #475569; }
        .grad { font-weight: 900; background: linear-gradient(90deg, #16a34a, #f59e0b); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .foot small { display: block; font-size: 2.3mm; font-weight: 600; color: #94a3b8; }
        @media print { html, body { background: #fff; } .bar { display: none; } .sheet { margin: 0; box-shadow: none; } }
    </style>
</head>
<body>
    <div class="bar"><button type="button" onclick="window.print()">Print this report</button><button type="button" class="alt" onclick="window.close()">Close</button></div>
    <div class="sheet">
        <div class="frame">
            <div class="wm" aria-hidden="true">
                <div class="wm-tile">{{ str_repeat('WIMS LICENCE · '.$licence->licence_number.' · ', 60) }}</div>
                <div class="wm-text">WIMS</div>
                @foreach (['tl', 'br'] as $corner)
                    <svg class="wm-guil {{ $corner }}" viewBox="-60 -60 120 120" fill="none">
                        @for ($i = 0; $i < 22; $i++)
                            <ellipse rx="{{ 8 + $i * 2.2 }}" ry="{{ 54 - $i * 1.6 }}" stroke="{{ $i % 2 ? '#f59e0b' : '#16a34a' }}" stroke-width=".25" transform="rotate({{ $i * 8.2 }})" />
                        @endfor
                        <circle r="56" stroke="#16a34a" stroke-width=".4" />
                    </svg>
                @endforeach
            </div>

            <div class="content">
                <header class="head">
                    <img src="{{ asset('images/logo.png') }}" alt="">
                    <div class="ttl"><small>{{ $appName }}</small><b>LICENCE CERTIFICATE</b><span>Version {{ $version }} · Official licence report</span></div>
                </header>

                <section class="hero">
                    <div class="seal">
                        <svg viewBox="0 0 200 200" aria-hidden="true">
                            <defs>
                                <linearGradient id="sg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{{ $c1 }}"/><stop offset="1" stop-color="{{ $c2 }}"/></linearGradient>
                                <path id="arcTop" d="M 100,100 m -69,0 a 69,69 0 1,1 138,0" />
                                <path id="arcBot" d="M 100,100 m -80,0 a 80,80 0 0,0 160,0" />
                            </defs>
                            @for ($i = 0; $i < 48; $i++)
                                <line x1="100" y1="4" x2="100" y2="{{ $i % 2 ? 9 : 13 }}" stroke="{{ $c1 }}" stroke-width="1.6" transform="rotate({{ $i * 7.5 }} 100 100)" />
                            @endfor
                            <circle cx="100" cy="100" r="86" fill="#fff" stroke="url(#sg)" stroke-width="3" />
                            <circle cx="100" cy="100" r="56" fill="{{ $soft }}" stroke="url(#sg)" stroke-width="1.5" stroke-dasharray="3 4" />
                            <text font-size="11.5" font-weight="900" letter-spacing="2.6" fill="{{ $c1 }}" text-anchor="middle"><textPath href="#arcTop" startOffset="50%">{{ $stateLabel }}</textPath></text>
                            <text font-size="10" font-weight="800" letter-spacing="2.2" fill="#64748b" text-anchor="middle"><textPath href="#arcBot" startOffset="50%">WIMS · {{ strtoupper($licence->edition) }}</textPath></text>
                            <g transform="translate(100 84) scale(.82)">
                                <path d="M0 -34 L30 -22 V6 C30 24 14 34 0 40 C-14 34 -30 24 -30 6 V-22 Z" fill="url(#sg)" />
                                @if ($live)
                                    <path d="M-13 2 L-4 11 L14 -9" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" />
                                @else
                                    <rect x="-11" y="-2" width="22" height="17" rx="3" fill="#fff" /><path d="M-7 -2 v-6 a7 7 0 0 1 14 0 v6" fill="none" stroke="#fff" stroke-width="4" />
                                @endif
                            </g>
                            <text x="100" y="136" font-size="16" font-weight="900" fill="#0f172a" text-anchor="middle">{{ $live ? max(0, $licence->days_left).' DAYS' : strtoupper($state) }}</text>
                            <text x="100" y="148" font-size="7.5" font-weight="800" letter-spacing="1.5" fill="#64748b" text-anchor="middle">{{ $live ? 'REMAINING' : 'STATUS' }}</text>
                        </svg>
                    </div>
                    <div>
                        <span class="no">{!! $svg('key', '#fff', 13) !!} {{ $licence->licence_number }}</span>
                        <h1>{{ $licence->licensee }}</h1>
                        <p>This certifies that <b>{{ $licence->licensee }}</b> is licensed to use the <b>{{ $appName }}</b> ({{ $licence->edition }} edition) from <b>{{ $licence->starts_at->format('j F Y') }}</b> until <b>{{ $licence->expires_at->format('j F Y') }}</b>, for the modules listed below.</p>
                        <div class="tags"><span>{{ $licence->licence_type }}</span><span>{{ $licence->duration_label }}</span><span>{{ $stateLabel }}</span>@if ($licence->deactivation_reason)<span>{{ \Illuminate\Support\Str::limit($licence->deactivation_reason, 40) }}</span>@endif</div>
                    </div>
                </section>

                <section class="tl">
                    <div class="track"><div class="fill"></div><div class="now"><i>TODAY · {{ $printedAt->format('d M Y') }}</i></div></div>
                    <div class="ends">
                        <div><small>VALID FROM</small><b>{{ $licence->starts_at->format('d M Y') }}</b></div>
                        <div class="mid"><small>{{ $elapsed }}% of the licence period used</small><b>{{ $licence->duration_label }}</b></div>
                        <div><small>VALID UNTIL</small><b>{{ $licence->expires_at->format('d M Y') }}</b></div>
                    </div>
                </section>

                <section class="grid">
                    @foreach ($cells as [$icon, $label, $value])
                        <div class="cell"><span class="ic">{!! $svg($icon, '#fff', 15) !!}</span><div><small>{{ $label }}</small><b>{{ $value }}</b></div></div>
                    @endforeach
                </section>

                <section class="keybox">
                    <div class="kh"><b>{!! $svg('key', '#fbbf24', 15) !!} LICENCE KEY</b><span>{{ strlen($key) }} CHARACTERS</span></div>
                    <div class="keyrows">
                        @if ($showKey)
                            @foreach ($blocks as $i => $row)
                                <div class="keyrow"><i>{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</i>@foreach ($row as $b)<span>{{ $b }}</span>@endforeach</div>
                            @endforeach
                        @else
                            @foreach ($blocks as $i => $row)
                                <div class="keyrow"><i>{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</i>@foreach ($row as $j => $b)<span>{{ ($i === 0 && $j === 0) || ($i === 7 && $j === 3) ? $b : '••••' }}</span>@endforeach</div>
                            @endforeach
                        @endif
                    </div>
                    <p class="keynote">{{ $showKey ? 'Keep this page safe: the key activates the licence.' : 'The full key is held by the Licence Manager.' }} Spaces and dashes are ignored when the key is pasted.</p>
                </section>

                <section class="hist">
                    <h3>{!! $svg('clock', '#475569', 14) !!} Licence history</h3>
                    <table>
                        @forelse ($events as $e)
                            <tr><td>{{ $eventNames[$e->event] ?? ucfirst($e->event) }}</td><td>{{ \Illuminate\Support\Str::limit($e->note, 70) }}</td><td>{{ $e->user->name ?? 'System' }} · {{ optional($e->created_at)->format('d M Y H:i') }}</td></tr>
                        @empty
                            <tr><td colspan="3">No history recorded.</td></tr>
                        @endforelse
                    </table>
                </section>

                <div class="grow"></div>

                <footer class="foot">
                    <div>Printed by {{ $printedBy }}<small>{{ $printedAt->format('j F Y, H:i') }}</small></div>
                    <div class="brand">{{ $appName }} <span class="grad">({{ $footerLabel }})</span>, ({{ $version }}). <span class="grad">Developed by Extrion</span><small>Questions about this licence? Contact {{ $contact }}.</small></div>
                    <div style="text-align:right">Page 1 of 1<small>{{ $licence->licence_number }}</small></div>
                </footer>
            </div>
        </div>
    </div>
    <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 600); });</script>
</body>
</html>
