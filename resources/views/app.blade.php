<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
  @php($faviconVersion = @filemtime(public_path('icons/favicon-32x32.png')) ?: time())
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" sizes="16x16" href="/icons/favicon-16x16.png?v={{ $faviconVersion }}" />
  <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32x32.png?v={{ $faviconVersion }}" />
  <link rel="icon" type="image/png" sizes="48x48" href="/icons/favicon-48x48.png?v={{ $faviconVersion }}" />
  <link rel="shortcut icon" href="/icons/favicon-32x32.png?v={{ $faviconVersion }}" />
  <link rel="manifest" href="{{ asset('/manifest.webmanifest') }}">
  <meta name="theme-color" content="#27447a" />
  <link rel="apple-touch-icon" sizes="180x180" href="/icons/icon-180x180.png?v={{ $faviconVersion }}" />
  <link rel="apple-touch-icon" sizes="152x152" href="/icons/icon-152x152.png?v={{ $faviconVersion }}" />
  <link rel="apple-touch-icon" sizes="144x144" href="/icons/icon-144x144.png?v={{ $faviconVersion }}" />
  <link rel="mask-icon" href="/icons/icon-512x512.png?v={{ $faviconVersion }}" color="#27447a" />
  <link rel="preload" as="image" href="/icons/icon-192x192.png?v={{ $faviconVersion }}" fetchpriority="high" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="black" />
  <meta name="apple-mobile-web-app-title" content="WIMS" />
  <meta name="application-name" content="WIMS" />
  <meta name="mobile-web-app-capable" content="yes" />
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title inertia>{{ config('app.name', 'WIMS') }}</title>

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,400;1,600&display=swap">

  <!-- Font Awesome Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <!-- Scripts -->
  <script>
    window.Locale = '{{ app()->getLocale() }}'
  </script>
  <script>
    window.__wimsInstallPrompt = null;
    window.addEventListener('beforeinstallprompt', function (event) {
      event.preventDefault();
      window.__wimsInstallPrompt = event;
      window.dispatchEvent(new CustomEvent('wims-install-prompt-ready'));
    });
  </script>
  <script>
    (function () {
      var host = window.location.hostname;
      var isLocal = host === '127.0.0.1' || host === 'localhost'
        || /^192\.168\.\d{1,3}\.\d{1,3}$/.test(host)
        || /^10\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(host)
        || /^172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}$/.test(host);
      if (!isLocal) return;

      var resetKey = 'wims-local-sw-reset-v1';
      if (window.sessionStorage.getItem(resetKey)) return;

      var jobs = [];
      if ('serviceWorker' in navigator) {
        jobs.push(
          navigator.serviceWorker.getRegistrations().then(function (regs) {
            return Promise.all(regs.map(function (reg) {
              return reg.unregister();
            }));
          })
        );
      }

      if ('caches' in window) {
        jobs.push(
          caches.keys().then(function (keys) {
            return Promise.all(keys.map(function (key) {
              return caches.delete(key);
            }));
          })
        );
      }

      Promise.all(jobs).finally(function () {
        window.sessionStorage.setItem(resetKey, '1');
        window.location.reload();
      });
    })();
  </script>
  <style>
    .app-loading {
      top: 0px;
      left: 0px;
      right: 0px;
      bottom: 0px;
      width: 100%;
      z-index: 40;
      display: flex;
      position: fixed;
      min-height: 100vh;
      align-items: center;
      flex-direction: column;
      justify-content: center;
    }

    .app-loading.bg-gray-50 {
      --tw-bg-opacity: 1;
      background-color: rgb(249 250 251 / var(--tw-bg-opacity));
    }

    .dark .app-loading.dark\:bg-gray-900 {
      --tw-bg-opacity: 1;
      background-color: rgb(17 24 39 / var(--tw-bg-opacity));
    }

    /* ===== Loading screen: a glowing HUD ring, the percentage in its centre, a glowing progress bar underneath ===== */
    .app-loading { overflow: hidden; background: radial-gradient(120% 90% at 50% 38%, #e6f0ff 0%, #f5f9ff 48%, #ffffff 100%); font-family: Montserrat, -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; }
    .wl-pct, .wl-wims, .wl-status, .wl-footer { font-family: Montserrat, -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; }
    .app-loading.bg-gray-50 { background: radial-gradient(120% 90% at 50% 38%, #e6f0ff 0%, #f5f9ff 48%, #ffffff 100%); }
    .wl-grid { position: absolute; inset: 0; pointer-events: none; background-image: linear-gradient(rgba(37, 99, 235, 0.07) 1px, transparent 1px), linear-gradient(90deg, rgba(37, 99, 235, 0.07) 1px, transparent 1px); background-size: 34px 34px; -webkit-mask-image: radial-gradient(60% 55% at 50% 45%, #000 10%, transparent 75%); mask-image: radial-gradient(60% 55% at 50% 45%, #000 10%, transparent 75%); }
    .wl-corner { position: absolute; width: 46px; height: 46px; border: 0 solid rgba(37, 99, 235, 0.7); filter: drop-shadow(0 0 5px rgba(59, 130, 246, 0.6)); }
    .wl-tl { top: 9%; left: 8%; border-top-width: 2px; border-left-width: 2px; border-top-left-radius: 4px; }
    .wl-tr { top: 9%; right: 8%; border-top-width: 2px; border-right-width: 2px; border-top-right-radius: 4px; }
    .wl-bl { bottom: 9%; left: 8%; border-bottom-width: 2px; border-left-width: 2px; border-bottom-left-radius: 4px; }
    .wl-br { bottom: 9%; right: 8%; border-bottom-width: 2px; border-right-width: 2px; border-bottom-right-radius: 4px; }
    .wl-hud { position: relative; width: 248px; height: 248px; display: grid; place-items: center; }
    .wl-glow { position: absolute; inset: -60px; z-index: 0; border-radius: 50%; background: radial-gradient(circle, rgba(59, 130, 246, 0.38) 0%, rgba(96, 165, 250, 0.16) 42%, rgba(96, 165, 250, 0) 70%); animation: wl-pulse 2.6s ease-in-out infinite; }
    @keyframes wl-pulse { 0%, 100% { transform: scale(0.92); opacity: 0.65; } 50% { transform: scale(1.1); opacity: 1; } }
    .wl-ring { position: relative; z-index: 1; width: 100%; height: 100%; overflow: visible; }
    .wl-ring circle { fill: none; transform-box: fill-box; transform-origin: 50% 50%; } /* every circle is centred on the same point: spin about its own centre, the same in every browser (an iPad drew px origins off the ring and the circles slid over each other) */
    .wl-ticks { stroke: rgba(37, 99, 235, 0.55); stroke-width: 7; stroke-dasharray: 1.4 9.6; animation: wl-spin 40s linear infinite; }
    .wl-track { stroke: rgba(37, 99, 235, 0.16); stroke-width: 9; stroke-dasharray: 15 6; }
    .wl-arc { stroke: url(#wl-grad); stroke-width: 9; stroke-dasharray: 15 6; filter: drop-shadow(0 0 5px rgba(37, 99, 235, 0.75)) drop-shadow(0 0 12px rgba(56, 189, 248, 0.55)); }
    .wl-mask { stroke: #fff; stroke-width: 24; transition: stroke-dasharray 0.25s linear; }
    .wl-dots { stroke: rgba(37, 99, 235, 0.7); stroke-width: 2.4; stroke-linecap: round; stroke-dasharray: 0.1 6.2; animation: wl-spin 28s linear infinite reverse; }
    .wl-inner { stroke: rgba(37, 99, 235, 0.22); stroke-width: 1.2; }
    @keyframes wl-spin { to { transform: rotate(360deg); } }
    .app-loading:not(.wl-font) .wl-center, .app-loading:not(.wl-font) .wl-status, .app-loading:not(.wl-font) .wl-footer { visibility: hidden; }
    .wl-center { position: absolute; z-index: 2; display: grid; justify-items: center; gap: 2px; text-align: center; }
    .wl-wims { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.32em; padding-left: 0.32em; color: #3b6fd6; opacity: 0.85; }
    .wl-pct { font-size: 2.35rem; font-weight: 700; line-height: 1; letter-spacing: -0.01em; font-variant-numeric: tabular-nums; color: #1d4ed8; text-shadow: 0 0 22px rgba(59, 130, 246, 0.6), 0 0 4px rgba(147, 197, 253, 0.9); }
    .wl-status { position: relative; z-index: 1; margin-top: 30px; font-size: 0.8rem; font-weight: 700; letter-spacing: 0.04em; color: #2f5fc4; }
    .wl-status i { font-style: normal; display: inline-block; width: 1.4em; overflow: hidden; vertical-align: bottom; text-align: left; animation: wl-dots 1.3s steps(4, end) infinite; }
    @keyframes wl-dots { from { width: 0; } to { width: 1.4em; } }
    .wl-bar { position: relative; z-index: 1; width: min(220px, 62vw); height: 14px; margin-top: 10px; padding: 2px; border-radius: 999px; background: rgba(37, 99, 235, 0.07); box-shadow: inset 0 0 0 1.5px rgba(37, 99, 235, 0.4), 0 0 14px rgba(59, 130, 246, 0.22); }
    .wl-bar span { display: block; width: 0%; height: 100%; min-width: 10px; border-radius: 999px; background: linear-gradient(90deg, #1d4ed8, #3b82f6 55%, #67e8f9); box-shadow: 0 0 10px rgba(56, 189, 248, 0.85), 0 0 22px rgba(59, 130, 246, 0.5); transition: width 0.25s linear; }

    /* dark theme: the same HUD on deep navy */
    .dark .app-loading, html.dark .app-loading { background: radial-gradient(120% 90% at 50% 38%, #0b2260 0%, #050b22 55%, #02050f 100%) !important; }
    .dark .wl-grid { background-image: linear-gradient(rgba(96, 165, 250, 0.1) 1px, transparent 1px), linear-gradient(90deg, rgba(96, 165, 250, 0.1) 1px, transparent 1px); }
    .dark .wl-corner { border-color: rgba(96, 200, 255, 0.85); }
    .dark .wl-glow { background: radial-gradient(circle, rgba(37, 99, 235, 0.55) 0%, rgba(37, 99, 235, 0.2) 42%, rgba(37, 99, 235, 0) 70%); }
    .dark .wl-ticks { stroke: rgba(125, 180, 255, 0.6); } .dark .wl-track { stroke: rgba(96, 165, 250, 0.2); } .dark .wl-dots { stroke: rgba(147, 197, 253, 0.85); } .dark .wl-inner { stroke: rgba(147, 197, 253, 0.25); }
    .dark .wl-wims { color: #8fc2ff; } .dark .wl-pct { color: #cfe6ff; text-shadow: 0 0 20px rgba(56, 189, 248, 0.9), 0 0 4px rgba(191, 227, 255, 0.9); } .dark .wl-status { color: #7dd3fc; }
    .dark .wl-bar { background: rgba(8, 18, 48, 0.8); box-shadow: inset 0 0 0 1.5px rgba(125, 180, 255, 0.5), 0 0 16px rgba(56, 189, 248, 0.25); }
    @media (prefers-reduced-motion: reduce) { .wl-ticks, .wl-dots, .wl-glow, .wl-status i { animation: none; } }
    /* footer label, the same wording as the dashboard footer */
    .wl-footer { position: absolute; left: 0; right: 0; bottom: max(14px, env(safe-area-inset-bottom)); z-index: 1; padding: 0 14px; text-align: center; font-size: 12px; line-height: 1.5; color: #9ca3af; }
    .wl-footer p { margin: 0; }
    .wl-footer .wl-lic { font-weight: 700; background: linear-gradient(90deg, #16a34a, #f59e0b, #16a34a); background-size: 250% 100%; -webkit-background-clip: text; background-clip: text; color: transparent; animation: wl-shimmer 6s linear infinite; }
    .wl-footer .wl-credit { font-weight: 600; background: linear-gradient(90deg, #9ca3af, #38bdf8, #9ca3af); background-size: 250% 100%; -webkit-background-clip: text; background-clip: text; color: transparent; animation: wl-shimmer 8s linear infinite; }
    .wl-footer .wl-terms { display: inline-flex; align-items: center; gap: 5px; margin-top: 2px; color: #9ca3af; }
    .wl-footer .wl-terms svg { width: 13px; height: 13px; fill: currentColor; }
    @keyframes wl-shimmer { from { background-position: 0 0; } to { background-position: 250% 0; } }
    .dark .wl-footer, .dark .wl-footer .wl-terms { color: #94a3b8; }
    @media (max-width: 639px) { .wl-footer .wl-long { display: none; } }
    @media (min-width: 640px) { .wl-footer .wl-short { display: none; } }
    @media (prefers-reduced-motion: reduce) { .wl-footer .wl-lic, .wl-footer .wl-credit { animation: none; } }
  </style>
  @routes
  @vite('resources/js/app.js', '')
  @inertiaHead
</head>

<body class="font-sans antialiased">
  <div id="app-loading" class="app-loading bg-gray-50 dark:bg-gray-900">
    <div class="wl-grid"></div>
    <span class="wl-corner wl-tl"></span><span class="wl-corner wl-tr"></span><span class="wl-corner wl-bl"></span><span class="wl-corner wl-br"></span>
    <div class="wl-hud">
      <div class="wl-glow"></div>
      <svg class="wl-ring" viewBox="0 0 240 240" aria-hidden="true">
        <defs>
          <linearGradient id="wl-grad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#67e8f9" /><stop offset="0.5" stop-color="#3b82f6" /><stop offset="1" stop-color="#1d4ed8" /></linearGradient>
          <mask id="wl-reveal"><circle id="wims-loader-mask" class="wl-mask" cx="120" cy="120" r="96" pathLength="100" stroke-dasharray="0 100" /></mask>
        </defs>
        <circle class="wl-ticks" cx="120" cy="120" r="113" />
        <!-- the rotation lives on a group with an explicit centre (not on a CSS origin), so the track, the arc and the mask start at 12 o'clock and stay concentric in every browser -->
        <g transform="rotate(-90 120 120)">
          <circle class="wl-track" cx="120" cy="120" r="96" />
          <circle class="wl-arc" cx="120" cy="120" r="96" mask="url(#wl-reveal)" />
        </g>
        <circle class="wl-dots" cx="120" cy="120" r="80" />
        <circle class="wl-inner" cx="120" cy="120" r="70" />
      </svg>
      <div class="wl-center">
        <div class="wl-wims">WIMS</div>
        <div class="wl-pct" id="wims-loader-percent">0%</div>
      </div>
    </div>
    <div class="wl-status">Loading<i id="wl-dots">...</i></div>
    <div class="wl-bar"><span id="wl-bar-fill"></span></div>
    @php($wlSettings = $page['props']['settings'] ?? [])
    @php($wlName = $wlSettings['app_name'] ?? 'RANS Workflow Integrated Management System')
    @php($wlVersion = $wlSettings['version_label'] ?? 'V7.0')
    @php($wlCredit = $wlSettings['footer_credit'] ?? 'Developed by Extrion')
    @php($wlLicence = $page['props']['licenceStatus']['label'] ?? null)
    <div class="wl-footer">
      <p class="wl-long">{{ $wlName }} @if($wlLicence)<span class="wl-lic">({{ $wlLicence }})</span>@endif, ({{ $wlVersion }}). <span class="wl-credit">{{ $wlCredit }}</span></p>
      <p class="wl-short">RANS WIMS @if($wlLicence)<span class="wl-lic">({{ $wlLicence }})</span>@endif ({{ $wlVersion }}). <span class="wl-credit">{{ $wlCredit }}</span></p>
      <p class="wl-terms"><svg viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" /></svg><span>Terms and Conditions</span></p>
    </div>
  </div>
  <script>
    (function () {
      var percent = 0;
      var target = 90;
      var finished = false;
      var lastTime = null;
      var rafId = null;
      // show the loader's text only once the WIMS font (Montserrat) is ready, so the percentage is never drawn in a fallback face (1.5 s at most)
      (function () {
        var wrap = document.getElementById('app-loading');
        var show = function () { if (wrap) wrap.classList.add('wl-font'); };
        if (document.fonts && document.fonts.load) {
          Promise.all([document.fonts.load('700 38px Montserrat'), document.fonts.load('600 12px Montserrat')]).then(show, show);
          setTimeout(show, 1500);
        } else { show(); }
      })();
      var maskEl = document.getElementById('wims-loader-mask');
      var barEl = document.getElementById('wl-bar-fill');
      var percentEl = document.getElementById('wims-loader-percent');

      function render() {
        if (maskEl) maskEl.style.strokeDasharray = percent + ' 100';
        if (barEl) barEl.style.width = percent + '%';
        if (percentEl) percentEl.textContent = Math.round(percent) + '%';
      }

      // Delta-time driven easing (not a fixed per-frame step) so the fill
      // moves at the same real-world speed no matter the display's refresh
      // rate — a 60Hz and 120Hz screen both reach ~90% in about the same time.
      function tick(now) {
        if (finished) return;
        if (lastTime == null) lastTime = now;
        var dt = Math.min(now - lastTime, 100);
        lastTime = now;

        var remaining = target - percent;
        percent += remaining * (1 - Math.pow(0.001, dt / 1000)) + dt * 0.01;
        if (percent > target) percent = target;

        render();
        rafId = requestAnimationFrame(tick);
      }

      render();
      rafId = requestAnimationFrame(tick);

      function onFullyFilled() {
        var wrap = document.getElementById('app-loading');
        if (!wrap) return;
        wrap.style.transition = 'opacity 0.4s ease';
        wrap.style.opacity = '0';
        setTimeout(function () {
          wrap.style.display = 'none';
        }, 400);
      }

      // Real completion is signalled by __wimsFinishLoading() once Vue
      // actually mounts. This completes synchronously rather than just
      // retargeting to 100 and waiting for the rAF loop to catch up —
      // requestAnimationFrame is throttled or fully paused by the browser
      // whenever the tab isn't in the foreground, which left the loader
      // stuck forever (Vue had mounted and the page was already usable
      // underneath it) any time that happened. The CSS transition on the
      // clip-path still gives a smooth finish in the normal foregrounded
      // case; it just no longer gates completion.
      window.__wimsFinishLoading = function () {
        if (finished) return;
        finished = true;
        if (rafId != null) {
          cancelAnimationFrame(rafId);
          rafId = null;
        }
        percent = 100;
        render();
        onFullyFilled();
      };
    })();
  </script>
  @inertia
</body>

</html>
